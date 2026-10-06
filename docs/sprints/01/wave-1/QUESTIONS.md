# Wave-1 Pre-flight: Вопросы и расхождения (План vs Факт)

**Дата:** 2025-10-05  
**База:** 426 PASS / 0 FAIL (baseline)

---

## 1. DDL — расхождения MySQL

| # | План (PLAN.md §2.1) | Фактический DDL (`sql/marking_tables.sql`) | Решение |
|---|---------------------|---------------------------------------------|---------|
| 1 | `id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY` | `id INTEGER PRIMARY KEY AUTOINCREMENT` (SQLite-стиль) | Изменить на `BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY` |
| 2 | `created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP` | Отсутствует | Добавить колонку |
| 3 | `acked_at TIMESTAMP NULL` | Отсутствует (есть `sent_at INT UNSIGNED`) | Добавить `acked_at TIMESTAMP NULL` |
| 4 | `sent_at` → оставить как `sent_at INT UNSIGNED` (момент отправки) | `sent_at INT UNSIGNED NOT NULL DEFAULT 0` | Оставить, переименовать в `sent_at` (уже так) |
| 4 | `acked_at TIMESTAMP NULL` | Отсутствует | Добавить |
| 5 | Индексы: `idx_kind_cis (kind, cis)`, `idx_check_uuid (check_uuid, acked)`, `idx_acked (acked, created_at)` | Текущие: `idx_kind_cis (kind, cis)`, `idx_check_uuid (check_uuid)`, `idx_acked (kind, acked)` | Обновить: `idx_check_uuid (check_uuid, acked)`, `idx_acked (acked, created_at)` |

---

## 2. DDL — расхождения SQLite (`tests/Fake/SqliteDb.php`)

| # | План | Фактический SQLite DDL | Решение |
|---|------|------------------------|---------|
| 1 | `id INTEGER PRIMARY KEY AUTOINCREMENT` | `id INTEGER PRIMARY KEY AUTOINCREMENT` | ✅ OK |
| 2 | `kind TEXT CHECK(kind IN ('sell','return'))` | `kind TEXT NOT NULL DEFAULT 'sell'` | Добавить `CHECK(kind IN ('sell','return'))` |
| 3 | `created_at DATETIME DEFAULT CURRENT_TIMESTAMP` | Отсутствует | Добавить |
| 4 | `acked_at DATETIME NULL` | Отсутствует | Добавить |
| 5 | `sent_at INTEGER NOT NULL DEFAULT 0` | `sent_at INTEGER NOT NULL DEFAULT 0` | ✅ OK |
| 5 | `acked INTEGER NOT NULL DEFAULT 0` | `acked INTEGER NOT NULL DEFAULT 0` | ✅ OK |
| 6 | Индексы: `idx_check_uuid (check_uuid, acked)`, `idx_acked (acked, created_at)` | `idx_check_uuid (check_uuid)`, `idx_acked (kind, acked)` | Обновить |

---

## 3. Model `MarkingSellPendingAck` — расхождения API

| # | План (PLAN.md §2) | Фактический код (`Models/MarkingSellPendingAck.php`) | Решение |
|---|-------------------|------------------------------------------------------|---------|
| 1 | `acknowledge(string $kind, array $cisList, string $checkUuid)` | `acknowledge(string $kind, array $cisList)` — **нет `checkUuid`** | Добавить параметр `checkUuid` и `WHERE check_uuid = ?` в UPDATE |
| 2 | `acknowledge` обновляет `acked_at = NOW()` | Только `acked = 1` | Добавить `acked_at = CURRENT_TIMESTAMP` |
| 3 | `cleanupOlderThan` использует `acked_at` | Использует `sent_at` | Переключить на `acked_at` (после добавления колонки) |
| 5 | `acknowledge` — идемпотентно: `UPDATE ... WHERE acked = 0 AND check_uuid = ?` | Нет фильтра по `check_uuid` | Добавить `check_uuid` в WHERE |

---

## 4. SellWorker — расхождения

| # | План (PLAN.md §3) | Фактический код | Решение |
|---|-------------------|-----------------|---------|
| 1 | Конструктор с DI `MarkingSellPendingAck` | Конструктор: `LmChzService`, `MarkingSellQueue` — **нет `MarkingSellPendingAck`** | Добавить DI |
| 2 | Kill-switch `usePendingAck()` + `MARKING_USE_PENDING_ACK` | Отсутствует | Добавить |
| 3 | Retry: `pending('sell', $checkUuid)` → если пусто → done; `soldIntersection($pending)` | Текущий: `soldIntersection($cisList)` — **нет audit-проверки** | Добавить retry-ветку с audit |
| 3 | Kill-switch `MARKING_USE_PENDING_ACK` | Отсутствует | Добавить в config + `usePendingAck()` |

---

## 5. ReturnWorker — аналогичные расхождения

| # | План | Факт | Решение |
|---|------|------|---------|
| 1 | DI `MarkingSellPendingAck` в конструкторе | Отсутствует | Добавить |
| 2 | Retry: `pending('return', $checkUuid)` → `soldIntersection($pending)` | Текущий: `soldIntersection($cisList)` | Добавить audit-retry |
| 3 | Kill-switch | Отсутствует | Добавить |

---

## 6. Config — отсутствует полностью

| Файл | Что добавить |
|------|--------------|
| `config/marking.php` | `'audit' => ['use_pending_ack' => env('MARKING_USE_PENDING_ACK', true), 'retention_days' => env('MARKING_AUDIT_RETENTION_DAYS', 30)]` |
| `.env.example` | `MARKING_USE_PENDING_ACK=1`, `MARKING_AUDIT_RETENTION_DAYS=30` |

---

## 7. Тесты — отсутствуют полностью

| Файл | План | Статус |
|-------|------|--------|
| `tests/Unit/MarkingSellPendingAckTest.php` | ~10 assert'ов | **Отсутствует** |
| `SellWorkerTest` +3 кейса (7-9) | +3 кейса | Отсутствует |
| `ReturnWorkerTest` +3 кейса | 3 кейса | Отсутствует |
| `tests/Integration/PendingAckRetryTest.php` | ~6 assert'ов | Отсутствует |

---

## Приоритеты (по плану)

1. **Шаг 1** — DDL MySQL + SQLite (идемпотентные миграции)
2. **Шаг 2** — Model `MarkingSellPendingAck` (API по плану)
3. **Шаг 3** — SellWorker (DI, record, acknowledge, retry, kill-switch)
4. **Шаг 4** — ReturnWorker (симметрично)
5. **Шаг 5** — Cron cleanup
6. **Шаг 6** — Тесты (новые файлы + доп. кейсы)
7. **Шаг 7** — Документация (SPEC, ОТЧЕТ, ОТКРЫТЫЕ_ВОПРОСЫ)
8. **Шаг 8** — Rollout plan (doc only)

---

## Вопросы к вам (قبل кодить)

1. **MySQL `created_at` / `acked_at`** — план требует `TIMESTAMP DEFAULT CURRENT_TIMESTAMP` для `created_at`. В MySQL это работает автоматически. `acked_at` nullable. Ок?
2. **`acknowledge` с `checkUuid`** — план требует `acknowledge(string $kind, array $cisList, string $checkUuid)`. Сейчас в модели нет `checkUuid`. Ок добавить?
3. **Kill-switch** — `MARKING_USE_PENDING_ACK` в `.env` / `config/marking.php`. Ок добавить?
4. **Config `audit.retention_days`** — default 30 дней. Ок?
6. **Tests** — создавать новые файлы тестов (`MarkingSellPendingAckTest.php`, `PendingAckRetryTest.php`) + доп. кейсы в существующих. Ок?
7. **Rollout plan** — `MARKING_USE_PENDING_ACK=0` фаза 1, потом `=1`. Ок?

**Gate:** Если DDL не совпадает с планом — обновляю DDL до кода (шаг 1). Начинаю?

---

*Сгенерировано автоматически после pre-flight. Базовая линия: 426 PASS / 0 FAIL.*