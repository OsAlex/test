# Диагностика отчёта и кода

Проверил отчёт по фактам и по логике. Ниже — реальные проблемы, не процессные. Никаких владельцев/ETA/подписей — только «что сломано → почему → как чинить → как проверить».

---

## 1. Арифметика тестов не сходится

**Факт:** отчёт заявляет **143 PASS**. В разбивке по suite:

```
14 + 15 + 9 + 14 + 24 + 15 + 19 + 19 + 5 = 134
```

Разница — **9 тестов** не отнесены ни к одному suite. Либо счётчик задваивает/утраивает, либо есть скрытые suite'ы, не упомянутые в таблице.

**Fix:** прогнать `php tests/run_all.php --verbose` и вывести точный список файлов + per-file count. Итог зафиксировать в отчёте.

**Проверка:** `php tests/run_all.php | tee /tmp/tests.log && grep -c "PASS\|OK" /tmp/tests.log` — должно совпасть с числом assert'ов.

---

## 2. Нет тестов на 6 критичных модулей

**Факт:** в списке suite'ов отсутствуют тесты для:

| Модуль | Что критично | Что не проверяется |
|--------|--------------|---------------------|
| `MarkingLogger` | PII-политика | маскирование `X-API-KEY`/КМ/GTIN не проверено |
| `MarkingMetrics` | Пороги алертов | `ratio()` за 5-мин окно не проверено |
| `MarkingReturnService` | `/cis/returned` | контракт sell→return, транзакционность |
| `process_sells.php` | Retry×3, backoff | логика повторных попыток |
| `process_returns.php` | То же | — |
| `MarkingSellQueue`/`MarkingCheckHistory`/`MarkingReturnQueue`/`MarkingEmergencyState` | SQL-корректность | ни один тест не подключается к реальной БД |

**Root cause:** все тесты — unit на фейках; ни один не проверяет БД. DDL `sql/marking_tables.sql` есть, но **не выполняется** в тестах.

**Fix:** добавить `tests/DbSmokeTest.php`:
- запустить `marking_tables.sql` в in-memory SQLite (или test-MySQL);
- прогнать INSERT/SELECT/UPDATE по каждой из 5 таблиц;
- проверить NULL-допуски (`item_id`, `found`, `verified`, `sold`, `is_blocked`).

**Проверка:** `php tests/run_all.php` — новый suite зелёный; попытка вставить NOT NULL в NULL-поле падает.

---

## 3. `marking_emergency_state` используется не по назначению

**Факт:**
- SPEC §1.4: `marking_emergency_state` — для аварийного режима (HTTP 203), поля `emergency_started_at`, `actual_end_at`.
- Отчёт §4.3: `MarkingEmergencyState` — «блокировки хостов».
- Отчёт §7.5: «Блокировка хостов CDN сейчас — in-memory (per-process); персист в `marking_emergency_state` — опциональный».

**Проблема:** две разные сущности (аварийный режим ГИС МТ vs. circuit breaker отдельного CDN-хоста) смешаны в одной таблице. Изначально таблица создавалась под §1.4, теперь туда же хотят писать `blocked_host_until`.

**Fix (выбрать одно):**

**Вариант A — разнести:**
```sql
-- marking_emergency_state (уже есть в §1.4) — оставить как есть
-- добавить:
CREATE TABLE marking_cdn_host_state (
    host VARCHAR(255) PRIMARY KEY,
    blocked_until TIMESTAMP NULL,
    fail_count INT DEFAULT 0,
    last_check_at TIMESTAMP NULL,
    avg_time_ms INT NULL
);
```

**Вариант B — переименовать и не путать:** `marking_emergency_state` → `marking_emergency_state`, оставить в покое; circuit breaker делать отдельной таблицей.

**Проверка:** `grep -rn "MarkingEmergencyState" Service/ Models/` — во всех местах семантика совпадает с §1.4 (203), не с host blocking.

---

## 4. Circuit breaker — per-process, не работает в FPM

**Факт:** отчёт §7.5: «Блокировка хостов CDN сейчас — in-memory (per-process); персист — опциональный».

**Проблема:** в проде PHP работает через FPM-pool (N воркеров). Каждый процесс имеет **свой** счётчик fail'ов. Если 3 fail'а случились в процессе A, процесс B **не знает** об этом и продолжает использовать мёртвый хост. Circuit breaker **не работает** в проде.

**Fix:**
- Persist счётчик fail'ов в `marking_cdn_host_state` (см. п.3) или в Redis/APCu с атомарным INCR.
- Перед использованием хоста — `SELECT blocked_until FROM marking_cdn_host_state WHERE host = ?`.
- После fail — атомарный `INSERT ... ON DUPLICATE KEY UPDATE fail_count = fail_count + 1`.

**Проверка:** unit-тест с двумя разными инстансами `CdnService`, разделяющими один storage. Первый инстанс 3 fail'а → второй инстанс видит хост заблокированным.

---

## 5. `checkHybrid` — не параллельно

**Факт:** отчёт §7.2: «PHP без потоков — онлайн выполняется с curl-таймаутом = барьер 1.5 c; истинно параллельный запуск (pcntl_fork) — в продаже».

**Проблема:** это **не** реализует SPEC §3.4. Если онлайн идёт первым и таймаутит на 1.5s, офлайн вообще не стартует. Если онлайн быстрый (0.5s), офлайн стартует после него и займёт ещё 0.5s → **общее 1.0s**, но SPEC говорит «оба должны прийти до 1.5s, решение по обеим ветвям».

Хуже: при такой архитектуре барьер **1.5s на онлайн** (curl-timeout) — а SPEC говорит про **общий барьер 1.5s** для принятия решения.

**Fix:** `curl_multi` без fork — это доступно в plain PHP:
```php
$mh = curl_multi_init();
$chOnline = $this->buildOnlineHandle();
$chOffline = $this->buildOfflineHandle();
curl_multi_add_handle($mh, $chOnline);
curl_multi_add_handle($mh, $chOffline);
// ждать с таймаутом 1.5s
do {
    $status = curl_multi_exec($mh, $running);
    if ($running) curl_multi_select($mh, 0.05);
} while ($running && microtime(true) - $start < 1.5);
```

**Проверка:** unit-тест — онлайн 1.4s, офлайн 1.6s → результат «только онлайн, решение по онлайну» (а не «оба не ответили»). Сейчас без curl_multi будет неверный результат.

---

## 6. `verified=false`: WARN или BLOCK?

**Факт:**
- SPEC §1.6: `1–9 → WARN`, значит `verified=false` (крипто-проверка) → **WARN**.
- PIOT §4 / Приложение 2: «нарушение формата» → **запрет продажи**.
- В отчёте это не отмечено как расхождение.

**Root cause:** в COMMENTS-6 эта коллизия отмечалась (я писал в предыдущем ревью), но в SPEC §1.6 она **не закрыта** — просто убрали исторический комментарий, но семантика осталась WARN.

**Fix:**
- Уточнить у Оператора ЧЗ или в актуальной методичке: `verified=false` — продажа разрешена с предупреждением или запрещена?
- Если BLOCK — изменить маппинг в `config/marking.prohibitions.error_code_levels`:
  ```php
  5 => 'BLOCK', 6 => 'BLOCK', 7 => 'BLOCK',  // крипто-подпись
  ```
- Если WARN — зафиксировать в SPEC §1.6 явно со ссылкой на источник.

**Проверка:** `grep -n "verified" config/marking.php tests/CodeCheckServiceTest.php` — соответствие SPEC и PIOT.

---

## 7. `.deb/.rpm` в репозитории — навсегда

**Факт:** отчёт §5: «бинарные пакеты ЕСМ/Атол (.deb/.rpm) попали в docs-коммит целиком. Если не нужны — исключить отдельным коммитом».

**Проблема:** «исключить отдельным коммитом» **не убирает** их из истории `.git`. Каждый клон репо теперь тянет N×100 MB бинарников **навсегда**.

**Fix (выбрать):**
1. Если история спринта короткая и не важна:
   ```bash
   git reset --soft HEAD~2  # откатить оба коммита
   # удалить бинарники
   git add -A
   git commit -m "..."
   ```
2. Если история важна:
   ```bash
   git filter-repo --path docs/sprints/01/PIOT/esmpack_linux64_1.6.4.0.477/ --invert-paths
   # или BFG: java -jar bfg.jar --delete-folders esmpack_linux64_1.6.4.0.477
   ```
   Плюс force-push + предупреждение команде.

**Проверка:** `git count-objects -vH` до/после; `git log --all --oneline -- 'docs/sprints/01/PIOT/esmpack_linux64_1.6.4.0.477/'` — пусто.

**Заодно:** добавить в `.gitignore`:
```
*.deb
*.rpm
*.tar.gz
docs/sprints/*/PIOT/esmpack_*/
```

---

## 8. `INSERT_ATOL_OFFICIAL_EXAMPLE` в проде — без гейта

**Факт:** отчёт §6.1: в `Models/Check::buildMarkingAttribute()` стоит плашка `INSERT_ATOL_OFFICIAL_EXAMPLE` с «рабочим приближением: additionalAttribute». §8.5: заменить после получения JSON.

**Проблема:** если кто-то выставит `MARKING_ENABLE=on` до получения JSON Атола — чек уйдёт на реальную ККТ со **неверной структурой тега 1260**. ККТ его отклонит или примет с ошибкой. Кассир увидит невнятную ошибку Атол.

**Fix:**
```php
// Models/Check.php::buildMarkingAttribute()
if (config('marking.atol.tag1260_placeholder') && getenv('APP_ENV') === 'prod') {
    throw new RuntimeException(
        'ATOL tag1260 example missing; refusing to build production check. ' .
        'Set MARKING_ATOL_PLACEHOLDER=0 after obtaining official JSON.'
    );
}
```

В `.env.example`:
```
MARKING_ATOL_PLACEHOLDER=1
```

В SPEC §9 — правило: прод не стартует при `placeholder=1` и `APP_ENV=prod`.

**Проверка:** установить `MARKING_ATOL_PLACEHOLDER=1`, `APP_ENV=prod` → `make_json()` бросает исключение до отправки на ККТ.

---

## 9. `vendor/autoload.php` stub — риск деплоя

**Факт:** отчёт §4.5 + §7.4: тесты создают локальный stub `vendor/autoload.php` в `.gitignore`, потому что `composer install` не выполнялся.

**Проблема:**
1. Если `composer.json` есть, но `vendor/` не закоммичен — на проде **нужен** `composer install`. Отчёт этого не описывает.
2. Если `composer.json` **нет**, то как проект работает с namespace `Models\`, `Service\`, `Tests\`? Через ручной `spl_autoload_register`? Тогда — где он инициализируется в проде? В отчёте нет.
3. Тесты на stub'е — тесты на другой среде, чем прод.

**Fix:**
- `git ls-files composer.json composer.lock` → проверить наличие.
- Если есть — добавить в деплой-чеклист `composer install --no-dev --optimize-autoloader`.
- Если нет — задокументировать в SPEC §9 реальный механизм автозагрузки в проде (не stub).

**Проверка:** развернуть чистый клон репо на тестовом хосте, выполнить деплой-инструкцию от начала до конца, запустить `php -r 'require "index.php"; echo "ok";'`.

---

## 10. `MarkingReturnService` — двойная запись

**Факт:**
- SPEC §2.1: «Возвраты после фискализации — **отдельная таблица** `marking_return_queue`».
- Отчёт §4.2: `MarkingReturnService` — «`/cis/returned` по чеку: очередь **+ синхронное** "лучшее усилие", **sell→return**».
- Отчёт §3 #9: «Очередь продаж: … `status` `return` для проведённых возвратов».

**Проблема:** «sell→return» означает, что при возврате **меняется статус в `marking_sell_queue` на `return`**. Плюс **создаётся** запись в `marking_return_queue`. Два источника истины. Что если один упадёт, второй нет?

**Fix (выбрать одно):**
- **A:** `marking_sell_queue` — не трогать; вся семантика возврата — только в `marking_return_queue`. Проверки: `SELECT ... WHERE order_id=? AND status='done'` остаются валидными.
- **B:** Не иметь отдельной `marking_return_queue`; всё в `marking_sell_queue` с полем `type`.

**Проверка:** unit-тест — после возврата `marking_sell_queue.status='done'` **не меняется**; в `marking_return_queue` появилась запись. Или наоборот, но консистентно.

---

## 11. Worker — race на `pending`

**Факт:** отчёт §4.2: `process_sells.php` — «pending → ЛМ ЧЗ → done/failed, retry×3». Не описано, как выбираются строки.

**Проблема:** если два воркера запустятся одновременно (или воркер запущен в двух экземплярах), оба выберут **одну** `pending` строку, оба вызовут `/cis/sell` → двойная регистрация продажи.

**Fix:**
```sql
-- атомарный захват
UPDATE marking_sell_queue
SET status = 'processing', processed_at = NOW(), attempts = attempts + 1
WHERE status = 'pending' AND id = (
    SELECT id FROM (
        SELECT id FROM marking_sell_queue
        WHERE status = 'pending'
        ORDER BY id LIMIT 1
    ) AS t
);
-- если affected_rows = 1 → работаем с этим id
```

Или `SELECT ... FOR UPDATE SKIP LOCKED` (MySQL 8+, PostgreSQL).

**Проверка:** unit-тест с in-memory SQLite (или test-MySQL) — запустить два параллельных захвата, убедиться, что только один получил строку.

---

## 12. Поля товаров — плашки без спецификации

**Факт:** отчёт §7.1: `cod_marking_cis` и `cod_marking_package_type` — «плашки до согласования схемы с 1С».

**Проблема:** нет описания:
- Где хранятся (таблица `order_items`? отдельная?).
- Формат КИ: разделитель `;`, максимальная длина, валидация.
- Откуда попадают в чек: сканер? импорт? вызов API?
- Что если КМ нет, а `cod_marking_package_type` стоит — как реагирует проверка?

**Fix:** добавить в SPEC §2.4 раздел «Маркированные товары в заказе»:
```sql
-- добавить к order_items:
cod_marking_cis VARCHAR(2000) NULL,       -- 'ки1;ки2;...'
cod_marking_package_type ENUM('ITEM','GROUP','BUNDLE','PRODUCT_SET') NULL,
cod_marking_scan_at TIMESTAMP NULL,
```

Плюс тест: `MarkingCheckService` с пустым `cod_marking_cis` и `package_type=ITEM` → SKIPPED, не BLOCK.

**Проверка:** `grep -rn "cod_marking_cis" Models/ Service/` — есть только одно место чтения, формат задокументирован.

---

## 13. `Авансовые чеки` — throw вместо graceful skip

**Факт:** отчёт §7.3: «Авансовые чеки (сводные позиции `getItemsSum`) в MVP не маркируются — сознательный throw».

**Проблема:** throw прерывает весь чек. Кассир видит непонятную ошибку, если случайно попал в авансовый режим. Правильно — не «throw», а **явный SKIPPED** с логом.

**Fix:**
```php
if ($this->isAdvanceCheck()) {
    return MarkingCheckResult::skipped('advance_check_no_goods');
}
```
Плюс счётчик `marking_skipped_advance_total`.

**Проверка:** unit-тест — авансовый чек не бросает исключение, `MarkingCheckResult.status = SKIPPED`, в истории есть строка с `skip_reason = 'advance_check_no_goods'`.

---

## 14. `MarkingStatus` uiPayload — не покрыт INFO и EMERGENCY

**Факт:** отчёт §4.5, suite `MarkingStatusTest` — «uiPayload BLOCK/WARN (цвет, текст, кнопки, KPI)». INFO и EMERGENCY не упомянуты.

**Fix:** добавить тесты:
- INFO: `errorCode=0`, `found=true`, `verified=true`, `sold=false` → uiPayload «зелёный, без кнопок».
- EMERGENCY_TIMEOUT: `source=EMERGENCY_TIMEOUT` → uiPayload «жёлтый, кнопка continue + warning».

**Проверка:** `grep -c "EMERGENCY_TIMEOUT" tests/MarkingStatusTest.php` ≥ 1.

---

## 15. `docs/sprints/01/.work_tmp_*` — «не позволила удалить»

**Факт:** отчёт §5: «осколки, песочница не позволила удалить».

**Проблема:** удаление файла — это `rm` или `git rm`. «Песочница не позволила» — либо правда (read-only mount), либо ошибка в правах. В любом случае — их либо нет, либо они есть.

**Fix:**
```bash
ls -la docs/sprints/01/.work_tmp_*    # проверить, что это
rm -rf docs/sprints/01/.work_tmp_*
git status                            # убедиться, что не трекаются
```

Если файловая система read-only — `chmod`, remount rw.

**Проверка:** `find docs/sprints/01 -name '.work_tmp_*'` → пусто.

---

## Сводная таблица

| # | Проблема | Где | Fix | Приоритет |
|---|----------|-----|-----|-----------|
| 1 | Тестов 134, не 143 | Отчёт §4.5 | Пересчитать, обновить | 🟡 |
| 2 | Нет тестов БД/логгера/метрик/return | tests/ | Добавить DB smoke + unit'ы | 🔴 |
| 3 | `marking_emergency_state` смешана семантика | SPEC §2, Models | Разнести host state в отдельную таблицу | 🔴 |
| 4 | Circuit breaker per-process | CdnService | Persist fail_count в storage | 🔴 |
| 5 | `checkHybrid` не параллелен | MarkingCheckService | curl_multi | 🔴 |
| 6 | `verified=false` — WARN или BLOCK | config + SPEC | Уточнить у Оператора | 🟠 |
| 7 | .deb/.rpm в git | docs commit | filter-repo / reset | 🟠 |
| 8 | ATOL placeholder без гейта | Check.php | throw при prod+placeholder | 🔴 |
| 9 | vendor stub | tests/ | Проверить composer.json, деплой-док | 🟠 |
| 10 | Двойная запись return | MarkingReturnService | Выбрать один источник | 🟠 |
| 11 | Worker race на pending | process_sells.php | SELECT FOR UPDATE / атомарный UPDATE | 🔴 |
| 12 | Поля `cod_marking_*` без спеки | Models | SPEC §2.4 + тест | 🟠 |
| 13 | Авансовый чек — throw | Check.php | SKIPPED вместо throw | 🟡 |
| 14 | uiPayload INFO/EMERGENCY не тестируются | tests | Добавить | 🟡 |
| 15 | `.work_tmp_*` не удалены | docs | rm -rf | 🟡 |

---

## Что можно проверить прямо сейчас, без внешних данных

1. Пересчитать тесты: `php tests/run_all.php --list | wc -l`.
2. `git count-objects -vH` — размер .deb/.rpm.
3. `grep -rn "verified" config/marking.php tests/CodeCheckServiceTest.php` — уровень для `verified=false`.
4. `grep -rn "MarkingEmergencyState" Service/ Models/` — семантика.
5. `grep -n "pcntl\|curl_multi" Service/Marking/MarkingCheckService.php` — параллельность.
6. `grep -n "FOR UPDATE\|SKIP LOCKED" Service/Marking/process_sells.php Service/Marking/process_returns.php`.
7. `grep -n "INSERT_ATOL_OFFICIAL_EXAMPLE\|tag1260_placeholder" Models/Check.php`.

Результаты по каждому пункту — либо «фикс уже есть, ок», либо «нужно править».

---

## Что дальше

Готов сразу:
- написать `tests/DbSmokeTest.php` (in-memory SQLite, 5 таблиц + NULL-допуски);
- написать `curl_multi`-версию `checkHybrid` с unit-тестом на race-сценарий (онлайн 1.4 / офлайн 1.6);
- написать атомарный `claimNextPending()` для воркеров с тестом на двойной захват;
- подготовить `filter-repo`-команду и `.gitignore` для `.deb/.rpm`.

Скажите, с чего начать.