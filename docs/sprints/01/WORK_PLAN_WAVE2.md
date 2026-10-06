# WORK_PLAN_WAVE2.md — Волна 2: закрытие замечаний COMMENTS-8.md

> **Источник:** `docs/sprints/01/COMMENTS-8.md` (15 проблем: 5🔴, 5🟠, 5🟡).
> **Дата фактчека:** 05.10.2026 (все утверждения COMMENTS-8 проверены по коду/гиту — см. §0).
> **Цель волны:** закрыть все 15 замечаний (код + тесты + SPEC + git), сделать историю чистой для пуша в origin.
> **Внешних данных волна не требует** (JSON Атола, sandbox, регионы, утверждение SPEC — остаются блокерами «продажного» режима, а не этой волны).

---

## 0. Фактчек COMMENTS-8 (что подтверждено)

| # | Утверждение | Фактчек | Статус |
|---|---|---|---|
| 1 | Тестов 134, а не 143 | В отчёте **собирали таблицу вручную** — пересчёт: статически в файлах 149 вызовов assert'ов, из них 6 — sentinel-`assertFalse(true)` внутри try-блоков, которые не выполняются (исключение бросается корректно: 3 в CdnServiceTest, 1 в GisMtAuthServiceTest, 1 в LmChzServiceTest, 1 в AddMarkingAttributesTest). **149 − 6 = 143** — аритметика сходится. Итог: неверна таблица в отчёте, не счётчик | ✅ подтверждено |
| 2 | Нет тестов на logger/metrics/return/воркеры/модели | Верно: из 9 suite ни один не трогает `MarkingLogger`, `MarkingMetrics`, `MarkingReturnService`, `process_*.php`, модели БД; `marking_tables.sql` нигде не исполняется | ✅ подтверждено |
| 3 | `marking_emergency_state` смешена семантика | Верно: в DDL/модели — circuit-breaker хостов, а в SPEC §1.4 таблица — под аварийный режим ГИС МТ (HTTP 203) | ✅ подтверждено |
| 4 | Circuit breaker per-process | Верно: `$hostState` in-memory + опциональный `blockPersister` (в проде не подключён) | ✅ подтверждено |
| 5 | `checkHybrid` не параллелен | Верно: последовательно «онлайн с curl-timeout=барьер → офлайн»; ни `curl_multi`, ни `pcntl` в коде | ✅ подтверждено |
| 6 | `verified=false` → WARN или BLOCK | Верно: `MarkingStatus::fromErrorCode()` не смотрит `verified` (WARN по errorCode 1..9); коллизия SPEC §1.6 vs PIOT §4 не закрыта в SPEC | ✅ подтверждено |
| 7 | .deb/.rpm в git навсегда | `git count-objects -vH`: **size-pack 186.89 MiB**. Бинарники — только в локальном `c5260b3`; `origin/main` стоит на `9d7ca39` (до наших коммитов) → **чистится пересбором локальных коммитов, filter-repo/force-push не нужны** | ✅ подтверждено |
| 8 | ATOL placeholder без гейта | Верно: `buildMarkingAttribute()` строит тег 1260 по приближению независимо от `MARKING_ENV` | ✅ подтверждено |
| 9 | vendor stub / деплой | `composer.json` + `composer.lock` **в гите** (автозагрузка в проде — composer vendor); деплой-документации нет, SPEC §9 про stub не говорит | ✅ подтверждено |
| 10 | Двойная запись return | Верно: `markReturn()` (sell→`return`) + запись в `marking_return_queue` одновременно; обе части предписаны SPEC §3.4 → нужно решение | ✅ подтверждено |
| 11 | Worker race на pending | Верно: `MarkingSellQueue::pending()` — `SELECT ... ORDER BY id LIMIT` без锁ов, `process_sells.php` без атомарного захвата | ✅ подтверждено |
| 12 | `cod_marking_*` без спеки | Верно: единственное чтение — `Check::collectMarkingByItem()`. Источник данных установлен: **таблица `cassa_ord_det`** (`Models\Order::getByOrderId`: `SELECT * FROM cassa_ord_det WHERE cod_ord_id=...`) | ✅ подтверждено |
| 13 | Авансовый чек — throw | Верно: `applyMarkingAttributes()` бросает `Exception` для типов AVANS/REFUND_AVANS/CORECT_* | ✅ подтверждено |
| 14 | uiPayload INFO/EMERGENCY не покрыты | Верно: в `MarkingStatusTest` — BLOCK/WARN только | ✅ подтверждено |
| 15 | `.work_tmp_*` не удалены | Файлы на месте (untracked); pwsh `Remove-Item` отклонён песочницей в workspace-write — повтор с привилегированным режимом в T15 | ✅ подтверждено |

**Итог фактчека:** 15/15 замечаний действительны. Две поправки к самой критике: #7 решается проще, чем предложено (история не пушена); #12 — таблица источника известна (`cassa_ord_det`), остаётся описать поля/форматы.

---

## 1. Задачи волны

Условные обозначения: **P0** = 🔴 из COMMENTS-8, **P1** = 🟠, **P2** = 🟡. «Проверка» — дословно из COMMENTS-8, где применимо.

### Блок A — P0 (критичные, без внешних зависимостей)

#### T1. `checkHybrid` — истинный параллелизм через `curl_multi` (COMMENTS #5, P0)
- **Что:** новый `Service/Marking/HybridRunner.php`: два curl-хэндла (онлайн `POST /codes/check` + офлайн `POST /outCheck`) в одном `curl_multi`, общий барьер `cdn.switch_threshold_sec` (1.5 c). Семантика решения:
  - обе ветки ответили до барьера → решение по **обеим** (BLOCK на любой ветке → BLOCK; иначе max);
  - только онлайн / только офлайн → по ответившей;
  - ни одной → `EMERGENCY_TIMEOUT` (WARN) + метрика (как сейчас).
  - Последовательный режим — только как fallback, если `ext-curl` без multi (не наш случай: `ext-curl` в require).
- **Файлы:** `Service/Marking/HybridRunner.php` (new), `Service/Marking/MarkingCheckService.php` (переписать `checkHybrid`), `Service/Marking/HttpClient.php` (документация: для гибрида — `CurlMultiClient`), `tests/Unit/HybridRunnerTest.php` (new).
- **Тесты (сценарии из COMMENTS-8):** онлайн 1.4s / офлайн 1.6s → «только онлайн»; онлайн 1.6s / офлайн 0.5s → «только офлайн»; оба >1.5s → EMERGENCY; оба <1.5s → совместное решение (BLOCK любой ветки побеждает). Задержка — параметр фейка (`FakeHttpClient::withDelayMs`).
- **Проверка:** «unit-тест — онлайн 1.4s, офлайн 1.6s → результат "только онлайн"».
- **Оценка:** 1.5 чел.-дн.

#### T2. Таблица `marking_cdn_host_state` + персистентный circuit breaker (COMMENTS #3 + #4, P0)
- **Что:** Option A из COMMENTS-8 — разнести:
  - `marking_emergency_state` вернуть к семантике SPEC §1.4 (аварийный режим ГИС МТ: `emergency_started_at`, `actual_end_at`, `reason`, `source`);
  - новую `marking_cdn_host_state` (`host` PK, `fail_count`, `blocked_until`, `last_fail_at`, `last_check_at`, `avg_time_ms`) — circuit breaker.
- **Файлы:** `docs/sprints/01/sql/marking_tables.sql` (DDL: новая таблица + переписать `marking_emergency_state`), `Models/MarkingCdnHostState.php` (new), интерфейс `CdnHostStateRepository` (методы `isBlocked`, `markSuccess`, `markFailure` — атомарный `INSERT ... ON DUPLICATE KEY UPDATE fail_count = fail_count + 1`), реализации: `DbCdnHostStateRepository` (выбран по умолчанию) и `InMemoryCdnHostStateRepository` (тесты). `CdnService` — хост-стейт через репозиторий (убрать валидный per-process `$hostState` как primary source, оставить только кэш), `MarkingEmergencyState` — переписать под §1.4.
- **Проверка (из COMMENTS-8):** «unit-тест с двумя разными инстансами `CdnService`, разделяющими один storage: первый 3 fail'а → второй видит хост заблокированным»; `grep -rn "MarkingEmergencyState" Service/ Models/` — семантика везде = §1.4 (203).
- **Оценка:** 1 чел.-дн.

#### T3. Атомарный захват строк воркерами (COMMENTS #11, P0)
- **Что:** `MarkingSellQueue::claimNextPending(): ?int` и `MarkingReturnQueue::claimNextPending(): ?int`:
  ```sql
  UPDATE marking_sell_queue
  SET status='processing', processed_at=NOW(), attempts=attempts+1
  WHERE status='pending' AND id = (
      SELECT id FROM (SELECT id FROM marking_sell_queue
                      WHERE status='pending' ORDER BY id LIMIT 1) AS t
  );
  ```
  `affected_rows = 1` → работником захвачен именно этот id (никакие два воркера не получат одну строку). `process_sells.php` / `process_returns.php` — переписать цикл на claim.
- **Тест:** два PDO-подключения к одной БД (file-SQLite для unit, MySQL в smoke) → две параллельные попытки claim → ровно одна получает строку.
- **Оценка:** 0.5 чел.-дн.

#### T4. Гейт ATOL placeholder (COMMENTS #8, P0)
- **Что:** `config/marking.php`: `atol_tag1260_placeholder` (env `MARKING_ATOL_PLACEHOLDER`, дефолт `1` — до получения официального JSON). `Models/Check::applyMarkingAttributes()`: если `MARKING_ENV=prod` И `placeholder=1` → `throw new \Exception('ATOL tag1260 official JSON not obtained — refusing to build marking attributes in production (MARKING_ATOL_PLACEHOLDER=1)')` **до** отправки на ККТ. `.env.example`: `MARKING_ATOL_PLACEHOLDER=1`. SPEC §9: правило «прод не стартует при placeholder=1».
- **Тест:** env prod + placeholder=1 → исключение до сборки атрибутов; env prod + placeholder=0 → атрибуты строятся; env test → поведение как сейчас.
- **Оценка:** 0.5 чел.-дн.

#### T5. Тесты на `MarkingLogger` / `MarkingMetrics` / `MarkingReturnService` (COMMENTS #2, P0 — часть 1)
- **Что:**
  - `tests/Unit/MarkingLoggerTest.php`: PII-маски (api_key → первые 8 символов, КМ → `****1234`, GTIN → `460…001`, `cis_list` → массив масок), уровни + sink, `log.level`-фильтр.
  - `tests/Unit/MarkingMetricsTest.php`: inc/observe/snapshot/reset; `ratio()` в 5-мин окне: >5% при низком totale, 0 при пустом окне; `alertIfEmergencyRatioHigh` срабатывает только при `marking_check_total > 0`.
  - `tests/Unit/MarkingReturnServiceTest.php`: после T10 (решение по return) — контракт: успех → return-запись `done`, sell-строка **не меняется**; ошибка → `failed`, sell не меняется; дубль вызова по тому же чеку → не плодит return-записи. Для тестогенности репозиторизации очередей (sell/return) в `MarkingReturnService` (интерфейсы + in-memory фейки; дефолт — Db-реализации).
- **Оценка:** 1.5 чел.-дн.

#### T6. Авансовый чек — SKIPPED вместо throw (COMMENTS #13, P0→P2 по таблице, но 0.5 дня)
- **Что:** `MarkingCheckResult::skipped(string $reason)` (source=`SKIPPED`); `Check::applyMarkingAttributes()`: авансовый тип → skipped-результат + история со `skip_reason='advance_check_no_goods'` + метрика `marking_skipped_advance_total`; **без** исключения.
- **Проверка (из COMMENTS-8):** авансовый чек не бросает исключение; в истории строка с `skip_reason='advance_check_no_goods'`.
- **Оценка:** 0.5 чел.-дн.

#### T7. Прозрачный счётчик тестов (COMMENTS #1, P0→P2)
- **Что:** `TestHarness`: флага `--list` (печатать каждый assert с файлом) и `--json` (машинный summary: per-file counts); в `run_all.php` — summary per-file. Отчёт: таблица suite'ов **генерируется из запуска**, не от руки.
- **Исправление отчёта** (фактические числа после волны): MarkingCode 14, MarkingStatus 16, GisMtAuth 10, CdnService 19, CodeCheck 25, LmChz 17, MarkingCheckService 23, AddMarkingAttributes 19, CassaTz 6 → 149 assert'ов, из них 6 sentinel'ов не выполняются → **143 в рантайме** (+ новые тесты волны).
- **Проверка (из COMMENTS-8):** `php tests/run_all.php --json | jq` — числа сходятся с реальным числом assert'ов.
- **Оценка:** 0.25 чел.-дн.

#### T8. uiPayload INFO и EMERGENCY (COMMENTS #14, P0→P2)
- **Что:** `MarkingStatus::uiPayload()`: INFO — зелёный, без кнопок (сейчас уже, но покрыть тестом); источник `EMERGENCY_TIMEOUT` → payload уровня WARN с пометкой «система в деградированном режиме» + кнопка continue. `MarkingStatusTest`: два новых сценария (из COMMENTS-8 дословно).
- **Оценка:** 0.25 чел.-дн.

### Блок B — P1 (оранжевые)

#### T9. `verified=false`: WARN или BLOCK — конфигурируемо + Q8 (COMMENTS #6, P1)
- **Что:** `config/marking.php` → `prohibitions: { error_code_levels: {0:'INFO', 1..9:'WARN', 10:'BLOCK', 11:'WARN'}, verified_false: 'WARN' }` (дефолт — WARN, как в SPEC §1.6; переключатель на BLOCK — одним значением в конфиге, без правки кода). `CodeCheckService`: per-code upgrade — `verified === false` И `verified_false=BLOCK` → BLOCK. SPEC §1.6 — явно зафиксировать текущую семантику + коллизию с PIOT §4; `ОТКРЫТЫЕ_ВОПРОСЫ.md` — новый **Q8**: «`verified=false` (сбой крипто-проверки): продажа с предупреждением или запрет?» — на оператора ЧЗ/методичку.
- **Тест:** переключение конфига WARN↔BLOCK меняет overall для кода с `verified=false`.
- **Оценка:** 0.5 чел.-дн. (+ ожидание ответа Q8)

#### T10. Возвраты: один источник истины (COMMENTS #10, P1)
- **Решение (рекомендация — Option A):** `marking_sell_queue` при возврате **не меняется** (остаётся `done` — это история продаж); вся семантика возврата — только в `marking_return_queue` (по `check_uuid`/`order_id`). Убрать статус `return` из ENUM sell-очереди (DDL + модель + `markReturn()` из `MarkingReturnService`); обновить SPEC §3.4; обновить `CdnService`-независимые тесты.
  *Обоснование:* sell-очередь = факты продаж (метрики/аудит); return-очередь = факты возвратов; мутация одной таблицы другой операцией — источник рассинхрона при частичных сбоях (вопрос COMMENTS-8: «что если один упадёт, второй нет?»).
- **Тест (из COMMENTS-8):** после возврата `marking_sell_queue.status='done'` не меняется; в `marking_return_queue` появилась запись.
- **Оценка:** 0.5 чел.-дн.

#### T11. Дочистить бинарники из git + `.gitignore` (COMMENTS #7, P1)
- **Что:** локальные коммиты не пушены (`origin/main` = `9d7ca39`) → безопасный пересбор **без** force-push:
  1. `git tag backup/pre-wave2 main` (страховка);
  2. `git reset --soft 9d7ca39`;
  3. `docs/sprints/01/PIOT/esmpack_linux64_1.6.4.0.477/` — файлы остаются на диске, выводят из индексирования: добавить в `.gitignore` `docs/sprints/*/PIOT/esmpack_*/`, `*.deb`, `*.rpm`, `*.tar.gz`;
  4. пересобрать 3 коммита (docs → код → отчёт) + коммит плана волны;
  5. `git count-objects -vH` до/после (ожидание: size-pack с ~187 MiB → единицы MiB).
- **Проверка (из COMMENTS-8):** `git log --all --oneline -- 'docs/sprints/01/PIOT/esmpack_linux64_1.6.4.0.477/'` — пусто; size-pack сверить.
- **Оценка:** 0.5 чел.-дн. (делать **первым** в волне, пока история короткая)

#### T12. Автозагрузка и деплой-документация (COMMENTS #9, P1)
- **Что:** `git ls-files` подтвердил `composer.json` + `composer.lock` в репозитории → прод-автозагрузка = composer vendor (не stub). SPEC §9 дополнить: (а) прод — `composer install --no-dev --optimize-autoloader` при деплое; (б) CLI-воркеры — `autoload_marking.php`; (в) stub `vendor/autoload.php` создаётся **только** тестами, в проде отсутствует. Новый `docs/sprints/01/DEPLOY.md`: деплой-чеклист (composer, `.env`, DDL `sql/marking_tables.sql`, cron-строки воркеров, `MARKING_ENABLE/ATOL_PLACEHOLDER` гейты).
- **Оценка:** 0.5 чел.-дн.

#### T13. SPEC §2.4 «Маркированные товары в заказе» (COMMENTS #12, P1)
- **Что:** описать в SPEC §2.4:
  - **Хранилище:** таблица `cassa_ord_det` (подтверждено: `Models\Order::getByOrderId` → `SELECT * FROM cassa_ord_det WHERE cod_ord_id=...`); DDL-дополнение в `sql/marking_tables.sql`:
    ```sql
    ALTER TABLE cassa_ord_det
      ADD cod_marking_cis VARCHAR(2000) NULL,          -- 'КИ1;КИ2;...', КИ ≤ 31 цифра
      ADD cod_marking_package_type ENUM('ITEM','GROUP','BUNDLE','PRODUCT_SET') NULL,
      ADD cod_marking_scan_at TIMESTAMP NULL;
    ```
  - **Форматы:** разделитель `;`, КИ — 1..31 цифра (валидация `MarkingCode::extractCis`), `package_type` только `ITEM`/`GROUP` в MVP (остальные значения — SKIP c log'ом);
  - **Источник заполнения:** сканер на кассе / импорт 1С / API — **владелец: отметить в §2.4 как открытое под-вопрос Q9** (в команде согласовать, кто пишет поля);
  - **Поведения:** есть `package_type`, нет КИ → SKIPPED (`skip_reason='not_marked'`), не BLOCK; КИ есть, `package_type` нет → дефолт `ITEM`.
- **Тест:** `MarkingCheckService`/`collectMarkingByItem` с пустым `cod_marking_cis` + `package_type=ITEM` → SKIPPED, не BLOCK.
- **Оценка:** 0.5 чел.-дн.

#### T14. DbSmokeTest (COMMENTS #2, P1 — часть 2)
- **Что:** `tests/DbSmokeTest.php`: исполнить DDL `marking_tables.sql` в тестовой БД (MySQL если есть в среде; иначе — **смукованный** эквивалент для SQLite: `ENUM`→`CHECK`-констрейны, `ON DUPLICATE KEY`→`INSERT OR REPLACE`, `JSON`→`TEXT` — с пометкой «смук для CI; продакшн-DDL валиден только в MySQL»). Сценарии по каждой из 6 таблиц (после T2: 5 + `marking_cdn_host_state`): INSERT/SELECT/UPDATE; NULL-допуски (`item_id`, `found`, `verified`, `sold`, `is_blocked`, `parent_cis`, `group_id`); NOT NULL-ограничения (попытка вставить NULL в обязательное поле падает); атомарность `claim` (T3) и host-state (T2) — на двух подключениях к одной БД.
- **Проверка (из COMMENTS-8):** новый suite зелёный; «попытка вставить NOT NULL в NULL-поле падает».
- **Оценка:** 1 чел.-дн.

### Блок C — P2 / финал

#### T15. Убрать `.work_tmp_*` (COMMENTS #15, P2)
- `Remove-Item` в привилегированном режиме (ранее отклонён в workspace-write); контроль: `Get-ChildItem docs/sprints/01 -Filter '.work*' -Force` → пусто; в `git status` отсутствуют.
- **Оценка:** 0.1 чел.-дн.

#### T16. Финальный прогон и отчёт
- `php -l` по всем файлам; `php tests/run_all.php --json` (ожидание: 143 + ~45 новых assert'ов, 0 FAIL); обновить `ОТЧЕТ_О_РЕЗУЛЬТАХ.md` (таблица — из запуска, статусы 15 пунктов COMMENTS-8 — «закрыто»); коммит волны.
- **Оценка:** 0.25 чел.-дн.

### Статус задач (факт, 05.10.2026)

| Задача | Статус | Факт |
|---|---|---|
| T1 (гибрид curl_multi) | ✅ | `HttpParallel` + шов `HttpParallelTransport`; 14 assert'ов гибрид-сценариев в `MarkingCheckServiceTest` + 3 гонки в mock-интеграции |
| T2 (host-state + emergency 203) | ✅ | `marking_cdn_host_state` + `MarkingCdnHostState` (DB/InMemory store); `MarkingEmergencyState` — INN-скейп (203); 9+9 assert'ов |
| T3 (атомарный claim) | ✅ | `claimNextPending()` (transaction + conditional UPDATE); `process_*.php` — claim-цикл с re-claim guard; 7 assert'ов `WorkerRaceTest` |
| T4 (гейт ATOL) | ✅ | `APP_ENV=prod` + `MARKING_ATOL_PLACEHOLDER=1` → `RuntimeException`; 6 assert'ов `AtolPlaceholderGateTest` |
| T5 (тесты logger/metrics/return) | ✅ | `MarkingLoggerTest` (9), `MarkingMetricsTest` (8), `MarkingReturnServiceTest` (12) |
| T6 (advance → SKIPPED) | ✅ | `skipAdvance()` + `skip_reason='advance_check_no_goods'` + метрика `marking_skipped_advance_total` |
| T7 (прозрачный счётчик) | ✅ | `TestHarness --list/--json` + per-suite summary; таблица отчёта из `--json` |
| T8 (uiPayload INFO/EMERGENCY) | ✅ | `MarkingStatusUiPayloadTest` (16): INFO green/кнопок нет, EMERGENCY_TIMEOUT yellow «Проверка не выполнена», SKIPPED gray |
| T9 (verified config + Q8) | ✅ | `prohibitions.error_code_levels`/`flags`/`to_verify`; Q8 в `ОТКРЫТЫЕ_ВОПРОСЫ.md`; 14 assert'ов `ProhibitionConfigTest` |
| T10 (возвраты — Option A) | ✅ | `return` убран из ENUM sell-очереди + migration; `markReturn()` удалён; 12 assert'ов |
| T11 (бинарники из git) | ✅ | тег + бандл → `reset --soft 9d7ca39` → esmpack вне индексирования → 3 логических коммита; `git log --all` по esmpack — пусто; размер pack ~190 MiB — это легаси-ZIP в базовой линии (см. ОТЧЕТ §5), пуш main добавляет ~10 MiB текста |
| T12 (автозагрузка/деплой) | ✅ | SPEC §9 (autoload, prod-гейт, mock-сервер); `AutoloadSmokeTest` (8) |
| T13 (SPEC §2.4) | ✅ | поля `cassa_ord_det` + форматы + `is_marked` + Q9; `MarkingItemValidator` (+13 assert'ов); «подлежит без КИ → BLOCK» — COMMENTS-9 Fix #12 (новее формулировки T13) |
| T14 (DbSmokeTest) | ✅ | 21 assert'ов: 6 таблиц + `cassa_ord_det`, NULL-допуски, NOT NULL, race 2 коннекции |
| T15 (`.work_tmp_*`) | ✅ | удалены (привилегированный режим); `git status` чистый от них |
| T16 (финальный прогон) | ✅ | `php -l` — 0 ошибок; **298/298 PASS** (unit) + **17/17 PASS** (mock-интеграция); отчёт обновлён |
| COMMENTS-9 Блок 4 (mock-сервер) | ✅ | `tests/mock/MarkingMockRouter.php` + `Fixtures.php` (file-backed state) + `tests/integration/run_against_mock.php`; два порта (18080/18081) — CLI-сервер Windows однопроцессный |

---

## 2. Порядок выполнения (зависимости)

```
T11 (бинарники, пока история не пушена)
  └─ T2 (host-state таблица + репозиторий)
       └─ T1 (curl_multi гибрид — использует тот же каркас репозиториев/тестов)
  └─ T10 (решение return → DDL/модели)
       └─ T5 (тесты return — от решения зависит контракт)
  └─ T3 (claim) → T6 (skipped) → T4 (гейт ATOL) → T9 (verified config) → T13 (SPEC §2.4 + тест)
  └─ T7 (счётчик) → T8 (uiPayload) → T14 (DbSmoke — после T2/T3/T10, чтобы покрыть финальный DDL)
  └─ T15 (осколки) → T16 (финальный прогон + отчёт)
```
Параллельно двумя исполнителями: (A) T1+T2+T3+T14, (B) T4+T5+T6+T7+T8+T9+T10+T13+T15+T16; T11 — до разделения.

## 3. Оценка

| Блок | Содержание | Человек-дни |
|---|---|---|
| A (P0) | T1…T8 (гибрид, breaker, claim, гейт, тесты, skipped, счётчик, uiPayload) | ~7 |
| B (P1) | T9…T14 (verified, return, бинарники, деплой, §2.4, DbSmoke) | ~3.75 |
| C (финал) | T15…T16 | ~0.35 |
| **Итого** | | **~11 чел.-дн** (1 исполнитель: ~2.5 недели; 2 в параллель: ~1.5 недели) |

## 4. Критерии готовности волны (DoD)

1. 15/15 пунктов COMMENTS-8 закрыты (статусы — в §1.1) + Блоки 2–4 COMMENTS-9 (критичные правки, mock-интеграция, сверка отчёта).
2. Новые assert'ы ≥ 45: **факт — 155 новых** (298 − 143): гибрид (14 + 3 mock), breaker (9), claim (7), гейт (6), skipped advance, uiPayload (16), verified-config (14), DbSmoke (21), PII-маски (9), ratio-окно (8) и др.
3. `php tests/run_all.php` — 0 FAIL; таблица отчёта сгенерирована из запуска (`--json` → `verify_run_all.json`).
4. Git: `esmpack_*` вне истории (`git log --all` — пусто ✓), `.gitignore` дополнен ✓; 3 коммита чистые, пуш-готово ✓. Уточнение: size-pack ~190 MiB — легаси-ZIP базовой линии (ancestor origin/main), не спринт-01 (см. ОТЧЕТ §5); expectation «< 10 MiB» недостижим без перезаписи легаси-истории.
5. SPEC: §1.6 (verified + Q8 + TO_VERIFY), §2.4 (маркированные товары в `cassa_ord_det` + Q9), §3.4 (return — Option A + общий барьер гибрида), §9 (гейт ATOL, автозагрузка, mock-сервер) обновлены.
6. DDL: 6 таблиц (после T2) + ALTER `cassa_ord_det` — порт-перевод зелёный в `DbSmokeTest` (SQLite); валидация в test-MySQL — на развёртывании.

## 5. Риски

| Риск | Митигация |
|---|---|
| `curl_multi` на прод-FPM/Windows | `ext-curl` уже в require; сценарии покрыты фейками с задержками + 1 ручной прогон на тест-хосте |
| SQLite ≠ MySQL в DbSmoke | Смукованный DDL — только для CI; продакшн-DDL валидируется в test-MySQL (блок C / блокеры среды) |
| Пересборка локальных коммитов (T11) | Тег `backup/pre-wave2` перед reset; история не пушена → force-push не нужен |
| `verified=false`: до ответа Q8 | Дефолт WARN (SPEC §1.6) — без риска избыточного запрета; переключение в конфиге без деплоя кода |
| Raced deploy до T4 | Гейт `MARKING_ATOL_PLACEHOLDER` + `MARKING_ENABLE=off` по умолчанию — двойная защита |

---
*План составлен по факту COMMENTS-8.md и фактчеку кода на 05.10.2026. Согласовать решение T10 (Option A) с командой перед стартом; остальное — без блокировок.*
