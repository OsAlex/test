# Ответы на 7 вопросов Pre-flight

## 1. MySQL `created_at` / `acked_at`

**Да, ок. С уточнениями:**

- `created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP` — работает, но MySQL 5.7+ **автоматически добавит** `ON UPDATE CURRENT_TIMESTAMP`, если не запретить явно. `created_at` не должен меняться при UPDATE → писать `TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP` **без** `ON UPDATE`, но MySQL всё равно добавит. Чтобы запретить: `created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP` — оставляем, но в комментарии зафиксировать, что менять не нужно.

  Более надёжно: `created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP` — `DATETIME` без implicit ON UPDATE.

- `acked_at TIMESTAMP NULL DEFAULT NULL` — обязательно указывать `DEFAULT NULL` явно. В strict mode MySQL 5.7+ без этого может быть warning.

**Итог:** для `created_at` использовать `DATETIME` (не `TIMESTAMP`), для `acked_at` — `TIMESTAMP NULL DEFAULT NULL`.

---

## 2. `acknowledge(string $kind, array $cisList, string $checkUuid)`

**Да, критично добавить `checkUuid`.** Без него:
- два чека с одинаковым CIS (частичная продажа агрегата) «перепутаются»;
- ack в одном чеке закроет pending в другом;
- retry второго чека решит, что всё уже отправлено, и не отправит.

Дополнительно — в `WHERE` должно быть `AND acked = 0` (идемпотентность: повторный вызов — no-op, не перезаписывает `acked_at`).

**Итоговый SQL:**
```sql
UPDATE marking_sell_pending_ack
SET acked = 1, acked_at = CURRENT_TIMESTAMP
WHERE kind = ? AND check_uuid = ?
  AND cis IN (...)
  AND acked = 0
```

---

## 3. Kill-switch `MARKING_USE_PENDING_ACK`

**Да, добавить. Дефолт — `true`.**

Обоснование:
- Дефолт `true`: после деплоя новый код работает сразу, старая логика не остаётся «по умолчанию».
- Откат — `MARKING_USE_PENDING_ACK=0` + `kill -HUP` PHP-FPM. Без релиза.
- В `config/marking.php`: `'audit' => ['use_pending_ack' => env('MARKING_USE_PENDING_ACK', true)]`.

Формулировка в `.env.example`:
```
# 1 = retry через audit-таблицу (Q10 fix). 0 = legacy soldIntersection по всему списку.
MARKING_USE_PENDING_ACK=1
```

---

## 4. `audit.retention_days=30`

**Да, 30 дней. Плюс защита от неверного env.**

```php
'audit' => [
    'retention_days' => max(7, (int) env('MARKING_AUDIT_RETENTION_DAYS', 30)),
],
```

Минимум **7 дней** — на случай, если в `.env` окажется `0` или отрицательное число. Иначе `cleanupOlderThan(0)` удалит свежие записи, и retry сломается.

Дополнительно — задокументировать в SPEC: «retention_days должно быть ≥ 2 × lease_minutes / 60 / 24 с округлением вверх». При `lease=15 мин` минимум = 1 день, при `lease=15 мин` × 2 = 30 мин — с запасом. 30 дней — комфортный запас.

---

## 5. (вопрос пропущен в pre-flight)

Пропущен, но по смыслу — вероятно, был про `sent_at` vs `created_at`. Отвечаю превентивно:

**`sent_at INT UNSIGNED` — оставить как есть.** Это момент отправки (unix int), используется для отладки. `created_at` — момент вставки записи (может отличаться от `sent_at` на миллисекунды, но полезно разделить: `created_at` — факт записи в audit, `sent_at` — факт попытки отправки в ЛМ ЧЗ). Не смешивать.

Если вопрос был про другое — уточните.

---

## 6. Тесты — создавать новые файлы + доп. кейсы

**Да, все 4 файла:**

| Файл | Тип | Кол-во assert'ов |
|------|-----|------------------|
| `tests/Unit/MarkingSellPendingAckTest.php` | новый | ~12 |
| `tests/Unit/SellWorkerTest.php` | дополнить кейсами 7–9 | +3 кейса, ~9 assert'ов |
| `tests/Unit/ReturnWorkerTest.php` | дополнить кейсами 7–9 | +3 кейса, ~9 assert'ов |
| `tests/Integration/PendingAckRetryTest.php` | новый | ~8 |

**Обязательные требования:**
- Каждый тест использует **свежий** `SqliteDb::inMemory()` в `setUp()` — не разделять состояние между кейсами.
- `MarkingSellPendingAckTest` — тестирует модель **изолированно**, без воркеров.
- `PendingAckRetryTest` — end-to-end: `SellWorker::processRow()` + audit + `/cis/sold` мок.
- Kill-switch тестируется **явно**: один кейс с `MARKING_USE_PENDING_ACK=0` (legacy-путь), один с `=1` (audit-путь).

**Ожидание после шага 6:** 426 + ~38 = **~464 PASS**, FAIL 0.

---

## 7. Rollout plan

**Да, но уточнить формулировки.**

Не «фаза 1 = 0, потом = 1», а **три фазы**:

| Фаза | `MARKING_USE_PENDING_ACK` | Длительность | Что проверяем |
|------|---------------------------|--------------|---------------|
| 1 | `0` | 1 сутки | Таблица пишется (`record` + `acknowledge` работают на потоке), retry идёт **по старой логике** (`soldIntersection` по всему списку). Цель — убедиться, что новая схема/модель не ломает прод. |
| 2 | `1` на 10% касс | 1 сутки | Retry идёт по audit. Смотрим метрики: `marking_sell_already_acked_total` > 0 (значит, retry реально попадает в ack-путь). `marking_sell_idempotent_skip_total` растёт. |
| 3 | `1` на 100% | постоянно | Наблюдаем `marking_sell_sold_cap_exceeded_total` → должно падать до 0. Это подтверждает, что `/cis/sold` больше не является bottleneck. |

**Почему `=0` на фазе 1, а не «удалить таблицу на откате»:**
- Таблица остаётся как audit-лог. Даже при `=0` она пишется — это данные для будущего анализа.
- Откат — только переключение флага, DDL не меняется.

**Дополнительное требование:** на фазе 1 добавить метрику `marking_pending_ack_pending_count` (из `stats()`) — растёт ли pending без ack. Если растёт монотонно — что-то не так с `acknowledge`.

---

## Сводка

| # | Вопрос | Ответ |
|---|--------|-------|
| 1 | `created_at`/`acked_at` | ✅ Да, `created_at DATETIME`, `acked_at TIMESTAMP NULL DEFAULT NULL` |
| 2 | `acknowledge` + `checkUuid` | ✅ Да, критично + `AND acked = 0` для идемпотентности |
| 3 | Kill-switch | ✅ Да, дефолт `true`, откат `=0` без релиза |
| 4 | `retention_days` | ✅ Да, 30, но `max(7, ...)` от неверного env |
| 5 | (пропущен) | ✅ `sent_at INT UNSIGNED` — оставить, не смешивать с `created_at` |
| 6 | Тесты | ✅ Да, 2 новых + дополнения; +~38 assert'ов |
| 7 | Rollout | ✅ Да, 3 фазы (`0` → `1` на 10% → `1` на 100%) |

---

## Готов начинать

Могу сразу:

**A.** Написать **Шаг 1 (DDL) + Шаг 2 (модель)** — готовый код для вставки в файлы.
**B.** Написать **Шаг 3 (SellWorker)** — diff существующего файла.

Рекомендую A — без работающей модели тестировать SellWorker бессмысленно.

Скажите: **«пиши шаги 1+2»** — выдам полный код для `marking_tables.sql`, `SqliteDb.php`, `MarkingSellPendingAck.php`. После применения — Шаг 3.