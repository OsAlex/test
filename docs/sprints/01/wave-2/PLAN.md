# Итоговый план работ: закрытие 2.6 и выход из волнового цикла

**Принцип:** диагноз → верификация → фикс → документы → защита. Каждый переход — только через gate.

---

## Phase 0. Признание ситуации

**Цель:** зафиксировать, что волновой цикл не сходится.

**Действие:** добавить абзац в `docs/sprints/01/STATUS.md`:

> Волновой цикл (2.0–2.4.3) не сходится: каждая волна вносит новые проблемы, retry-логика откатывалась при падении тестов без диагностики. Переход к линейной схеме: диагноз → верификация → фикс → документ.

**Gate:** абзац присутствует в STATUS.md.

**Статус:** ✅ выполнено.

---

## Phase 1. Диагностика

**Цель:** получить факты, а не гипотезы.

### 1.1. Зафиксировать git-состояние
```bash
git log --oneline -5
git status --short
```

### 1.2. Зафиксировать baseline тестов
```bash
php tests/run_all.php 2>&1 | tail -3
php tests/run_all.php --json > /tmp/tests_baseline.json
```

### 1.3. Зафиксировать retry-логику в воркерах
```bash
grep -n "pending\|soldIntersection\|usePendingAck\|record\|acknowledge" \
    Service/Marking/SellWorker.php \
    Service/Marking/ReturnWorker.php
```

### 1.4. Воспроизвести падение (в отдельной ветке)
```bash
git checkout -b tmp/q10-diagnosis
# при необходимости — минимальная правка для воспроизведения
php tests/run_all.php 2>&1 | tee /tmp/failing.log
```

**Артефакт:** `docs/sprints/01/wave-1/DIAGNOSIS.md` с 4 выводами.

**Gate:** причина известна или явно опровергнута.

**Статус:** ✅ выполнено. Вывод: audit-first retry уже реализован (SellWorker L106–143, ReturnWorker L81–104), тесты зелёные.

---

## Phase 2'. Верификация + Q10-тест

**Цель:** принять/опровергнуть диагноз по фактическому коду + доказать FIFO-независимость.

### 2.1. Верификация диагноза

**Проблема:** DIAGNOSIS.md утверждает «ALREADY DONE» — это ровно тот паттерн, который 6 раз оказывался неверным. Принять как гипотезу.

**Действие:** прочитать фактические строки кода.

```bash
sed -n '100,160p' Service/Marking/SellWorker.php
sed -n '75,160p'  Service/Marking/ReturnWorker.php
grep -n "soldIntersection" Service/Marking/SellWorker.php Service/Marking/ReturnWorker.php
grep -n "use_pending_ack" config/marking.php
grep -n "MARKING_USE_PENDING_ACK" .env.example
```

**Что проверить:**

| # | Проверка | Ожидание |
|---|----------|----------|
| 1 | `$pending = $this->pendingAck->pending(...)` **перед** soldIntersection | есть |
| 2 | `soldIntersection($pending)` — аргумент именно `$pending`, не `$cisList` | есть |
| 3 | `$toSell = array_diff($pending, $stillSold)` — отправляется остаток | есть |
| 4 | audit-ветка активна по умолчанию (`use_pending_ack = true`) | есть |
| 5 | legacy-ветка существует как fallback | есть |

**Если пункты 1–3 — есть:** диагноз подтверждён, фикс не нужен.
**Если пункт 2 не выполняется (`soldIntersection($cisList)`):** регрессия, нужен фикс.

**Артефакт:** 3 строки в `DIAGNOSIS.md` — «verified: yes/no + evidence».

### 2.2. Q10-тест (единственная обязательная работа)

**Цель:** доказать FIFO-независимость на данных, не на моках.

**Файл:** `tests/Unit/Q10FifoIndependenceTest.php`.

**Сценарий A (различающий legacy и audit-first):**

```
1. attempts=2 (retry), check_uuid='ck-q10'
2. pending() возвращает ['cis-x', 'cis-y']
3. /cis/sold МОК возвращает ['cis-old-1' ... 'cis-old-10000'] — 
   cis-x в хвосте, за cap, не виден
4. Ожидание audit-first:
   - soldIntersection($pending) === []
   - $toSell === ['cis-x', 'cis-y']
   - sell(['cis-x', 'cis-y']) — отправлен остаток
5. Ожидание legacy (для контраста):
   - soldIntersection($cisList) тот же результат [] при не-FIFO
   → двойной sell
   → тест доказывает, что audit-first не зависит от порядка
```

**Сценарий B (регрессионный):**

```
1. attempts=2, pending = ['cis-a', 'cis-b']
2. /cis/sold возвращает ['cis-a']
3. Ожидание:
   - acknowledge(['cis-a'])
   - $toSell === ['cis-b']
   - sell(['cis-b']) — только остаток
   - после успеха: acknowledge(['cis-b'])
```

**Сценарий C (kill-switch):**

```
1. MARKING_USE_PENDING_ACK=0
2. Тот же вход, что в A
3. Ожидание: используется legacy soldIntersection($cisList)
```

**Критерий:** тест зелёный, покрывает 3 сценария, ~8–10 assert'ов.

### 2.3. Разрешить противоречие Backlog

**Проблема:** `DIAGNOSIS.md` §1.3–1.4: «ALREADY DONE».
`WAVE_1_COMPLETION_REPORT.md` §4 (Backlog): «High | Full retry via `pending()`».

**Действие:** после §2.1 (верификации) — одно из двух:
- Диагноз подтверждён → убрать из Backlog, заменить на «Q10-тест добавлен (2.2)».
- Диагноз опровергнут → Backlog остаётся, применять фикс.

**Артефакт:** правки в обоих документах.

**Gate Phase 2':** Q10-тест зелёный + диагноз verified (yes/no) + Backlog синхронизирован.

---

## Phase 3. Синхронизация документов

**Цель:** документы отражают реальность.

### 3.1. Убрать противоречия внутри отчётов

В `WAVE_1_COMPLETION_REPORT.md`, `FINAL_REPORT.md`, `ОТЧЕТ_О_РЕЗУЛЬТАТАХ.md`:
- Заменить «**ПОЛНОСТЬЮ ЗАКРЫТО**» на фактическое состояние.
- Убрать формулировки «архитектурная основа готова» как заменитель готовности.
- Убрать «High priority: Full retry via `pending()`» из Backlog, если оно сделано.

### 3.2. Одно число тестов

Все упоминания (426 / 446 / 463) → **одно фактическое** из Phase 1.2. Проверка:
```bash
grep -rn "PASS:" docs/sprints/01/*.md | sort -u
```

### 3.3. Один отчёт вместо четырёх

Оставить `ОТЧЕТ_О_РЕЗУЛЬТАТАХ.md`. Удалить:
```bash
git rm docs/sprints/01/WAVE_1_COMPLETION_REPORT.md
git rm docs/sprints/01/FINAL_REPORT.md
git rm docs/sprints/01/COMMENTS-13-RESULTS.md
```

### 3.4. SPEC и ОТКРЫТЫЕ_ВОПРОСЫ

- `SPEC.md` §2.6: «retry через `pending()` — реализован, `Q10FifoIndependenceTest` зелёный».
- `ОТКРЫТЫЕ_ВОПРОСЫ.md` Q10: **Закрыт** + дата + ссылка на коммит.
- Удалить упоминание `PendingAckRetryTest.php` как «удалён из-за падения» — заменить на «заменён `Q10FifoIndependenceTest`».

**Артефакт:** 1 коммит только с документами.

**Gate:** `grep -c "PASS:" docs/sprints/01/*.md` — одно число; Q10 в статусе «Закрыт».

---

## Phase 4. Защита от повтора

**Цель:** чтобы следующая находка не превратилась в волну 2.5.

**Действие:** добавить в `docs/sprints/01/STATUS.md`:

### 4.1. Правило «нет отчёта без диагностики»

> Любой фикс включает: (а) `php tests/run_all.php` до, (б) после, (в) failing log при попытке. Отчёт без этих трёх — не отчёт.

### 4.2. Правило «не удалять retry при падении тестов»

> При падении тестов после изменения retry-логики: остановиться, записать log, идентифицировать причину, исправить. Нельзя откатить без записи причины.

### 4.3. Rule of three

> Если 3 последовательных фикса не закрывают функциональность — остановиться, написать диагноз, не начинать четвёртый.

### 4.4. Правило верификации диагноза

> Документ-диагноз («ALREADY DONE», «не требует фикса») — гипотеза до подтверждения `sed -n` по фактическим строкам кода. Верификация фиксируется в самом документе.

**Артефакт:** правки в STATUS.md.

**Gate:** правила присутствуют, доступны команде.

---

## Итоговая последовательность

```
Phase 0 (признание)                ── ✅ выполнено
    ↓
Phase 1 (диагностика)              ── ✅ выполнено (DIAGNOSIS.md)
    ↓ [gate: причина известна]
Phase 2' (верификация + Q10-тест)  ── текущая работа
    ├── 2.1  sed -n по воркерам          30 мин
    ├── 2.2  Q10FifoIndependenceTest    30 мин
    └── 2.3  Backlog ↔ DIAGNOSIS sync   15 мин
    ↓ [gate: тест зелёный, диагноз verified]
Phase 3 (документы)                ── 1 коммит
    ↓ [gate: одно число тестов, Q10 закрыт]
Phase 4 (защита)                   ── правила в STATUS.md
```

---

## Что делать сейчас

**Точка входа:** Phase 2' §2.1 — верификация диагноза.

**Команды для первого шага:**

```bash
sed -n '100,160p' Service/Marking/SellWorker.php
sed -n '75,160p'  Service/Marking/ReturnWorker.php
grep -n "soldIntersection" Service/Marking/SellWorker.php Service/Marking/ReturnWorker.php
grep -n "use_pending_ack" config/marking.php
grep -n "MARKING_USE_PENDING_ACK" .env.example
```

**Что это даст:** подтверждение/опровержение «ALREADY DONE» из DIAGNOSIS.md.

**Что я напишу сразу после:** `Q10FifoIndependenceTest.php` под **реальный** API (не под предположения) — по результату §2.1.

---

## Что НЕ делаем

- Не запускаем RaceSuite — он про другое, не блокирует 2.6.
- Не расширяем audit-таблицу — DDL достаточен.
- Не трогаем emergency / cron / throttle — отдельные темы.
- Не пишем новых отчётов типа `WAVE_1_1_*.md`.
- Не начинаем новую волну.

---

**Готов к §2.1. Пришлите вывод 5 команд — либо сразу дайте добро на написание Q10-теста под текущее состояние кода.**