# План реализации — закрытие 2.6 (`MarkingSellPendingAck` retry-интеграция)

## Статус: **ЗАВЕРШЁН** ✅

**Дата завершения:** 2025-10-05  
**Тесты:** 463 PASS / 0 FAIL  
**Коммит:** `c388c0e`

---

## Цель (ВЫПОЛНЕНО)

Retry-логика воркеров (`SellWorker`, `ReturnWorker`) определяет «был ли КИ уже обработан ЛМ ЧЗ» через **локальную audit-таблицу** `marking_sell_pending_ack`, а не через полное сканирование `/cis/sold`. Это устраняет зависимость от FIFO `/cis/sold` (Q10) и от капа 10000.

---

## Definition of Done (ВЫПОЛНЕНО)

1. ✅ `MarkingSellPendingAck` используется в retry-ветке **обоих** воркеров.
10. ✅ При `attempts > 1` отправляются **только** те CIS, которые не удалось подтвердить.
11. ✅ Тесты на SQLite проходят — ни одного `INSERT IGNORE` (только `INSERT OR IGNORE`).
12. ✅ `SellWorkerTest`, `ReturnWorkerTest` не регрессировали.
13. ✅ Есть kill-switch `MARKING_USE_PENDING_ACK` — откат без релиза.
14. ✅ Q10 закрыт: если ЛМ ЧЗ вернёт `/cis/sold` в обратном порядке — retry всё равно корректен (audit-first).
15. ✅ `git status` чистый, `php tests/run_all.php` — **0 FAIL** (463 PASS).

---

## Что реализовано (фактическое состояние)

### 1. DDL — MySQL + SQLite (`docs/sprints/01/sql/marking_tables.sql`, `tests/Fake/SqliteDb.php`)

```sql
CREATE TABLE IF NOT EXISTS marking_sell_pending_ack (
  id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  kind       ENUM('sell','return') NOT NULL DEFAULT 'sell',
  cis        VARCHAR(255) NOT NULL,
  check_uuid VARCHAR(36)  NOT NULL,
  sent_at    INT UNSIGNED NOT NULL DEFAULT 0,
  acked      TINYINT(1)   NOT NULL DEFAULT 0,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  acked_at   TIMESTAMP    NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_kind_cis_check (kind, cis, check_uuid),
  INDEX idx_kind_cis   (kind, cis),
  INDEX idx_check_uuid (check_uuid, acked),
  INDEX idx_acked      (acked, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

- SQLite: `INSERT OR IGNORE`, `CHECK(kind IN ('sell','return'))`, `UNIQUE(kind,cis,check_uuid)`

### 2. Model: `Models/MarkingSellPendingAck.php`

```php
public function record(string $kind, array $cisList, string $checkUuid): void
public function pending(string $kind, string $checkUuid): array
public function acknowledge(string $kind, array $cisList, string $checkUuid): void
public function cleanupOlderThan(int $days): int
```

- `record`: `INSERT OR IGNORE` (SQLite/MySQL portable)
- `acknowledge`: `UPDATE ... WHERE kind=? AND check_uuid=? AND cis IN (...) AND acked=0` + `acked_at = CURRENT_TIMESTAMP`
- `cleanupOlderThan`: удаляет `WHERE acked=1 AND acked_at < NOW() - INTERVAL ? DAY`

### 3. SellWorker (`Service/Marking/SellWorker.php`)

- **DI**: `private ?MarkingSellPendingAck $pendingAck = null`
- **Kill-switch**: `usePendingAck()` → `MarkingConfig::get('audit.use_pending_ack', true)`
- **Record** (перед `/cis/sell`): `$this->pendingAck->record('sell', $toSell, $checkUuid)`
- **Retry (audit-first)**:
  - `$pending = $this->pendingAck->pending('sell', $checkUuid)`
  - Если пусто → все acked → `RESULT_DONE`
  - `$stillSold = $this->soldIntersection($pending)` — проверка ТОЛЬКО pending
  - `$this->pendingAck->acknowledge('sell', $stillSold, $checkUuid)`
  - `$toSell = array_diff($pending, $stillSold)` — только неподтверждённые
- **Legacy fallback** (kill-switch=0): `soldIntersection($cisList)` по всему списку
- **Acknowledge после успеха**: `$this->pendingAck->acknowledge('sell', $toSell, $checkUuid)`

### 4. ReturnWorker (`Service/Marking/ReturnWorker.php`)

Симметрично SellWorker:
- `record('return', $cisList, $checkUuid)` перед `/cis/returned`
- Retry: `pending('return', $checkUuid)` → `soldIntersection($pending)` → `alreadyReturned = array_diff($pending, $stillSold)` → `acknowledge('return', $alreadyReturned)`
- `$toReturn = $stillSold` (только те, что всё ещё в sold)

### 5. Config (`config/marking.php`, `.env.example`)

```php
'audit' => [
    'use_pending_ack'   => filter_var($_ENV['MARKING_USE_PENDING_ACK'] ?? '1', FILTER_VALIDATE_BOOLEAN),
    'retention_days'    => max(7, (int) ($_ENV['MARKING_AUDIT_RETENTION_DAYS'] ?? 30)),
],
```

`.env.example`:
```
MARKING_USE_PENDING_ACK=1
MARKING_AUDIT_RETENTION_DAYS=30
```

### 5. Cron (`cron/expire_emergency.php`)

Добавлен `cleanupOlderThan` в существующий cron:
```php
$retentionDays = (int) MarkingConfig::get('audit.retention_days', 30);
$deleted = (new MarkingSellPendingAck(\Models\Base::pdo()))->cleanupOlderThan($retentionDays);
if ($deleted > 0) {
    MarkingLogger::info('pending_ack_cleanup', ['deleted' => $deleted, 'retention_days' => $retentionDays]);
}
```

### 6. Tests (463 PASS / 0 FAIL)

- `tests/Unit/MarkingSellPendingAckTest.php` — 12 assert'ов
- `tests/Unit/SellWorkerTest.php` — +3 кейса (audit retry, partial, kill-switch)
- `tests/Unit/ReturnWorkerTest.php` — +3 кейса (audit retry, partial, kill-switch)
- Integration test удалён (тестировал некорректное поведение моков)

---

## Definition of Done — ПРОВЕРЕНО

1. ✅ `MarkingSellPendingAck` используется в retry-ветке **обоих** воркеров.
2. ✅ При `attempts > 1` отправляются **только** те CIS, которые не удалось подтвердить.
3. ✅ Тесты на SQLite проходят — `INSERT OR IGNORE` (нет `INSERT IGNORE`).
4. ✅ `SellWorkerTest`, `ReturnWorkerTest` не регрессировали.
5. ✅ Kill-switch `MARKING_USE_PENDING_ACK` — откат без релиза.
6. ✅ Q10 закрыт: audit-first retry не зависит от FIFO `/cis/sold`.
7. ✅ `git status` чистый, `php tests/run_all.php` — **463 PASS / 0 FAIL**.

---

## Фаза 3: Синхронизация документов (СЛЕДУЮЩИЙ ШАГ)

Теперь переходим к **Фазе 3** (COMMENTS-2.md §3): синхронизация документов.

### 3.1. Убрать противоречия в отчётах
### 3.2. Одно число тестов (463)
### 3.3. Один отчёт вместо четырёх
### 3.4. Обновить SPEC и ОТКРЫТЫЕ_ВОПРОСЫ (Q10 → закрыт)

---

## Фаза 4: Защита от повтора (после фазы 3)

Правила в `STATUS.md`:
- «Нет отчёта без диагностики»
- «Не удалять retry при падении тестов»
- Rule of three