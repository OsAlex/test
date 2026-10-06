# Вопросы по плану wave-2/PLAN.md

**Дата:** 2025-10-05  
**База:** Commit `c388c0e` (COMMENTS-13..17: wave 2.4)  
**Текущий статус:** 463 PASS / 0 FAIL

---

## Верификация фазы 2.1 (выполнена)

| # | Проверка | Ожидание | Статус |
|---|----------|----------|--------|
| 1 | `$pending = $this->pendingAck->pending(...)` перед soldIntersection | есть (SellWorker L108, ReturnWorker L83) | ✅ |
| 2 | `soldIntersection($pending)` — аргумент `$pending`, не `$cisList` | есть (SellWorker L124, ReturnWorker L100) | ✅ |
| 3 | `$toSell = array_diff($pending, $stillSold)` | есть (SellWorker L131, ReturnWorker L101) | ✅ |
| 4 | audit-ветка активна по умолчанию (`use_pending_ack = true`) | есть (config default=true) | ✅ |
| 5 | legacy-ветка существует как fallback | есть (else-ветки) | ✅ |

**Диагноз подтверждён: ALREADY DONE ✅** — audit-first retry уже реализован и работает.

---

## Вопросы к плану (Phase 2.2 — Q10-тест)

### Вопрос 1: Интерфейс FakeHttpClient для мока `/cis/sold`

**Проблема:** В PLAN.md §2.2 указан мок `/cis/sold` через FakeHttpClient. Но в текущих тестах (SellWorkerTest.php, ReturnWorkerTest.php) моки строятся через `FakeHttpClient::when('GET', '#/cis/sold#', ...)`.

**Вопрос:** Нужно ли расширять `FakeHttpClient` для поддержки возврата разных ответов в зависимости от параметров запроса (например, `skip` и `limit` параметры в URL `/cis/sold?skip=X&limit=Y`), или текущий функционал `when('GET', '#/cis/sold#', ...)` достаточен?

**Предложение:** Текущий `FakeHttpClient::when()` использует regex-паттерн. Для Q10-теста достаточно, чтобы мок возвращал пустой массив для `/cis/sold?skip=0&limit=100` (legacy) или для конкретных CIS из pending (audit-first). 

**Решение:** Использовать существующий `when()` с regex `#/cis/sold#` — это уже работает в существующих тестах (см. SellWorkerTest.php строки 59-60, 77).

---

### Вопрос 2: Контракт FakeHttpClient::when()

**Текущий API:**
```php
$http->when('GET', '#/cis/sold#', ['codes' => ['cis-x', 'cis-y'], 'total' => 2]);
```

**Вопрос:** Поддерживает ли `FakeHttpClient::when()` callable для динамических ответов в зависимости от query-параметров (skip, limit)?

**Текущая реализация:** В `FakeHttpClient::when()` второй аргумент — regex-строка. Callback получает `$call` массив с `url`, `body`, `headers`. Можно парсить URL внутри callback.

**Предложение:** Для Q10-теста (Scenario A) нужно вернуть `['codes' => ['cis-old-1'...'cis-old-10000'], 'total' => 10000]` для legacy-пути, и `['codes' => [], 'total' => 2]` для audit-first пути. Это можно сделать через closure в `when()`.

---

### Вопрос 3: Структура теста Q10FifoIndependenceTest.php

**Предлагаемая структура (по PLAN.md §2.2):**

```php
// Файл: tests/Unit/Q10FifoIndependenceTest.php

use Models\Base;
use Models\MarkingSellPendingAck;
use Models\MarkingSellQueue;
use Service\Marking\LmChzService;
use Service\Marking\MarkingConfig;
use Service\Marking\SellWorker;
use Tests\Fake\SqliteDb;
use Tests\Fake\FakeHttpClient;
use Tests\TestHarness;

function test_q10_fifo_independence(): void
{
    TestHarness::section('Q10: FIFO-независимость retry через audit-таблицу');
    MarkingConfig::set('audit.use_pending_ack', true);
    MarkingConfig::resetCache();

    $pdo = SqliteDb::create();
    SqliteDb::markingSchema($pdo);
    // ... тесты сценариев A, B, C
}
```

**Вопрос:** Нужно ли выносить общие фикстуры (`$outCheckOk`, `$codes`) в отдельные хелперы, как в `SellWorkerTest.php`?

**Решение:** Да, скопировать паттерн из `SellWorkerTest.php` — там есть `$outCheckOk` closure и `$codes` массив.

---

### Вопрос 4: Сценарий A (FIFO vs non-FIFO) — детали реализации

**План Scenario A:**
```
1. attempts=2, check_uuid='ck-q10'
2. pending() возвращает ['cis-x', 'cis-y']
3. /cis/sold МОК возвращает ['cis-old-1' ... 'cis-old-10000'] — cis-x в хвосте, за cap
4. Ожидание audit-first:
   - soldIntersection($pending) === []
   - $toSell === ['cis-x', 'cis-y']
   - sell(['cis-x', 'cis-y']) — отправлен остаток
```

**Вопрос:** Как реализовать мок `/cis/sold`, который возвращает 10000 кодов (где `cis-x` и `cis-y` — в хвосте, за cap)?

**Проблема:** Создание массива из 10000 элементов в тесте может быть тяжелым.

**Предложение:** Использовать меньший cap для теста (например, cap=3 вместо 10000), что упростит тест и ускорит его.

**Альтернатива:** Оставить cap=10000 в конфиге, но в тесте мокать `sold()` так, чтобы он возвращал `total=10000` и пустой `codes` для первых страниц, а на последней — нужные CIS. Но это усложнит тест.

**Рекомендация:** В тесте временно переопределить `SOLD_SCAN_CAP` на маленькое значение (например, 3) через конфиг или рефлексию, чтобы тест был быстрым и понятным.

---

### Вопрос 5: Kill-switch тест (Scenario C)

**План:**
```
1. MARKING_USE_PENDING_ACK=0
2. Тот же вход, что в A
3. Ожидание: используется legacy soldIntersection($cisList)
```

**Вопрос:** Как переключить kill-switch в тесте? 

В тестах используется `MarkingConfig::set('audit.use_pending_ack', false)` + `MarkingConfig::resetCache()`. Это уже используется в `SellWorkerTest.php` кейс 9.

**Вопрос:** Нужно ли тестировать оба режима в одном тестовом файле или разделить?

**Решение:** Добавить 2 кейса в Q10-тест: один с `use_pending_ack=true`, один с `false`.

---

## Резюме вопросов

| # | Вопрос | Критичность | Предлагаемое решение |
|---|--------|-------------|----------------------|
| 1 | FakeHttpClient для `/cis/sold` | Низкая | Использовать существующий `when()` с regex |
| 2 | Callable в `when()` для динамических ответов | Средняя | Использовать closure в `when()` с парсингом URL |
| 3 | Структура теста Q10 | Низкая | Скопировать паттерн из `SellWorkerTest.php` |
| 4 | Cap в тесте (10000 vs малый) | **Высокая** | Поставить малый cap (3) через конфиг или рефлексию |
| 5 | Kill-switch тест | Низкая | Скопировать кейс 9 из `SellWorkerTest.php` |

---

## Рекомендация: Начать с Q10-теста (Phase 2.2)

**Приоритет:** Написать `tests/Unit/Q10FifoIndependenceTest.php` с 3 сценариями:
1. **Scenario A** — FIFO vs audit-first (малый cap, доказывает независимость)
2. **Scenario B** — Частичный sold (audit + /cis/sold комбо)
3. **Scenario C** — Kill-switch off (legacy путь)

**Оценка:** ~30 минут на написание теста.

---

**Согласен ли вы с планом? Начать писать `Q10FifoIndependenceTest.php`?**