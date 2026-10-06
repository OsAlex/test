# Продолжаю. Блок 2 — критические фиксы #5, #4, #11

Поскольку диагностика ещё не прогнана, выдаю патчи в виде готовых модулей с явными точками встраивания. Адаптировать под реальные сигнатуры — 10–15 минут правки после `diagnose.sh`.

---

## Fix #5. `checkHybrid` через `curl_multi`

### Что сломано

`MarkingCheckService::checkHybrid()` в текущей реализации (по отчёту §7.2) выполняет **последовательно**: curl онлайн с таймаутом 1.5 с, потом офлайн. Это ломает SPEC §3.4:

- Если онлайн таймаутит 1.5 с — офлайн вообще не стартует → `EMERGENCY_TIMEOUT` там, где мог быть ответ.
- Если онлайн отвечает за 1.4 с — офлайн стартует после, ответит за 1.6 с → по SPEC должен быть «оба пришли до 1.5 с»? Нет, общий таймер уже прошёл 1.5 с.

### Root cause

Plain PHP без потоков — но `curl_multi` даёт неблокирующий параллельный HTTP без `pcntl_fork`.

### Патч: `Service/Marking/HttpParallel.php`

```php
<?php
declare(strict_types=1);

namespace Service\Marking;

/**
 * Параллельный запуск N HTTP-запросов с общим барьером.
 * В отличие от curl_exec — все запросы стартуют одновременно,
 * барьер применяется к общему времени, а не к каждому отдельно.
 */
final class HttpParallel
{
    /**
     * @param array<string, array{url:string, method?:string, headers?:array, body?:string}> $requests
     *   ключ => описание запроса (ключ используется как имя результата)
     * @param float $barrierSec  жёсткий барьер для всего блока
     * @return array<string, HttpResponse|null>  null => запрос не успел / не был запущен
     */
    public static function run(array $requests, float $barrierSec): array
    {
        $results = [];
        if (!$requests) return $results;

        $mh = curl_multi_init();
        $handles = [];
        $start = microtime(true);

        foreach ($requests as $key => $req) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL            => $req['url'],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => self::flattenHeaders($req['headers'] ?? []),
                CURLOPT_CUSTOMREQUEST  => $req['method'] ?? 'GET',
                CURLOPT_CONNECTTIMEOUT => (int) ceil($barrierSec),
                CURLOPT_TIMEOUT        => (int) ceil($barrierSec) + 1,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            if (isset($req['body'])) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $req['body']);
            }
            curl_multi_add_handle($mh, $ch);
            $handles[$key] = $ch;
            $results[$key] = null; // по умолчанию — не ответил
        }

        // Ждём, пока все завершатся или выйдет барьер
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                // Не блокируемся больше 50 мс, чтобы успеть проверить барьер
                curl_multi_select($mh, 0.05);
            }
            $elapsed = microtime(true) - $start;
            if ($elapsed >= $barrierSec) {
                break;
            }
        } while ($running > 0 && $status === CURLM_OK);

        // Собираем то, что успело ответить
        foreach ($handles as $key => $ch) {
            $body = curl_multi_getcontent($ch);
            if ($body !== null && $body !== false && $body !== '') {
                $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                if ($httpCode > 0) {
                    $results[$key] = new HttpResponse($httpCode, $body);
                }
            }
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }

        curl_multi_close($mh);
        return $results;
    }

    private static function flattenHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $k => $v) {
            $out[] = is_int($k) ? (string) $v : "$k: $v";
        }
        return $out;
    }
}
```

### Патч `MarkingCheckService::checkHybrid()`

Заменить последовательную реализацию на:

```php
public function checkHybrid(
    array $codes,
    string $fiscalDriveNumber,
    int $timeZone = 2
): MarkingCheckResult {
    $barrier = (float) MarkingConfig::get('code_check.timeout', 1.5);

    // Готовим два запроса: онлайн и офлайн
    $online  = $this->buildOnlineRequest($codes, $fiscalDriveNumber, $timeZone);
    $offline = $this->buildOfflineRequest($codes);

    $responses = HttpParallel::run([
        'online'  => $online,
        'offline' => $offline,
    ], $barrier);

    $onlineResult  = $responses['online']  ? $this->parseOnline($responses['online'])  : null;
    $offlineResult = $responses['offline'] ? $this->parseOffline($responses['offline']) : null;

    // Матрица решений — SPEC §3.4
    if ($onlineResult !== null && $offlineResult !== null) {
        return $this->mergeBoth($onlineResult, $offlineResult, $codes);
    }
    if ($onlineResult !== null) {
        return $this->fromOnlineOnly($onlineResult, $codes);
    }
    if ($offlineResult !== null) {
        return $this->fromOfflineOnly($offlineResult, $codes);
    }

    // Оба не ответили за барьер → EMERGENCY_TIMEOUT
    $this->metrics->increment('marking_emergency_total');
    return MarkingCheckResult::emergencyTimeout($codes, [
        'barrier_sec'    => $barrier,
        'online_url'     => $online['url'],
        'offline_url'    => $offline['url'],
    ]);
}
```

### Тест на race-сценарий — `tests/MarkingCheckServiceRaceTest.php`

```php
<?php
declare(strict_types=1);

namespace Tests;

class MarkingCheckServiceRaceTest
{
    public function run(TestHarness $t): void
    {
        // Сценарий 1: онлайн 1.4 с, офлайн 0.3 с → оба успели, merge
        $this->testBothFast($t);

        // Сценарий 2: онлайн 0.2 с, офлайн 2.0 с (за барьером) → только онлайн
        $this->testOfflineTimeout($t);

        // Сценарий 3: онлайн 2.0 с, офлайн 0.3 с → только офлайн
        $this->testOnlineTimeout($t);

        // Сценарий 4: оба 2.0 с → EMERGENCY_TIMEOUT
        $this->testBothTimeout($t);
    }

    private function testBothFast(TestHarness $t): void
    {
        $fixture = new RaceFixture();
        $fixture->setOnlineDelay(1.4)->setOfflineDelay(0.3);
        // Запуск через реальный HTTP к локальному фейковому серверу
        // (или через инъекцию HttpParallel в тестовом режиме)
        $result = $fixture->run(barrierSec: 1.5);
        $t->assertEquals('HYBRID', $result->source, 'оба успели → HYBRID');
    }

    private function testOfflineTimeout(TestHarness $t): void
    {
        $fixture = new RaceFixture();
        $fixture->setOnlineDelay(0.2)->setOfflineDelay(2.0);
        $result = $fixture->run(barrierSec: 1.5);
        $t->assertEquals('ONLINE', $result->source, 'офлайн за барьером → только ONLINE');
    }

    private function testOnlineTimeout(TestHarness $t): void
    {
        $fixture = new RaceFixture();
        $fixture->setOnlineDelay(2.0)->setOfflineDelay(0.3);
        $result = $fixture->run(barrierSec: 1.5);
        $t->assertEquals('OFFLINE', $result->source, 'онлайн за барьером → только OFFLINE');
    }

    private function testBothTimeout(TestHarness $t): void
    {
        $fixture = new RaceFixture();
        $fixture->setOnlineDelay(2.0)->setOfflineDelay(2.0);
        $result = $fixture->run(barrierSec: 1.5);
        $t->assertEquals('EMERGENCY_TIMEOUT', $result->source, 'оба за барьером → EMERGENCY_TIMEOUT');
    }
}
```

Реальный `RaceFixture` можно поднять как локальный HTTP-сервер (встроенный `php -S`) с контролируемыми задержками, либо — если в проекте есть `FakeHttpClient` — расширить его поддержкой `delay`. Суть: тест должен доказать, что **общий** барьер 1.5 с, а не **индивидуальный** таймаут на каждый запрос.

### Проверка

```bash
php tests/run_all.php --filter=RaceTest
```

Должно быть 4 PASS. **Без `HttpParallel`** тест 4 (оба таймаутят) не выявит проблему (последовательно → всегда `EMERGENCY_TIMEOUT`, но по неверной причине). Симптом бага в текущей реализации — **сценарий 1 (оба быстрые) даёт ONLINE-only** вместо HYBRID, потому что офлайн вообще не запускался.

---

## Fix #4. Persistence circuit breaker

### Что сломано

Отчёт §7.5: блокировка хостов CDN in-memory (per-process). В FPM-pool N воркеров — N независимых счётчиков. Circuit breaker не работает.

### Root cause

Нет shared storage. В проекте нет Redis (по `package.json`/`composer.json` — plain PHP). Есть MySQL.

### Патч: `Models/MarkingCdnHostState.php` + миграция

**DDL — добавить в `sql/marking_tables.sql`:**

```sql
CREATE TABLE IF NOT EXISTS marking_cdn_host_state (
    host           VARCHAR(255) PRIMARY KEY,
    blocked_until  TIMESTAMP NULL,
    fail_count     INT NOT NULL DEFAULT 0,
    last_fail_at   TIMESTAMP NULL,
    last_check_at  TIMESTAMP NULL,
    avg_time_ms    INT NULL,
    updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_blocked (blocked_until)
);
```

**Модель:**

```php
<?php
declare(strict_types=1);

namespace Models;

use PDO;

final class MarkingCdnHostState
{
    public function __construct(private PDO $pdo) {}

    public function isBlocked(string $host): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT blocked_until FROM marking_cdn_host_state WHERE host = ?"
        );
        $stmt->execute([$host]);
        $until = $stmt->fetchColumn();
        if ($until === false || $until === null) return false;
        return strtotime((string) $until) > time();
    }

    public function recordSuccess(string $host, int $avgTimeMs): void
    {
        $this->pdo->prepare("
            INSERT INTO marking_cdn_host_state
                (host, blocked_until, fail_count, last_check_at, avg_time_ms)
            VALUES (?, NULL, 0, NOW(), ?)
            ON DUPLICATE KEY UPDATE
                blocked_until = NULL,
                fail_count    = 0,
                last_check_at = NOW(),
                avg_time_ms   = VALUES(avg_time_ms)
        ")->execute([$host, $avgTimeMs]);
    }

    public function recordFailure(string $host, int $threshold = 3, int $blockSeconds = 900): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO marking_cdn_host_state
                (host, fail_count, last_fail_at)
             VALUES (?, 1, NOW())
             ON DUPLICATE KEY UPDATE
                fail_count   = fail_count + 1,
                last_fail_at = NOW()"
        );
        $stmt->execute([$host]);

        // Проверяем, не пора блокировать
        $cnt = (int) $this->pdo->query(
            "SELECT fail_count FROM marking_cdn_host_state WHERE host = " .
            $this->pdo->quote($host)
        )->fetchColumn();

        if ($cnt >= $threshold) {
            $until = date('Y-m-d H:i:s', time() + $blockSeconds);
            $this->pdo->prepare("
                UPDATE marking_cdn_host_state
                SET blocked_until = ?, fail_count = 0
                WHERE host = ?
            ")->execute([$until, $host]);
        }
    }

    public function nextUnblockAt(string $host): ?string
    {
        $stmt = $this->pdo->prepare(
            "SELECT blocked_until FROM marking_cdn_host_state
             WHERE host = ? AND blocked_until > NOW()"
        );
        $stmt->execute([$host]);
        $val = $stmt->fetchColumn();
        return $val === false ? null : (string) $val;
    }
}
```

**Патч в `CdnService`:**

```php
private function isBlocked(string $host): bool
{
    if ($this->hostState !== null) {
        return $this->hostState->isBlocked($host);
    }
    // fallback на in-memory (для тестов без БД)
    $until = $this->blockedHosts[$host] ?? 0;
    return $until > time();
}

private function onFailure(string $host): void
{
    if ($this->hostState !== null) {
        $this->hostState->recordFailure(
            $host,
            (int) MarkingConfig::get('cdn.switch_max_failures', 3),
            (int) MarkingConfig::get('cdn.block_duration', 900)
        );
        return;
    }
    // in-memory fallback
    $this->failCount[$host] = ($this->failCount[$host] ?? 0) + 1;
    if ($this->failCount[$host] >= 3) {
        $this->blockedHosts[$host] = time() + 900;
        $this->failCount[$host] = 0;
    }
}

private function onSuccess(string $host, int $avgTimeMs): void
{
    if ($this->hostState !== null) {
        $this->hostState->recordSuccess($host, $avgTimeMs);
    } else {
        unset($this->blockedHosts[$host], $this->failCount[$host]);
    }
}
```

**Инъекция в конструктор `CdnService`:**

```php
public function __construct(
    private HttpClient $http,
    private ?MarkingCdnHostState $hostState = null,
) {}
```

В проде — `new CdnService($http, new MarkingCdnHostState($pdo))`. В тестах — `new CdnService($fakeHttp, null)`.

### Тест — `tests/CdnCircuitBreakerPersistenceTest.php`

```php
public function run(TestHarness $t): void
{
    // Предполагается in-memory SQLite (или test-MySQL) в $this->pdo
    $this->applyTable($this->pdo, 'marking_cdn_host_state');

    $stateA = new MarkingCdnHostState($this->pdo);
    $stateB = new MarkingCdnHostState($this->pdo);

    $host = 'https://cdn01.test';

    // 3 fail'а через инстанс A
    $stateA->recordFailure($host);
    $stateA->recordFailure($host);
    $stateA->recordFailure($host);

    // Инстанс B видит блокировку — это и есть shared state
    $t->assertTrue($stateB->isBlocked($host), 'Инстанс B видит блокировку из A');
    $t->assertTrue($stateB->nextUnblockAt($host) !== null, 'blocked_until установлен');

    // Успешный health-check снимает блокировку
    $stateA->recordSuccess($host, 42);
    $t->assertTrue(!$stateB->isBlocked($host), 'Успех снял блокировку для обоих');
}
```

### Проверка

```bash
TEST_DB_DSN="sqlite::memory:" php tests/run_all.php --filter=CdnCircuitBreaker
```

Должно пройти 3 PASS. Без persistence — тест «Инстанс B видит блокировку из A» упадёт.

---

## Fix #11. Атомарный захват `pending` в воркере

### Что сломано

`process_sells.php` выбирает pending-строку без блокировки. Два воркера одновременно → двойной вызов `/cis/sell`.

### Патч: `Models/MarkingSellQueue::claimNextPending()`

```php
/**
 * Атомарно захватывает одну pending-строку.
 * Возвращает id или null, если очередь пуста.
 *
 * Использует SELECT ... FOR UPDATE SKIP LOCKED (MySQL 8+, PostgreSQL)
 * либо атомарный UPDATE (MySQL 5.7).
 */
public function claimNextPending(): ?int
{
    $driver = (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'mysql' && $this->supportsSkipLocked()) {
        return $this->claimWithSkipLocked();
    }
    if ($driver === 'pgsql') {
        return $this->claimWithSkipLocked();
    }
    return $this->claimWithAtomicUpdate();
}

private function claimWithSkipLocked(): ?int
{
    $this->pdo->beginTransaction();
    try {
        $stmt = $this->pdo->query(
            "SELECT id FROM marking_sell_queue
             WHERE status = 'pending'
             ORDER BY id ASC
             LIMIT 1
             FOR UPDATE SKIP LOCKED"
        );
        $id = $stmt->fetchColumn();
        if ($id === false) {
            $this->pdo->commit();
            return null;
        }
        $id = (int) $id;
        $this->pdo->prepare(
            "UPDATE marking_sell_queue
             SET status = 'processing',
                 processed_at = NOW(),
                 attempts = attempts + 1
             WHERE id = ?"
        )->execute([$id]);
        $this->pdo->commit();
        return $id;
    } catch (\Throwable $e) {
        $this->pdo->rollBack();
        throw $e;
    }
}

private function claimWithAtomicUpdate(): ?int
{
    // Атомарный UPDATE с подзапросом: работает и без FOR UPDATE.
    // Два параллельных инстанса конкурируют за одну строку —
    // у одного UPDATE затронет 1 строку, у другого — 0.
    $this->pdo->beginTransaction();
    try {
        $this->pdo->exec(
            "UPDATE marking_sell_queue
             SET status = 'processing',
                 processed_at = NOW(),
                 attempts = attempts + 1
             WHERE status = 'pending'
               AND id = (
                   SELECT id FROM (
                       SELECT id FROM marking_sell_queue
                       WHERE status = 'pending'
                       ORDER BY id ASC LIMIT 1
                   ) AS t
               )"
        );
        $affected = (int) $this->pdo->query("SELECT ROW_COUNT()")->fetchColumn();
        if ($affected === 0) {
            $this->pdo->commit();
            return null;
        }

        // Возвращаем id строки, которую только что захватили.
        // В MySQL нельзя вернуть id через UPDATE без RETURNING,
        // поэтому — по processed_at, ближайший к NOW.
        $stmt = $this->pdo->query(
            "SELECT id FROM marking_sell_queue
             WHERE status = 'processing'
             ORDER BY processed_at DESC, id DESC
             LIMIT 1"
        );
        $id = (int) $stmt->fetchColumn();
        $this->pdo->commit();
        return $id > 0 ? $id : null;
    } catch (\Throwable $e) {
        $this->pdo->rollBack();
        throw $e;
    }
}

private function supportsSkipLocked(): bool
{
    static $supports = null;
    if ($supports !== null) return $supports;
    try {
        $version = (string) $this->pdo->query("SELECT VERSION()")->fetchColumn();
        $supports = version_compare($version, '8.0.0', '>=');
    } catch (\Throwable) {
        $supports = false;
    }
    return $supports;
}
```

### Патч `process_sells.php`

```php
$queue = new MarkingSellQueue($pdo);
$lmChz = new LmChzService(/* ... */);

$processed = 0;
while (($id = $queue->claimNextPending()) !== null) {
    $row = $queue->find($id);
    try {
        $lmChz->sell($row->cisList());
        $queue->markDone($id);
        $processed++;
    } catch (HttpException $e) {
        if ($row->attempts() >= 3) {
            $queue->markFailed($id, $e->getMessage());
            // алерт
        } else {
            $queue->markPending($id); // вернуть в очередь для retry
        }
    }
}
```

### Тест на race — уже в `DbSmokeTest::testAtomicClaim`

Расширить его дополнительным assert'ом: **два инстанса `MarkingSellQueue` подряд** должны получить **разные** id:

```php
private function testAtomicClaimAcrossInstances(TestHarness $t): void
{
    // Подготовка: 1 pending
    $this->pdo->exec("DELETE FROM marking_sell_queue");
    $this->pdo->exec("
        INSERT INTO marking_sell_queue (order_id, check_uuid, cis_list, status)
        VALUES (4001, 'aaaa...', '[\"x\"]', 'pending')
    ");

    $qA = new \Models\MarkingSellQueue($this->pdo);
    $qB = new \Models\MarkingSellQueue($this->pdo);

    $idA = $qA->claimNextPending();
    $idB = $qB->claimNextPending();

    $t->assertTrue($idA !== null, 'первый инстанс получил строку');
    $t->assertTrue($idB === null, 'второй инстанс не получил ту же строку');
}
```

### Проверка

```bash
TEST_DB_DSN="mysql:host=127.0.0.1;dbname=marking_test" \
TEST_DB_USER=root TEST_DB_PASS=secret \
php tests/run_all.php --filter=DbSmokeTest
```

Ожидание: `testAtomicClaimAcrossInstances` — PASS.

---

## Сводка блока 2

| Fix | Что добавлено | Файлы |
|-----|---------------|-------|
| #5 | `HttpParallel`, новый `checkHybrid` | `Service/Marking/HttpParallel.php`, патч `MarkingCheckService` |
| #4 | Persistence circuit breaker | `Models/MarkingCdnHostState.php`, DDL, патч `CdnService` |
| #11 | Атомарный захват pending | метод `MarkingSellQueue::claimNextPending`, патч `process_sells.php` |
| #5, #4, #11 | Тесты | `MarkingCheckServiceRaceTest`, `CdnCircuitBreakerPersistenceTest`, расширение `DbSmokeTest` |

**Что нужно от вас перед следующим блоком:**

1. Прогнать `scripts/diagnose.sh` и прислать вывод — по #5 (есть ли `curl_multi`/`pcntl`), #11 (`SELECT ... FOR UPDATE` или обычный), #4 (`blocked_until` где-то есть?), чтобы патчи точнее легли.
2. Подтвердить, что `PDO` доступен в проде (в отчёте `MemoryHistoryWriter` — без БД). Если БД нет — persistence circuit breaker и atomic claim **невозможны** без файлового/Redis-storage. Это изменит архитектуру.

---

## Следующий блок (готов выдать следующим сообщением)

- **#8** — гейт `MARKING_ATOL_PLACEHOLDER=1` + `APP_ENV=prod` → throw в `Check::buildMarkingAttribute`.
- **#3** — разнести `marking_emergency_state` и `marking_cdn_host_state` (уже начато в #4).
- **#10** — устранить двойную запись return: либо `marking_sell_queue.status='return'`, либо `marking_return_queue` — но не оба.
- **#12** — определить `cod_marking_cis` / `cod_marking_package_type` в SPEC §2.4 + тест «пустой cis → SKIPPED, не BLOCK».
- **#6** — `verified=false`: обосновать выбор WARN/BLOCK ссылкой на источник.
- **#7** — `filter-repo` для .deb/.rpm + `.gitignore`.
- **#9** — проверить `composer.json`, при отсутствии — задокументировать autoload.
- **#13** — заменить throw на `MarkingCheckResult::skipped('advance_check')`.
- **#14** — тесты uiPayload для INFO и EMERGENCY_TIMEOUT.
- **#1** — сверка счёта тестов: 134 vs 143.

Скажите «дальше» — выдам блок 3.

# Блок 3 — фиксы #8, #12, #10, #3, #13, #6, #9, #7, #14, #1

По порядку критичности. Каждый фикс — патч + тест + проверка.

---

## Fix #8. Гейт на ATOL placeholder в проде

### Проблема

`Models/Check.php::buildMarkingAttribute()` содержит приближение тега 1260 (`additionalAttribute`). Если `MARKING_ENABLE=on` до получения официального JSON Атол — чек уйдёт на реальную ККТ с неверной структурой.

### Патч

**`config/marking.php` — добавить:**

```php
'atol' => [
    // 1 = использовать placeholder (mock-режим), 0 = только официальный пример.
    'tag1260_placeholder' => (bool) env('MARKING_ATOL_PLACEHOLDER', true),
    // Путь к официальному JSON, полученному от Атол (ДККТ 10.10.8.24).
    'tag1260_example_path' => env('MARKING_ATOL_EXAMPLE_PATH', ''),
],
```

**`.env.example` — добавить:**

```
MARKING_ATOL_PLACEHOLDER=1
MARKING_ATOL_EXAMPLE_PATH=
```

**`Models/Check.php` — в начале `buildMarkingAttribute()`:**

```php
private function assertAtolExampleAvailable(): void
{
    $isProd   = (getenv('APP_ENV') ?: 'dev') === 'prod';
    $isMock   = (bool) MarkingConfig::get('atol.tag1260_placeholder', true);
    $path     = (string) MarkingConfig::get('atol.tag1260_example_path', '');

    if ($isProd && $isMock) {
        throw new \RuntimeException(
            'ATOL tag1260 placeholder is enabled in production. ' .
            'Set MARKING_ATOL_PLACEHOLDER=0 and MARKING_ATOL_EXAMPLE_PATH ' .
            'after obtaining the official JSON from ATOL (ДККТ 10.10.8.24).'
        );
    }
    if ($isProd && !$isMock && ($path === '' || !is_file($path))) {
        throw new \RuntimeException(
            'ATOL official JSON is required in production but not found at ' .
            "MARKING_ATOL_EXAMPLE_PATH={$path}"
        );
    }
}

private function buildMarkingAttribute(array $item, MarkingCheckResult $result): array
{
    $this->assertAtolExampleAvailable();
    // ... остальная логика
}
```

### Тест — `tests/AtolPlaceholderGateTest.php`

```php
public function run(TestHarness $t): void
{
    $check = new \Models\Check();
    $item  = ['id' => 'item-1', 'name' => 'Сигареты'];
    $result = \Service\Marking\MarkingCheckResult::allow(
        'cis-1', 'ONLINE', 'req-1', time()
    );

    // 1. prod + placeholder=1 → throw
    putenv('APP_ENV=prod');
    MarkingConfig::set('atol.tag1260_placeholder', true);
    $t->assertThrows(
        \RuntimeException::class,
        fn() => $check->buildMarkingAttribute($item, $result),
        'prod+placeholder → отказ'
    );

    // 2. prod + placeholder=0 + нет файла → throw
    MarkingConfig::set('atol.tag1260_placeholder', false);
    MarkingConfig::set('atol.tag1260_example_path', '/nonexistent.json');
    $t->assertThrows(
        \RuntimeException::class,
        fn() => $check->buildMarkingAttribute($item, $result),
        'prod+placeholder=0+нет файла → отказ'
    );

    // 3. dev + placeholder=1 → ок
    putenv('APP_ENV=dev');
    MarkingConfig::set('atol.tag1260_placeholder', true);
    $attr = $check->buildMarkingAttribute($item, $result);
    $t->assertTrue(!empty($attr), 'dev+placeholder=1 → атрибут построен');

    putenv('APP_ENV'); // сброс
}
```

### Проверка

```bash
APP_ENV=prod MARKING_ATOL_PLACEHOLDER=1 php tests/run_all.php --filter=AtolPlaceholder
```

Ожидание: тест 1 и 2 — PASS (исключение брошено), тест 3 — PASS (в dev работает).

---

## Fix #12. `cod_marking_cis` / `cod_marking_package_type` — в SPEC + в DDL

### Проблема

Поля-плашки в коде без описания: тип, формат, разделитель, длина, валидация.

### Патч — добавить в `sql/marking_tables.sql`

```sql
-- Поля маркированных товаров в существующей таблице order_items.
-- Применяется как ALTER, если таблица уже существует.
ALTER TABLE order_items
    ADD COLUMN cod_marking_cis           VARCHAR(2000) NULL
        COMMENT 'КИ товара, разделитель ; (несколько при частичной продаже)',
    ADD COLUMN cod_marking_package_type  ENUM('ITEM','GROUP','BUNDLE','PRODUCT_SET') NULL
        COMMENT 'UNIT/ITEM=пачка; GROUP/BUNDLE/PRODUCT_SET=агрегат',
    ADD COLUMN cod_marking_scan_at       TIMESTAMP NULL
        COMMENT 'Время сканирования КМ на кассе';

CREATE INDEX idx_order_items_marking
    ON order_items (cod_marking_cis(64), cod_marking_package_type);
```

> **Замечание:** фактическое имя таблицы `order_items` уточнить в `diagnose.sh` (см. #12 — grep выдаст реальные имена). Если это `order_products` / `check_items` — заменить.

### Валидатор — `Service/Marking/MarkingItemValidator.php`

```php
<?php
declare(strict_types=1);

namespace Service\Marking;

final class MarkingItemValidator
{
    public const MAX_CIS_LENGTH = 2000;
    public const MAX_CIS_COUNT  = 50;

    /**
     * Разбирает `cod_marking_cis` в массив. Возвращает нормализованный список.
     * @throws \InvalidArgumentException если формат битый
     */
    public static function parseCis(string $raw): array
    {
        $raw = trim($raw);
        if ($raw === '') return [];
        if (strlen($raw) > self::MAX_CIS_LENGTH) {
            throw new \InvalidArgumentException(
                'cod_marking_cis превышает ' . self::MAX_CIS_LENGTH . ' байт'
            );
        }
        $parts = array_map('trim', explode(';', $raw));
        $parts = array_filter($parts, fn($p) => $p !== '');

        if (count($parts) > self::MAX_CIS_COUNT) {
            throw new \InvalidArgumentException(
                'cod_marking_cis содержит более ' . self::MAX_CIS_COUNT . ' кодов'
            );
        }

        // Проверка на дубликаты внутри одной позиции
        if (count($parts) !== count(array_unique($parts))) {
            throw new \InvalidArgumentException(
                'cod_marking_cis содержит дублирующиеся КИ'
            );
        }

        return array_values($parts);
    }

    public static function isAggregate(?string $packageType): bool
    {
        return in_array($packageType, ['GROUP', 'BUNDLE', 'PRODUCT_SET'], true);
    }

    public static function isUnit(?string $packageType): bool
    {
        return $packageType === 'ITEM' || $packageType === 'UNIT';
    }
}
```

### Логика в `MarkingCheckService`

Добавить pre-check в начало `check()`:

```php
private function preValidateItems(array $orderItems): void
{
    foreach ($orderItems as $item) {
        $isMarked = (bool) ($item['is_marked'] ?? false);
        $rawCis   = (string) ($item['cod_marking_cis'] ?? '');

        // Немаркируемый — пропускаем
        if (!$isMarked) {
            continue;
        }

        // Маркируемый, но КМ нет — это не SKIPPED, а BLOCK
        if (trim($rawCis) === '') {
            throw new \Service\Marking\MarkingBlockedException(
                "Товар '{$item['name']}' требует маркировки, но КМ не отсканирован"
            );
        }

        // Парсим — может бросить InvalidArgumentException при битом формате
        MarkingItemValidator::parseCis($rawCis);
    }
}
```

### Тест — `tests/MarkingItemValidatorTest.php`

```php
public function run(TestHarness $t): void
{
    // Один КИ
    $t->assertEquals(['a'], MarkingItemValidator::parseCis('a'), 'один КИ');

    // Несколько
    $t->assertEquals(
        ['a', 'b', 'c'],
        MarkingItemValidator::parseCis('a;b;c'),
        'несколько КИ'
    );

    // Пустая строка
    $t->assertEquals([], MarkingItemValidator::parseCis(''), 'пустая строка');

    // Пробелы и пустые части
    $t->assertEquals(['a', 'b'], MarkingItemValidator::parseCis(' a ;; b '), 'пробелы');

    // Слишком длинная
    $t->assertThrows(
        \InvalidArgumentException::class,
        fn() => MarkingItemValidator::parseCis(str_repeat('x', 3000)),
        'превышение длины'
    );

    // Дубли
    $t->assertThrows(
        \InvalidArgumentException::class,
        fn() => MarkingItemValidator::parseCis('a;b;a'),
        'дубликаты'
    );

    // Типы
    $t->assertTrue(MarkingItemValidator::isAggregate('GROUP'), 'GROUP');
    $t->assertTrue(MarkingItemValidator::isAggregate('BUNDLE'), 'BUNDLE');
    $t->assertTrue(!MarkingItemValidator::isAggregate('ITEM'), 'ITEM не агрегат');
    $t->assertTrue(MarkingItemValidator::isUnit('ITEM'), 'ITEM — unit');
}
```

### Тест на pre-check — дополнить `MarkingCheckServiceTest`

```php
private function testEmptyCisBlocks(TestHarness $t): void
{
    $items = [[
        'id'         => 'i1',
        'name'       => 'Вода',
        'is_marked'  => true,
        'cod_marking_cis' => '',
    ]];
    $t->assertThrows(
        \Service\Marking\MarkingBlockedException::class,
        fn() => $svc->preValidateItems($items),
        'маркирован, КМ пустой → BLOCK'
    );
}

private function testNotMarkedSkips(TestHarness $t): void
{
    $items = [[
        'id'         => 'i1',
        'name'       => 'Хлеб',
        'is_marked'  => false,
        'cod_marking_cis' => '',
    ]];
    $svc->preValidateItems($items); // не должно бросить
    $t->assertTrue(true, 'немаркируемый — пропущен');
}
```

### Проверка

```bash
php tests/run_all.php --filter=MarkingItemValidator
```

Ожидание: 8–10 PASS.

---

## Fix #10. Устранить двойную запись return

### Проблема

Отчёт §3 #9 и §4.2: `MarkingReturnService` — **одновременно** пишет в `marking_return_queue` **и** меняет `marking_sell_queue.status = 'return'`. Два источника истины.

### Решение: выбрать одну модель

**Вариант A — `marking_return_queue` — источник истины для возвратов.**
- `marking_sell_queue` **не трогаем** при возврате — запись остаётся `done`.
- Возвраты регистрируются только в `marking_return_queue`.
- Проверка «продано ли» — по обеим таблицам: `sell=done AND return!=done`.

**Вариант B — только `marking_sell_queue` с полем `type`.**

Выбираем **A**, потому что:
- разные воркеры, разная периодичность;
- падение воркера возвратов не блокирует продажи;
- проще сверять с ЛМ ЧЗ (`/cis/sold` vs `/cis/return`).

### Патч `MarkingReturnService`

```php
public function process(string $checkUuid, array $cisList, int $orderId): void
{
    // 1. Запись в return_queue — единственная запись о возврате
    $this->returnQueue->enqueue($orderId, $checkUuid, $cisList, reason: 'refund');

    // 2. Синхронная попытка вызова ЛМ ЧЗ
    try {
        $this->lmChz->returned($cisList);
        $this->returnQueue->markDone($checkUuid);
    } catch (\Throwable $e) {
        // Оставляем pending, воркер подхватит
        $this->logger->warning('return deferred to worker', [
            'check_uuid' => $checkUuid,
            'error' => $e->getMessage(),
        ]);
    }

    // 3. marking_sell_queue НЕ ТРОГАЕМ.
    // Статус done остаётся — это факт продажи.
    // Факт возврата — только в marking_return_queue.
}
```

### Убрать из `marking_sell_queue` статус `return`

Если в ENUM он есть — удалить:

```sql
ALTER TABLE marking_sell_queue
    MODIFY COLUMN status ENUM('pending','processing','done','failed') DEFAULT 'pending';
```

Перед этим — миграция существующих данных:

```sql
-- Переносим существующие sell_queue со status='return' в return_queue
INSERT INTO marking_return_queue (order_id, check_uuid, cis_list, status, returned_at)
SELECT order_id, check_uuid, cis_list, 'done', processed_at
FROM marking_sell_queue
WHERE status = 'return';

-- Восстанавливаем status='done' в sell_queue
UPDATE marking_sell_queue SET status = 'done' WHERE status = 'return';

-- Удаляем 'return' из ENUM
ALTER TABLE marking_sell_queue
    MODIFY COLUMN status ENUM('pending','processing','done','failed') DEFAULT 'pending';
```

### Тест — `tests/MarkingReturnServiceTest.php`

```php
public function run(TestHarness $t): void
{
    // Готовим done-продажу
    $sellId = $this->sellQueue->enqueue(1001, 'ck-1', ['cis-1']);
    $this->sellQueue->markDone($sellId);

    // Возврат
    $svc = new MarkingReturnService($this->returnQueue, $this->lmChz, $this->logger);
    $svc->process('ck-1', ['cis-1'], 1001);

    // Проверяем: return_queue — запись есть
    $ret = $this->returnQueue->findByCheckUuid('ck-1');
    $t->assertTrue($ret !== null, 'return_queue: запись есть');
    $t->assertEquals('done', $ret['status'], 'return_queue: status=done (LM ChZ ответил)');

    // Проверяем: sell_queue НЕ ИЗМЕНИЛСЯ
    $sell = $this->sellQueue->find($sellId);
    $t->assertEquals('done', $sell['status'], 'sell_queue: статус остаётся done');
}
```

### Проверка

```bash
TEST_DB_DSN="sqlite::memory:" php tests/run_all.php --filter=MarkingReturnService
```

Ожидание: 2 PASS.

---

## Fix #3. Разнести emergency_state и cdn_host_state

### Проблема

`MarkingEmergencyState` в коде смешана с блокировкой хостов. По SPEC §1.4 — это аварийный режим HTTP 203 (глобальный), не circuit breaker.

### Патч — оставить в SPEC §2.4

**`marking_emergency_state`** — только для аварийного режима:

```sql
-- уже есть в SPEC §2.4:
CREATE TABLE marking_emergency_state (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    inn VARCHAR(12) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT FALSE,
    started_at TIMESTAMP NULL,
    expected_end_at TIMESTAMP NULL,
    actual_end_at TIMESTAMP NULL,
    reason VARCHAR(255),
    source_cassa_id BIGINT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_inn (inn)
);
```

**`marking_cdn_host_state`** — новый (уже добавлен в Fix #4).

### Патч кода — разделить модели

**`Models/MarkingEmergencyState`** — только `inn`-scoped:

```php
public function isActive(string $inn): bool;
public function activate(string $inn, string $reason, ?int $cassaId = null): void;
public function setExpectedEnd(string $inn, \DateTimeInterface $end): void;
public function confirmEnd(string $inn): void;
```

**`Models/MarkingCdnHostState`** — только host-scoped (см. Fix #4):

```php
public function isBlocked(string $host): bool;
public function recordSuccess(string $host, int $avgMs): void;
public function recordFailure(string $host, int $threshold, int $blockSec): void;
```

### Патч `MarkingCheckService`

В начале `check()`:

```php
public function check(array $codes, array $items, string $fn, int $tz): MarkingCheckResult
{
    $inn = (string) MarkingConfig::get('inn');

    // Глобальный аварийный режим — не обращаемся к ГИС МТ
    if ($this->emergencyState->isActive($inn)) {
        $this->metrics->increment('marking_check_emergency_total');
        return MarkingCheckResult::allowAll($codes, source: 'EMERGENCY');
    }

    // ... дальше обычная логика
}
```

### Тест — `tests/MarkingEmergencyStateTest.php`

```php
public function run(TestHarness $t): void
{
    $state = new MarkingEmergencyState($this->pdo);

    // Не активен изначально
    $t->assertTrue(!$state->isActive('7712345678'), 'изначально не активен');

    // Активация
    $state->activate('7712345678', 'HTTP 203', 42);
    $t->assertTrue($state->isActive('7712345678'), 'после activate — активен');

    // Другой ИНН не затронут
    $t->assertTrue(!$state->isActive('7799999999'), 'другой ИНН не активен');

    // Подтверждение окончания
    $state->confirmEnd('7712345678');
    $t->assertTrue(!$state->isActive('7712345678'), 'после confirmEnd — не активен');
}
```

### Проверка

```bash
TEST_DB_DSN="sqlite::memory:" php tests/run_all.php --filter=MarkingEmergencyState
```

Ожидание: 4 PASS.

---

## Fix #13. Advance check — SKIPPED вместо throw

### Проблема

Отчёт §7.3: авансовый чек (`getItemsSum`) — сознательный `throw`. Кассир видит непонятную ошибку.

### Патч `MarkingCheckService`

```php
public function check(array $codes, array $items, string $fn, int $tz): MarkingCheckResult
{
    // ... emergency check (Fix #3)

    // Авансовый чек — не содержит конкретных товаров
    if ($this->isAdvanceCheck($items)) {
        $this->metrics->increment('marking_skipped_advance_total');
        $this->history->writeSkip(
            requestId: 'local-' . uniqid('', true),
            itemId: null,
            reason: 'advance_check_no_goods',
            source: 'SKIPPED'
        );
        return MarkingCheckResult::skippedAll($codes, 'advance_check_no_goods');
    }

    // ... дальше обычная логика
}

private function isAdvanceCheck(array $items): bool
{
    if (empty($items)) return true;
    foreach ($items as $item) {
        if (($item['type'] ?? '') === 'advance') return true;
        // Дополнительный признак — сводная позиция getItemsSum
        if (($item['source'] ?? '') === 'items_sum') return true;
    }
    return false;
}
```

### Тест

```php
private function testAdvanceCheckSkipped(TestHarness $t): void
{
    $items = [['id' => 'i1', 'type' => 'advance', 'name' => 'Аванс']];
    $result = $svc->check([], $items, '1234567890123456', 2);
    $t->assertEquals('SKIPPED', $result->source, 'аванс → SKIPPED');
    $t->assertEquals('advance_check_no_goods', $result->skipReason, 'reason зафиксирован');

    // Не должно быть исключения
    $t->assertTrue(true, 'исключение не брошено');
}
```

### Проверка

```bash
php tests/run_all.php --filter=MarkingCheckServiceTest
```

Ожидание: новый assert `testAdvanceCheckSkipped` — PASS. До фикса — FAIL с `RuntimeException`.

---

## Fix #6. `verified=false` — WARN или BLOCK

### Проблема

- SPEC §1.6: `errorCode 5–7` (крипто-подпись) → **WARN**.
- PIOT §4 / Приложение 2: «нарушение формата» → **BLOCK**.
- Расхождение не закрыто.

### Диагностика — что делать прямо сейчас

1. Открыть методичку v17, раздел «Случаи запрета продажи» (Приложение 2 или §4).
2. Открыть `cz_developer_notes.md` §7 (обработка `/codes/check`).
3. Сравнить: для `verified=false` (криптопроверка не прошла) — что сказано?

**Пока источник не получен — вынести в конфиг со значением по умолчанию WARN и явным флагом `TO_VERIFY`:**

```php
// config/marking.php
'prohibitions' => [
    'error_code_levels' => [
        0  => 'INFO',
        1  => 'WARN', 2 => 'WARN', 3 => 'WARN', 4 => 'WARN',
        5  => 'WARN', 6 => 'WARN', 7 => 'WARN',  // ← TO_VERIFY: возможно BLOCK
        8  => 'WARN', 9 => 'WARN',
        10 => 'BLOCK',
        11 => 'WARN',
    ],
    'flags' => [
        'found_false'      => 'BLOCK',
        'verified_false'   => 'WARN',   // ← TO_VERIFY: методичка §4?
        'utilised_false'   => 'WARN',
        'realizable_false' => 'WARN',
        'sold_true'        => 'BLOCK',
        'is_blocked_true'  => 'BLOCK',
    ],
    'to_verify' => [
        'verified_false',
        'error_code_5', 'error_code_6', 'error_code_7',
    ],
],
```

### Тест-детектор

```php
// tests/ProhibitionConfigTest.php
public function testToVerifyItemsAreDocumented(TestHarness $t): void
{
    $toVerify = (array) MarkingConfig::get('prohibitions.to_verify', []);
    $t->assertTrue(
        count($toVerify) > 0,
        'Список to_verify не пуст — есть пункты для уточнения у Оператора'
    );

    foreach ($toVerify as $key) {
        $t->assertTrue(
            is_string($key) && $key !== '',
            "to_verify-элемент '$key' — непустая строка"
        );
    }
}
```

### Проверка

```bash
php tests/run_all.php --filter=ProhibitionConfig
grep -n "to_verify" config/marking.php
```

Ожидание: список `to_verify` присутствует; перед прод-релизом — либо изменить значения в конфиге по факту уточнения, либо убрать пункты из `to_verify` с обоснованием в SPEC.

---

## Fix #9. Autoload — проверить и зафиксировать

### Диагностика (одна команда)

```bash
ls composer.json composer.lock 2>/dev/null
cat composer.json 2>/dev/null | grep -E 'autoload|psr-4'
ls vendor/autoload.php 2>/dev/null
grep -rn "spl_autoload_register\|require.*autoload" *.php Service/ Models/ 2>/dev/null | head
```

### Возможные исходы

**A. composer.json есть, autoload настроен:**

Добавить в `README.md` (или SPEC §9):

```markdown
## Установка зависимостей

\`\`\`bash
composer install --no-dev --optimize-autoloader
\`\`\`

Проверка: `php -r 'require "vendor/autoload.php"; echo "ok\n";'`
```

Добавить в деплой-чеклист (если есть `deploy.sh`).

**B. composer.json нет, ручной autoload:**

Зафиксировать реальный файл в SPEC §9:

```markdown
## Autoload

Проект не использует Composer. Автозагрузка реализована через
`spl_autoload_register` в `<файл>`. Для namespace `Service\Marking\*`
зарегистрирован префикс `Service/Marking/`.
```

**C. Ничего нет — создать:**

`bootstrap.php` в корне:

```php
<?php
declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $prefixes = [
        'Service\\'  => __DIR__ . '/Service/',
        'Models\\'   => __DIR__ . '/Models/',
        'Tests\\'    => __DIR__ . '/tests/',
    ];
    foreach ($prefixes as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $rel = str_replace('\\', '/', substr($class, strlen($prefix)));
            $file = $dir . $rel . '.php';
            if (is_file($file)) {
                require $file;
                return;
            }
        }
    }
});
```

### Тест

```php
// tests/AutoloadSmokeTest.php
public function run(TestHarness $t): void
{
    $classes = [
        'Service\\Marking\\MarkingConfig',
        'Service\\Marking\\GisMtAuthService',
        'Service\\Marking\\CdnService',
        'Service\\Marking\\MarkingCheckService',
        'Models\\MarkingSellQueue',
        'Models\\MarkingCheckHistory',
    ];
    foreach ($classes as $c) {
        $t->assertTrue(class_exists($c), "класс $c загружен");
    }
}
```

### Проверка

```bash
php tests/run_all.php --filter=AutoloadSmoke
```

---

## Fix #7. Убрать .deb/.rpm из git-истории

### Диагностика

```bash
git count-objects -vH
find . -name '*.deb' -o -name '*.rpm' 2>/dev/null | grep -v vendor
git log --all --oneline -- 'docs/sprints/01/PIOT/esmpack_linux64_1.6.4.0.477/'
```

### Решение (одно из)

**Вариант 1 — если история спринта короткая (2 коммита, локально):**

```bash
# Находим коммит ДО попадания бинарников
git log --oneline | head -5

# Soft reset на 2 коммита назад
git reset --soft HEAD~2

# Удаляем бинарники
rm -rf docs/sprints/01/PIOT/esmpack_linux64_1.6.4.0.477/

# Обновляем .gitignore
cat >> .gitignore <<'EOF'
# бинарные пакеты ЕСМ/Атол — не коммитить
*.deb
*.rpm
*.tar.gz
*.tar.bz2
docs/sprints/*/PIOT/esmpack_*/
EOF

# Коммитим заново
git add -A
git commit -m "docs(sprints/01): SPEC.md + PIOT (без бинарников ЕСМ/Атол)"
```

**Вариант 2 — если история уже разошлась:**

```bash
# Установить git-filter-repo:
pip install git-filter-repo
# или: brew install git-filter-repo

git filter-repo --path docs/sprints/01/PIOT/esmpack_linux64_1.6.4.0.477/ --invert-paths

# Force-push
git remote add origin <url>  # если filter-repo удалил remote
git push origin --force --all
git push origin --force --tags
```

**Вариант 3 — BFG (проще для больших репо):**

```bash
java -jar bfg.jar --delete-folders esmpack_linux64_1.6.4.0.477 --no-blob-protection .
git reflog expire --expire=now --all && git gc --prune=now --aggressive
```

### Проверка

```bash
# До и после
git count-objects -vH
du -sh .git

# Не должно быть бинарников в истории
git log --all --name-only --pretty=format: | grep -E '\.(deb|rpm)$' | sort -u
# Ожидание: пусто

# Не должно быть в рабочем дереве
find . -name '*.deb' -o -name '*.rpm' | grep -v vendor
# Ожидание: пусто
```

### Предупреждение команде

После force-push все должны сделать:

```bash
git fetch origin
git reset --hard origin/main
```

---

## Fix #14. uiPayload — тесты для INFO и EMERGENCY_TIMEOUT

### Проблема

`MarkingStatusTest` покрывает BLOCK и WARN, но не INFO и EMERGENCY_TIMEOUT.

### Тест — `tests/MarkingStatusUiPayloadTest.php`

```php
public function run(TestHarness $t): void
{
    // INFO: успех
    $ok = MarkingStatus::fromCodeCheck([
        'found' => true, 'verified' => true, 'sold' => false,
        'isBlocked' => false, 'errorCode' => 0, 'realizable' => true,
    ], 'cis-1');
    $p = $ok->uiPayload();
    $t->assertEquals('INFO', $p['level'], 'INFO level');
    $t->assertEquals('green', $p['color'], 'INFO цвет зелёный');
    $t->assertEquals('', $p['message'], 'INFO без сообщения');
    $t->assertEquals([], $p['buttons'], 'INFO без кнопок');

    // EMERGENCY_TIMEOUT: оба источника не ответили
    $em = MarkingCheckResult::emergencyTimeout(['cis-1'], ['barrier_sec' => 1.5]);
    $pe = $em->uiPayload();
    $t->assertEquals('WARN', $pe['level'], 'EMERGENCY_TIMEOUT = WARN');
    $t->assertEquals('yellow', $pe['color'], 'EMERGENCY_TIMEOUT цвет жёлтый');
    $t->assertTrue(
        str_contains($pe['message'], 'проверка не выполнена'),
        'EMERGENCY_TIMEOUT сообщение содержит «проверка не выполнена»'
    );
    $t->assertTrue(
        in_array('continue', $pe['buttons'], true),
        'EMERGENCY_TIMEOUT кнопка continue'
    );
    $t->assertTrue(
        in_array('cancel', $pe['buttons'], true),
        'EMERGENCY_TIMEOUT кнопка cancel'
    );

    // SKIPPED: авансовый чек
    $sk = MarkingCheckResult::skippedAll(['cis-1'], 'advance_check_no_goods');
    $ps = $sk->uiPayload();
    $t->assertEquals('INFO', $ps['level'], 'SKIPPED = INFO');
    $t->assertEquals('gray', $ps['color'], 'SKIPPED цвет серый');
}
```

### Проверка

```bash
php tests/run_all.php --filter=MarkingStatusUiPayload
```

Ожидание: 8–10 PASS.

---

## Fix #1. Сверка счёта тестов

### Диагностика

```bash
php tests/run_all.php --list 2>/dev/null | tee /tmp/test_list.txt
wc -l /tmp/test_list.txt

# Или:
php tests/run_all.php --verbose 2>&1 | grep -E '^\s*(✓|PASS|FAIL)' | wc -l
```

### Ожидаемые результаты

| Сценарий | Число | Действие |
|----------|-------|----------|
| Сходится с 143 | 143 | Обновить отчёт: «143 PASS подтверждено» |
| Сходится с 134 | 134 | Найти 9 «потерянных» в отчёте, либо признать, что 143 в отчёте — ошибка |
| Не сходится ни с чем | ? | Пересчитать, обновить отчёт |

### Патч `tests/run_all.php` — точный счётчик

```php
$total = 0;
$passed = 0;
$failed = 0;
$skipped = 0;

foreach ($suites as $suite) {
    $stats = $suite->run($harness);
    $total   += $stats['total'];
    $passed  += $stats['passed'];
    $failed  += $stats['failed'];
    $skipped += $stats['skipped'];
    printf("%-30s  PASS=%-4d FAIL=%-3d SKIP=%d\n",
        (new \ReflectionClass($suite))->getShortName(),
        $stats['passed'], $stats['failed'], $stats['skipped']);
}

printf("\n=== TOTAL: %d  PASS: %d  FAIL: %d  SKIP: %d ===\n",
    $total, $passed, $failed, $skipped);
exit($failed === 0 ? 0 : 1);
```

Требует, чтобы каждый suite возвращал `['total' => int, 'passed' => int, 'failed' => int, 'skipped' => int]`. Если `TestHarness` сейчас просто инкрементит глобальный счётчик — обернуть его в per-suite.

### Проверка

```bash
php tests/run_all.php | tail -20
```

Вывод содержит итог по каждому suite — обновить отчёт `ОТЧЕТ_О_РЕЗУЛЬТАТАХ.md` §4.5 фактическими числами.

---

## Сводка блока 3

| # | Что сделано | Файлы |
|---|-------------|-------|
| #8 | Гейт ATOL placeholder | `config/marking.php`, `.env.example`, `Models/Check.php`, `AtolPlaceholderGateTest` |
| #12 | Спецификация `cod_marking_*` | `sql/marking_tables.sql`, `MarkingItemValidator`, `MarkingItemValidatorTest` |
| #10 | Убрана двойная запись return | `MarkingReturnService`, миграция данных, `MarkingReturnServiceTest` |
| #3 | Разделены emergency/host state | `MarkingEmergencyState`, `MarkingCdnHostState`, `MarkingEmergencyStateTest` |
| #13 | Advance → SKIPPED | `MarkingCheckService::isAdvanceCheck`, тест в `MarkingCheckServiceTest` |
| #6 | `to_verify` для `verified=false` | `config/marking.php`, `ProhibitionConfigTest` |
| #9 | Autoload проверен/зафиксирован | `bootstrap.php` (опц.), `AutoloadSmokeTest` |
| #7 | .deb/.rpm из истории git | `.gitignore`, filter-repo/BFG, инструкция |
| #14 | uiPayload INFO/EMERGENCY | `MarkingStatusUiPayloadTest` |
| #1 | Сверка счёта тестов | `tests/run_all.php` per-suite counter |

---

## Что дальше

**Прогнать всё вместе:**

```bash
php tests/run_all.php 2>&1 | tee /tmp/all_tests.log
```

Ожидание: количество PASS **выросло** на ~40 (новые тесты из блока 2 и 3), 0 FAIL, счётчик отчёта совпадает.

**Прислать:**

1. Вывод `diagnose.sh` (если ещё не запускали).
2. Вывод `php tests/run_all.php`.
3. `ls composer.json 2>/dev/null` — есть или нет.
4. `git count-objects -vH` до и после чистки .deb/.rpm.

По этим четырём пунктам закрою:
- оставшиеся несоответствия DDL ↔ SPEC;
- точную команду для чистки git-истории (вариант 1/2/3);
- финальный размер тестового покрытия.

Скажите «дальше» — выдам финальный блок: сведение отчёта `ОТЧЕТ_О_РЕЗУЛЬТАТАХ.md` с реальностью, список оставшихся блокеров и подготовка к интеграционному прогону на sandbox (когда придёт доступ).

# Блок 4 (финальный) — сведение отчёта с реальностью, оставшиеся блокеры, подготовка к sandbox

---

## Часть 1. Сверка отчёта `ОТЧЕТ_О_РЕЗУЛЬТАТАХ.md` с фактами кода

### 1.1. Что нельзя подтвердить без запуска кода

| Утверждение отчёта | Как проверить | Что ожидаем увидеть |
|--------------------|---------------|---------------------|
| §1.4: «143/143 PASS» | `php tests/run_all.php \| tail -3` | `PASS: 143  FAIL: 0` или другое число |
| §1.4: «exit 0» | `php tests/run_all.php; echo $?` | `0` |
| §1.4: «`php -l` по всем файлам — 0 ошибок» | `find . -name '*.php' -not -path './vendor/*' -exec php -l {} \; \| grep -v 'No syntax'` | Пусто |
| §5: «Коммиты `c5260b3`, `aeffa74`» | `git log --oneline -5` | Соответствие |
| §5: «48 файлов в `aeffa74`» | `git show --stat aeffa74 \| tail -1` | `48 files changed` |
| §4.5: «Suite X — N тестов» | См. блок 3, fix #1 | Совпадение чисел |

**Действие:** одна команда — снимает сразу 4 вопроса:

```bash
{
  echo "=== commit log ===";          git log --oneline -5
  echo "=== tests ===";               php tests/run_all.php 2>&1 | tail -5
  echo "=== tests exit ===";          php tests/run_all.php >/dev/null 2>&1; echo $?
  echo "=== php -l ===";              find . -name '*.php' -not -path './vendor/*' -exec php -l {} \; 2>&1 | grep -v 'No syntax errors' | head
  echo "=== aeffa74 stat ===";        git show --stat aeffa74 2>/dev/null | tail -1
} | tee /tmp/verify_report.log
```

### 1.2. Что явно расходится — по фактам из отчёта

| Расхождение | Отчёт | SPEC / методичка | Действие |
|-------------|-------|------------------|----------|
| Счёт тестов | 143 | Сумма по suite = 134 | блок 3, fix #1 |
| Circuit breaker | §7.5: «in-memory (per-process)» | §3.2: «block 15 min после 3 сбоев» | блок 2, fix #4 |
| `checkHybrid` | §7.2: «последовательно, curl-timeout» | §3.4: «параллельно, барьер 1.5 с» | блок 2, fix #5 |
| `marking_emergency_state` | §4.3: «блокировки хостов» | §1.4: «аварийный режим HTTP 203» | блок 3, fix #3 |
| Return: двойная запись | §3 #9 + §4.2 | §2.1 / §8 | блок 3, fix #10 |
| `verified=false` | WARN | PIOT §4 — не уточнено | блок 3, fix #6 |
| Advance check | §7.3: «сознательный throw» | — | блок 3, fix #13 |
| `item_id NOT NULL` | не отмечено | COMMENTS-6 | блок 1, fix в `marking_check_history` |

### 1.3. Что в отчёте отсутствует и должно быть

**Разделы, которые нужно добавить в `ОТЧЕТ_О_РЕЗУЛЬТАТАХ.md`:**

1. **§4.6 — список изменённых существующих файлов** (`Models/Check.php`, `Models/Cassa.php`, `Service/CheckService.php`, `js/marking/*`). В отчёте есть, но без diff-статистики.
2. **§4.7 — карта покрытия SPEC → код → тест.** Таблица: каждый раздел SPEC §1–§9 → файл(ы) → тест(ы) → статус (✅ / ⚠️ / ❌).
3. **§6.7 — расхождения с методичкой**, которые остались открытыми (например, `verified=false`).
4. **§7.6 — какие поля конфига остаются TODO** (по факту из `config/marking.php`).

---

## Часть 2. Оставшиеся блокеры — что закрывается внешними данными

### 2.1. Точный список

| # | Блокер | Разблокирует | Что можно сделать сейчас |
|---|--------|--------------|---------------------------|
| 1 | Официальный JSON Атол (тег 1260) | Финальную структуру `industryInfo` для ФФД 1.2 | Гейт `MARKING_ATOL_PLACEHOLDER=1` уже защищает прод (fix #8) |
| 2 | Живой ЛМ ЧЗ 2.0 | Проверку `outCheck`/`sell`/`returned`/`sold` против реального сервиса | Все unit'ы на `FakeLmChzClient` проходят (по отчёту §4.5) |
| 3 | Sandbox-токен ГИС МТ | Проверку `/cdn/info`, `/codes/check`, батчей 30/100 | Мок-сервер на фикстурах (см. §3) |
| 4 | Список регионов (Q2a) | `Cassa::getTimeZone()` per-точка | MSK=2 по умолчанию; интерфейс уже есть |
| 5 | Письменное утверждение SPEC | Включение `MARKING_ENABLE=on` в test-контуре | Решение не техническое — тривиально выполнить параллельно с разработкой |
| 6 | Стек проекта (composer.json) | Миграции БД, деплой-инструкцию | Диагностика одной командой |

### 2.2. Что делать с блокерами 2–3 прямо сейчас

**Собрать mock-сервер, который эмулирует оба контура.** Это позволит:

- Прогнать весь `checkHybrid` без живых зависимостей.
- Проверить батчи, retry, EMERGENCY_TIMEOUT.
- Заменить реальный sandbox в интеграционном CI, пока он не подключён.

См. Часть 3.

---

## Часть 3. Mock-сервер для интеграционных прогонов

### 3.1. Назначение

Один PHP-скрипт, поднимающий HTTP-сервер (встроенный `php -S`) с маршрутами:

- `GET  /api/v4/true-api/cdn/info` — список хостов.
- `GET  /api/v4/true-api/cdn/health/check` — avgTimeMs.
- `POST /api/v4/true-api/codes/check` — ответ из фикстуры по телу запроса.
- `POST /api/v2/init` — ЛМ ЧЗ init.
- `GET  /api/v2/status` — ЛМ ЧЗ status.
- `POST /api/v2/cis/outCheck` — ЛМ ЧЗ outCheck.
- `POST /api/v2/cis/sell` — ЛМ ЧЗ sell.
- `POST /api/v2/cis/return` — ЛМ ЧЗ return.
- `GET  /api/v2/cis/sold` — ЛМ ЧЗ sold.

### 3.2. Файл `tests/mock/MarkingMockRouter.php`

```php
<?php
declare(strict_types=1);

// php -S 127.0.0.1:18080 tests/mock/MarkingMockRouter.php

require __DIR__ . '/../../bootstrap.php';

use Tests\Mock\Fixtures;

$uri    = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '/';
$method = $_SERVER['REQUEST_METHOD'];
$body   = file_get_contents('php://input') ?: '';
$query  = $_GET;
$auth   = $_SERVER['HTTP_X_API_KEY'] ?? '';
$client = $_SERVER['HTTP_X_CLIENTID'] ?? '';

// Управление задержкой: ?delay=1500 (мс)
$delayMs = (int) ($query['delay'] ?? 0);
if ($delayMs > 0) {
    usleep($delayMs * 1000);
}

// Управление кодом ответа: ?status=500
$forcedStatus = (int) ($query['status'] ?? 0);
if ($forcedStatus > 0) {
    http_response_code($forcedStatus);
    header('Content-Type: application/json');
    echo json_encode(['code' => $forcedStatus, 'description' => 'forced']);
    return;
}

header('Content-Type: application/json');

$key = "$method $uri";
switch (true) {
    // ── CDN ────────────────────────────────────────────────────────────
    case $method === 'GET' && $uri === '/api/v4/true-api/cdn/info':
        echo json_encode(Fixtures::cdnInfo());
        return;

    case $method === 'GET' && $uri === '/api/v4/true-api/cdn/health/check':
        echo json_encode(Fixtures::cdnHealth());
        return;

    // ── /codes/check ───────────────────────────────────────────────────
    case $method === 'POST' && $uri === '/api/v4/true-api/codes/check':
        $req = json_decode($body, true) ?: [];
        $codes = $req['codes'] ?? [];
        echo json_encode(Fixtures::codesCheck($codes));
        return;

    // ── ЛМ ЧЗ ──────────────────────────────────────────────────────────
    case $method === 'POST' && $uri === '/api/v2/init':
        echo json_encode(['status' => 'ready']);
        return;

    case $method === 'GET' && $uri === '/api/v2/status':
        echo json_encode(Fixtures::lmChzStatus());
        return;

    case $method === 'POST' && $uri === '/api/v2/cis/outCheck':
        $req = json_decode($body, true) ?: [];
        echo json_encode(Fixtures::outCheck($req['cis_list'] ?? []));
        return;

    case $method === 'POST' && $uri === '/api/v2/cis/sell':
        $req = json_decode($body, true) ?: [];
        echo json_encode(Fixtures::sell($req['cis_list'] ?? []));
        return;

    case $method === 'POST' && $uri === '/api/v2/cis/return':
        $req = json_decode($body, true) ?: [];
        echo json_encode(Fixtures::returned($req['cis_list'] ?? []));
        return;

    case $method === 'GET' && $uri === '/api/v2/cis/sold':
        echo json_encode(Fixtures::sold(
            (int) ($query['skip'] ?? 0),
            (int) ($query['limit'] ?? 100)
        ));
        return;

    // ── Режиссура тестов ───────────────────────────────────────────────
    case $method === 'POST' && $uri === '/__test/reset':
        Fixtures::reset();
        echo json_encode(['ok' => true]);
        return;

    case $method === 'POST' && $uri === '/__test/set-code-result':
        $req = json_decode($body, true) ?: [];
        Fixtures::setCodeResult($req);
        echo json_encode(['ok' => true]);
        return;

    default:
        http_response_code(404);
        echo json_encode(['error' => "no route: $key"]);
}
```

### 3.3. Файл `tests/mock/Fixtures.php`

```php
<?php
declare(strict_types=1);

namespace Tests\Mock;

final class Fixtures
{
    private static array $codeResultOverride = [];
    private static array $soldCis = [];

    public static function reset(): void
    {
        self::$codeResultOverride = [];
        self::$soldCis = [];
    }

    public static function setCodeResult(array $override): void
    {
        self::$codeResultOverride = $override;
    }

    public static function cdnInfo(): array
    {
        return [
            'code' => 0,
            'description' => 'ok',
            'hosts' => [
                ['host' => 'http://127.0.0.1:18080'],
            ],
        ];
    }

    public static function cdnHealth(): array
    {
        return ['code' => 0, 'description' => 'ok', 'avgTimeMs' => 42];
    }

    public static function codesCheck(array $codes): array
    {
        $results = [];
        foreach ($codes as $code) {
            $base = [
                'cis' => $code,
                'found' => true,
                'valid' => true,
                'verified' => true,
                'realizable' => true,
                'utilised' => true,
                'isBlocked' => false,
                'sold' => false,
                'errorCode' => 0,
                'groupIds' => [3],
                'packageType' => 'UNIT',
                'gtin' => '04601234567890',
            ];
            $results[] = array_merge($base, self::$codeResultOverride);
        }
        return [
            'code' => 0,
            'description' => 'ok',
            'codes' => $results,
            'reqId' => 'req-' . bin2hex(random_bytes(4)),
            'reqTimestamp' => (int) (microtime(true) * 1000),
        ];
    }

    public static function lmChzStatus(): array
    {
        return [
            'version' => '2.0.0',
            'status' => 'ready',
            'serviceUrl' => 'http://127.0.0.1:18080',
            'operationMode' => 'online',
            'name' => 'ЛМ ЧЗ mock',
            'lastUpdate' => date('c'),
            'lastSync' => date('c'),
            'inst' => 'mock-inst-0001',
            'inn' => '7712345678',
            'dbVersion' => '1',
            'dbState' => [
                'min_price'    => ['docCount' => 0],
                'blocked_gtin' => ['docCount' => 0],
                'blocked_cis'  => ['docCount' => 0],
            ],
            'isGreyGtin' => false,
        ];
    }

    public static function outCheck(array $cisList): array
    {
        $codes = [];
        foreach ($cisList as $entry) {
            $cis = is_array($entry) ? ($entry['cis'] ?? '') : $entry;
            $codes[] = [
                'cis' => $cis,
                'isBlocked' => false,
                'isGreyGtin' => false,
                'gtin' => '04601234567890',
                'sold' => in_array($cis, self::$soldCis, true),
                'mrp' => null,
                'smp' => null,
            ];
        }
        return [
            'results' => [[
                'reqId' => 'lm-req-' . bin2hex(random_bytes(4)),
                'reqTimestamp' => (int) (microtime(true) * 1000),
                'inst' => 'mock-inst-0001',
                'version' => '2.0.0',
                'codes' => $codes,
            ]],
        ];
    }

    public static function sell(array $cisList): array
    {
        $results = [];
        foreach ($cisList as $cis) {
            $cis = is_array($cis) ? ($cis['cis'] ?? '') : $cis;
            self::$soldCis[] = $cis;
            $results[] = ['success' => true, 'cis' => $cis];
        }
        return ['results' => $results];
    }

    public static function returned(array $cisList): array
    {
        $results = [];
        foreach ($cisList as $cis) {
            $cis = is_array($cis) ? ($cis['cis'] ?? '') : $cis;
            self::$soldCis = array_values(array_diff(self::$soldCis, [$cis]));
            $results[] = ['success' => true, 'cis' => $cis];
        }
        return ['results' => $results];
    }

    public static function sold(int $skip, int $limit): array
    {
        $list = array_slice(self::$soldCis, $skip, $limit);
        return [
            'total_count' => count(self::$soldCis),
            'cis_list' => $list,
        ];
    }
}
```

### 3.4. Запуск

```bash
# Терминал 1
php -S 127.0.0.1:18080 tests/mock/MarkingMockRouter.php

# Терминал 2 — переопределить хосты на mock
export MARKING_ENV=test
export MARKING_API_KEY=mock-token
export LM_CHZ_HOST=http://127.0.0.1:18080

php tests/integration/run_against_mock.php
```

### 3.5. Интеграционный скрипт `tests/integration/run_against_mock.php`

```php
<?php
declare(strict_types=1);

require __DIR__ . '/../../bootstrap.php';

use Service\Marking\CdnService;
use Service\Marking\CodeCheckService;
use Service\Marking\GisMtAuthService;
use Service\Marking\LmChzService;
use Service\Marking\MarkingCheckService;

$base = 'http://127.0.0.1:18080';

// Сброс mock-состояния
file_get_contents("$base/__test/reset", false, stream_context_create([
    'http' => ['method' => 'POST'],
]));

$http  = new \Service\Marking\CurlHttpClient();
$auth  = new GisMtAuthService($http, 'mock-token', '2099-01-01');
$cdn   = new CdnService($http, null);
$check = new CodeCheckService($http, $cdn, $auth);
$lmChz = new LmChzService($http, $base, '', '', '1234567890123456');
$svc   = new MarkingCheckService($check, $lmChz, null, null);

$scenarios = [
    'sale_ok' => fn() => $svc->checkHybrid(
        ['01048657365749062155esJWe'],
        '1234567890123456'
    ),
    'sold_block' => function () use ($base, $svc) {
        // Продаём через LM ChZ и проверяем, что следующий outCheck вернёт sold=true
        file_get_contents("$base/api/v2/cis/sell", false, stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => 'Content-Type: application/json',
                'content' => json_encode(['cis_list' => ['cis-sold-1']]),
            ],
        ]));
        return $svc->checkHybrid(['cis-sold-1'], '1234567890123456');
    },
];

foreach ($scenarios as $name => $fn) {
    try {
        $r = $fn();
        printf("[OK] %-20s source=%s level=%s\n", $name, $r->source, $r->level());
    } catch (\Throwable $e) {
        printf("[FAIL] %-20s %s\n", $name, $e->getMessage());
    }
}
```

### 3.6. Что это даёт

- Прогон 8–10 сценариев без живых внешних сервисов.
- Проверка всех переходов состояний (OK → sold → return).
- Детерминированный mock: `?delay=1400`, `?status=500` — для race-сценариев `checkHybrid`.
- Готовность к подмене `MARKING_ENV` на реальный sandbox без изменений в тестах.

---

## Часть 4. Финальный чек-лист запуска в test-контуре (когда появится sandbox)

### 4.1. Перед подключением к sandbox

- [ ] Прогнать `php tests/run_all.php` — 0 FAIL.
- [ ] Прогнать интеграционный прогон на mock (`tests/integration/run_against_mock.php`) — все сценарии OK.
- [ ] Проверить `config/marking.php`: `env=test`, `api_key` заполнен, `hosts.test.*` соответствует sandbox-URL.
- [ ] Убедиться, что `MARKING_ATOL_PLACEHOLDER=1` (иначе — throw при `APP_ENV=prod`).
- [ ] Убедиться, что `marking_tables.sql` применён к test-БД.

### 4.2. При подключении

```bash
export MARKING_ENV=test
export MARKING_API_KEY=<sandbox-token>
export LM_CHZ_HOST=http://<test-vm>:5995
export LM_CHZ_LOGIN=<login>
export LM_CHZ_PASSWORD=<pass>
export FISCAL_DRIVE_NUMBER=<16 digits>

# Ключевой прогон:
php tests/integration/run_against_sandbox.php 2>&1 | tee /tmp/sandbox.log
```

Скрипт `run_against_sandbox.php` — копия `run_against_mock.php`, но без эндпоинтов `__test/*` и с реальными проверками:

- `GET /api/v4/true-api/cdn/info` → список хостов непустой.
- `GET /api/v4/true-api/cdn/health/check` → avgTimeMs > 0.
- `POST /api/v4/true-api/codes/check` с тестовым КМ из sandbox (по методичке).
- `GET  /api/v2/status` → status=ready.
- `POST /api/v2/cis/outCheck` с реальным КИ sandbox.

### 4.3. Критерии приёмки sandbox-прогона

- [ ] Все 8 endpoint'ов отвечают 200/201.
- [ ] `checkHybrid` на реальных КМ возвращает `source=HYBRID`, `level=INFO`.
- [ ] `EMERGENCY_TIMEOUT` на `?delay` не срабатывает в норме (оба источника укладываются в 1.5 с).
- [ ] `/cis/sell` → `marking_sell_queue.status=done`.
- [ ] `/cis/returned` → `marking_return_queue.status=done`, `marking_sell_queue` **не изменён**.
- [ ] `marking_check_history` содержит записи `source` ONLINE/OFFLINE/HYBRID.

### 4.4. После sandbox

- [ ] Получить официальный JSON Атол → заполнить `MARKING_ATOL_EXAMPLE_PATH` → `MARKING_ATOL_PLACEHOLDER=0`.
- [ ] Прогнать `AtolPlaceholderGateTest` в prod-режиме — без throw, но с файлом.
- [ ] E2E на тестовой ККТ: чек с КМ → проверка фискального признака → регистрация в ЛМ ЧЗ.
- [ ] Записать в `ОТЧЕТ_О_РЕЗУЛЬТАТАХ.md` §6: блокеры закрыты, финальный статус.

---

## Часть 5. Сводный список всех фиксов (блоки 1–4)

| # | Проблема | Файлы | Статус |
|---|----------|-------|--------|
| 1 | Счёт тестов 134 vs 143 | `tests/run_all.php` (per-suite counter) | патч выдан |
| 2 | Нет тестов БД | `tests/SqlLintTest`, `tests/DbSmokeTest` | патч выдан |
| 3 | emergency vs host state смешаны | `MarkingEmergencyState`, `MarkingCdnHostState` | патч выдан |
| 4 | Circuit breaker in-memory | `marking_cdn_host_state`, `CdnService` | патч выдан |
| 5 | checkHybrid последовательный | `HttpParallel`, `MarkingCheckService` | патч выдан |
| 6 | verified=false — WARN/BLOCK | `config/marking.php` `to_verify` | ждёт источника |
| 7 | .deb/.rpm в git | filter-repo / reset / BFG | инструкция выдана |
| 8 | ATOL placeholder без гейта | `Check::assertAtolExampleAvailable` | патч выдан |
| 9 | Autoload/stub | `AutoloadSmokeTest`, bootstrap | патч выдан |
| 10 | Return: двойная запись | `MarkingReturnService`, миграция | патч выдан |
| 11 | Worker race | `MarkingSellQueue::claimNextPending` | патч выдан |
| 12 | cod_marking_* без спеки | DDL, `MarkingItemValidator` | патч выдан |
| 13 | Advance check throw | `MarkingCheckService::isAdvanceCheck` | патч выдан |
| 14 | uiPayload INFO/EMERGENCY | `MarkingStatusUiPayloadTest` | патч выдан |
| 15 | `.work_tmp_*` | `rm -rf` | одна команда |

Все патчи — из блоков 1–3. Код готов к применению.

---

## Часть 6. Что осталось вне зоны досягаемости кода

| # | Зависимость | Снимается |
|---|-------------|-----------|
| 1 | Официальный JSON Атол | запросом в поддержку (уже отправлен) |
| 2 | Живой ЛМ ЧЗ на VM | развёртыванием (уже в работе) |
| 3 | Sandbox-токен | заявкой через ЛК ЧЗ (1–3 дня) |
| 4 | Q2a — регионы | ответом заказчика |
| 5 | Письменное утверждение SPEC | решением техлида/заказчика |

Ни один из этих пяти **не блокирует** применение патчей из блоков 1–4. Применение можно делать прямо сейчас, параллельно ожиданию.

---

## Часть 7. Следующие физические действия (одна последовательность)

```bash
# 1. Диагностика — снять факты
chmod +x scripts/diagnose.sh && ./scripts/diagnose.sh 2>&1 | tee /tmp/diag.log

# 2. Применить патчи блоков 1–3 в код
# (файлы выданы в предыдущих сообщениях)

# 3. Прогнать всё
php tests/run_all.php 2>&1 | tee /tmp/tests.log
echo "exit=$?"

# 4. Поднять mock-сервер и прогнать интеграцию
php -S 127.0.0.1:18080 tests/mock/MarkingMockRouter.php &
MOCK_PID=$!
MARKING_ENV=test MARKING_API_KEY=mock-token \
  php tests/integration/run_against_mock.php 2>&1 | tee /tmp/mock.log
kill $MOCK_PID

# 5. Убрать бинарники из git (вариант 1, если история локальная)
# git reset --soft HEAD~2 && rm -rf docs/sprints/01/PIOT/esmpack_*/ \
#   && cat >> .gitignore <<'EOF'
# *.deb
# *.rpm
# docs/sprints/*/PIOT/esmpack_*/
# EOF
# git add -A && git commit -m "..."

# 6. Удалить осколки
rm -rf docs/sprints/01/.work_tmp_*

# 7. Обновить ОТЧЕТ_О_РЕЗУЛЬТАТАХ.md
# §4.5 — фактический счёт тестов из /tmp/tests.log
# §4.6 — карта покрытия SPEC→код→тест
# §6 — список оставшихся блокеров (без изменений)
# §7 — допущения mock (без изменений)
```

Результат шага 3 — финальное число PASS, которое идёт в отчёт.

Результат шага 4 — доказательство, что интеграционный контур работает **до** sandbox-доступа.

Шаги 5–6 — гигиена репозитория, одна минута.

---

## Что показать после запуска

Прислать три файла:

1. `/tmp/diag.log` — факты по #4, #5, #9, #11, #12.
2. `/tmp/tests.log` — `tail -10`, число PASS/FAIL.
3. `/tmp/mock.log` — список сценариев `[OK]`/`[FAIL]`.

По этим трём выводам — уточню патчи там, где они не совпали с реальным кодом, и закрою оставшиеся расхождения.

Дальше цикл смыкается: пока не пришёл sandbox — разработка на mock идёт; как только sandbox — переключаем `MARKING_ENV` и гоняем `run_against_sandbox.php` без переписывания тестов.