# Проверка волны 2 — находки и следующий шаг

Волна 2 закрыла 15/15 COMMENTS-8 корректно по фактам отчёта. Ниже — что реально сломано после внимательного разбора и один новый критичный баг, который волна 2 не покрыла.

---

## 1. Что волна 2 сделала корректно

| # | Пункт | Проверка |
|---|-------|----------|
| 5 | `HttpParallel` + `HttpParallelTransport` + общий барьер | ✅ — матрица both/online/offline/none покрыта `MarkingCheckServiceTest` (44 assert'а) |
| 4 | `DbCdnHostStateStore` + `InMemoryCdnHostStateStore` | ✅ — `CdnCircuitBreakerPersistenceTest` (9) на двух воркерах |
| 3 | Разделение `marking_emergency_state` (INN) / `marking_cdn_host_state` (host) | ✅ — `MarkingEmergencyStateTest` (9), UNIQUE(inn) |
| 11 | `claimNextPending()` | ✅ — `WorkerRaceTest` (7), race на 2 коннекциях |
| 8 | `assertAtolExampleAvailable()` | ✅ — `AtolPlaceholderGateTest` (6) |
| 10 | Решение A — только `marking_return_queue` | ✅ — `MarkingReturnServiceTest` (12), sell не мутируется |
| 13 | Advance → SKIPPED | ✅ — `MarkingCheckServiceTest`, `skip_reason='advance_check_no_goods'` |
| 14 | uiPayload INFO/EMERGENCY | ✅ — `MarkingStatusUiPayloadTest` (16) |
| 7 | esmpack из git | ✅ — `git log --all` пусто, hash-object пусто |

Сумма assert'ов по suite: **298** — сходится. Расхождение #1 из COMMENTS-8 закрыто.

---

## 2. Новые находки (волна 2 не покрыла)

### 2.1. `processing` — вечный зомби 🔴

**Что сломано.** `claimNextPending()` переводит строку `pending → processing` в транзакции. Но если воркер упал **после** claim и **до** `markDone`/`markFailed` — строка остаётся в `processing` **навсегда**. Следующий цикл claim её не подберёт (status ≠ pending), retry не сработает.

**Симптом в проде:** после падения воркера (OOM, kill -9, деплой) каждая прерванная запись «залипает». Через сутки — сотни `processing`, КИ не зарегистрированы в ЛМ ЧЗ. Продажи разрешены (факт фискализации есть), но `/cis/sell` не отправлен.

**Fix — heartbeat + stale reclaim:**

```sql
ALTER TABLE marking_sell_queue
    ADD COLUMN heartbeat_at TIMESTAMP NULL,
    ADD INDEX idx_processing_stale (status, heartbeat_at);

ALTER TABLE marking_return_queue
    ADD COLUMN heartbeat_at TIMESTAMP NULL,
    ADD INDEX idx_processing_stale (status, heartbeat_at);
```

В `claimNextPending()` — перед claim:

```php
// Возвращаем зомби в pending
$this->pdo->exec("
    UPDATE marking_sell_queue
    SET status = 'pending', heartbeat_at = NULL
    WHERE status = 'processing'
      AND (heartbeat_at IS NULL OR heartbeat_at < NOW() - INTERVAL 10 MINUTE)
      AND attempts < 3
");

// Зомби с attempts >= 3 → failed + алерт
$this->pdo->exec("
    UPDATE marking_sell_queue
    SET status = 'failed', processed_at = NOW()
    WHERE status = 'processing'
      AND (heartbeat_at IS NULL OR heartbeat_at < NOW() - INTERVAL 10 MINUTE)
      AND attempts >= 3
");
```

В `process_sells.php` — обновлять heartbeat каждые 30 секунд между retry:

```php
while (($id = $queue->claimNextPending()) !== null) {
    $queue->heartbeat($id);  // UPDATE heartbeat_at = NOW()
    try {
        $lmChz->sell($row->cisList());
        $queue->markDone($id);
    } catch (...) {
        // ...
    }
}
```

**Тест — `tests/WorkerStaleReclaimTest.php`:**

```php
public function run(TestHarness $t): void
{
    // 1. Строка "зависла" — heartbeat старый, status=processing, attempts=1
    $id = $this->queue->enqueue(5001, 'ck-stale', ['cis-x']);
    $this->queue->claimNextPending(); // → processing, attempts=1
    // Эмулируем «воркер умер 11 минут назад»
    $this->pdo->exec("
        UPDATE marking_sell_queue
        SET heartbeat_at = NOW() - INTERVAL 11 MINUTE
        WHERE id = $id
    ");

    // 2. Новый воркер подбирает зомби
    $reclaimed = $this->queue->claimNextPending();
    $t->assertTrue($reclaimed !== null, 'зомби подхвачен новым воркером');
    $t->assertEquals($id, $reclaimed, 'подхвачен именно stale id');

    // 3. attempts=3 → failed, не подбираем
    $this->pdo->exec("UPDATE marking_sell_queue SET attempts=3 WHERE id=$id");
    $this->pdo->exec("UPDATE marking_sell_queue SET heartbeat_at=NOW() - INTERVAL 11 MINUTE, status='processing' WHERE id=$id");
    $this->queue->claimNextPending();
    $row = $this->queue->findById($id);
    $t->assertEquals('failed', $row['status'], 'attempts>=3 → failed');
}
```

**Проверка:**

```bash
TEST_DB_DSN="sqlite::memory:" php tests/run_all.php --filter=WorkerStaleReclaim
```

Ожидание: 3 PASS.

---

### 2.2. Счётчик коммитов: 5 vs 6 🟡

**Факт:**
- `ИТОГИ_ВОЛНЫ_2.md` §1: «Коммитов на `main` (волна 2) — 6».
- `ИТОГИ_ВОЛНЫ_2.md` §5: перечислено **6** коммитов (`fb8fa19`, `0865c19`, `03f977e`, `6bc2fa5`, `914b606`, `4378c98`).
- `ОТЧЕТ_О_РЕЗУЛЬТАТАХ.md` §5: перечислено **5** коммитов (`fb8fa19`…`914b606`), без `4378c98`.

Коммит `4378c98` (docs — «финальный список коммитов в ОТЧЕТ §5») **не попал** в сам ОТЧЕТ.

**Fix:**

```bash
git show --stat 4378c98 | tail -5     # убедиться, что коммит существует
git log --oneline -3                  # посмотреть, куда он идёт
```

Затем — обновить §5 в `ОТЧЕТ_О_РЕЗУЛЬТАТАХ.md`, добавив строку `4378c98` либо удалив её из `ИТОГИ_ВОЛНЫ_2.md`. Один из документов должен быть эталоном.

**Проверка:**

```bash
grep -c "^| \`" ОТЧЕТ_О_РЕЗУЛЬТАТАХ.md      # число строк-коммитов
grep -c "^| \`" ИТОГИ_ВОЛНЫ_2.md            # должно совпасть
```

---

### 2.3. `APP_ENV` не задан → placeholder уходит в прод 🟠

**Что сломано.** В `AtolPlaceholderGateTest`:

```php
$isProd = (getenv('APP_ENV') ?: 'dev') === 'prod';
```

Если `APP_ENV` **не установлен** (типично для bare PHP), дефолт — `'dev'`. В проде без явного `APP_ENV=prod` гейт не сработает → чек уйдёт на ККТ с placeholder'ом.

**Fix — двойная проверка:**

```php
private function assertAtolExampleAvailable(): void
{
    $env     = getenv('APP_ENV');
    $isProd  = $env === 'prod' || $env === 'production';
    $isMock  = (bool) MarkingConfig::get('atol.tag1260_placeholder', true);
    $path    = (string) MarkingConfig::get('atol.tag1260_example_path', '');

    // Ни dev, ни test, ни prod → отказываем
    if ($env === false && !$isMock) {
        throw new \RuntimeException(
            'APP_ENV не задан. Установите APP_ENV=dev|test|prod.'
        );
    }

    if ($isProd && $isMock) {
        throw new \RuntimeException(
            'ATOL tag1260 placeholder активен в prod. ' .
            'Установите MARKING_ATOL_PLACEHOLDER=0 и MARKING_ATOL_EXAMPLE_PATH.'
        );
    }
    if ($isProd && !$isMock && ($path === '' || !is_file($path))) {
        throw new \RuntimeException(
            "ATOL official JSON отсутствует: MARKING_ATOL_EXAMPLE_PATH={$path}"
        );
    }
}
```

**Тест — добавить в `AtolPlaceholderGateTest`:**

```php
private function testMissingAppEnv(TestHarness $t): void
{
    putenv('APP_ENV');  // снимаем переменную
    MarkingConfig::set('atol.tag1260_placeholder', false);
    $t->assertThrows(
        \RuntimeException::class,
        fn() => $check->buildMarkingAttribute($item, $result),
        'APP_ENV не задан + placeholder=0 → отказ'
    );
}
```

---

### 2.4. `Cassa::getTimeZone()` — нет валидации диапазона 🟠

**Что сломано.** SPEC §1.2: «диапазон 1..11». Но если в `cassa_ord_det.timezone` лежит `0`, `12` или `'abc'` — что вернёт `Cassa::getTimeZone()`?

**Fix:**

```php
public function getTimeZone(): int
{
    $raw = $this->timezone ?? null;
    if ($raw !== null && is_numeric($raw)) {
        $v = (int) $raw;
        if ($v >= 1 && $v <= 11) {
            return $v;
        }
        // Логируем как аномалию и падаем на дефолт
        \Service\Marking\MarkingLogger::warning('invalid cassa timezone', [
            'cassa_id' => $this->id,
            'value' => $raw,
        ]);
    }
    return (int) MarkingConfig::get('default_timezone', 2);
}
```

**Тест:**

```php
public function testOutOfRangeFallsBackToDefault(TestHarness $t): void
{
    $c = new \Models\Cassa(['id' => 1, 'timezone' => 0]);
    $t->assertEquals(2, $c->getTimeZone(), 'timezone=0 → default=2');

    $c = new \Models\Cassa(['id' => 1, 'timezone' => 12]);
    $t->assertEquals(2, $c->getTimeZone(), 'timezone=12 → default=2');

    $c = new \Models\Cassa(['id' => 1, 'timezone' => 'abc']);
    $t->assertEquals(2, $c->getTimeZone(), 'timezone=abc → default=2');

    $c = new \Models\Cassa(['id' => 1, 'timezone' => 5]);
    $t->assertEquals(5, $c->getTimeZone(), 'timezone=5 → 5');
}
```

---

### 2.5. Q8 — `verified=false`: нужен финальный ответ 🟠

**Факт:** в конфиге `to_verify` содержит `verified_false`, `error_code_5`, `error_code_6`, `error_code_7`. Маппинг — WARN.

**Что важно знать:** по PIOT §4 / Приложение 2 «нарушение формата» — BLOCK. По SPEC §1.6 errorCode 5–7 (крипто-подпись) — WARN. Формально это **разные** кейсы:

- **`verified=false`, `errorCode=0`** — КМ структурно валиден, но подпись не верифицирована. Скорее BLOCK (нельзя продавать непроверенный товар).
- **`errorCode=5–7`** — ошибка криптоподписи на этапе парсинга. WARN, т.к. сам КМ не распознан — возможно, проблема с эмуляцией сканера.

**Fix — разделить:**

```php
// config/marking.php
'prohibitions' => [
    'error_code_levels' => [
        0  => 'INFO',
        1  => 'WARN', 2 => 'WARN', 3 => 'WARN', 4 => 'WARN',
        5  => 'WARN', 6 => 'WARN', 7 => 'WARN',
        8  => 'WARN', 9 => 'WARN',
        10 => 'BLOCK',
        11 => 'WARN',
    ],
    'flags' => [
        'found_false'    => 'BLOCK',
        'verified_false' => 'BLOCK',   // ← ИЗМЕНЕНО: PIOT §4 (нарушение формата → запрет)
        'utilised_false' => 'WARN',
        'realizable_false' => 'WARN',
        'sold_true'      => 'BLOCK',
        'is_blocked_true'=> 'BLOCK',
    ],
    'to_verify' => [
        // Пусто: Q8 закрыт — verified_false теперь BLOCK
        // Если Оператор ответит иначе — вернуть в to_verify
    ],
],
```

**Проверка:**

```bash
php tests/run_all.php --filter=ProhibitionConfig
```

Ожидание: тест `testToVerifyItemsAreDocumented` либо упадёт (если он требует непустой список), либо пропустит. Смотреть — если упадёт, обновить тест, оставив комментарий «Q8 закрыт: BLOCK по PIOT §4».

---

### 2.6. Q9 — `cod_marking_*` без owner'а 🟠

**Факт:** SPEC §2.4 описывает формат, но не источник. Три варианта:

1. **Сканер на кассе** — кассир сканирует КМ, поле заполняется в UI.
2. **Импорт из 1С** — при создании заказа в 1С заполняются `cod_marking_cis`.
3. **Внешний API** — товароучётная система вызывает API.

**Что нужно для закрытия:**

В SPEC §2.4 добавить раздел «Источник заполнения» с явным выбором. Пока ответа нет — **валидатор** в `MarkingItemValidator` уже падает с `MarkingBlockedException` при пустом `cod_marking_cis`, если `is_marked=1`. Это правильно.

**Fix — добавить в SPEC:**

```markdown
### §2.4.1 Источник `cod_marking_cis` / `is_marked`

**MVP:** заполняется кассиром через сканер КМ на кассе (или вручную в UI).
**TODO-step2:** интеграция с 1С — поле выгружается из товароучётной системы.
**TODO-step3:** внешний API для предзаполнения.

До реализации Q9 — валидатор `MarkingItemValidator::parseCis` падает с
`MarkingBlockedException`, если `is_marked=1` и `cod_marking_cis=''`.
```

---

### 2.7. `heartbeat_at` — метрика для алерта 🟡

**Дополнение к 2.1.** Метрика «сколько stale reclaim'ов за 5 минут»:

```php
// MarkingMetrics
$this->metrics->increment('marking_stale_reclaim_total');
```

Алерт: `marking_stale_reclaim_total / marking_sell_total > 0.01` за 5 мин → critical (воркер нестабилен).

---

### 2.8. Deadlock в `claimNextPending()` 🟡

**Что может случиться.** Транзакция с `SELECT ... FOR UPDATE` + `UPDATE` может упасть с `Deadlock found`. Под нагрузкой (несколько воркеров, параллельный INSERT).

**Fix — retry на deadlock:**

```php
public function claimNextPending(): ?int
{
    $attempts = 0;
    while ($attempts < 3) {
        try {
            return $this->claimOnce();
        } catch (\PDOException $e) {
            if (!str_contains($e->getMessage(), 'Deadlock')) {
                throw $e;
            }
            $attempts++;
            usleep(random_int(10_000, 100_000)); // 10–100 мс
        }
    }
    throw new \RuntimeException('claimNextPending: deadlock после 3 попыток');
}
```

**Тест:** сложно воспроизвести детерминированно, но можно проверить, что `claimOnce` вызывается повторно при моке `PDOException`.

---

## 3. Сводка находок волны 2

| # | Проблема | Приоритет | Файлы |
|---|----------|-----------|-------|
| 2.1 | **Stale `processing` — зомби** | 🔴 | DDL, `MarkingSellQueue`, `MarkingReturnQueue`, `process_*.php`, `WorkerStaleReclaimTest` |
| 2.2 | Счётчик коммитов 5 vs 6 | 🟡 | `ОТЧЕТ_О_РЕЗУЛЬТАТАХ.md` §5 или `ИТОГИ_ВОЛНЫ_2.md` §5 |
| 2.3 | `APP_ENV` не задан → placeholder в prod | 🟠 | `Models/Check.php::assertAtolExampleAvailable`, `AtolPlaceholderGateTest` |
| 2.4 | `getTimeZone()` — нет валидации 1..11 | 🟠 | `Models/Cassa.php`, `CassaTimeZoneTest` |
| 2.5 | Q8 — `verified=false` → BLOCK (по PIOT §4) | 🟠 | `config/marking.php` `prohibitions.flags` |
| 2.6 | Q9 — источник `cod_marking_*` | 🟠 | SPEC §2.4.1 |
| 2.7 | Метрика stale reclaim | 🟡 | `MarkingMetrics`, алерт |
| 2.8 | Deadlock retry в claim | 🟡 | `MarkingSellQueue::claimNextPending` |

---

## 4. Порядок применения

**Сначала — 2.1 (stale processing)** — критический баг, из-за которого данные теряются после падения воркера.

Затем параллельно:

1. **2.5** — изменение конфига `verified_false → BLOCK` (по PIOT §4). Одна строка + комментарий.
2. **2.3** — `assertAtolExampleAvailable` — ужесточение проверки.
3. **2.4** — валидация `timezone` в `Cassa`.
4. **2.2** — сведение коммитов.

Затем:

5. **2.6** — SPEC §2.4.1.
6. **2.7, 2.8** — метрика + retry.

---

## 5. Что запустить прямо сейчас

```bash
# 1. Проверить счётчик коммитов
git log --oneline -10
git show --stat 4378c98 | head -20

# 2. Проверить APP_ENV на prod-стенде (если есть)
php -r 'var_dump(getenv("APP_ENV"));'

# 3. Прогнать тесты как есть — baseline
php tests/run_all.php 2>&1 | tail -5

# 4. Проверить, есть ли heartbeat_at в DDL
grep -n "heartbeat" sql/marking_tables.sql
# если пусто — 2.1 подтверждён

# 5. Проверить Q8 в конфиге
grep -n "verified_false\|to_verify" config/marking.php
```

Ожидание по шагу 4: **пусто** → баг 2.1 подтверждён, патч обязателен.

Ожидание по шагу 5: `verified_false` в `to_verify` → патч 2.5 обязателен.

---

## 6. Что предложить после фиксов 2.1–2.5

1. **Прогнать полный набор** — ожидается 298 + 3 (2.1) + 1 (2.3) + 4 (2.4) = **~306 PASS**.
2. **Обновить `ИТОГИ_ВОЛНЫ_2.md`** — добавить раздел «Волна 2.1» с фиксами 2.1–2.8.
3. **Подготовить `ОТЧЕТ_О_РЕЗУЛЬТАТАХ.md`** к переходу в волну 3 — обновить §4.5, §4.7, §6.7.

---

Готов сразу:
- написать полностью **DDL-миграцию для 2.1** (heartbeat + index) + **патч `claimNextPending`** + **тест `WorkerStaleReclaimTest`**;
- патч **`Cassa::getTimeZone`** + **`assertAtolExampleAvailable`** + **`AtolPlaceholderGateTest`**;
- патч **`config/marking.php`** для Q8 (`verified_false → BLOCK`).

Скажите, с чего начинать — но по приоритету первым идёт **2.1** (stale processing).