# Проверка волны 2.3 — оставшиеся проблемы

Волна 2.3 закрыла 8/8 COMMENTS-12. Счёт 418 сходится (364 + 26 ReturnWorkerTest + 8 CdnServiceTest + 4 MarkingEmergencyStateTest + 2 MarkingCheckServiceTest + правки WorkerStaleReclaim). Но при разборе — **два возврата ранее закрытых проблем** и **четыре новые**, введённые самими патчами 2.3.

---

## 1. Критические — возврат ранее закрытого

### 1.1. HTTP 203 в hybrid-ветке `/codes/check` — по-прежнему не активирует emergency 🔴

**Что сломано.** Отчёт волны 2.3 §2.2:
> «Hybrid-ветка (raw-запрос, не CdnService) — **без изменений**; двойного счёта метрики нет.»

То есть 203 в hybrid-ветке **не активирует** `marking_emergency_state`. Находка 2.2 из COMMENTS-12 (проверка волны 2.2) была про online-only, и её закрыли в `CdnService`. Но hybrid — **самый частый режим** (параллельно онлайн+офлайн). Если 203 прилетает от `/codes/check` в hybrid — emergency не активируется.

**Root cause.** В hybrid-ветке `/codes/check` идёт через `HttpParallel`, не через `CdnService`. Логика активации 203 добавлена **только в CdnService**.

**Fix** — в `MarkingCheckService::checkHybrid()`, после сбора ответов `HttpParallel`:

```php
foreach (['online' => $responses['online'], 'offline' => $responses['offline']] as $src => $resp) {
    if ($resp !== null && $resp->status() === 203) {
        $this->emergencyState->activate(
            (string) MarkingConfig::get('inn'),
            "HTTP 203 from hybrid/$src"
        );
        $this->metrics->inc('marking_emergency_total');
        $this->logger->warning('hybrid_emergency_203', ['source' => $src]);
    }
}

// Если emergency активирован — не мерджим, отдаём allowAll
if ($this->emergencyState->isActive((string) MarkingConfig::get('inn'))) {
    return MarkingCheckResult::allowAll($codes, source: 'EMERGENCY');
}
```

Плюс вызов `onEmergency203()` из `CdnService` — оставить как есть (для онлайн-only). Двойного счёта не будет, если активация идемпотентна (см. находку 2.2 ниже).

**Проверка:**

```php
public function testHybridCodesCheck203ActivatesEmergency(TestHarness $t): void
{
    $this->transport->setResponse('online', new HttpResponse(203, ''));
    $this->transport->setResponse('offline', new HttpResponse(200, $this->validOfflineBody()));

    $r = $svc->checkHybrid(['cis-1'], '1234567890123456');

    $t->assertEquals('EMERGENCY', $r->source, '203 в hybrid → EMERGENCY');
    $t->assertTrue($this->emergencyState->isActive('1234567890'), 'state активирован');
    $t->assertEquals(1, $this->metrics->get('marking_emergency_total'),
        'метрика инкрементирована ОДИН раз');
}
```

**Проверить перед патчем:**

```bash
grep -n "203" Service/Marking/MarkingCheckService.php
```

Если `203` встречается только в комментариях — патч обязателен.

---

### 1.2. `expireIfStale` не вызывается через cron — emergency может остаться активным 🔴

**Что сломано.** Отчёт §7.3:
> «`expireIfStale()` — в `check()` (каждая проверка кодов), а не в cron... cron-вызывание не требуется.»

**Сценарий:**
1. Авария активирована.
2. Через 8 суток магазин **не работал** (праздники, закрытие, ремонт).
3. Первая продажа после открытия → `check()` → `expireIfStale()` → снимает emergency. ✅

**Но:** если магазин работает **круглосуточно** и `check()` вызывается **только при продаже маркированного товара**, а в ночные часы таких продаж нет — `expireIfStale` не вызывается. Emergency остаётся активным **до первой продажи маркированного товара**.

**Хуже:** если в магазине перестали продавать маркированный товар (сезон, изменение ассортимента), emergency активен **неопределённо долго**. Все проверки — `allowAll` (fail-open).

**Fix — два уровня:**

1. **Оставить в `check()`** — как сейчас.
2. **Добавить в cron** — раз в час через `cron/expire_emergency.php`:

```php
// cron/expire_emergency.php — ежечасно
require __DIR__ . '/../bootstrap.php';

$state = new \Models\MarkingEmergencyState(\Models\Base::pdo());
$expired = $state->expireIfStale();
if ($expired > 0) {
    error_log("[marking] emergency auto-expired for $expired INN(s)");
}
```

Регистрация:
```
0 * * * * php /path/to/cron/expire_emergency.php
```

**Проверка:** в отчёте §7.3 упомянуть, что cron **обязателен** (не «не требуется»). Также добавить в SPEC §9 (деплой-чеклист).

---

## 2. Новые проблемы, введённые патчами 2.3

### 2.1. `expireIfStale()` — race с `activate()` 🔴

**Что сломано.** `expireIfStale()` вызывается в начале `check()`. Если в этот же момент другой воркер видит 203 и вызывает `activate()`, порядок может быть:

1. Worker A: `expireIfStale()` → читает `is_active=true`, `started_at=8 дней назад` → ставит `is_active=0`.
2. Worker B: `activate()` → видит, что запись есть, обновляет `reason`, `started_at=NOW`.
3. Worker A: коммитит свой `UPDATE` → `is_active=0`.

Результат: **авария фактически активна** (203 пришёл секунду назад), но `is_active=0`. Все проверки идут в ГИС МТ, которая в аварии → 203 → activate → ... **петля**.

**Fix — защита от race через `updated_at`:**

```php
public function expireIfStale(): int
{
    $rows = $this->pdo->exec("
        UPDATE marking_emergency_state
        SET is_active = 0,
            actual_end_at = COALESCE(actual_end_at, started_at + INTERVAL 7 DAY)
        WHERE is_active = 1
          AND updated_at < NOW() - INTERVAL 30 SECOND   -- защита от race
          AND (
              (actual_end_at IS NOT NULL AND actual_end_at < NOW() - INTERVAL 3 DAY)
              OR
              (actual_end_at IS NULL AND started_at < NOW() - INTERVAL 7 DAY)
          )
    ");
    // ...
}
```

`updated_at < NOW() - INTERVAL 30 SECOND` — если запись меняли меньше 30 секунд назад, не трогаем.

**Проверка:**

```php
public function testExpireDoesNotRaceWithActivate(TestHarness $t): void
{
    $this->state->activate('7712345678', '203');
    $this->pdo->exec("
        UPDATE marking_emergency_state
        SET started_at = NOW() - INTERVAL 8 DAY,
            updated_at = NOW() - INTERVAL 5 SECOND    -- недавно менялась
        WHERE inn = '7712345678'
    ");

    $this->state->expireIfStale();
    $t->assertTrue($this->state->isActive('7712345678'),
        'недавно обновлённая запись не expirе-ится');
}
```

---

### 2.2. `activate()` обновляет `started_at` — счётчик 7 дней сбрасывается 🔴

**Что сломано.** Отчёт §2.5:
> «Повторный activate обновляет `started_at`/`reason` (новый период аварийного режима).»

Если 203 приходит раз в N дней (стабильная авария), `started_at` сбрасывается **каждым** 203. Значит, `started_at + 7 DAY` никогда не наступит — авто-expire не сработает.

**Сценарий:**
1. День 0: 203 → activate, `started_at=0`.
2. День 3: 203 снова → activate, `started_at=3`.
3. День 6: 203 → `started_at=6`.
4. ... бесконечно.

Emergency активен **вечно**, Оператор никогда не выйдет из него автоматически. Единственный выход — `confirmEnd()` вручную.

**Fix** — не обновлять `started_at` при повторной активации:

```sql
-- race-safe activate:
INSERT INTO marking_emergency_state (inn, is_active, started_at, reason, source_cassa_id)
VALUES (:inn, 1, NOW(), :reason, :cassa_id)
ON DUPLICATE KEY UPDATE
    is_active  = 1,
    reason     = VALUES(reason),
    updated_at = NOW()
    -- started_at НЕ обновляем
```

Если хочется отслеживать «когда последний раз видели 203» — добавить отдельную колонку `last_seen_at`:

```sql
ALTER TABLE marking_emergency_state
    ADD COLUMN last_seen_at TIMESTAMP NULL;
```

`activate()` → `last_seen_at=NOW()`, `started_at` — только при переходе `is_active: 0 → 1`.

**Проверка:**

```php
public function testActivateDoesNotResetStartedAt(TestHarness $t): void
{
    $this->state->activate('7712345678', '203 #1');
    $started1 = $this->state->getStartedAt('7712345678');

    sleep(1);
    $this->state->activate('7712345678', '203 #2');
    $started2 = $this->state->getStartedAt('7712345678');

    $t->assertEquals($started1, $started2, 'started_at не сброшен');
}
```

---

### 2.3. ИНН не задан → emergency не активируется (fail-open) 🟠

**Что сломано.** Отчёт §2.2:
> «Отсутствие ИНН/БД — не блок (warn-логи `emergency_state_no_inn` / `emergency_state_activate_failed`).»

Если `COMPANY_INN` не задан в `.env`, при 203 аварийный режим **не активируется**, касса продолжает ходить в ГИС МТ → 203 → ... **бесконечный цикл**. Проверки не работают, но продажи разрешены (по коду проверка кодов не блокирует).

**Fix — fail-closed при отсутствии ИНН:**

```php
private function onEmergency203(string $endpoint, string $host): void
{
    $inn = (string) MarkingConfig::get('inn');
    if ($inn === '') {
        // Не знаем, для какого ИНН активировать — блокируем проверки
        $this->logger->critical('emergency_203_no_inn', ['endpoint' => $endpoint]);
        $this->metrics->inc('marking_emergency_no_inn_total');
        throw new \RuntimeException(
            'HTTP 203 received but COMPANY_INN is not configured'
        );
    }
    // ... активация
}
```

Или мягче — в `MarkingCheckService`:

```php
if ($this->emergencyState === null || MarkingConfig::get('inn') === '') {
    // Нет возможности активировать emergency → возвращаем EMERGENCY без активации
    return MarkingCheckResult::allowAll($codes, source: 'EMERGENCY');
}
```

**Проверка:**

```php
public function test203WithoutInnReturnsEmergency(TestHarness $t): void
{
    MarkingConfig::set('inn', '');   // ИНН не задан
    $this->transport->setResponse('online', new HttpResponse(203, ''));

    $r = $svc->checkHybrid(['cis-1'], '1234567890123456');
    $t->assertEquals('EMERGENCY', $r->source, 'fail-closed при отсутствии ИНН');
}
```

---

### 2.4. `expireIfStale()` в `check()` — нагрузка на БД при масштабе 🟡

**Что сломано.** `expireIfStale()` — UPDATE в начале **каждого** `check()`. При 1000 касс × 100 чеков/час = 100 000 UPDATE/час. Все с `WHERE is_active=1` (индекс есть) — быстро, но:

- каждый UPDATE берёт **row lock** на запись;
- при 1000 кассах — конкуренция за одну строку `inn=CONST`;
- чаще всего `is_active=0` (аварии редки) → UPDATE возвращает 0 строк, но всё равно идёт по индексу.

**Fix — throttle через статическую метку или кэш:**

```php
private static ?int $lastExpireCheck = null;

public function checkHybrid(...): MarkingCheckResult
{
    $now = time();
    if (self::$lastExpireCheck === null || $now - self::$lastExpireCheck > 300) {
        try {
            $this->emergencyState->expireIfStale();
            self::$lastExpireCheck = $now;
        } catch (\Throwable $e) {
            $this->logger->warning('expire_if_stale_failed', ['error' => $e->getMessage()]);
        }
    }
    // ...
}
```

Проверять раз в 5 минут вместо каждой проверки. Точность авто-expire **не критична** — час или 5 минут задержки не влияют на безопасность.

**Проверка:**

```php
public function testExpireThrottled(TestHarness $t): void
{
    $svc->checkHybrid(['cis-1'], '1234567890123456');
    $callsAfter1 = $this->emergencyState->expireCallCount();

    $svc->checkHybrid(['cis-2'], '1234567890123456');
    $svc->checkHybrid(['cis-3'], '1234567890123456');
    $callsAfter3 = $this->emergencyState->expireCallCount();

    $t->assertEquals($callsAfter1, $callsAfter3,
        'expireIfStale не вызывается повторно в течение throttle-окна');
}
```

---

## 3. Проблемы операционного уровня

### 3.1. Кап `/cis/sold` = 10000 — пагинация от начала, не от конца 🟠

**Что сломано.** `/cis/sold?skip=0&limit=100` возвращает **самые старые** проданные. Кап 10000 = первые 10000.

**Сценарий:**
- За 30 дней (retention ЛМ ЧЗ) продано **50000 КИ**.
- В строке воркера КИ, проданный 2 дня назад.
- При retry выгружаем первые 10000 (5-30-дневной давности) — **не находим** наш КИ.
- Отправляем `/cis/sell` повторно → ЛМ ЧЗ отвечает «уже продан» → `failed` после 3 попыток.

**Fix — пагинировать с конца:**

```php
// Сначала получаем общее число
$total = $this->lmChz->soldCount();

// Читаем последние 10000: skip начинается от total - 10000
$start = max(0, $total - 10000);
for ($skip = $start; $skip < $total; $skip += 100) {
    $page = $this->lmChz->sold($skip, 100);
    // ...
}
```

Если `/cis/sold` не поддерживает `count` — читать страницы до конца, запоминая последние 10000. Или — пагинация в обратном порядке (если есть параметр).

**Проверка:** уточнить в методичке ЛМ ЧЗ порядок выдачи `/cis/sold`. Если порядок неопределён — **нельзя** полагаться на «последние 10000». В этом случае — пагинировать **полностью** либо хранить локальный индекс отправленных КИ.

**Более надёжное решение:** вести локальную таблицу `marking_sell_sent` (КИ, отправленные в ЛМ ЧЗ, но не подтверждённые `done`). При retry — сначала проверять локальную таблицу, потом `/cis/sold`. Это устраняет зависимость от капа ЛМ ЧЗ.

---

### 3.2. `extendLease` в `ReturnWorker` — есть ли после рефакторинга? 🟠

**Что проверить.** Волна 2.2 добавила `extendLease()` в `process_returns.php` перед `/cis/returned`. Волна 2.3 **переписала** `process_returns.php` под `ReturnWorker`. По отчёту §2.1:
> «`process_returns.php` переписан под worker (claim-цикл + re-claim guard как в `process_sells.php`)».

Про `extendLease` в `ReturnWorker` — **не сказано**.

**Fix — проверить:**

```bash
grep -n "extendLease" Service/Marking/ReturnWorker.php
grep -n "extendLease" Service/Marking/process_returns.php
```

Если `extendLease` **нет** в `ReturnWorker::process()` → добавить перед вызовом `lmChz->returned()`:

```php
public function process(int $id): void
{
    $row = $this->queue->findById($id);
    // ...
    $this->queue->extendLease($id);   // ← обязательно

    try {
        $this->lmChz->returned($cisList);
        // ...
    }
}
```

---

### 3.3. Миграция `attempts` в return-очереди — не переводит `processing → pending` 🟠

**Что сломано.** Отчёт §4.1:
> «Зомби-строки с attempts=0 после деплоя reclaim'ятся как pending — повторный `/cis/returned` идемпотентен через сверку `/cis/sold`.»

Reclaim в `ReturnQueue` ищет `status='processing' AND heartbeat_at < NOW()`. Но у legacy-строк (волна 2.2) — **нет heartbeat_at** (колонка появилась в 2.3).

**Что произойдёт при миграции:**
1. `ALTER TABLE marking_return_queue ADD COLUMN attempts INT UNSIGNED NOT NULL DEFAULT 0`.
2. Существующие `status='processing'` с `heartbeat_at=NULL`.
3. Reclaim смотрит `heartbeat_at < NOW()` — для NULL это `NULL < NOW()` = **NULL** → не true.
4. Строка **никогда** не reclaim'ится.

**Fix — миграция должна явно перевести processing → pending:**

```sql
-- До reclaim: перевести legacy processing в pending
UPDATE marking_return_queue
SET status = 'pending', heartbeat_at = NULL
WHERE status = 'processing' AND heartbeat_at IS NULL;

-- Затем ALTER
ALTER TABLE marking_return_queue ADD COLUMN attempts INT UNSIGNED NOT NULL DEFAULT 0;
```

Плюс — то же самое для `marking_sell_queue`, если при деплое 2.2 → 2.3 есть зомби.

**Проверка:** запрос перед деплоем:

```sql
SELECT COUNT(*) FROM marking_return_queue
WHERE status = 'processing' AND heartbeat_at IS NULL;
```

Если > 0 — миграция обязательна.

---

### 3.4. Отчёт волны 2.3 §5 — хэши-плейсхолдеры 🟡

**Что сломано.** Таблица коммитов:
```
| `(текущий-1)` | feat(marking) | ... |
| `(текущий-2)` | docs(sprints/01) | ... |
```

Это не отчёт — это черновик. После коммита — заменить на реальные хэши:

```bash
git log --oneline -3
```

И обновить таблицу.

---

## 4. Сводная таблица

| # | Проблема | Приоритет | Файлы |
|---|----------|-----------|-------|
| 1.1 | Hybrid 203 не активирует emergency (возврат 2.2) | 🔴 | `MarkingCheckService::checkHybrid`, тест |
| 1.2 | `expireIfStale` не вызывается через cron | 🔴 | `cron/expire_emergency.php`, SPEC §9 |
| 2.1 | `expireIfStale` race с `activate` | 🔴 | `MarkingEmergencyState::expireIfStale`, тест |
| 2.2 | `activate` сбрасывает `started_at` | 🔴 | `MarkingEmergencyState::activate`, DDL, тест |
| 2.3 | ИНН не задан → emergency fail-open | 🟠 | `CdnService`, `MarkingCheckService`, тест |
| 2.4 | `expireIfStale` — нагрузка на БД | 🟡 | `MarkingCheckService::checkHybrid`, тест |
| 3.1 | Кап `/cis/sold` — пагинация от начала | 🟠 | `SellWorker`, `ReturnWorker`, возможно локальная таблица |
| 3.2 | `extendLease` в ReturnWorker после рефакторинга | 🟠 | `ReturnWorker::process` |
| 3.3 | Миграция `attempts` не переводит legacy processing | 🟠 | `sql/marking_tables.sql` |
| 3.4 | Хэши коммитов — плейсхолдеры | 🟡 | `ОТЧЕТ_ВОЛНЫ_2_3.md` §5 |

---

## 5. Диагностика перед патчами

```bash
# 1. Hybrid 203
grep -n "203" Service/Marking/MarkingCheckService.php

# 2. expireIfStale — где вызывается
grep -rn "expireIfStale" Service/ cron/ config/

# 3. activate — что обновляет
grep -A 10 "function activate" Models/MarkingEmergencyState.php

# 4. ИНН в onEmergency203
grep -A 8 "function onEmergency203" Service/Marking/CdnService.php

# 5. extendLease в ReturnWorker
grep -n "extendLease" Service/Marking/ReturnWorker.php Service/Marking/SellWorker.php

# 6. /cis/sold пагинация
grep -B2 -A10 "sold(" Service/Marking/SellWorker.php

# 7. Legacy processing в БД (если БД есть)
# SELECT COUNT(*) FROM marking_return_queue WHERE status='processing' AND heartbeat_at IS NULL;

# 8. Baseline
php tests/run_all.php 2>&1 | tail -5
```

Ожидание: baseline 418 PASS; ответы на п.1, п.5 — что реально в коде.

---

## 6. Порядок применения

1. **2.2 (`activate` не сбрасывает `started_at`)** — критический, ведёт к вечному emergency.
2. **2.1 (`expireIfStale` race)** — критический, та же тема.
3. **1.1 (hybrid 203)** — возврат ранее закрытого, ломает hybrid.
4. **1.2 (cron для expire)** — операционный, обязателен в деплое.
5. **3.2 (`extendLease` в ReturnWorker)** — быстрая проверка + патч.
6. **3.3 (миграция legacy processing)** — обязательна при деплое.
7. **2.3 (ИНН fail-closed)** — важный footgun.
8. **3.1 (кап `/cis/sold`)** — архитектурный, требует решения.
9. **2.4 (throttle expire)** — оптимизация.
10. **3.4 (хэши в отчёте)** — косметика.

---

## Что дальше

Готов сразу:
- **Патч 2.2** — `activate()` без сброса `started_at` + DDL `last_seen_at` + тест.
- **Патч 2.1** — `expireIfStale()` с race-guard через `updated_at` + тест.
- **Патч 1.1** — hybrid 203 → activate + тест.
- **Патч 1.2** — `cron/expire_emergency.php` + регистрация + SPEC §9.
- **Патч 3.2** — диагностика + при необходимости extendLease в ReturnWorker.
- **Патч 3.3** — миграция legacy processing в DDL.

Скажите, что применять первым. По приоритету — **2.2** (started_at) и **2.1** (race) критичны, они ведут к fail-open emergency.