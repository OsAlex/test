# Проверка волны 2.2 — оставшиеся проблемы

Волна 2.2 закрыла 5/5 COMMENTS-11 формально. Арифметика сходится: 321 + 7 (WorkerStaleReclaimTest) + 25 (SellWorkerTest) + 4 (AtolPlaceholderGate) + 7 (MarkingCheckServiceTest) = **364**. Но при внимательном разборе — есть несимметричные пробелы и один новый баг, введённый самой волной 2.2.

---

## 1. Что закрыто корректно

| # | Пункт COMMENTS-11 | Проверка |
|---|---|---|
| 1 | Lease semantics (`NOW + 15 min`) | ✅ — `WorkerStaleReclaimTest` (25 assert'ов), `extendLease()` в SellWorker + process_returns |
| 2 | 2xx-фильтр + 203 | ⚠️ — см. находку 2 ниже |
| 3 | Идемпотентный `/cis/sell` через `/cis/sold` | ✅ — `SellWorkerTest` (25 assert'ов), кап 5000 задокументирован |
| 4 | APP_ENV whitelist | ✅ — `AtolPlaceholderGateTest` (кейсы 5–8) |
| 5 | Backoff x5 + jitter | ✅ — `WorkerStaleReclaimTest` (кейсы 8–9) |

---

## 2. Находки — что осталось

### 2.1. `process_returns.php` без идемпотентного retry 🔴

**Что сломано.** `SellWorker` (волна 2.2) перед retry вызывает `/cis/sold` и исключает уже зарегистрированные КИ. У `process_returns.php` **симметричной проверки нет**.

**Сценарий:**
1. Воркер отправляет `/cis/returned` для КИ.
2. ЛМ ЧЗ **обработал** запрос, но ответ потерялся (сетевой таймаут).
3. Воркер ловит `HttpException`, ставит `pending`, retry.
4. Второй `POST /cis/returned` для того же КИ.

**Что делает ЛМ ЧЗ при повторе возврата:**
- Вариант A — отклоняет как «уже возвращён». Воркер → `failed` после 3 попыток. Данные корректны, но лишний алерт.
- Вариант B — обрабатывает повторно. Возможна порча БД проданных ЛМ ЧЗ.

**Fix — `ReturnWorker` по образцу `SellWorker`:**

```php
// Service/Marking/ReturnWorker.php
final class ReturnWorker
{
    public function __construct(
        private LmChzService $lmChz,
        private MarkingReturnQueue $queue,
        private MarkingLogger $logger,
        private MarkingMetrics $metrics,
    ) {}

    public function process(int $id): void
    {
        $row = $this->queue->findById($id);
        $cisList = json_decode($row['cis_list'], true) ?: [];
        $attempt = (int) $row['attempts'];

        // На retry — сверяем с ЛМ ЧЗ: КИ, которых уже нет в БД проданных → возврат применён
        if ($attempt > 1) {
            $sold = $this->lmChz->sold(0, 1000);   // пагинация
            $remaining = array_intersect($cisList, $sold->cisList());
            // remaining = КИ, всё ещё числятся проданными → их нужно вернуть
            // array_diff($cisList, $sold) = КИ, которых нет в проданных → возврат уже применён

            if (empty($remaining)) {
                $this->logger->info('return already applied', ['cis' => $cisList]);
                $this->metrics->inc('marking_return_already_applied_total');
                $this->queue->markDone($id);
                return;
            }
            $cisList = array_values($remaining);
        }

        try {
            $this->lmChz->returned($cisList);
            $this->queue->markDone($id);
        } catch (HttpException $e) {
            // ... retry / failed как в SellWorker
        }
    }
}
```

Плюс `attempts` в `marking_return_queue` — добавить колонку и инкремент в claim (по аналогии с sell).

**Проверка:**

```php
public function testReturnRetryIdempotent(TestHarness $t): void
{
    // 1. КИ продано → enqueue return
    $this->lmChz->mock->addToSold(['cis-1']);
    $id = $this->queue->enqueue(7001, 'ck-ret', ['cis-1']);

    // 2. Первый /cis/returned «теряет» ответ
    $this->lmChz->mock->failNext('returned', new HttpException('timeout'));
    // Но успевает убрать КИ из проданных (эмуляция: обработал, но ответ потерян)
    $this->lmChz->mock->addToReturned(['cis-1']);   // => убрать из sold

    // 3. Retry должен увидеть, что КИ уже не в sold → done без второго вызова
    $worker = new ReturnWorker($this->lmChz, $this->queue, $this->logger, $this->metrics);
    $worker->process($id);

    $t->assertEquals('done', $this->queue->findById($id)['status'], 'retry → done');
    $t->assertEquals(1, $this->lmChz->mock->callCount('returned'),
        'второй /cis/returned не вызван');
}
```

---

### 2.2. HTTP 203 от `/codes/check` и `/cdn/info` — не активирует emergency 🔴

**Что сломано.** Волна 2.2 добавила `activateEmergency203()` в `MarkingCheckService::checkHybrid()`. Судя по описанию:
> «HTTP 203 (аварийный режим ГИС МТ): новый `activateEmergency203()` — `MarkingEmergencyState::activate(ИНН, 'HTTP 203 от CDN-хоста …')`»

Слово «CDN-хоста» — **из health-check**. По SPEC §1.4 (оригинал) 203 может прийти от **трёх** endpoint'ов: `/codes/check`, `/cdn/info`, `/cdn/health/check`.

**Root cause.** Если 203 приходит от `/codes/check` (это **основной** endpoint проверки), он уходит в `parseOnlineResponse` и обрабатывается как «обычная ошибка» → `null` → fallback на офлайн. Emergency не активируется.

**Fix — обрабатывать 203 централизованно, в `HttpParallel::run` или сразу после:**

```php
// В checkHybrid:
$responses = HttpParallel::run([...], $barrier);

foreach (['online' => $responses['online'], 'offline' => $responses['offline']] as $src => $resp) {
    if ($resp !== null && $resp->status() === 203) {
        $this->activateEmergency203($src, $resp);
        // Продолжаем — но пометим, что аварийный режим активен
    }
}

// Если emergency активирован — не делаем fallback, возвращаем EMERGENCY
if ($this->emergencyState->isActive($inn)) {
    return MarkingCheckResult::allowAll($codes, source: 'EMERGENCY');
}
```

Также в `CdnService`: если `/cdn/info` или `/cdn/health/check` вернули 203 — активировать emergency (не помечать хост как «сломанный»).

**Как проверить:**

```php
public function testCodesCheck203TriggersEmergency(TestHarness $t): void
{
    $this->transport->setResponse('online', new HttpResponse(203, ''));
    $this->transport->setResponse('offline', new HttpResponse(200, $this->validOfflineBody()));

    $r = $svc->checkHybrid(['cis-1'], '1234567890123456');
    $t->assertEquals('EMERGENCY', $r->source, '203 от /codes/check → EMERGENCY');
    $t->assertTrue($this->emergencyState->isActive('1234567890'), 'emergency активирован');
    $t->assertEquals(1, $this->metrics->get('marking_emergency_total'), 'метрика инкрементирована');
}
```

**Проверить перед патчем:**

```bash
grep -n "203" Service/Marking/MarkingCheckService.php
grep -n "203" Service/Marking/CdnService.php
grep -n "203" Service/Marking/HttpParallel.php
```

Если 203 обрабатывается **только в CdnService/checkHybrid** — патч обязателен.

---

### 2.3. `marking_emergency_state` — нет авто-истечения 🟠

**Что сломано.** SPEC §1.4: «После завершения аварии — ещё 3 дня от `emergency_actual_end_at`». Но **кто ставит `emergency_actual_end_at`**? Оператор через email/ЛК. Если он не поставил (потерянное письмо, ошибка) — `is_active` остаётся `true` **навсегда**. Все проверки — `allowAll` → продажа без проверки КМ.

**Это fail-open** — вреднее, чем fail-closed: если Оператор не подтвердил конец аварии, мы **продолжаем разрешать продажи** без проверки, теряя фискальный аудит.

**Fix — авто-истечение + алерт:**

```php
// В MarkingEmergencyState:
public function expireIfStale(): int
{
    $rows = $this->pdo->exec("
        UPDATE marking_emergency_state
        SET is_active = FALSE,
            actual_end_at = COALESCE(actual_end_at, started_at + INTERVAL 7 DAY)
        WHERE is_active = TRUE
          AND (
              (actual_end_at IS NOT NULL AND actual_end_at + INTERVAL 3 DAY < NOW())
              OR
              (actual_end_at IS NULL AND started_at + INTERVAL 7 DAY < NOW())
          )
    ");
    return (int) $rows;
}
```

**7 дней** — консервативный лимит: 3 дня «гарантированной аварии» + 4 дня «пока Оператор не подтвердил». Если 7 дней прошло, а `actual_end_at` не пришёл — снимаем emergency, **логируем critical и алертим**:

```php
if ($rows > 0) {
    $this->logger->critical('emergency auto-expired without operator confirmation', [
        'expired_count' => $rows,
    ]);
    $this->metrics->inc('marking_emergency_auto_expired_total', $rows);
}
```

**Вызов** — из cron/планировщика раз в час + в начале `checkHybrid`:

```php
public function checkHybrid(...): MarkingCheckResult
{
    $this->emergencyState->expireIfStale();   // идемпотентно, дешево
    // ...
}
```

**Проверка:**

```php
public function testEmergencyAutoExpires(TestHarness $t): void
{
    $this->emergencyState->activate('7712345678', '203');
    // Эмулируем «8 дней назад»
    $this->pdo->exec("
        UPDATE marking_emergency_state
        SET started_at = NOW() - INTERVAL 8 DAY
        WHERE inn = '7712345678'
    ");
    $this->emergencyState->expireIfStale();
    $t->assertTrue(!$this->emergencyState->isActive('7712345678'),
        'emergency истёк через 7 дней');
}
```

---

### 2.4. `LEASE_MINUTES` зашит константой 🟡

**Что сломано.** Отчёт §7.2: «Lease 15 минут зашит константой `LEASE_MINUTES` (не конфиг)».

**Fix — в `config/marking.php`:**

```php
'sell_queue' => [
    'lease_minutes'    => env('MARKING_LEASE_MINUTES', 15),
    'max_attempts'     => env('MARKING_MAX_ATTEMPTS', 3),
    'deadlock_max'     => env('MARKING_DEADLOCK_MAX', 5),
],
'return_queue' => [
    'lease_minutes'    => env('MARKING_LEASE_MINUTES', 15),
    'max_attempts'     => env('MARKING_MAX_ATTEMPTS', 3),
],
```

Использовать `MarkingConfig::get('sell_queue.lease_minutes')` вместо константы.

**Проверка:** `grep -rn "LEASE_MINUTES" Models/ Service/` — должно вернуть 0 совпадений.

---

### 2.5. `MarkingEmergencyState::activate()` — идемпотентность 🟡

**Что сломано.** Если два воркера одновременно получают 203 для одного ИНН и оба вызывают `activate()`:
- С `UNIQUE(inn)` второй INSERT упадёт.
- Если `activate()` ловит исключение и логирует — emergency уже активен, всё ок.
- Если не ловит — второй воркер крашится.

**Fix — использовать `INSERT ... ON DUPLICATE KEY UPDATE`:**

```sql
INSERT INTO marking_emergency_state (inn, is_active, started_at, reason, source_cassa_id)
VALUES (:inn, 1, NOW(), :reason, :cassa_id)
ON DUPLICATE KEY UPDATE
    is_active = 1,
    started_at = COALESCE(started_at, NOW()),
    reason     = VALUES(reason),
    updated_at = NOW()
```

Проверить текущую реализацию — если не так, применить патч.

**Проверка:**

```php
public function testActivateIdempotent(TestHarness $t): void
{
    $this->state->activate('7712345678', '203 from worker A');
    $this->state->activate('7712345678', '203 from worker B');   // не падает
    $t->assertTrue($this->state->isActive('7712345678'), 'still active');
}
```

---

### 2.6. `SellWorker` — нет метрики для идемпотентного skip 🟡

**Что сломано.** Когда retry находит КИ в `/cis/sold` и пропускает `/cis/sell` — логируется info, но **нет метрики**. Операционно важно: если `attempts > 1` и skip случается **часто** — либо ЛМ ЧЗ часто теряет ответы, либо сеть нестабильна.

**Fix:**

```php
// В SellWorker при skip:
$this->metrics->inc('marking_sell_idempotent_skip_total');

// Алерт в SPEC §5:
// ratio = marking_sell_idempotent_skip_total / marking_sell_total
// ratio > 0.05 за 5 мин → WARN
```

**Проверка:** `grep -n "idempotent_skip" Service/Marking/SellWorker.php`.

---

### 2.7. `FakeEmergencyState` — изоляция между тестами 🟡

**Что сломано.** Отчёт §2.2: «`FakeEmergencyState` получил переопределённый `activate()` + `$reasons`». Не сказано, что состояние сбрасывается между тестами. Если нет `reset()` в bootstrap или в setUp — тесты могут влиять друг на друга.

**Fix:**

```php
class FakeEmergencyState implements EmergencyStateInterface
{
    private array $active = [];
    private array $reasons = [];

    public function reset(): void
    {
        $this->active = [];
        $this->reasons = [];
    }
    // ...
}
```

Вызов `reset()` в `bootstrap.php` для каждого suite.

**Проверка:** `grep -n "FakeEmergencyState" tests/bootstrap.php tests/Unit/*.php` — должны быть вызовы `reset()`.

---

### 2.8. Кап `/cis/sold` = 5000 — реальная проблема при росте 🟠

**Что сломано.** Отчёт §7.1: кап 5000 проданных. При росте БД ЛМ ЧЗ свыше 5000 КИ сверка пропустит часть. Retry отправит `/cis/sell` повторно.

**Что делать в ЛМ ЧЗ**. По методичке `/cis/sold` — пагинация по `skip/limit`, до 1000 за раз. При 5000+ проданных нужен **полный обход** — но это дорого.

**Альтернатива — использовать «окно» по времени:**

```php
// Вместо полной выгрузки — выгружаем только за последние N часов
$sold = $this->lmChz->soldSince(
    since: new \DateTimeImmutable('-2 hours'),
    skip: 0,
    limit: 1000
);
```

ЛМ ЧЗ по методичке **не поддерживает** фильтр по времени в `/cis/sold`. Но можно использовать косвенно: если воркер обрабатывает retry через 30 секунд после падения — КИ точно в последних 1000 проданных.

**Практичное решение:**

```php
// Вместо капа 5000 — увеличить до 10000 и добавить метрику пропуска:
$maxPages = 100;   // 100 * 100 = 10 000
$found = [];
for ($skip = 0; $skip < $maxPages * 100; $skip += 100) {
    $page = $this->lmChz->sold($skip, 100);
    if (empty($page->cisList())) break;
    $found = array_merge($found, $page->cisList());
    if (count($found) > 10_000) {
        $this->metrics->inc('marking_sell_sold_cap_exceeded_total');
        $this->logger->warning('sold cap exceeded — idempotency may fail', [
            'cis_count' => count($cisList),
        ]);
        break;
    }
}
```

При `marking_sell_sold_cap_exceeded_total > 0` — **алерт**, что нужен другой подход (например, вести локальную таблицу sent-but-unacked CIS).

---

## 3. Сводная таблица находок

| # | Проблема | Приоритет | Файлы |
|---|----------|-----------|-------|
| 2.1 | `process_returns.php` без идемпотентного retry | 🔴 | `ReturnWorker` (новый), `process_returns.php`, DDL `attempts` в `marking_return_queue`, тест |
| 2.2 | HTTP 203 от `/codes/check` не активирует emergency | 🔴 | `MarkingCheckService::checkHybrid`, `CdnService`, тест |
| 2.3 | `marking_emergency_state` без авто-истечения (fail-open) | 🟠 | `MarkingEmergencyState::expireIfStale`, cron, тест |
| 2.4 | `LEASE_MINUTES` — константа, не конфиг | 🟡 | `config/marking.php`, `MarkingSellQueue`, `MarkingReturnQueue` |
| 2.5 | `activate()` идемпотентность | 🟡 | `MarkingEmergencyState`, тест |
| 2.6 | Нет метрики идемпотентного skip | 🟡 | `SellWorker`, SPEC §5 |
| 2.7 | `FakeEmergencyState` без reset | 🟡 | `tests/Fake/FakeEmergencyState.php`, `bootstrap.php` |
| 2.8 | Кап `/cis/sold` = 5000 | 🟠 | `SellWorker::soldIntersection`, метрика + SPEC |

---

## 4. Что запустить прямо сейчас (диагностика)

```bash
# 1. HTTP 203 — где обрабатывается
grep -rn "203" Service/Marking/*.php

# 2. Idempotency в return
grep -n "sold\|idempot\|already" Service/Marking/process_returns.php

# 3. Lease в конфиге
grep -rn "LEASE_MINUTES" Models/ Service/ config/

# 4. activate — ON DUPLICATE KEY
grep -A 5 "function activate" Models/MarkingEmergencyState.php

# 5. attempts в return-очереди
grep -n "attempts" sql/marking_tables.sql | grep -i return

# 6. FakeEmergencyState reset
grep -n "reset\|clear" tests/Fake/FakeEmergencyState.php

# 7. Baseline тестов
php tests/run_all.php 2>&1 | tail -5
```

Ожидание:
- п.1 — увидеть, обрабатывается ли 203 только в `CdnService` или также в `MarkingCheckService`.
- п.2 — если `grep` пусто → патч 2.1 обязателен.
- п.3 — увидеть константу → патч 2.4 нужен.
- п.4 — понять текущую реализацию.
- п.5 — если `attempts` нет в `marking_return_queue` → патч 2.1 требует DDL-миграции.
- п.6 — если `reset` нет → патч 2.7 нужен.
- п.7 — baseline 364 PASS.

---

## 5. Порядок применения

1. **Находка 2.2** (HTTP 203) — сначала диагностика. Если подтверждено — патч критический, минимальный.
2. **Находка 2.1** (idempotent return) — симметрична уже закрытому #3, логически обязательна.
3. **Находка 2.3** (emergency auto-expire) — fail-open, критично для корректности.
4. **Находка 2.8** (кап `/cis/sold`) — связана с 2.1 (та же идемпотентность).
5. **Находки 2.4–2.7** — быстрые точечные патчи.

---

## Что дальше

Готов сразу:
- **Патч 2.1** — `ReturnWorker` + DDL + тест.
- **Патч 2.2** — централизованная обработка 203 в `MarkingCheckService` + тест.
- **Патч 2.3** — `expireIfStale()` + cron-интеграция + тест.
- **Патч 2.4–2.7** — точечные правки одной пачкой.

Скажите, что применить в первую очередь. По приоритету — **2.1 и 2.2** (критические, симметричны уже закрытым проблемам).