# Проверка COMMENTS-13 + COMMENTS-14 — что реально осталось

Волна 2.4 закрыла 7 из 8 замечаний COMMENTS-12 + 7 из 7 COMMENTS-14. Прогресс есть. Но есть **более важное наблюдение**: цикл не сходится. 7 волн подряд каждая вносит 5–8 новых проблем. Разберу оставшееся, а затем — что делать с циклом.

---

## 1. Критическая проблема — 2.6 «рекомендуется», но не сделана 🔴

**Что в отчёте §10:**
> «2.6 Tail-scan FIFO-независимость | 🟠 DDL `marking_sell_pending_ack` (kind, cis, check_uuid, acked) добавлен в SQL + SQLite; **интеграция в воркеры — рекомендуется как следующий архитектурный шаг**»

**Что не так.** DDL существует, но:
- Нет модели `MarkingSellPendingAck`.
- Нет записи в таблицу при `/cis/sell` / `/cis/returned`.
- Нет чтения при retry.
- Таблица — мёртвый артефакт.

**Почему это критично.** Вся защита от FIFO-зависимости `tail-scan` (§2.6 COMMENTS-12) — **в DDL, но не в коде**. При этом отчёт закрывает 2.6 как «🟠 DDL добавлен» — формально, но не по существу.

Если ЛМ ЧЗ вернёт `/cis/sold` в обратном порядке (Q10 — открыт), **всё, что было закрыто в 2.4 (tail-scan), сломается** — retry снова отправит `/cis/sell` для уже проданных КИ.

**Fix — либо сделать, либо не заявлять как «закрыто»:**

**Вариант A — реализовать полностью:**

```php
// Models/MarkingSellPendingAck.php
final class MarkingSellPendingAck
{
    public function __construct(private \PDO $pdo) {}

    public function record(string $kind, array $cisList, string $checkUuid): void
    {
        // kind: 'sell' | 'return'
        $stmt = $this->pdo->prepare(
            "INSERT INTO marking_sell_pending_ack (kind, cis, check_uuid, acked)
             VALUES (:kind, :cis, :check_uuid, 0)"
        );
        foreach ($cisList as $cis) {
            $stmt->execute(['kind' => $kind, 'cis' => $cis, 'check_uuid' => $checkUuid]);
        }
    }

    public function acknowledge(array $cisList): void
    {
        $in = implode(',', array_fill(0, count($cisList), '?'));
        $this->pdo->prepare(
            "UPDATE marking_sell_pending_ack SET acked = 1 WHERE cis IN ($in)"
        )->execute($cisList);
    }

    public function pending(string $checkUuid): array
    {
        $stmt = $this->pdo->prepare(
            "SELECT cis FROM marking_sell_pending_ack
             WHERE check_uuid = ? AND acked = 0"
        );
        $stmt->execute([$checkUuid]);
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }
}
```

В `SellWorker::process()`:
```php
// Перед первым /cis/sell:
$this->pendingAck->record('sell', $cisList, $row['check_uuid']);

// При retry:
$stillPending = $this->pendingAck->pending($row['check_uuid']);
if (empty($stillPending)) {
    $this->queue->markDone($id);   // всё уже подтверждено
    return;
}
$cisList = $stillPending;   // отправляем только неподтверждённые

// После успеха:
$this->pendingAck->acknowledge($cisList);
```

**Вариант B — убрать DDL и откатить 2.6 в статус «не закрыто»:**
```sql
DROP TABLE marking_sell_pending_ack;
```
И в §10 COMMENTS-13 явно: «2.6 — открыто, в бэклоге».

**Что рекомендую:** **Вариант A** — 40 строк кода, закрывает архитектурный вопрос надолго. **Убирает Q10 из блокеров.**

---

## 2. Несогласованность отчёта — три разных числа тестов 🟠

**Что в тексте одного документа:**
- Заголовок: «Тесты: **426 PASS / 0 FAIL (после COMMENTS-14: +1 тест)**».
- §1: «Завершённые задачи (8 из 8)».
- §5: «`PASS: 425 FAIL: 0`».
- §10 footer: «Тесты: **426 PASS / 0 FAIL**».

**Проблема.** Читатель не может понять реальное число. Это **регресс волны 2.2/2.3**, где счётчики сходились.

**Fix:** пересчитать фактически:

```bash
php tests/run_all.php --json | jq '.passed'
php tests/run_all.php 2>&1 | tail -1
```

Затем — обновить **все три места** (заголовок, §5, §10) одним числом.

---

## 3. `__unknown__` sentinel — cross-tenant contamination 🟠

**Что в §10 2.3:**
> «`CdnService::onEmergency203()` + `MarkingCheckService::activateEmergency203()` — `__unknown__` + `activate()`; `gisEmergencyActive('')` → `isActive('__unknown__')`»

**Что не так.** Sentinel `__unknown__` — **общий для всех касс без ИНН**. Сценарий:

1. Магазин A (без `COMPANY_INN`) получает 203 → `activate('__unknown__')`.
2. Магазин B (тоже без ИНН, но **в другом юрлице**) читает `isActive('__unknown__')` → **true**.
3. Магазин B уходит в `allowAll` — продаёт без проверок КМ. Хотя у него аварии нет.

**Плюс** — если позже зададут `COMPANY_INN`, старая запись `__unknown__` **не удаляется**. Она остаётся активной, но `isActive('7712345678')` вернёт `false` — то есть emergency уже не сработает для правильного ИНН.

**Fix — либо явный sentinel cleanup, либо отказ от sentinel:**

**Вариант A** — sentinel только в пределах процесса + TTL:

```php
private function onEmergency203(string $endpoint, string $host): void
{
    $inn = (string) MarkingConfig::get('inn');
    if ($inn === '') {
        // Не активируем глобальный sentinel — только метрика + лог
        $this->logger->critical('emergency_203_no_inn', ['endpoint' => $endpoint]);
        $this->metrics->inc('marking_emergency_no_inn_total');
        // Возвращаем временный in-memory флаг:
        $this->transientEmergency203 = time() + 3600;   // 1 час
        return;
    }
    $this->emergencyState->activate($inn, "HTTP 203 from $endpoint at $host");
}
```

Плюс `isEmergencyActive()` учитывает `transientEmergency203`:
```php
public function isEmergencyActive(): bool
{
    if ($this->transientEmergency203 > time()) return true;
    $inn = (string) MarkingConfig::get('inn');
    return $inn !== '' && $this->emergencyState->isActive($inn);
}
```

**Вариант B** — sentinel, но с явной очисткой при появлении настоящего ИНН:

В `assertConfig()` при старте:
```php
if (MarkingConfig::get('inn') !== '') {
    // Чистим устаревший sentinel
    $this->pdo->exec("DELETE FROM marking_emergency_state WHERE inn = '__unknown__'");
}
```

**Рекомендую A** — локальный флаг без записи в БД, изоляция по процессу.

---

## 4. `maybeExpireEmergency()` — public для тестов, но кем вызывается? 🟠

**Что в §10 2.2:**
> «✅ Убран из `check()`/`checkHybrid()`; `maybeExpireEmergency()` **оставлен (теперь public для тестов)**»

**Что не так.** Если функция public **только** для тестов и **не вызывается** из production-кода — это dead code в проде. Grep:

```bash
grep -rn "maybeExpireEmergency" Service/ cron/
```

Ожидаемые результаты:
- **Вызывается из cron** — тогда она не dead, public для тестов оправдан.
- **Не вызывается нигде** — удалить функцию или сделать private и покрыть косвенно.

**Fix:** либо вызвать из `cron/expire_emergency.php` (там throttle-семантика не нужна — cron раз в час), либо удалить.

---

## 5. `getActiveWithLastSeen()` — dead method 🟠

**Что в §10 2.7:**
> «`getActiveWithLastSeen()` в `MarkingEmergencyState` (мониторинг: активные аварии + `minutes_since_last_203`)»

**Проблема.** Метод добавлен, но **не вызывается** ни из одного endpoint/дашборда/скрипта. Это dead code.

**Fix — либо wire up, либо удалить:**

Если wire up — добавить health-check endpoint:

```php
// health/marking.php (или аналог)
$state = new MarkingEmergencyState($pdo);
$active = $state->getActiveWithLastSeen();
header('Content-Type: application/json');
echo json_encode([
    'active_emergencies' => array_map(fn($r) => [
        'inn' => $r['inn'],
        'minutes_since_last_203' => $r['minutes_since_last_203'],
        'started_at' => $r['started_at'],
    ], $active),
]);
```

Если удалить — убрать метод и колонку `last_seen_at` из DDL (или оставить, но в SPEC пометить как «reserved for monitoring dashboard, not used yet»).

**Рекомендую:** wire up — health endpoint полезен.

---

## 6. Cron — `exit(1)` без мониторинга 🟠

**Что в §10 2.5:**
> «✅ `flock(LOCK_EX | LOCK_NB)` + `try/catch` + `exit(1)` + `MarkingLogger::warning()`»

**Что не так.** `exit(1)` — правильный сигнал для cron-супервизора. Но **кто** его ловит?

- Если crontab настроен с `MAILTO=` — письма уйдут. Но этого в SPEC §9 **нет**.
- Если есть Sentry/monit/systemd-timer — надо явно.

**Fix — SPEC §9, раздел «Мониторинг cron»:**

```
Регистрация cron:
0 * * * * /usr/bin/php /path/to/cron/expire_emergency.php >> /var/log/marking-cron.log 2>&1

Требуется:
- MAILTO=ops@company.com в crontab ИЛИ
- systemd-timer с OnFailure=+ unit, отправляющим в Sentry ИЛИ
- monit check on exit code 1

Без мониторинга exit(1) — молчаливый отказ.
```

Плюс — сам cron **всегда** должен логировать:

```php
if ($expired > 0) {
    MarkingLogger::warning('emergency_auto_expired', ['count' => $expired, 'inns' => $inns]);
}
MarkingLogger::info('expire_emergency_cron_ok', ['expired' => $expired]);
```

Иначе успешный прогон — тихий.

---

## 7. Пути в §9 — неверные 🟡

**Что в §9 отчёта:**
```bash
php -l Service/Marking/MarkingEmergencyState.php
```

**Но** по §2 файл лежит в `Models/MarkingEmergencyState.php`, а не `Service/...`.

**Fix:** обновить пути в §9. Проверка:

```bash
git ls-files | grep -E "(MarkingEmergencyState|CdnService|MarkingCheckService|ReturnWorker|SellWorker)"
```

Все существующие файлы должны быть указаны с правильными путями.

---

## 8. Дата `2025-01-17` — регресс 🟡

**Что в COMMENTS-13 §1:**
> «**Дата:** 2025-01-17»

**Проблема.** Все остальные документы спринта (COMMENTS-8..12, ОТЧЕТ, SPEC, WORK_PLAN) датированы **2026**. Год 2025 в шапке нового документа — либо опечатка, либо копипаста из старого шаблона.

**Fix:** `2026-01-17` или `2026-10-05` (по дате волны). Одно число, согласованное со всей остальной документацией.

---

## 9. `marking_sell_pending_ack` — колонка `kind` без спецификации 🟡

**Что в DDL:**
```sql
CREATE TABLE marking_sell_pending_ack (
    kind ..., cis ..., check_uuid ..., acked ...
);
```

**Проблема.** Что за `kind`? Какие значения? В коде не используется (см. §1). Если это `sell|return` — зафиксировать в SPEC §2.x. Если ничего — убрать колонку.

**Fix:** добавить комментарий в DDL + SPEC:

```sql
kind ENUM('sell','return') NOT NULL COMMENT 'операция: регистрация продажи или возврат',
```

---

## 10. Мета-наблюдение: цикл не сходится

**Данные по волнам:**

| Волна | PASS | Новых проблем | Природа проблем |
|-------|------|---------------|-----------------|
| 2 | 298 | 15 (COMMENTS-8) | Накопленные в 1 волне |
| 2.1 | 321 | 8 (COMMENTS-10) | Введены фиксом 2 |
| 2.2 | 364 | 8 (COMMENTS-11/12) | Введены фиксами 2.1, 2.2 |
| 2.3 | 418 | 8 (COMMENTS-12) | Введены фиксами 2.2, 2.3 |
| 2.4 | 426 | 8 (COMMENTS-13/14) | Введены фиксами 2.3, 2.4 |

**Тренд:** каждая волна вносит столько же проблем, сколько закрывает. Цикл **расходится**, а не сходится.

**Что делать:**

1. **Прекратить точечные фиксы.** Написать **один** детерминированный race-suite, который покрывает все сценарии из §3.4 SPEC одновременно:
   - Lease истёк во время вызова.
   - Второй воркер reclaim'ит в это время.
   - Retry `/cis/sell` при потерянном ответе.
   - Retry `/cis/returned` при потерянном ответе.
   - Emergency 203 из hybrid + online-only + cron.
   - Auto-expire при отсутствии confirmation.
   - Pagination `/cis/sold` при обрезанном FIFO.

2. **Запустить его в CI** — не пропускать при коммите.

3. **Добавить mutation-test** для покрытия: не «сколько assert'ов», а «какие изменения ломают тесты». Если фикс 2.4 не ломает ни один тест при откате — тесты не покрывают фикс.

4. **Остановить волновой цикл.** Один большой прогон вместо 6 маленьких.

---

## Сводка

| # | Проблема | Приоритет | Что делать |
|---|----------|-----------|------------|
| 1 | 2.6 (FIFO-независимость) — DDL без интеграции | 🔴 | Реализовать `MarkingSellPendingAck` или убрать DDL |
| 2 | Три разных числа тестов в одном документе | 🟠 | Сверить фактом `php tests/run_all.php --json` |
| 3 | `__unknown__` — cross-tenant contamination | 🟠 | Local-флаг вместо sentinel-ИНН |
| 4 | `maybeExpireEmergency()` — dead в проде? | 🟠 | Grep; удалить или wire в cron |
| 5 | `getActiveWithLastSeen()` — нет вызова | 🟠 | Health-endpoint или удалить |
| 6 | Cron `exit(1)` без мониторинга | 🟠 | SPEC §9: MAILTO/systemd OnFailure |
| 7 | Пути `Service/Marking/MarkingEmergencyState.php` неверны | 🟡 | `Models/...` в §9 |
| 8 | Дата `2025-01-17` | 🟡 | Привести к 2026 |
| 9 | `kind` без спецификации | 🟡 | Комментарий ENUM или убрать |
| 10 | Цикл не сходится | 🔴 | Один big-bang race-suite вместо волн |

---

## Что делать прямо сейчас

**Остановиться и не начинать волну 2.5.**

1. Свести **один** `RaceSuiteTest` — 15–20 сценариев, покрывающих весь жизненный цикл:
   - lease / heartbeat / reclaim / extend
   - retry sell + return при потерянных ответах
   - emergency 203 (hybrid, online-only, /cdn/info, /cdn/health/check)
   - auto-expire (7 дней, 3 дня после confirm)
   - cross-tenant emergency isolation
   - pagination /cis/sold при обратном порядке
   - ИНН отсутствует → transient, не sentinel

2. Прогнать suite. **Все failing — это реальные баги**, а не новые тесты под текущую реализацию.

3. Починить. **Один коммит, один прогон, 0/0.**

4. Замерить coverage — не «сколько assert'ов», а какая доля строк `Service/Marking/` покрыта.

Только после этого — возвращаться к ревью. Иначе волна 2.5 внесёт ещё 5 проблем, волна 2.6 — ещё 5.

---

## Что могу написать прямо сейчас

Готов подготовить **`tests/Integration/RaceSuiteTest.php`** — единый suite на 15–20 сценариев из списка выше. Он покажет реальное состояние системы, а не «сколько assert'ов прошло». Это остановит волновой цикл.

Или, если приоритет — закрыть текущие 9 находок: начну с **#1** (2.6 — FIFO-независимость) как единственного реально критического архитектурного пробела.

Скажите, что делать.