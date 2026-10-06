# Стоп. Отчёт противоречит сам себе — 2.6 не закрыт

## Что сломано в отчёте

**Executive Summary:**
> «**2.6** (FIFO-независимость `/cis/sold` — Q10) **архитектурно закрыт**. DDL + модель + базовая интеграция (`record`/`acknowledge`) + kill-switch + legacy fallback + тесты.»

**What Was Delivered §3 SellWorker:**
> «**Retry (audit-first)**: `pending('sell', $checkUuid)` → если пусто → `done` … `toSell = pending - stillSold`»

**Critical Bug Fixes Applied #4:**
> «Retry audit check **ломал тесты** | **Убран**, оставлен legacy fallback»

**Next Steps (Backlog):**
> «**High** | Full retry via `pending()` instead of `/cis/sold` (Option A)»

**Это четыре взаимно исключающих утверждения в одном документе.**

- В Summary retry через `pending()` — **есть**.
- В Bug Fixes retry через `pending()` — **убран** («ломал тесты»).
- В Backlog retry через `pending()` — **не сделан**, приоритет High.
- В §3 SellWorker retry через `pending()` — **описан как рабочий**.

Что реально в коде — предсказуемо: **`pending()` вызывается, но результат переопределяется через `soldIntersection($cisList)` по всей строке**. То есть 2.6 не закрыт, а **подготовлен**. Слово «архитектурно» здесь — эвфемизм.

Пятая итерация подряд с одинаковым итогом: DDL + модель + record/acknowledge есть, retry-логика удалена под предлогом «ломала тесты».

---

## Что это значит для Q10

FIFO-независимость достигается ровно одной заменой:

```php
// Было (зависит от FIFO /cis/sold):
$registered = $this->soldIntersection($cisList);

// Должно быть (не зависит):
$pending = $this->pendingAck->pending('sell', $checkUuid);
$registered = $this->soldIntersection($pending);   // сверка ТОЛЬКО по pending
```

**Без этой замены:**
- `/cis/sold` возвращает последние 10000 (если FIFO) или первые 10000 (если не FIFO).
- CIS старше капа — не найдётся.
- Retry отправит `/cis/sell` повторно → ЛМ ЧЗ либо примет дубль (портит БД), либо отклонит (лишний failed).
- Q10 **открыт**. Как в волне 2.4.

---

## Что не сходится в цифрах

**Тесты.** Заявлено 446 PASS (baseline 426, прирост +20). По списку:

| Источник | Заявлено |
|----------|----------|
| `MarkingSellPendingAckTest` | 12 |
| `SellWorkerTest` (+3 кейса) | ~9 |
| `ReturnWorkerTest` (+3 кейса) | ~9 |
| `PendingAckRetryTest` | 10 |
| Config-тесты (?) | 2 |
| **Итого по файлам** | **~42** |

**Прирост в шапке:** +20. **Расхождение:** ~22 assert'а. Либо часть тестов не зачтена, либо заявленные цифры по файлам не подтверждены.

**Файлы.** Production заявлено 16 — перечислено 12. Куда делись ещё 4?

**Список тестов содержит дубликат:** `MarkingSellPendingAckTest.php (NEW, 12 tests)` указан **дважды**. И `MarkingSellPendingAck.php (NEW model)` попал в раздел «Tests» — это production-класс.

**Нумерация.** Три раза «### 5.» подряд — Config, Cron, Tests. Признак склейки из шаблонов без сверки.

---

## Что мне нужно от вас — до старта чего-либо

**Один вывод:**

```bash
git stash                                   # убрать незакоммиченные правки
git log --oneline -1                        # зафиксировать hash текущего HEAD

# Восстановить retry-логику через pending() временно в отдельной ветке:
git checkout -b tmp/audit-retry-experiment
# внести две строки:
#   $pending = $this->pendingAck->pending('sell', $checkUuid);
#   $registered = $this->soldIntersection($pending);

php tests/run_all.php 2>&1 | tee /tmp/failing.log
grep -B2 -A20 "FAIL" /tmp/failing.log | head -100
```

**Что я хочу увидеть в `/tmp/failing.log`:**

- **Какие именно тесты падают** (названия, assert-сообщения).
- **Сколько** (1–2 — это локальный баг, 10+ — системная проблема).
- **Что именно** (SQLite quirk, логика, мок).

Причины-гипотезы, которые нужно проверить в первую очередь:

| Симптом | Вероятная причина | Fix |
|---------|-------------------|-----|
| `column index out of range` | `UPDATE ... IN (?, ?, ?)` — параметры передаются неправильно (array_merge даёт 2 + N, а placeholders — N) | Перепроверить `array_merge([$kind, $checkUuid], $cisList)` |
| `SQLSTATE[HY000]: General error` | `INSERT OR IGNORE` — SQLite поддерживает, MySQL — нет; если тест на SQLite использует MySQL-ветку — упадёт | Уже исправлено, но проверить |
| `pending()` вернул `null`/`false` | `fetchAll(PDO::FETCH_COLUMN)` на SQLite возвращает `false` при пустом результате | Обернуть: `return $result ?: []` |
| `acknowledge` не находит CIS | В `record` CIS записаны с пробелами, в retry — без | Trim в обоих местах |

---

## Что НЕ делать

1. **Не начинать Wave-2.**
2. **Не добавлять `RaceSuite`.** Это не заменит закрытие 2.6.
3. **Не писать новых отчётов** типа «Wave-1.1 Completion». Документ должен быть один — фактический.

---

## Что делать после диагностики

**Если падает 1–2 теста** — я пишу патч на конкретный симптом. Это 15–30 минут работы. Q10 закрыт.

**Если падает 10+** — значит в API модели `MarkingSellPendingAck` есть системная проблема. Я пишу минимальный изолированный тест, который её воспроизводит, и разбираем в отдельном проходе.

**Если падает 0** — значит отчёт соврал: retry через `pending()` работает, и его зря откатили. Тогда — оставить логику и синхронизировать отчёт.

---

## Что мне прислать

Короткий вывод из трёх команд:

```bash
# 1. Что в git сейчас
git log --oneline -3
git status --short

# 2. Что реально в retry-ветке SellWorker
grep -n "pending\|soldIntersection\|usePendingAck" Service/Marking/SellWorker.php

# 3. Что упало при включении retry через pending (после временной правки)
php tests/run_all.php 2>&1 | grep -E "FAIL|PASS:" | tail -20
```

По этому — точечный патч. **Без волны 2.5 и без новых документов.**

Скажите: пришлёте вывод — или я пишу минимальный изолированный тест `pending() + soldIntersection(pending)` прямо сейчас, чтобы вы запустили его отдельно и увидели реальную причину?