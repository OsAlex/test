# Ответы на 5 вопросов

## Вопрос 1. FakeHttpClient для `/cis/sold` — regex `#/cis/sold#`

**Ответ: да, но с уточнением.**

Regex `#/cis/sold#` **не** ловит query-параметры `?skip=X&limit=Y`. `#/cis/sold#` — это match по подстроке, попадёт и на `/cis/sold?skip=0`. Но если нужно **различать** запросы по `skip` — паттерн должен включать `skip`:

```php
// Ловит любой /cis/sold (все страницы → один ответ):
$http->when('GET', '#/cis/sold#', fn($call) => [...]);

// Различает страницы:
$http->when('GET', '#/cis/sold\?skip=0#', fn($call) => $page1);
$http->when('GET', '#/cis/sold\?skip=100#', fn($call) => $page2);
```

**Для Q10-теста достаточно первого** — пагинация не критична, `total` из первой страницы определяет поведение.

---

## Вопрос 2. Callable в `when()` для динамических ответов

**Ответ: да, использовать closure.**

**НО** — не парсить URL внутри callback. FakeHttpClient передаёт `$call['url']` в closure — просто читать `skip` из него:

```php
$http->when('GET', '#/cis/sold#', function (array $call): array {
    parse_str(parse_url($call['url'], PHP_URL_QUERY) ?? '', $q);
    $skip = (int) ($q['skip'] ?? 0);

    if ($skip >= 300) return ['codes' => [], 'total' => 300];
    return ['codes' => array_map(fn($i) => "cis-old-$i", range($skip, $skip + 99)), 'total' => 300];
});
```

**НО** для Q10 — проще. Не нужно динамики. Один ответ на всё:

```php
$http->when('GET', '#/cis/sold#', fn() => [
    'codes' => ['cis-old-1', 'cis-old-2', 'cis-old-3'],   // cap=3, cis-x НЕ в списке
    'total' => 3,
]);
```

---

## Вопрос 3. Структура `Q10FifoIndependenceTest.php`

**Ответ: не копировать паттерн из `SellWorkerTest.php` — использовать то, что там уже есть.**

**Проблема:** в PLAN.md указано `TestHarness::section()` + `function test_q10_...()` — это процедурный стиль. В `SellWorkerTest.php` — классовый (`class SellWorkerTest { public function run(TestHarness $t): void { ... } }`).

**Не смешивать.** Q10-тест должен быть **в том же стиле**, что и остальные:

```php
final class Q10FifoIndependenceTest
{
    public function run(TestHarness $t): void
    {
        $this->scenarioAFifoIndependence($t);
        $this->scenarioBPartialAck($t);
        $this->scenarioCKillSwitch($t);
    }

    private function scenarioAFifoIndependence(TestHarness $t): void
    {
        // ...
    }
}
```

**Фикстуры:** копировать `$outCheckOk` / `$codes` из `SellWorkerTest.php` **не нужно** — вынести в `tests/Fake/MarkingFixtures.php`, если они там ещё не вынесены. Если вынесены — использовать.

**Проверить:**
```bash
grep -n "class SellWorkerTest\|function run\|private function" tests/Unit/SellWorkerTest.php | head
```

Скопировать структуру `run(TestHarness $t)`.

---

## Вопрос 4. Cap в тесте (10000 vs малый) — **ключевой вопрос**

**Ответ: НЕ переопределять cap. Использовать реальный cap + один короткий ответ.**

**Почему не через конфиг:** `SOLD_SCAN_CAP` зашит константой (судя по прошлому отчёту). Введение конфига ради теста — оверинжиниринг.

**Почему не через рефлексию:** рефлексия в тестах — красный флаг. Она ломается при рефакторинге и не тестирует реальное поведение.

**Правильное решение:** cap не важен для доказательства FIFO-независимости. Достаточно:

```php
// /cis/sold возвращает 3 кода, которых нет в pending
$http->when('GET', '#/cis/sold#', fn() => [
    'codes' => ['cis-other-1', 'cis-other-2', 'cis-other-3'],
    'total' => 3,
]);

// pending = ['cis-x', 'cis-y']
// audit-first: soldIntersection(['cis-x', 'cis-y']) → пересечение с 3 кодами → []
// → toSell = ['cis-x', 'cis-y'] → sell вызывается
```

**Это уже доказывает FIFO-независимость** — если бы логика зависела от порядка, при `total=3` она бы тоже не нашла `cis-x`. Но тест показывает, что `pending()` даёт правильный вход в `soldIntersection` независимо от содержимого `/cis/sold`.

**Для усиления — Scenario A':**

```php
// /cis/sold возвращает ОБРАТНЫЙ порядок (имитация non-FIFO):
$http->when('GET', '#/cis/sold#', fn() => [
    'codes' => ['cis-newest', 'cis-older', 'cis-oldest'],
    'total' => 3,
]);

// pending = ['cis-oldest']
// audit-first: soldIntersection(['cis-oldest']) → пересечение → ['cis-oldest']
// → acknowledge(['cis-oldest']), toSell = []
// → sell НЕ вызывается (уже продан)
```

**Если бы `/cis/sold` был FIFO-зависимым** — при non-FIFO ответе `cis-oldest` не нашёлся бы в первых 3 записях. Audit-first находит, потому что сверяет только `pending`.

**Cap не нужен.** Реальный cap 10000 не влияет на эти два сценария.

---

## Вопрос 5. Kill-switch тест

**Ответ: да, через `MarkingConfig::set('audit.use_pending_ack', false)`. Оба режима — в одном файле.**

**Структура:**

```php
private function scenarioCKillSwitch(TestHarness $t): void
{
    // Setup: тот же вход, что в A
    $pending = ['cis-x', 'cis-y'];
    $soldResponse = ['cis-other-1'];   // не содержит pending

    // Переключить на legacy
    MarkingConfig::set('audit.use_pending_ack', false);
    MarkingConfig::resetCache();

    $worker = new SellWorker(...);
    $worker->process($id);

    // Ожидание: soldIntersection вызван с $cisList (полный список), не с $pending
    $t->assertEquals(1, $http->callCount('GET', '/cis/sold'),
        'legacy: /cis/sold вызван');

    // Восстановить конфиг для изоляции следующих тестов
    MarkingConfig::set('audit.use_pending_ack', true);
    MarkingConfig::resetCache();
}
```

**Обязательно:** восстановить `use_pending_ack = true` в конце **каждого** теста, который её менял. Иначе тесты после него пойдут по legacy-пути.

**Лучше:** `try/finally` вокруг изменения конфига:

```php
try {
    MarkingConfig::set('audit.use_pending_ack', false);
    MarkingConfig::resetCache();
    // ... assertions
} finally {
    MarkingConfig::set('audit.use_pending_ack', true);
    MarkingConfig::resetCache();
}
```

---

## Сводная таблица ответов

| # | Вопрос | Ответ |
|---|--------|-------|
| 1 | FakeHttpClient для `/cis/sold` | Regex `#/cis/sold#` достаточен; для различения страниц — `#/cis/sold\?skip=0#` |
| 2 | Callable в `when()` | Да, использовать `fn($call)`, парсить `$call['url']` через `parse_str` |
| 3 | Структура Q10-теста | Классовый стиль, `run(TestHarness $t)`, скопировать шаблон из `SellWorkerTest.php` |
| 4 | Cap в тесте | **Не переопределять.** Использовать короткий ответ `/cis/sold` (3 кода, не содержащих pending) + сценарий с non-FIFO порядком |
| 5 | Kill-switch | `MarkingConfig::set(...)` + `resetCache()` + `try/finally` для восстановления |

---

## Что запустить перед написанием теста

```bash
# 1. Точный API FakeHttpClient::when
grep -n "function when\|function callCount\|function calls" tests/Fake/FakeHttpClient.php

# 2. Точный API TestHarness (assert-методы)
grep -n "public function assert\|public function run\|markSkipped" tests/TestHarness.php | head -20

# 3. Структура SellWorkerTest — скопировать шаблон
sed -n '1,60p' tests/Unit/SellWorkerTest.php

# 4. Как конфиг сбрасывается между тестами
grep -n "resetCache\|MarkingConfig::set" tests/bootstrap.php tests/Unit/SellWorkerTest.php | head
```

**По этим 4 выводам** — соберу `Q10FifoIndependenceTest.php` под **реальный** API, а не под предположения.

**Пришлите вывод — либо сразу пишу тест под текущий API с TODO в 2–3 местах, где не знаю точных сигнатур.**