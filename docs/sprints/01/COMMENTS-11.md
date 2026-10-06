# Проверка волны 2.1 — что не покрыто

Волна 2.1 закрыла 8/8 находок COMMENTS-10 формально. Но при внимательном разборе реализации появились новые проблемы — преимущественно введённые самим фиксом 2.1 (heartbeat) и в местах, где mock-интеграция обходит реальные race-условия.

---

## Проблема 1. Heartbeat не обновляется во время обработки — race возвращается 🔴

### Что сломано

Формулировка волны 2.1:
> «`claimNextPending()` — ... claim ставит heartbeat, воркеры `process_*.php` обновляют heartbeat **после claim**»

То есть heartbeat выставляется **один раз** — сразу после claim, до вызова `lmChz->sell()`. Дальше воркер уходит в HTTP-вызов ЛМ ЧЗ.

### Root cause

Если `lmChz->sell()` длится дольше `stale_threshold` (10 мин по текущему описанию) — heartbeat становится stale. Второй воркер, запущенный в этот момент, **reclaim'ит** строку, которую первый воркер ещё активно обрабатывает. Оба вызовут `/cis/sell` для одного КИ.

С учётом `lm_chz.read_timeout = 10` сек — типичный сценарий не сработает. Но при сетевых зависаниях, ретраях внутри curl или массовом батче (100 КИ за раз) — 10 минут вполне реальны.

### Fix — lease semantics вместо last-seen

**`claimNextPending()`** — при захвате выставлять heartbeat в **будущее**:

```sql
UPDATE marking_sell_queue
SET status = 'processing',
    heartbeat_at = NOW() + INTERVAL 15 MINUTE,   -- lease duration
    attempts = attempts + 1
WHERE id = :id
```

**Reclaim** — искать не «старый heartbeat», а «просроченный lease»:

```sql
UPDATE marking_sell_queue
SET status = 'pending', heartbeat_at = NULL
WHERE status = 'processing'
  AND heartbeat_at < NOW()                    -- lease истёк
  AND attempts < 3
```

**Продление lease** — если обработка длится больше 15 мин, воркер обязан **продлить** lease:

```php
while (($id = $queue->claimNextPending()) !== null) {
    $queue->extendLease($id, 15);   // heartbeat_at = NOW() + 15 min
    try {
        $lmChz->sell($row->cisList());
        $queue->markDone($id);
    } catch (...) {
        // ...
    }
}
```

Продление — не «каждые 30 сек», а **перед каждым потенциально долгим вызовом**. Это проще и надёжнее polling'а.

### Как проверить

```php
// WorkerStaleReclaimTest — новый кейс
public function testLeaseExtendsDuringLongCall(TestHarness $t): void
{
    $id = $this->queue->enqueue(6001, 'ck-lease', ['cis-x']);
    $this->queue->claimNextPending();   // heartbeat_at = NOW + 15 min
    // Эмулируем «прошло 16 минут»
    $this->pdo->exec("
        UPDATE marking_sell_queue
        SET heartbeat_at = NOW() - INTERVAL 1 MINUTE
        WHERE id = $id
    ");
    // Другой воркер подберёт строку — потому что lease истёк
    $reclaimed = $this->queue->claimNextPending();
    $t->assertEquals($id, $reclaimed, 'истёкший lease подхвачен');

    // Продление lease → снова недоступно для другого воркера
    $this->queue->claimNextPending();   // processing, lease = NOW + 15
    $reclaimed2 = $this->queue->claimNextPending();
    $t->assertTrue($reclaimed2 === null, 'активный lease не подбирается');
}
```

Ожидание: 2 PASS.

---

## Проблема 2. HTTP 500 в hybrid-ветке — не покрыт тестами 🟠

### Что сломано

Покрытие `MarkingCheckServiceTest` (44 assert'а) по описанию:
> «both→HYBRID-merge, online-late→OFFLINE, offline-late→ONLINE, both-late→EMERGENCY_TIMEOUT»

Матрица покрывает **таймауты**, но не **HTTP-ошибки**. Что если:
- online отвечает **500** за 0.3 с;
- offline отвечает **200** за 0.4 с.

`HttpParallel` вернёт для online `HttpResponse(500, body)`. Далее `parseOnline()` — что он делает?

### Root cause

Если `parseOnline()` слепо парсит JSON-тело (которое на 500 может быть `{"error":"..."}` без ожидаемых полей) — получим либо исключение, либо `MarkingCheckResult` с мусорными данными вместо `null`. Fallback на offline не сработает.

### Fix

В `HttpParallel::run` или сразу после — фильтровать ответы:

```php
private function isSuccessResponse(?HttpResponse $resp): bool
{
    if ($resp === null) return false;
    return $resp->status() >= 200 && $resp->status() < 300;
}

// В checkHybrid:
$onlineResult  = $this->isSuccessResponse($responses['online'])
    ? $this->parseOnline($responses['online'])
    : null;
$offlineResult = $this->isSuccessResponse($responses['offline'])
    ? $this->parseOffline($responses['offline'])
    : null;
```

Особый случай — **HTTP 203**: он не 2xx, но означает «аварийная ситуация». Должен приводить к `source=EMERGENCY`, а не к fallback. Обработать отдельно:

```php
if ($responses['online']?->status() === 203) {
    $this->emergencyState->activate($inn, 'HTTP 203');
    return MarkingCheckResult::allowAll($codes, source: 'EMERGENCY');
}
```

### Как проверить

```php
public function testOnline500FallsBackToOffline(TestHarness $t): void
{
    $this->transport->setResponse('online', new HttpResponse(500, '{"error":"boom"}'));
    $this->transport->setResponse('offline', new HttpResponse(200, $this->validOfflineBody()));

    $r = $svc->checkHybrid(['cis-1'], '1234567890123456');
    $t->assertEquals('OFFLINE', $r->source, 'online 500 → fallback на offline');
}

public function testOnline203TriggersEmergency(TestHarness $t): void
{
    $this->transport->setResponse('online', new HttpResponse(203, ''));
    $this->transport->setResponse('offline', new HttpResponse(200, $this->validOfflineBody()));

    $r = $svc->checkHybrid(['cis-1'], '1234567890123456');
    $t->assertEquals('EMERGENCY', $r->source, 'HTTP 203 → emergency, не fallback');
    $t->assertTrue($this->emergencyState->isActive('1234567890'), 'emergency_state активирован');
}
```

Ожидание: 2 PASS.

---

## Проблема 3. Retry `/cis/sell` — нет идемпотентности 🟠

### Что сломано

Цикл воркера:
```php
while (($id = $queue->claimNextPending()) !== null) {
    try {
        $lmChz->sell($row->cisList());   // POST /cis/sell
        $queue->markDone($id);
    } catch (HttpException $e) {
        if ($row->attempts() >= 3) {
            $queue->markFailed($id, $e->getMessage());
        } else {
            $queue->markPending($id);      // retry
        }
    }
}
```

Если `/cis/sell` **обработал** запрос, но **ответ потерялся** (timeout на клиенте, network drop) — воркер ставит `pending` и ретраит. При повторе `/cis/sell` вызывается второй раз для тех же КИ.

### Root cause

Методичка ЛМ ЧЗ §2.1.6: `/cis/sell` **добавляет КИ в БД проданных**. Двойной вызов может:
- вернуть 200 для уже проданных (idempotent по факту);
- вернуть ошибку «уже в БД проданных».

Если второй вариант — воркер уйдёт в `failed` после 3 попыток, алерт сработает, но данные в ЛМ ЧЗ правильные.

Если первый — двойной вызов безвреден, но счётчик `attempts` инкрементируется зря.

Поведение зависит от реализации ЛМ ЧЗ, но у нас есть простой способ сделать **клиентскую идемпотентность**:

### Fix — «проверить перед retry»

Перед каждой retry-попыткой — вызвать `GET /cis/sold?cis_list=...` (или эквивалент) и проверить, нет ли уже этих КИ в БД проданных.

```php
private function sellWithIdempotency(array $cisList, int $attempt): bool
{
    // На повторных попытках — сначала проверить, не обработалось ли
    if ($attempt > 1) {
        $sold = $this->lmChz->sold(0, 1000);   // выгрузка БД проданных
        $alreadySold = array_intersect($cisList, $sold->cisList());
        $remaining = array_diff($cisList, $alreadySold);

        if (empty($remaining)) {
            $this->logger->info('sell already applied, skipping retry', [
                'cis_list' => $cisList,
                'attempt'  => $attempt,
            ]);
            return true;
        }
        // Частично продано — повторяем только для remaining
        $cisList = array_values($remaining);
    }

    $this->lmChz->sell($cisList);
    return true;
}
```

Проблема: `/cis/sold` возвращает **все** проданные, не фильтрует по списку. Если у нас 10 000 проданных — фильтрация в PHP. Приемлемо, но не оптимально.

Альтернатива — если ЛМ ЧЗ имеет endpoint `GET /cis/check/{cis}` (single) — использовать его. По методичке — только `GET /api/v2/cis/sold` с пагинацией.

### Как проверить

```php
public function testRetryAfterLostResponse(TestHarness $t): void
{
    // Первый вызов: sell бросает HttpException (ответ потерялся)
    $this->lmChz->mock->failNext('sell', new HttpException('timeout'));
    // Второй вызов: sell видит, что КИ уже продан
    $this->lmChz->mock->addToSold(['cis-1']);

    $this->worker->processOne();

    $row = $this->queue->findByCheckUuid('ck-1');
    $t->assertEquals('done', $row['status'], 'retry не привёл к failed');
    $t->assertTrue($this->lmChz->mock->sellCallCount() === 1,
        'повторный /cis/sell не вызван — данные уже в ЛМ ЧЗ');
}
```

Ожидание: 1 PASS.

---

## Проблема 4. `APP_ENV unset + placeholder=1` → mock в проде 🟠

### Что сломано

Формулировка волны 2.1:
> «отсутствие APP_ENV + `placeholder=0` → RuntimeException (неявный dev-fallback запрещён); ... mock-режим без APP_ENV допустим»

То есть:
- `APP_ENV=unset` + `placeholder=1` → **OK** (mock).
- `APP_ENV=prod` + `placeholder=1` → **throw**.

Свежий деплой на bare PHP-FPM без `APP_ENV` попадает в первую строку. `MARKING_ATOL_PLACEHOLDER=1` — **дефолт** в `config`. Значит, прод-сервер без явного `APP_ENV` уйдёт в mock-режим и **отправит чек на ККТ** с placeholder-структурой тега 1260.

### Root cause

`'tag1260_placeholder' => (bool) env('MARKING_ATOL_PLACEHOLDER', true)` — дефолт `true`. Плюс отсутствие `APP_ENV` не блокирует.

### Fix — двойной барьер

**Вариант A — изменить дефолт:** `env('MARKING_ATOL_PLACEHOLDER', false)`. Тогда явное `=1` обязательно для mock. Безопаснее.

**Вариант B — ужесточить проверку:** APP_ENV допускается только `dev`/`test`/`prod`/`production`. Всё остальное (включая unset) → throw при `placeholder=1`.

```php
private function assertAtolExampleAvailable(): void
{
    $env = getenv('APP_ENV');
    $allowed = ['dev', 'test', 'prod', 'production'];

    if ($env === false || !in_array($env, $allowed, true)) {
        throw new \RuntimeException(
            "APP_ENV must be one of: " . implode('|', $allowed) .
            ". Got: " . var_export($env, true)
        );
    }

    $isProd = in_array($env, ['prod', 'production'], true);
    $isMock = (bool) MarkingConfig::get('atol.tag1260_placeholder', true);
    $path   = (string) MarkingConfig::get('atol.tag1260_example_path', '');

    if ($isProd && $isMock) {
        throw new \RuntimeException('ATOL placeholder активен в prod');
    }
    if ($isProd && !$isMock && ($path === '' || !is_file($path))) {
        throw new \RuntimeException("ATOL JSON отсутствует: $path");
    }
}
```

Тогда mock-режим работает только при **явном** `APP_ENV=dev|test`. Никаких неявных дефолтов.

### Как проверить

```php
public function testUnsetAppEnvIsRejected(TestHarness $t): void
{
    putenv('APP_ENV');        // unset
    MarkingConfig::set('atol.tag1260_placeholder', true);

    $t->assertThrows(
        \RuntimeException::class,
        fn() => $check->buildMarkingAttribute($item, $result),
        'APP_ENV unset → throw'
    );
}

public function testUnknownAppEnvIsRejected(TestHarness $t): void
{
    putenv('APP_ENV=staging');
    $t->assertThrows(
        \RuntimeException::class,
        fn() => $check->buildMarkingAttribute($item, $result),
        'APP_ENV=staging → throw'
    );
}
```

Ожидание: 2 PASS.

---

## Проблема 5. Deadlock backoff — 50/100 мс мало 🟡

### Что сломано

Формулировка волны 2.1:
> «до 3 попыток, backoff 50/100 мс при PDOException «deadlock»/«lock wait timeout»»

Deadlock возникает, когда два воркера одновременно меняют порядок блокировок. При 3 попытках и backoff 50/100 мс — суммарное окно ожидания **150 мс**. При активной нагрузке deadlock повторится.

### Fix

Стандартный паттерн — экспоненциальный backoff с jitter:

```php
for ($attempt = 1; $attempt <= 5; $attempt++) {
    try {
        return $this->claimOnce();
    } catch (PDOException $e) {
        if (!$this->isDeadlock($e)) throw $e;
        if ($attempt >= 5) throw $e;

        // Экспоненциальный backoff с jitter: 50/100/200/400 мс + 0–50 мс
        $baseMs = 50 * (1 << ($attempt - 1));
        $jitterMs = random_int(0, 50);
        usleep(($baseMs + $jitterMs) * 1000);
    }
}
```

Также — **разный порядок блокировок** в SQL для разных воркеров. Но это уже архитектурное изменение; backoff достаточен.

### Как проверить

```php
public function testDeadlockRetriesWithBackoff(TestHarness $t): void
{
    $this->pdo->mock->failNext(3, new PDOException('Deadlock found'));
    $start = microtime(true);
    $id = $this->queue->claimNextPending();
    $elapsed = microtime(true) - $start;

    $t->assertTrue($id !== null, 'claim прошёл после 3 retry');
    $t->assertTrue($elapsed >= 0.15, 'backoff ≥ 150 мс');
    $t->assertTrue($elapsed < 2.0, 'backoff < 2 с');
}
```

Ожидание: 1 PASS.

---

## Сводка находок волны 2.1

| # | Проблема | Приоритет | Файлы |
|---|----------|-----------|-------|
| 1 | Heartbeat не обновляется во время обработки — race возвращается | 🔴 | `MarkingSellQueue`, `MarkingReturnQueue`, `process_*.php`, `WorkerStaleReclaimTest` |
| 2 | HTTP 500 в hybrid-ветке — нет fallback | 🟠 | `MarkingCheckService::checkHybrid`, `MarkingCheckServiceTest` |
| 3 | Retry `/cis/sell` без идемпотентности | 🟠 | `process_sells.php`, `LmChzService`, тест |
| 4 | `APP_ENV unset + placeholder=1` → mock в проде | 🟠 | `Models/Check::assertAtolExampleAvailable`, `AtolPlaceholderGateTest` |
| 5 | Deadlock backoff 50/100 мс | 🟡 | `MarkingSellQueue::claimNextPending`, `MarkingReturnQueue::claimNextPending` |

---

## Порядок применения

1. **Проблема 1 (heartbeat)** — критический, введён волной 2.1. Применить первым.
2. **Проблема 4 (`APP_ENV`)** — критический footgun, минимальный патч.
3. **Проблема 2 (HTTP 500)** — важный, влияет на корректность hybrid.
4. **Проблема 3 (идемпотентность)** — важный, влияет на дубликаты в ЛМ ЧЗ.
5. **Проблема 5 (backoff)** — косметика.

---

## Что запустить прямо сейчас

```bash
# 1. Проверить текущее состояние heartbeat
grep -n "heartbeat_at" sql/marking_tables.sql
grep -n "heartbeat" Service/Marking/process_sells.php
grep -n "INTERVAL" Models/MarkingSellQueue.php

# 2. Проверить APP_ENV-логику
grep -n "APP_ENV\|app_env\|getenv" Models/Check.php

# 3. Проверить fallback на HTTP-ошибки
grep -n "isSuccess\|status()\|httpCode" Service/Marking/MarkingCheckService.php

# 4. Проверить, есть ли idempotency в retry
grep -n "sold\|idempot\|already" Service/Marking/process_sells.php

# 5. Прогнать тесты (baseline)
php tests/run_all.php 2>&1 | tail -5
```

Ожидание:
- п.1 — увидеть `heartbeat_at` в DDL и `heartbeat()` после claim;
- п.2 — увидеть текущую реализацию `assertAtolExampleAvailable`;
- п.3 — понять, есть ли фильтрация не-2xx;
- п.4 — понять, есть ли проверка «уже продано» перед retry;
- п.5 — baseline 321 PASS.

---

## Что дальше

Готов сразу:
- **Патч 1** — lease-semantics: DDL (без изменений), `claimNextPending` (heartbeat → NOW + 15 min), `extendLease()`, `process_*.php` (продление перед LM ChZ), тесты.
- **Патч 2** — фильтрация HTTP-ответов в `checkHybrid`, тесты для 500 и 203.
- **Патч 3** — идемпотентный retry через `/cis/sold`.
- **Патч 4** — ужесточение `assertAtolExampleAvailable` + тесты для unset/staging.
- **Патч 5** — экспоненциальный backoff с jitter.

Скажите, с какого начинать. По приоритету — **1** (heartbeat) и **4** (APP_ENV) — быстрые и критичные.