# Проверка волны 2.4 — оставшиеся проблемы

Волна 2.4 закрыла большинство находок COMMENTS-12. Счёт 425 сходится, но **не 8/8** — в отчёте §1 перечислено 8 пунктов, а COMMENTS-12 их было 10. **Пропущен 3.2** (`extendLease` в `ReturnWorker`), плюс есть **четыре новых проблемы**, введённые самими патчами 2.4.

---

## 1. Что закрыто корректно

| Пункт | Статус |
|-------|--------|
| 2.1 race guard в `expireIfStale` (`updated_at < NOW - 30s`) | ✅ |
| 2.2 `activate()` не сбрасывает `started_at` | ✅ |
| 2.3 No-INN → metric + CRITICAL log | ✅ (частично, см. 2.3 ниже) |
| 2.4 throttle per-process | ⚠️ не работает под FPM (см. 2.2) |
| 3.1 pagination `/cis/sold` с конца | ⚠️ зависит от FIFO (см. 2.1) |
| 3.3 legacy zombie backfill SQL | ⚠️ не в migration runner (см. 2.4) |
| 1.1 hybrid 203 → activate | ✅ (я ошибочно флагнул это в волне 2.3 — здесь подтверждено, что уже работало с волны 2.2) |
| 1.2 cron `expire_emergency.php` | ⚠️ деталей нет (см. 2.5) |

---

## 2. Что осталось

### 2.1. `extendLease` в `ReturnWorker` — не в списке 8/8 🔴

**Что сломано.** В COMMENTS-12 было 10 пунктов, включая:
> **3.2** `extendLease` в ReturnWorker после рефакторинга — 🟠

В отчёте волны 2.4 §1 перечислено **8 пунктов** — 3.2 отсутствует. Либо он пропущен случайно, либо отброшен без обоснования.

**Сценарий, если пропущен:**
1. Воркер берёт return-строку, `claim` ставит lease `NOW + 15 min`.
2. `/cis/returned` для батча 100 КИ + сетевые ретраи внутри curl → **может затянуться**.
3. Lease истекает → второй воркер reclaim'ит → двойной возврат.

**Fix — проверить и, если нужно, добавить:**

```bash
grep -n "extendLease" Service/Marking/ReturnWorker.php
```

Если пусто — в `ReturnWorker::process()` перед `lmChz->returned()`:

```php
public function process(int $id): void
{
    $row = $this->queue->findById($id);
    // ...
    $this->queue->extendLease($id);   // ← обязательно, симметрия с SellWorker

    try {
        $this->lmChz->returned($cisList);
        // ...
    }
}
```

**Проверка:**

```php
public function testReturnWorkerExtendsLease(TestHarness $t): void
{
    $id = $this->queue->enqueue(7001, 'ck-1', ['cis-1']);
    $this->queue->claimNextPending();
    $before = $this->queue->findById($id)['heartbeat_at'];

    $this->lmChz->mock->delay('returned', 200); // 200 мс
    (new ReturnWorker(...))->process($id);

    $after = $this->queue->findById($id)['heartbeat_at'];
    $t->assertTrue($after > $before, 'lease продлён');
}
```

---

### 2.2. Throttle — под FPM это no-op, под CLI — работает 🟠

**Что сломано.** Отчёт §7:
> «Throttle per-process — под FPM (1 процесс = 1 запрос) throttle не накапливается — cron (1.2) — основной периодический путь»

Это **признание, что код в `maybeExpireEmergency()` под FPM не работает**: статическая переменная `$lastEmergencyExpireAt` перезаписывается каждым запросом (FPM = новый процесс на запрос). Значит:
- `check()` под FPM всё равно вызывает `expireIfStale()` **каждый раз** (UPDATE по индексу).
- Комментарий в коде говорит «throttle 5 мин» — **вводит в заблуждение** будущего разработчика.

**Fix — убрать throttle из CLI-only путей или сделать shared:**

**Вариант A — убрать throttle из `check()/checkHybrid()` и положиться только на cron:**

```php
public function checkHybrid(...): MarkingCheckResult
{
    // Убрать: maybeExpireEmergency()
    // Работает cron — раз в час.
    // ...
}
```

Это правильно: `expireIfStale` идемпотентна, cron запускается раз в час — этого достаточно. В `check()` вызов **не нужен**.

**Вариант B — throttle через БД-флаг:**

```sql
UPDATE marking_emergency_state
SET is_active = 0, ...
WHERE is_active = 1
  AND updated_at < NOW() - INTERVAL 30 SECOND
  AND ...
```

Уже есть `updated_at < NOW() - INTERVAL 30 SECOND`. При каждой проверке — 1 UPDATE. Под нагрузкой 1000 касс × 100 чеков/час = 100k UPDATE/час. Это **много**.

**Вариант C — throttle через APCu/Redis (shared):**

```php
if (apcu_fetch('marking_expire_throttle')) {
    return;
}
// ... expireIfStale()
apcu_store('marking_expire_throttle', 1, 300);
```

**Рекомендация:** **Вариант A** — самый простой и правильный. Cron раз в час + `expireIfStale` идемпотентна. В `check()` — не вызывать.

---

### 2.3. No-INN — fail-open с побочным эффектом 🔴

**Что сломано.** Отчёт §7:
> «No-INN → no throw | Документировано в коде: 203 уже возвращает EMERGENCY allowAll, throw бы заблокировал фискализацию»

Аргумент верный для **одного чека**. Но не учтён **цикл**:

1. `COMPANY_INN` не задан.
2. Приходит 203 от `/codes/check`.
3. `onEmergency203` не может активировать emergency (нет ИНН) → metric + log, но `is_active` не выставлен.
4. `check()` возвращает `EMERGENCY allowAll` → чек проходит.
5. Следующий чек → снова HTTP к ГИС МТ → снова 203 → снова п.3.
6. ...

Итог: во время аварии ГИС МТ **каждый чек** делает HTTP-запрос, который вернёт 203, тратит 200–500 мс, снова fail-open. Не блокирует, но неэффективно.

**Fix — использовать sentinel-ИНН при отсутствии реального:**

```php
private function onEmergency203(string $endpoint, string $host): void
{
    $inn = (string) MarkingConfig::get('inn');
    if ($inn === '') {
        $this->logger->critical('emergency_203_no_inn', ['endpoint' => $endpoint]);
        $this->metrics->inc('marking_emergency_no_inn_total');
        $inn = '__unknown__';   // ← sentinel: один общий emergency для всех касс без ИНН
    }

    try {
        $this->emergencyState->activate($inn, "HTTP 203 from $endpoint at $host");
    } catch (\Throwable $e) {
        $this->logger->error('emergency_state_activate_failed', [
            'inn' => $inn, 'endpoint' => $endpoint, 'error' => $e->getMessage(),
        ]);
    }
}
```

Тогда emergency активируется и подхватывается в `checkHybrid()` (через `isActive`) — цикл ГИС МТ прерывается.

Плюс — CRITICAL-алерт на `marking_emergency_no_inn_total > 0`. Метрика уже есть, но **нужен alert**: в SPEC §5 добавить.

**Проверка:**

```php
public function test203WithoutInnActivatesSentinelEmergency(TestHarness $t): void
{
    MarkingConfig::set('inn', '');
    $this->transport->setResponse('online', new HttpResponse(203, ''));

    $svc->checkHybrid(['cis-1'], '1234567890123456');

    $t->assertTrue($this->emergencyState->isActive('__unknown__'),
        'sentinel-emergency активирован');
}
```

---

### 2.4. Legacy zombie backfill — «документирован», но не запущен 🟠

**Что сломано.** Отчёт §1:
> «3.3 Legacy zombie backfill | ✅ Документирован | SQL в `marking_tables.sql` (COMMENTS-13 3.3)»

SQL **в файле**, но не **в migration runner**. Что произойдёт при деплое:
1. Разработчик видит SQL в `marking_tables.sql`.
2. **Возможно, не читает комментарии**.
3. Применяет только ALTER/CREATE, пропуская UPDATE.
4. Legacy processing-строки с `heartbeat_at IS NULL` **никогда не reclaim'ятся** (reclaim фильтрует по `heartbeat_at < NOW()`, а NULL < NOW() = NULL, не true).

**Fix — авто-применение через migration:**

Если в проекте **нет** migration-системы (plain PHP), добавить в `sql/marking_tables.sql` **идемпотентный** блок, который сам себя защищает:

```sql
-- Legacy zombie backfill (COMMENTS-13 3.3) — идемпотентно:
-- переводим processing+NULL heartbeat в pending (reclaim подхватит)
UPDATE marking_sell_queue
SET status = 'pending', heartbeat_at = NULL
WHERE status = 'processing' AND heartbeat_at IS NULL;

UPDATE marking_return_queue
SET status = 'pending', heartbeat_at = NULL
WHERE status = 'processing' AND heartbeat_at IS NULL;
```

Плюс **добавить в SPEC §9** явный шаг:

```
Деплой:
1. ALTER TABLE (idempotent)
2. Backfill legacy zombies (idempotent UPDATE выше)
3. Проверить: SELECT COUNT(*) FROM marking_sell_queue WHERE status='processing' AND heartbeat_at IS NULL → 0
```

**Проверка:**

```sql
SELECT COUNT(*) AS zombies
FROM marking_sell_queue
WHERE status = 'processing' AND heartbeat_at IS NULL;
-- Ожидание: 0
```

---

### 2.5. `cron/expire_emergency.php` — нет деталей 🟠

**Что проверить.** Отчёт §2 упоминает файл, но не описывает:
- **Что при недоступности БД?** Если PDO кидает — cron упадёт, alert не отправится.
- **Log?** Куда пишет?
- **Lock?** Если два cron запустятся одновременно (перекрытие запусков), оба сделают UPDATE — не критично (идемпотентно), но лучше сериализовать.
- **Exit codes?** `0` при успехе, ≠0 при ошибке — чтобы cron-мониторинг (Sentry/monit) ловил сбой.
- **Обработка `is_active=0`?** Если никого не expire — OK, тихий выход.

**Fix — обернуть в try/catch + lock + метрики:**

```php
#!/usr/bin/env php
<?php
declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

use Service\Marking\MarkingLogger;

$logger = new MarkingLogger();

// Простой файловый lock
$lockFile = sys_get_temp_dir() . '/marking_expire_emergency.lock';
$lock = fopen($lockFile, 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
    $logger->warning('expire_emergency_lock_busy');
    exit(0);   // не ошибка, просто пропуск
}

try {
    $state = new \Models\MarkingEmergencyState(\Models\Base::pdo());
    $expired = $state->expireIfStale();
    if ($expired > 0) {
        $logger->warning('emergency_auto_expired', ['count' => $expired]);
    }
    exit(0);
} catch (\Throwable $e) {
    $logger->error('expire_emergency_failed', ['error' => $e->getMessage()]);
    exit(1);
} finally {
    flock($lock, LOCK_UN);
    fclose($lock);
}
```

**Регистрация cron:**

```
0 * * * * /usr/bin/php /path/to/cron/expire_emergency.php >> /var/log/marking-cron.log 2>&1
```

**Проверка:**

```bash
php cron/expire_emergency.php; echo $?   # ожидание: 0
# Без БД:
DATABASE_DSN=invalid php cron/expire_emergency.php; echo $?   # ожидание: 1
```

---

### 2.6. Tail-scan `/cis/sold` — гарантирован ли FIFO? 🟠

**Что сломано.** Отчёт §8:
> «Q10 ... Порядок `/cis/sold` — гарантированно ли `skip=0` возвращает **самые старые** (FIFO)?»

Вопрос **не закрыт**, но код **уже написан** в предположении FIFO (`start = max(0, total - cap)`). Если ЛМ ЧЗ вернёт в обратном порядке — `total - cap` укажет на **самые старые**, а не самые новые. Тогда retry не найдёт свои КИ → повторный `/cis/sell`.

**Fix — не полагаться на FIFO:**

**Вариант A — локальная таблица sent-but-unacked:**

```sql
CREATE TABLE marking_sell_pending_ack (
    cis VARCHAR(255) PRIMARY KEY,
    sent_at TIMESTAMP NOT NULL,
    check_uuid VARCHAR(36) NOT NULL,
    INDEX idx_sent (sent_at)
);
```

При `/cis/sell` — **до** отправки INSERT в эту таблицу. При успешном ответе — DELETE. При retry — сначала искать в этой таблице, потом (если там нет) — в `/cis/sold` с полной пагинацией.

**Вариант B — обход всей выдачи `/cis/sold`:**

```php
$found = [];
$skip = 0;
while (true) {
    $page = $this->lmChz->sold($skip, 1000);
    if (empty($page->cisList())) break;
    $found = array_merge($found, $page->cisList());
    $skip += 1000;
    if ($skip > 100_000) {   // hard cap
        $this->metrics->inc('marking_sell_sold_hard_cap_exceeded');
        break;
    }
}
$intersection = array_intersect($cisList, $found);
```

Дороже, но не зависит от порядка. При 50000 проданных — 50 запросов. Приемлемо при retry (редко).

**Рекомендация:** **Вариант A** — local-audit-table. Один раз написать — надолго закрыть вопрос.

**Проверка:** `grep -n "marking_sell_pending_ack" Service/Marking/SellWorker.php` — должно быть вставлено перед `lmChz->sell()`.

---

### 2.7. `last_seen_at` добавлен, но не используется 🟡

**Что сломано.** Отчёт §7:
> «`last_seen_at` | Добавлен в DDL, но НЕ используется в `expireIfStale()` — только для мониторинга»

**Но мониторинг тоже не добавлен.** Колонка пишется при каждом `activate()` и всё. Мёртвый код.

**Fix — либо использовать, либо убрать:**

Использование: в SPEC §5 добавить метрику «частота 203 по ИНН»:

```sql
SELECT inn, 
       TIMESTAMPDIFF(MINUTE, started_at, last_seen_at) AS last_203_ago_min
FROM marking_emergency_state
WHERE is_active = 1
```

Дашборд «активные аварии с последним 203» — полезно оператору.

Или убрать колонку (не коммитить).

---

## 3. Сводная таблица

| # | Проблема | Приоритет | Файлы |
|---|----------|-----------|-------|
| 2.1 | `extendLease` в `ReturnWorker` — не в 8/8 | 🔴 | `ReturnWorker::process`, тест |
| 2.2 | Throttle под FPM — no-op, вводит в заблуждение | 🟠 | `MarkingCheckService::maybeExpireEmergency`, комментарии |
| 2.3 | No-INN → emergency не активируется (цикл 203) | 🔴 | `CdnService::onEmergency203`, sentinel-ИНН, тест |
| 2.4 | Legacy backfill — в файле, но не в runner | 🟠 | `sql/marking_tables.sql`, SPEC §9 |
| 2.5 | `cron/expire_emergency.php` — без lock/error/log | 🟠 | cron-скрипт |
| 2.6 | Tail-scan зависит от FIFO (Q10) | 🟠 | `SellWorker`/`ReturnWorker` → локальная таблица |
| 2.7 | `last_seen_at` — dead column | 🟡 | DDL или дашборд |

---

## 4. Диагностика перед патчами

```bash
# 1. extendLease в ReturnWorker
grep -n "extendLease" Service/Marking/ReturnWorker.php Service/Marking/SellWorker.php

# 2. Throttle комментарий
grep -B2 -A5 "maybeExpireEmergency\|lastEmergencyExpireAt" Service/Marking/MarkingCheckService.php

# 3. No-INN
grep -A10 "function onEmergency203" Service/Marking/CdnService.php

# 4. Legacy backfill в DDL
grep -n "backfill\|legacy\|heartbeat_at IS NULL" docs/sprints/01/sql/marking_tables.sql

# 5. Cron
cat cron/expire_emergency.php | head -40

# 6. Пагинация /cis/sold
grep -B2 -A15 "soldIntersection" Service/Marking/SellWorker.php

# 7. last_seen_at — где пишется/читается
grep -rn "last_seen_at" Models/ Service/ sql/

# 8. Baseline
php tests/run_all.php 2>&1 | tail -3
```

Ожидание по каждому пункту — либо «уже корректно», либо «патч обязателен».

---

## 5. Порядок применения

1. **2.1** (`extendLease` в ReturnWorker) — критическая симметрия, может быть просто забыт.
2. **2.3** (No-INN sentinel) — критический fail-open цикл.
3. **2.5** (cron: lock/error/log) — операционная зрелость.
4. **2.4** (backfill в runner + SPEC §9) — безопасность деплоя.
5. **2.2** (throttle под FPM) — убрать из `check()` или перенести в APCu.
6. **2.6** (FIFO-независимость) — архитектурная, требует таблицы.
7. **2.7** (`last_seen_at`) — использовать или убрать.

---

## 6. Что дальше

Готов сразу:
- **Патч 2.1** — диагностика + `extendLease` в `ReturnWorker` + тест.
- **Патч 2.3** — sentinel-ИНН `__unknown__` + тест + SPEC §5 alert.
- **Патч 2.5** — cron с lock/error/log.
- **Патч 2.4** — идемпотентный backfill в DDL + SPEC §9.
- **Патч 2.2** — убрать throttle из `check()`.
- **Патч 2.6** — таблица `marking_sell_pending_ack` + интеграция в `SellWorker/ReturnWorker`.

Начинать с **2.1** — быстрая проверка (одна grep) и, вероятно, быстрое исправление. Затем **2.3** — критический, но 5-строчный патч.