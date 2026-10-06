# RaceSuite — статус адаптации (AUDIT §5 #1, COMMENTS-16 «Вариант 1»)

**Файл:** `tests/Integration/RaceSuiteTest.php` (в корне приложения — сюда переносится как есть).

## Что сделано (итерация 2, 06.10.2026)

1. Все 15 сценариев COMMENTS-16 разложены по методам с **реальными сигнатурами API**,
   проверенными по коду репозитория:
   - `SellWorker::__construct(LmChzService, MarkingSellQueue, ?MarkingSellPendingAck)` —
     в черновике COMMENTS-16 были несуществующие аргументы `MarkingLogger`/`MarkingMetrics`
     (логирование — статика `MarkingLogger::*`, конфигурация — `MarkingConfig::get()`);
   - вход воркера — `processRow(MarkingSellQueue $row)` (не `process(int $id)`);
   - fake HTTP подаётся через интерфейс `HttpClient` + env-override
     `MARKING_LM_CHZ_HOST` (см. `config/marking.php`, блок «hosts.test»);
   - kill-switch pending-ack — `MarkingConfig 'audit.use_pending_ack'` (env `MARKING_USE_PENDING_ACK`).
2. Маппинг «сценарий → поверхность API» зафиксирован в шапке файла (таблица 1–15).

## Что осталось (завершается в app-репозитории)

Тела тестов помечены `markIncomplete('RACE-nn …')` и требуют файлов, которых **нет**
в docs-репозитории (только в app): `tests/TestHarness.php`, `tests/Fake/SqliteDb.php`,
`tests/Fake/FakeHttpClient.php`, модели `Marking*`. Это ровно те файлы, которые
COMMENTS-16 просил прислать («head -60 tests/TestHarness.php…»): их содержимое
нужно от команды приложения — после получения тела 15 тестов заполняются за один коммит.

## Критерий готовности (по COMMENTS-16 «Что делать по результатам»)

Прогон `php tests/run_all.php --filter=RaceSuite`: падения ≤3 → точечные фиксы;
4–8 → стоп волны; >8 → архитектурный ревью. До полного зелёного прогона п.1 AUDIT §5
остается 🟡 (каркас готов, логика проверки — в TODO строках каждого теста).
