# SPEC.md — Единая спецификация интеграции с Честным Знаком (ЧЗ)
# Sprint 01

> **Источник:** `COMMENTS.md`, `COMMENTS-2.md`, `COMMENTS-3.md`, `COMMENTS-4.md`, `COMMENTS-5.md`, `COMMENTS-7.md`, `docs/sprints/01/Запрос_заказчику_ответ.md`.
> **Дата:** 08.02.2026 (обновлено после Запрос_заказчику_ответ.md).
> **Статус:** Направлен на утверждение. Без письменного утверждения — не начинать Шаг 3 (код).
>
> **Критическое условие (COMMENTS-2.md §1, COMMENTS-4.md §2.5):** Код (Шаг 3) **не начинается** до утверждения единого `SPEC.md`. Заявка на sandbox и развертывание ЛМ ЧЗ должны быть сделаны **немедленно (до Шага 1)**.

---

## 1. Общие требования

### 1.1 Планируемый запуск
- **Дедлайн:** **01.03.2026** (рабочий). Буфер **01.04.2026**.
- **Токен prod валиден** до **01.10.2026** per методичка v17.
- **Sandbox-токен** — отдельный вопрос, см. §1.4.
- **Требуется у заказчика:** номер и дата норм-акта, вводящего обязательную маркировку.
- **Если 01.03 — обязательно** покрыть аварийные ситуации (HTTP 203) и офлайн-режим ЛМ ЧЗ до релиза.

### 1.2 Регионы и тайм-зона
- **MVP:** Only MSK, `timeZone = 2`.
- **Интерфейс:** `Cassa::getTimeZone()`: возвращает целое число 1..11, по умолчанию `2` (MSK), fallback — `config('marking.default_timezone')`.
- **timeZone влияет на:** `smp`/`mrp` для групп КМ **3, 12, 16**.
- **Если есть регионы не в MSK:** сделать `Cassa::getTimeZone()` в таблице касс со значением по умолчанию `2`.
- **SPEC строго указывает:** диапазон 1..11, дефолт 2, при отсутствии значения в кассе — фоллбэк на `config('marking.default_timezone')`.
- **Q2a (docs/sprints/01/Запрос_заказчику_ответ.md):** шаблонeria — таблица с городом, адресом, ID ЧЗ (1..11), количеством касс. **Если все точки в MSK** — `timeZone=2` и не расширять интерфейс. **Если хотя бы одна вне MSK** — `Cassa::getTimeZone()` с маппингом по `cassa_id`.
- **Важно:** `timeZone` — это **часовой пояс МЕСТА РАСЧЁТА**, а не офиса. Калининград (UTC+1, код 1), Камчатка (UTC+12, код 11).

### 1.3 X-API-KEY и Token rotation policy
- Хранится в `.env` через `config('cz.api_key')`.
- **Никогда не логировать полный ключ** — редигстрировать как `MARKING_API_KEY=sk-…` (обрезанная строка) или `sha1(token)` / первые 8 символов.
- **Политика ротации (новая §1.3.1):**
```
Триггеры:
- expires_at < now + 30 дней → WARN-алерт
- expires_at < now + 7 дней  → CRITICAL-алерт + email
- expires_at < now           → offline-only + CRITICAL

Механизмы:
- Ручная: CLI-команда marking:token:refresh
- Автоматическая: POST /api/v3/true-api/auth/permissive-access (УКЭП)

Аудит:
- marking_token_audit: old_hash, new_hash, reason, actor, created_at
```
- **Валидация при старте:** проверять `expires_at`. Если истёк — логировать critical, работать в offline-only режиме (если ЛМ ЧЗ готов).
- **`.env.example`:** добавить без реального значения:
  ```
  GIS_MT_TOKEN=
  GIS_MT_TOKEN_EXPIRES=2026-10-01
  COMPANY_INN=
  ```

### 1.4 Sandbox — ДВА контура (Q4a)
| Контур | Что это | Как получить |
|--------|---------|--------------|
| **ГИС МТ sandbox** | Облачный тестовый контур ЧЗ (`https://markirovka.sandbox.crptech.ru`) | Заявка через ЛК ЧЗ → «Техподдержка» → «Доступ к тестовому контуру» |
| **ЛМ ЧЗ sandbox** | Локально установленный модуль ЛМ ЧЗ 2.0 на тестовом хосте | Скачать дистрибутив с `chestnyznak.ru`, установить на VM/сервер (Ubuntu 22.04), инициализировать с токеном ГИС МТ sandbox |
| **ЕСМ / ТС ПИоТ** | Локально на кассе (для офлайн-авторизации через Token) | Установить дистрибутив ЕСМ ≥ 1.6.2.0 на тестовую кассу |

**Q4a (docs/sprints/01/Запрос_заказчику_ответ.md):** Срок действия sandbox-токена требует прямой проверки в ЛК ЧЗ (Тестовый контур → Профиль → Токен → «Действительнодо»). **Не путать с prod-токеном (01.10.2026).**
**Рекомендация для SPEC:** зафиксировать **правило**, а не дату:
- «Sandbox-токен действителен до `<дата из ЛК>`. Ответственный — [роль]. Проверка каждые 30 дней. При истечении < 14 дней — алерт.»

### 1.5 Атоль API v2 структура
- **Порт:** 16732.
- **Endpoint:** `api/v2/requests`.
- **industryInfo** находится **на уровне позиции чека** (`items[]`), а не на уровне чека.
- **Тег 1260** = `additionalAttribute`.

**Формат 1263 (финальное неизменяемое правило, §1.5, per docs/sprints/01/Запрос_заказчику_ответ.md):**
- **`ДД.ММ.ГГГГ`** (по приказу ФНС ЕД-7-20/662@). В Alter Questions earlier ISO was mentioned, but **финальное правило: ДД.ММ.ГГГГ** (приказ ФНС). ISO (`YYYY-MM-DD`) используется в других полях API ГИС МТ, но **не в теге 1263**.
- **В Alter Questions Q3b заменено на финильное правило:** `1263 — формат ДД.ММ.ГГГГ (приказ ФНС ЕД-7-20/662@). Уточнение закрыто.`

**1264 (финальное неизменяемое правило, §1.5):**
- **Конфиг + справочник по товарным группам.**
- ГИС МТ в `/codes/check` **не возвращает** номер документа основания. Группа КМ определяется из `groupIds` в ответе.
- **Структура `config/marking.php`** (из docs/sprints/01/Запрос_заказчику_ответ.md):
```php
'tag1260' => [
    'default' => ['1262' => '030', '1263' => '28.12.2018', '1264' => '1955'],
    'by_group' => [
        3  => ['1263' => '28.12.2018', '1264' => '1875'], // табак
        12 => ['1263' => '28.12.2018', '1264' => '1944'], // АТП
        16 => ['1263' => '28.12.2018', '1264' => '1951'], // НСП
        6  => ['1263' => '28.12.2018', '1264' => '1955'], // вода
        7  => ['1263' => '30.11.2019', '1264' => '1749'], // пиво
        15 => ['1263' => '15.12.2020', '1264' => '2099'], // молочка
        9  => ['1263' => '28.12.2018', '1264' => '1956'], // одежда
        10 => ['1263' => '28.12.2018', '1264' => '1958'], // шины
        5  => ['1263' => '28.12.2018', '1264' => '1957'], // духи
    ],
],
```
- **ФОИВ код 1262 = "030"** для **всех** товарных групп ЧЗ (код ФНС). Не нужно ждать ответа Атоль по этому пункту — зафиксировать как факт.

### 1.6 Коды запрета (ProhibitionLevel) — ИСПРАВЛЕНО
**Правильная таблица:**

| Код | Значение | Level |
|-----|----------|-------|
| 0 | Нет ошибки | INFO |
| 1–9 | Ошибки валидации/крипто/AI | **WARN** |
| 10 | Не найден в ГИС МТ | **BLOCK** |
| 11 | Не найден в трансгране | WARN |

**Волна 2 (COMMENTS-9 Fix #6): маппинг и флаги — из конфига.**
Таблица выше — дефолт; override — `config marking.prohibitions`:
- `error_code_levels`: `{0: INFO, 1..9: WARN, 10: BLOCK, 11: WARN}` (изменяемо без кода);
- `flags` — per-code флаги ответа `/codes/check`, «только в сторону запрета» (max):
  - `found_false → BLOCK` — **только при errorCode 0/null** («код не найден, ошибок нет» =
    не согласованность данных ГИС МТ). При errorCode 1..11 действует таблица выше
    (в частности, 11 = «не найден в трансгране» — WARN, **не** BLOCK — иначе 11 стал бы BLOCK);
  - `verified_false → BLOCK` — Q8 закрыт по PIOT §4 (COMMENTS-10 2.5): продажа КИ,
    не верифицированного ГИС МТ, запрещена; переключение — одной строкой конфига
    (см. TO_VERIFY ниже);
  - `sold_true → BLOCK` (дубль/повторная продажа);
  - `is_blocked_true → BLOCK`.
- Реализация: `MarkingStatus::fromErrorCode()` + `MarkingStatus::resolveLevel()`
  (используют `CodeCheckService` и `MarkingStatus::fromCodeCheck()`).

**TO_VERIFY (закрыт — COMMENTS-10 2.5, волна 2.1):**
- `verified_false` — **BLOCK** (Q8 закрыт по PIOT §4: «продажа без верифицированного КИ
  запрещена; касса запрашивает сканирование»). Изначальный вариант WARN (волна 2) отменён;
- `error_code_5`, `error_code_6`, `error_code_7` — WARN по таблице 1–9 (PIOT §4);
- `config marking.prohibitions.to_verify` — **пустой список** (все позиции покрыты
  `error_code_levels`/`flags`); механизм сохранён: новая позиция = новая проверка
  перед продом (QA-чек-лист).

### 1.7 Multiple КМ в чеке
- **Онлайн:** одним POST-запросом на `/codes/check` со списком всех КМ (до 100 КМ за запрос).
- **Офлайн:** одним POST-запросом на `/outCheck` со списком всех КИ (до 100 КИ).
- **Если список > 100** — разбить на батчи по 100.
- **Ответы парсить** как массив, собирать результаты по каждому КМ.

### 1.8 Check.php — addMarkingAttributes
- **Сигнатура:** `addMarkingAttributes(array $items, MarkingCheckResult $result)`
- **Мatching:** по `item_id`, а не по `?string $itemGuid`.
- **Multiple КМ на один item:** сопоставление по `item_id`.
- **При BLOCK:** позиция не добавляется в чек (или чек отклоняется).

---

## 2. Модели данных

### 2.1 marking_sell_queue
```sql
CREATE TABLE marking_sell_queue (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT NOT NULL,
    check_uuid VARCHAR(36) NOT NULL,
    cis_list JSON NOT NULL,
    status ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'pending',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,
    error VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP NULL,
    heartbeat_at TIMESTAMP NULL,                -- COMMENTS-11 #1: момент ИСТЕЧЕНИЯ LEASE (NOW + 15 мин при claim/extendLease), не last-seen
    INDEX idx_order (order_id),
    INDEX idx_status (status),
    INDEX idx_uuid (check_uuid),
    INDEX idx_processing_stale (status, heartbeat_at)
)
```
**Статусы:**
- `pending` — проверка еще не выполнялась.
- `processing` — идет обработка (worker взял в работу).
- `done` — проверка прошла успешно, чек можно оформлять.
- `failed` — retry 3 раза с backoff истощился — алерт персонала.
- **Lease semantics (COMMENTS-10 2.1 → COMMENTS-11 #1):** `heartbeat_at` — момент истечения LEASE
  (`claimNextPending()`/`extendLease()`: NOW + 15 мин). Reclaim «зомби» — только при истёкшем
  lease (`heartbeat_at < NOW` или NULL): attempts < 3 → `pending`, иначе → `failed`
  (+ `processed_at`, метрика `marking_stale_reclaim_total`). Воркер продлевает lease
  (`extendLease`) перед каждым долгим HTTP-вызовом ЛМ ЧЗ — активная обработка НЕ отснимается.
- **`attempts` = число claim'ов (обработок)** — инкрементит сам claim (COMMENTS-11 #1);
  retry-путь НЕ добавляет +1 → ровно 3 обработки строки.
- **Идемпотентный retry `/cis/sell` (COMMENTS-11 #3):** при `attempts > 1` перед подтверждением
  продажи — сверка `/cis/sold` (пагинация по 100, кап 5000 КИ): КИ, уже зарегистрированные
  ЛМ ЧЗ предыдущей попыткой, не отправляются повторно; всё продано → `done` без вызова sell.
- `skipped` — **чек фискализирован, но запись не создана** (товар не маркирован, `is_marked=false`). **Не создаётся запись** вообще. (Ранее статус `skipped` в ENUM противоречил тексту: «запись не создаётся вообще», но `skipped` был в ENUM столбца. Исправлено: убрано `skipped` из ENUM, теперь определяется через `skip_reason` в `marking_check_history` или через условие `is_marked=false` на этапе валидации перед созданием записи).

### 2.2 marking_check_history — ИСПРАВЛЕНО (docs/sprints/01/Запрос_заказчику_ответ.md §2)
```sql
CREATE TABLE marking_check_history (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    request_id VARCHAR(36) NOT NULL,
    item_id VARCHAR(36) NULL,        -- добавлено: NULL allowed для офлайн
    cis VARCHAR(255) NULL,         -- добавлено: выделенный КИ (для табака ≠ code)
    code VARCHAR(255) NOT NULL,
    gtin VARCHAR(14) NULL,
    group_id INT NULL,
    parent_cis VARCHAR(255) NULL, -- для агрегатов
    found BOOLEAN NULL,
    verified BOOLEAN NULL,
    sold BOOLEAN NULL,
    is_blocked BOOLEAN NULL,
    prohibition ENUM('ALLOW','WARN','BLOCK') NOT NULL,
    source ENUM('ONLINE','OFFLINE','HYBRID','EMERGENCY','EMERGENCY_TIMEOUT','SKIPPED') NOT NULL,
    skip_reason ENUM('not_marked','duplicate_pre_fiscal','cancelled_pre_fiscal','advance_check_no_goods') NULL,
    cdn_host VARCHAR(255),
    error_code INT,
    package_type VARCHAR(20),
    response_time_ms INT,
    order_id BIGINT,
    check_id BIGINT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_request (request_id),
    INDEX idx_created (created_at)
)
```
**Важно:** 
- `item_id` → NULL allowed (офлайн-проверка асинхронна/повторна).
- `cis`, `gtin`, `group_id`, `parent_cis` — добавлены для офлайн-проверок.
- `found`/`verified`/`sold`/`is_blocked` → NULL allowed (офлайн-данные ЛМ ЧЗ не возвращают `found`/`verified`).
- `source` — как раздел классичыны проверки (ONLINE/OFFLINE/HYBRID/EMERGENCY/SKIPPED).
- `parent_cis` — для агрегатов (когда в чеке есть блок и входящие в него единицы).

### 2.3 §3.4 MarkingCheckService — EMERGENCY_TIMEOUT (docs/sprints/01/Запрос_заказчику_ответ.md §1)
**Разделение:** `EMERGENCY_TIMEOUT` значение enum хранится в §2 (как значение `source`), а текстовое описание и логика — в §3.4.

**§3.4 (новое/исправленное):**
> «Если оба ответа пришли до 1.5 с → решение по обеим веткам. Если только онлайн → по онлайн. Если только офлайн → по офлайн. **Если ни один не ответил за 1.5 s → `source = EMERGENCY_TIMEOUT`, `prohibition = WARN`, алерт, если доля EMERGENCY_TIMEOUT > 5% за 5 минут.**»

**COMMENTS-11 #2 (волна 2.2) — валидность HTTP-ответов гибрида:**
- учитываются **только 2xx** (`HttpResponse::ok()`); 4xx/5xx-тела НЕ парсятся (500 с JSON
  «error» не превращается в результат) — ветка отбрасывается, решение по оставшейся ветке
  (401 онлайн — отдельная ветка: метрика `marking_401_total`, офлайн остаётся);
- **HTTP 203 (аварийный режим ГИС МТ)** → `MarkingEmergencyState::activate(ИНН, 'HTTP 203…')`
  + метрика `marking_emergency_total` + лог `hybrid_online_203`, результат — `source = EMERGENCY`
  **без merge** с офлайн-веткой (при невалидном теле 203 — `allowAll`); правило §3.5
  (агрегат + единицы при недоступном онлайн → BLOCK) применяется как для EMERGENCY.

**COMMENTS-12 (волна 2.3) — дополнения к аварийному режиму ГИС МТ:**
- **2.2 — централизованная обработка 203:** 203 на **любом** endpoint ГИС МТ (`/codes/check`,
  `/cdn/info`, `/cdn/health/check`) активирует `marking_emergency_state` — единая точка
  `CdnService::onEmergency203(endpoint, host)` (метрика `marking_emergency_total` +
  `activate(ИНН, 'HTTP 203 …')`); БД/отсутствие ИНН — не блок (warn-лог `emergency_state_no_inn` /
  `emergency_state_activate_failed`). Гибрид-ветка (COMMENTS-11 #2) обрабатывает 203 своей точкой
  (raw-запрос, не CdnService) — двойного счёта метрики нет.
- **2.3 — авто-expire (fail-open):** `MarkingEmergencyState::expireIfStale()` в начале
  `check()`: `is_active=1` И (`actual_end_at` задан и ему > 3 суток ИЛИ `actual_end_at` NULL и
  режим активен > 7 суток) → `is_active=0`, `actual_end_at = started_at + 7 суток` (метка
  авто-expire); метрика `marking_emergency_auto_expired_total` + ERROR-лог. Режим не висит вечно
  после восстановления ГИС МТ; БД недоступна — не блок (try/catch в `check()`).
- **2.5 — `activate()` race-safe:** два параллельных 203 от разных воркеров не роняют второго —
  при UNIQUE-конфликте по `inn` один повтор DELETE+INSERT (портативный эквивалент
  `ON DUPLICATE KEY UPDATE`; `INSERT … ON DUPLICATE KEY UPDATE` не портативен для SQLite).

### 2.4 §2.1 `skipped/duplicate/cancelled` — разделение (docs/sprints/01/Запрос_заказчику_ответ.md §4)
Вместо одного статуса `skipped` с трижды разными смыслами — **три отдельных статуса** или `skip_reason ENUM`:

**Вариант А (три статуса):**
- `skipped` — **чек фискализирован, но запись не создана** (товар не маркирован, `is_marked=false`). **Не создаётся запись** вообще.
- `duplicate` — **КИ уже в БД проданных**. Создаётся запись с `status=duplicate`, алерт персоналу.
- `cancelled` — **чек отменён до фискализации**. **Запись не создаётся** (путь валидации до создания записи).

**Вариант Б (один статус + reason):**
- `skip_reason ENUM('not_marked','duplicate_pre_fiscal','cancelled_pre_fiscal') NULL`
- Тогда `skipped` = «запись была создана, но по `skip_reason` не должна регистрироваться». Это осмысленный статус.

**Принято (реализация, см. sql/marking_tables.sql):** Вариант Б — `skip_reason` в `marking_check_history`;
из ENUM `marking_sell_queue.status` статус `skipped` отсутствует (товар не маркирован → запись не создаётся).
Статус `return` в ENUM `marking_sell_queue` **УБРАН** в волне 2 (COMMENTS-9 Fix #10, решение A — см. §3.4).

**Волна 2 (COMMENTS-9 Fix #12): поля маркированных товаров.**
Источник данных — таблица `cassa_ord_det` (поз. заказа; `Models\Order::getByOrderId` →
`SELECT * FROM cassa_ord_det WHERE cod_ord_id=...`, из неё же собирается `Order::items`):

| Поле | Формат | Семантика |
|------|--------|-----------|
| `cod_marking_cis` VARCHAR(2000) NULL | КИ через `;` (≤50 шт., ≤2000 байт, без дублей) | отсканированные КМ позиции |
| `cod_marking_package_type` ENUM('ITEM','UNIT','GROUP','BUNDLE','PRODUCT_SET') NULL | GROUP/BUNDLE/PRODUCT_SET = агрегат, иначе единица | тип позиции (SPEC §3.5) |
| `cod_marking_scan_at` DATETIME NULL | — | момент сканирования КМ |
| `is_marked` (признак 1С) | 0/1 | товар подлежит маркировке |

Семантика (реализовано в `Check::collectMarkingByItem` + `MarkingItemValidator`):
- `is_marked=1` ИЛИ `package_type` задан → товар подлежит маркировке;
- **покрыт, но КИ пустые** → `MarkingBlockedException` (BLOCK ДО проверки кодов:
  «товар требует маркировки, но КМ не отсканирован») — решение волны 2:
  COMMENTS-8 #12 («не BLOCK») заменён COMMENTS-9 Fix #12 («BLOCK») — свежая спецификация;
- КИ есть → `MarkingItemValidator::parseCis` (дубли/лимиты) → проверка кодов;
- не подлежит → позиция не маркируется (`skip_reason='not_marked'` в истории).

**§2.4.1 Источник заполнения `cod_marking_*` / `is_marked` (COMMENTS-10 2.6; Q9 — решение MVP):**
- **MVP (шаг 1):** с ПО кассы — кассир сканирует КМ на кассе (мобильный терминал/сканер
  ККТ); `cod_marking_cis`/`cod_marking_package_type`/`cod_marking_scan_at` пишутся в
  `cassa_ord_det` на позицию заказа, `is_marked` берётся из признака 1С (маска товара).
  Это контур реального продукта, который проверяем на первом шаге.
- **Шаг 2:** импорт из 1С (заполнение полей при создании заказа в 1С, передача в кассу) —
  будет рассмотрен после стабилизации MVP.
- **Шаг 3:** API/интеграции (автозаполнение при создании заказа через API) — после шага 2.
- Валидация `cod_marking_cis` (лимиты 50/2000, дубли) выполняется на кассе
  (`MarkingItemValidator`) ДО фискализации — независимо от источника заполнения.

### 2.5 §2.1 — отдельная таблица `marking_return_queue` (docs/sprints/01/Запрос_заказчику_ответ.md §3)
**Ранее в SPEC §2.1 было:** «отдельная таблица `marking_return_queue` (см. §2.9 — новая)».
**Исправлено:** §2.9 теперь существует и содержит таблицу.

**§2.9 marking_return_queue:**
```sql
CREATE TABLE marking_return_queue (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    order_id BIGINT NOT NULL,
    check_uuid VARCHAR(36) NOT NULL,
    cis_list JSON NOT NULL,
    status ENUM('pending','processing','done','failed') NOT NULL DEFAULT 'pending',
    attempts INT UNSIGNED NOT NULL DEFAULT 0,   -- COMMENTS-12 2.1: число claim'ов (обработок)
    returned_at TIMESTAMP NULL,
    reason VARCHAR(255) NULL,
    error VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP NULL,
    heartbeat_at TIMESTAMP NULL,                -- COMMENTS-11 #1: момент истечения lease (NOW+15 мин при claim/extend)
    INDEX idx_order (order_id),
    INDEX idx_status (status),
    INDEX idx_processing_stale (status, heartbeat_at)
)
```
- **Lease semantics (COMMENTS-11 #1, волна 2.2):** `heartbeat_at` — не last-seen, а момент
  истечения lease; reclaim «зомби» — только при истёкшем lease. Параметры очередей
  (lease-длительность, max-обработок, deadlock-retry) — из config `sell_queue`/`return_queue`
  (COMMENTS-12 2.4; env `MARKING_LEASE_MINUTES`/`MARKING_MAX_ATTEMPTS`/`MARKING_DEADLOCK_MAX`).
- **Идемпотентный retry (COMMENTS-12 2.1, волна 2.3):** `Service/Marking/ReturnWorker` —
  при `attempts > 1` перед `/cis/returned` сверка `/cis/sold`: КИ, не числящиеся проданными, —
  возврат применён предыдущей попыткой и повторно НЕ отправляется (всё применено → done без
  вызова; частичный — только остаток). Метрики: `marking_return_already_applied_total`,
  `marking_return_idempotent_skip_total`, `marking_return_sold_cap_exceeded_total` (кап 10000, 2.8).

### 1.3.1 Token rotation policy (docs/sprints/01/Запрос_заказчику_ответ.md §1.3.1)
Было дублирование: §1.3 как «раздел» и §1.3 как «подраздел Token rotation policy». Исправлено:
- **§1.3:** общая политика (можно оставить обзорные строки).
- **§1.3.1:** «Token rotation policy» — детальный список триггеров, механизмов и аудита (см. выше).

### 1.6 §1.6 — только корректный маппинг (docs/sprints/01/Запрос_заказчику_ответ.md §10)
В SPEC оставлен **только актуальный маппинг**:
```
| 1–9 | Ошибки валидации/крипто/AI | WARN |
| 10 | Не найден в ГИС МТ | BLOCK |
| 11 | Не найден в трансгране | WARN |
```
**Старый исторический комментарий** про «неверную таблицу» **удален**, иначе читатель будет путаться.

### 1.5 Факты без ссылок на ревью (docs/sprints/01/Запрос_заказчику_ответ.md §2.9)
**Убраны все конструкции «Q3b (COMMENTS-5.md):»** и подобные. В SPEC оставлены только факты:
- **Формат 1263:** `ДД.ММ.ГГГГ` (приказ ФНС ЕД-7-20/662@).
- **1262 = "030"** для всех групп ЧЗ (код ФНС).
- **1264:** конфиг + справочник по товарным группам (структура в §1.5 верна, дообавлен fallback на `tag1260.default`, если группа не найдена в `by_group`).

### 1.1 Дата запуска и разделение токенов (docs/sprints/01/Запрос_заказчику_ответ.md §2.8)
**Разделены три разных понятия:**

- **§1.1:** «Дедлайн 01.03.2026.»
- **§1.3.1:** «Prod-токен валиден до 01.10.2026 per методичка v17.»
- **§1.4:** «Sandbox-токен — см. §1.4 (правило действия, проверка в ЛК ЧЗ).»

---

## 3. Дополнительные разделы (новые, по docs/sprints/01/Запрос_заказчику_ответ.md)

### 3.1 §5 Мониторинг (docs/sprints/01/Запрос_заказчику_ответ.md §5)
**Конкретные метрики (Prometheus naming):**
- `marking_check_duration_seconds` — histogram — измерение времени проверки.
- `marking_check_fail_total` — counter — количество failed проверок (ошибка валидации/крипто/AI).
- `marking_check_warn_total` — counter — количество WARN (errorCode 1–9).
- `marking_check_block_total` — counter — количество BLOCK (errorCode 10).
- `marking_emergency_total` — counter — количество EMERGENCY_TIMEOUT.
- `marking_401_total` — counter — количество 401 на health-check (токен сломан).
- `marking_check_total` — counter — общее число проверок (знаменатель).
- `marking_return_pending_total` — counter — возвры в pending статусе > 15 мин.

**Пороги алертов:**
- `marking_check_warn_total / marking_check_total > 0.05` за 5 минут → WARN-алерт в Slack.
- `marking_emergency_total > 5% за 5 минут` → CRITICAL-алерт + email.
- `marking_401_total > 0` → немедленный CRITICAL-алерт (токен нужно обновить).

**Каналы уведомлений:**
- Slack: #marking-alerts (WARN), #marking-critical (CRITICAL).
- Email: ежедневный отчет на billing@company.com.
- SMS: только CRITICAL с кодом BV ( blocking/validation error ).

**Dashboard (Prometheus Grafana):**
- Панель «Статус проверок»: общая карта соотношения онлайн/офлайн/гибрид.
- Панель «ALERTS»: текущие алерты с часовыми диаграммами.
- Панель «Return queue»: количество pending/processed/failed возвратов.

### 3.2 §6 UI кассира (docs/sprints/01/Запрос_заказчику_ответ.md §6)
**Макет (текстовое описание для разработки):**

**BLOCK:**
```
╔════════════════════════════════════════════════════════════════════
║ ⛔ ТОВАР ПО ЗАПРЕТУ ║
║ ║
║ ║ Товар под запретом, продажа заблокирована. ║
║ ║ Нажмите для деталей. ║
║ ║ ║ ║
║ ║ KPI: N шт. pending / N в обработке / N выполнено ║
║ ║ ║ ║
║ ║ Действия: ║
║ ║ • Позвонить мастеру/менеджеру ║
║ • Отменить чек ║
║ ║ ║ ║
╚════════════════════════════════════════════════════════════════════
```

**WARN:**
```
╔════════════════════════════════════════════════════════════════════
║ ⚠️ ПРОДАЖА С ОСТОРОЖНОСТЬЮ ║
║ ║ ║
║ ║ Можно продолжить с осторожностью. ║
║ ║ KPI: N шт. pending / N в обработке / N выполнено ║
║ ║ ║ ║
║ ║ Действия: ║
║ • Продолжить чек ║
║ • Отменить чек ║
║ ║ ║ ║
╚════════════════════════════════════════════════════════════════════
```

**Тексты сообщений:**
- BLOCK: «Товар под запретом маркировки. Продажа запрещена по методичке §5. Обратитесь к мастеру.»
- WARN: «Есть неразобранные коды маркировки. Продолжить можно, но есть риск штрафа.»

**Действия кассира:**
- BLOCK: позвонить мастеру/менеджеру, отменить чек через систему.
- WARN: продолжить чек с осторожностью, отметить в логе, обработать позже.

### 3.3 §7 Логирование (docs/sprints/01/Запрос_заказчику_ответ.md §6)
**Формат:** JSON Lines (каждая строка — отдельный JSON-объект).

**Уровни и что пишется:**
- **DEBUG:** Полный request/response цикла проверки (для.deep debug, содержит X-API-KEY sha1).
- **INFO:** Ключевые события: проверка начата/завершена, возврат успешно обработан, токен обновлен.
- **WARN:** errorCode 1–9 (валидация/крипто/AI), EMERGENCY_TIMEOUT > 5% за 5 мин, возвры в pending > 15 мин.
- **ERROR:** errorCode 10 (не найден в ГИС МТ), 401 (токен сломан), сбой записи в БД.

**Куда пишутся:**
- `logs/marking.log` (файл ротации по tamaño, retention 30 дней).
- **Sentry:** только ERROR-level (критические сбои, не включая самих X-API-KEY).
- **Loki:** для метрик и поиска по шаблонам (WARN-level и выше).

**PII-политика (какие поля редактируются/маскируются):**
- `X-API-KEY` — **никогда** в логи (только `sha1()` или первые 8 символов).
- `marking_code` — маскировать: `****1234` (показать последние 4 цифры).
- `gtin` — маскировать по Dynamic Masking (показать первые 3 и последние 3 цифры).
- `order_id`, `check_id` — писать как есть (это не ПИИ в смысле конфиденциальности).

### 3.4 §8 `/cis/return` (docs/sprints/01/Запрос_заказчику_ответ.md §6)
**Точка вызова:** в `CheckService::refundCheck()` (после фискализации чека, перед возвратом денег клиенту).

**Волна 2 (COMMENTS-9 Fix #10, решение A) — БЕЗ двойной записи:**
`marking_return_queue` — ЕДИНСТВЕННЫЙ источник истины по возвратам. Статус `'return'`
в `marking_sell_queue` УБРАН (ENUM: pending/processing/done/failed):
- Успех: `return_status = 'processed'`, `marking_return_queue.status = 'done'`,
  `returned_at = NOW()`; **`marking_sell_queue` НЕ меняется** (остаётся `done` —
  это факт продажи; факт возврата — только в `marking_return_queue`).
- Ошибка: `return_status = 'failed'`, `marking_return_queue.status = 'failed'` +
  `error`; **`marking_sell_queue` НЕ меняется**.
- Повторный вызов `processByCheck` по тому же чеку — дубль НЕ создаётся
  (проверка `MarkingReturnQueue::getForCheck`).

**Взаимодействие с `marking_sell_queue`:** нет (решение A). Отдельная трасса:
падение return-воркера не блокирует продажи.
Существующие БД с `status='return'` — мигрировать блоком-комментарием в
`sql/marking_tables.sql` (перенос в `marking_return_queue` + возврат к `done`).

**Гибрид (волна 2, COMMENTS-9 Fix #5):** `MarkingCheckService::checkHybrid` —
параллельный запуск обеих веток через `HttpParallel` (curl_multi, общий барьер
`cdn.switch_threshold_sec` = 1.5 с), решение по матрице:
- оба ответа до барьера → `source = HYBRID` (merge per-code, max(BLOCK>WARN>INFO));
- только онлайн → `ONLINE`; только офлайн → `OFFLINE`;
- ни один → `EMERGENCY_TIMEOUT` + WARN + метрика + алерт (>5% за 5 мин).
Примечание: в гибриде онлайн-ветка идёт на ОДИН кандидат-хост (быстрый путь);
полный failover по хостам — в онлайн-только режиме. Сбой кандидата фиксируется
в `marking_cdn_host_state` (circuit breaker виден всем FPM-воркерам, Fix #4).

### 3.5 §1.6.1 Pre-check агрегатов (docs/sprints/01/Запрос_заказчику_ответ.md §10)
**Fix — в SPEC §1.6.1 добавить явное ограничение:**
```
Pre-check агрегатов выполняется ТОЛЬКО для онлайн-проверки (есть parent в ответе /codes/check).
Для офлайн-ветки (ЛМ ЧЗ) pre-check невозможен — ЛМ ЧЗ не возвращает parent.
Если в чеке одновременно агрегат и единицы, а онлайн-ветка недоступна → запретить продажу
(BLOCK) до восстановления онлайна.
```

### 3.6 §3.5 `LmChzService::sold()` (docs/sprints/01/Запрос_заказчику_ответ.md §11)
**Fix — заменить в SPEC §3.5:**
```php
public function sold(int $skip = 0, int $limit = 100): SoldResult
// GET /api/v2/cis/sold — выгрузка списка проданных (для бэкапа/сверки).
// Не «подтверждение продажи»; подтверждение — это sell().
```

### 3.7 Duplicates and roadmap fixes (docs/sprints/01/Запрос_заказчику_ответ.md §7-9)
- **§2.5 duplicate in Plan:** удален второй упоминание §2.5.
- **Roadmap #19-#23:** добавлены в таблицу Блока 4:
  - **#19**: Мониторинг (метрики + алерты) — 🟠, зависимость от #1
  - **#20**: UI кассира (BLOCK/WARN) — 🟠, зависимость от #1
  - **#21**: Логирование (формат, retention) — 🟠, зависимость от #1
  - **#22**: Миграции БД (Laravel?) — 🟠, зависимость от #1, уточнить стек
  - **#23**: `/cis/return` в roadmap **Блок 1** (критично) — 🔴, зависимость от #6
- **Стек проекта:** проверен через `composer.json` — фиксируется в SPEC §9.
- **`packageType=GROUP` parent field:** добавлено ограничение в §1.6.1, что pre-check агрегатов возможен только в онлайн.

### 3.8 Artifacts fixes (docs/sprints/01/Запрос_заказчику_ответ.md §6)
- **§5 KPI:** `N шт. pending / N в обработке / N выполнено` (было `N件`, BV код убран)
- **§6:** `SMS: только CRITICAL (аварийный режим, 401, sync_error > 72 ч)` (BV код убран, `marking_check_total` добавлен как знак)
- **§5:** `marking_check_total` добавлен как знак (знаменатель для расчета процентов алертов).

---

## 4. Roadmap (Блок 5)

### 4.1 Немедленно (сегодня)
1. **Отправить заказчику:** Q1a (номор акта по группам), Q2a (регионы и ЧЗ ID), Q4a (скриншот из ЛК ЧЗ со сроком sandbox-токена).
2. **Запросить у Атол** официальный JSON тега 1260 (ещё не отправлено).
3. ✅ **Уточнить стек** проекта — **проверено по composer.json: plain PHP 8, фреймворков НЕТ** (см. §9). Миграции — вручную, `sql/marking_tables.sql`.

### 4.2 Шаг 1 (0.5–1 день)
5. Получить ответы Q1a, Q2a, Q4a (или зафиксировать плейсхолдеры).
6. Развернуть тестовый ЛМ ЧЗ 2.0 на Ubuntu 22.04 VM (параллельно).
7. Утвердить `SPEC.md` письменно (✅ от техлида и заказчика).

### 4.3 Шаг 2 (1–1.5 дня)
8. Финализировать SPEC.md (закрыть оставшиеся TODO из таблицы правок).
9. Написать §5–§8 **полностью** (мониторинг/UI/логирование/return).
10. Добавить новые разделы: §1.9, §1.10, §1.11.
11. Добавить таблицы `marking_emergency_state` и `marking_return_queue` в §2.
12. Исправить нумерацию §1.3 → §1.3.1, §1.6 (убрать старый комментарий), §2.5 (EMERGENCY_TIMEOUT в §3.4).
13. Убрать все ссылки на COMMENTS-* из SPEC.
14. Обновить §10 — оценка ~20–21 чел.-ч.

### 4.4 Шаг 3 — Код (после утверждения SPEC.md)
**Начинать только после письменного утверждения SPEC.md.**

**Порядок реализации:**
1. `MarkingCode` (extractCis + decodeMrp) + unit-тесты — самая легко тестируемая часть, без зависимостей.
2. `GisMtAuthService` → `CdnService` → `CodeCheckService` (онлайн).
3. `LmChzService` (офлайн) — зависит от готовности ЛМ ЧЗ.
4. `MarkingCheckService::checkHybrid` (оркестратор).
5. Интеграция в `Check`/`CheckService` (тег 1260, `/cis/sell`, `/cis/return`).
6. В конце — `marking_sell_queue` worker.

### 4.5 Итоговые оценки (после утверждения SPEC.md)
- **Документы:** ~20–21 чел.-ч.
- **Код (Шаг 3):** ~26 чел.-дней (2 недели × 3 разработчика), разбивка:
  - Dev 1: `MarkingCode` + `GisMtAuthService` + unit-тесты.
  - Dev 2: `CdnService` → `CodeCheckService` + мониторинг (§5).
  - Dev 3: `LmChzService` → `MarkingCheckService` + `/cis/return` (§8).

### 4.6 Таблица правок (из docs/sprints/01/Запрос_заказчику_ответ.md §6)

| # | Проблема | Раздел | Действие | Приоритет |
|---|----------|--------|----------|-----------|
| 1 | `/cdn/info` «выдаёт токен» | SPEC §3.2, План Q7, Открытые_вопросы Q7 | Заменить на `.env`/`config` + `/auth/permissive-access` | 🔴 |
| 2 | Нет `marking_emergency_state` | SPEC §2 | Добавить CREATE TABLE | 🔴 |
| 3 | Нет §2.9 `marking_return_queue` | SPEC §2 | Добавить CREATE TABLE | 🔴 |
| 4 | `skipped` в ENUM противоречит тексту | SPEC §2.1 | Убрать из ENUM или переопределить | 🔴 |
| 5 | `item_id NOT NULL` в check_history | SPEC §2.2 | `NULL` | 🔴 |
| 6 | Артефакты в §5–§8 (件, BV) | SPEC §5, §6 | Убрать/заменить | 🟡 |
| 7 | Дубликат §2.5 в Плане | План Блок 2 | Удалить второй | 🟠 |
| 8 | Roadmap нет #19–#23 | План Блок 4 | Добавить в таблицу | 🟠 |
| 9 | Стек проекта без подтверждения | План §2.9 | ✅ Проверено: plain PHP 8 без фреймворков (см. §9) | 🟡 |
| 10 | Pre-check агрегатов в офлайне | SPEC §1.6.1 | Добавить ограничение | 🟡 |
| 11 | `sold(cis_list)` — не endpoint | SPEC §3.5 | Заменить на `sold(skip, limit)` | 🔴 |
| 12 | Q3b «ISO vs ДД.ММ.ГГГГ» | Открытые_вопросы Q3b | Закрыть → ДД.ММ.ГГГГ | 🟡 |

---

## 9. Стек проекта (уточнение roadmap #9/#22 — проверено по composer.json)

- **Фреймворка НЕТ:** `composer.json` не содержит `laravel/framework`, `symfony/framework-bundle`, `yiisoft/yii2` (проверка по команде из Запрос_заказчику_ответ.md). Plain PHP 8 (локальный CLI — PHP 8.4).
- **PSR-4:** `Service\` → `Service/`, `Models\` → `Models/`, `Erp\Models\`, `Marketing\Models\`, `Worksheets\Models\`, `Reports\`.
- **БД:** MySQL, доступ через `Models\Base::query()` (PDO, база `veira-souz`); конфиг — `Models\Base::getConfig()` (Dotenv, `.env` в корне).
- **Конфигурация ЧЗ:** `config/marking.php` + `\Service\Marking\MarkingConfig::get('cdn.switch_threshold_sec')` — аналог `config('marking.*')` из SPEC без фреймворка.
- **HTTP:** curl (`curl_setopt_array`, как в `Models\Check::send()`); обёртка `Service\Marking\HttpClient` (реализация `CurlHttpClient`, в тестах — фейк).
- **Миграции (roadmap #22):** исполняются вручную/через сервис — `docs/sprints/01/sql/marking_tables.sql` (Laravel Migrations не требуются).
- **Деплой (маркировка, `sql/marking_tables.sql`):**
  1. `CREATE`/`ALTER` таблиц (idempotent — повторный запуск безопасен).
  2. **Backfill legacy zombies** (COMMENTS-13 3.3) — `UPDATE ... WHERE status='processing' AND heartbeat_at IS NULL`
     для `marking_sell_queue`/`marking_return_queue` (idempotent, тот же SQL в файле).
  3. Проверка после деплоя: `SELECT COUNT(*) FROM marking_sell_queue WHERE status='processing' AND heartbeat_at IS NULL`
     → ожидание `0`; то же для `marking_return_queue`.
- **UI кассира:** jQuery + Bootstrap 5 (`package.json`) → карточка `js/marking/marking_status_card.js` (в проекте нет Vue — `.vue`-компонент заменён jQuery-аддоном).
- **Тесты:** PHPUnit отсутствует (нет dev-зависимостей) → `tests/run_all.php` (собственный assert-харнес
  `tests/TestHarness.php` с per-suite счётчиками и флагами `--list`/`--json`; волна 2 — 298+ проверок).
- **CLI-воркеры:** `Service/Marking/process_sells.php`, `Service/Marking/process_returns.php` (автозагрузка — `autoload_marking.php`).
- **Волна 2 (COMMENTS-9):**
  - **Autoload/деплой (Fix #9):** в `vendor/` нет `autoload.php` до `composer install`
    (composer.json/lock в git; `ext-curl`, `vlucas/phpdotenv` и др. — в require). Тестовый bootstrap
    создаёт stub `vendor/autoload.php` (PSR-4 `Service\`, `Models\`); в ПРОДЕ — `composer install`
    (vendor/ не в git — gitignored). Проверка: `tests/Unit/AutoloadSmokeTest.php`.
  - **Гейт ATOL placeholder (Fix #8 + COMMENTS-11 #4):** `APP_ENV` обязан быть ОДНИМ из
    `dev|test|prod|production` (lowercase) — любое другое значение (включая НЕНАЗНАЧЕННЫЙ,
    `staging`; mock-обёртка не освобождает) → `RuntimeException` ДО остальных проверок
    (whitelist, без неявного dev-fallback). Далее: `APP_ENV=prod` + `MARKING_ATOL_PLACEHOLDER=1`
    → `RuntimeException` в `Check::assertAtolExampleAvailable()` — чек НЕ уходит на ККТ с
    несертифицированной структурой industryInfo (placeholder `INSERT_ATOL_OFFICIAL_EXAMPLE` в
    `Models\Check::buildMarkingAttribute()`). Prod + `MARKING_ATOL_PLACEHOLDER=0` — обязателен
    файл официального JSON (`MARKING_ATOL_EXAMPLE_PATH`, ДККТ 10.10.8.24).
    Тест: `AtolPlaceholderGateTest` (кейсы 5–8).
  - **Mock-сервер интеграционных прогонов (Блок 4):** `tests/mock/MarkingMockRouter.php`
    (`php -S 127.0.0.1:18080 tests/mock/MarkingMockRouter.php`) + фикстуры `tests/mock/Fixtures.php`
    + сценарии `tests/integration/run_against_mock.php` — прогон полного цикла (продажа/возврат/
    гонки гибрида) БЕЗ живых сервисов; хосты переключаются через `.env`
    (`MARKING_AUTH_HOST`, `MARKING_LM_CHZ_HOST`).
- **Волна 2.1 (COMMENTS-10) / волна 2.2 (COMMENTS-11) / волна 2.3 (COMMENTS-12):**
  - **Lease semantics очередей (#2.1 → #1):** `heartbeat_at` — момент истечения LEASE
    (NOW + 15 мин при claim/`extendLease()`), reclaim «зомби» по истёкшему lease,
    `attempts` = число claim'ов; идемпотентный retry `/cis/sell` через сверку `/cis/sold`
    (`Service/Marking/SellWorker`, #3); deadlock-retry до 5 попыток, экспоненциальный
    backoff 50/100/200/400 мс + jitter (#5). Подробности — §2.1 и `ИТОГИ_ВОЛНЫ_2.md` §5.1/§5.2.
  - **`verified_false → BLOCK` (#2.5, Q8 закрыт):** `config prohibitions.flags.verified_false = 'BLOCK'`,
    `to_verify = []`; оговорка по методичке («предупреждение УОТ», случай 10) — в `ОТКРЫТЫЕ_ВОПРОСЫ.md` Q8.
  - **Источник `cod_marking_*`/`is_marked` (#2.6, Q9):** §2.4.1 — MVP: сканер на кассе
    (ПО кассы), шаг 2 — импорт 1С, шаг 3 — API.
  - **HTTP-валидность гибрида (#2):** только 2xx парсятся; 203 → активация
    `marking_emergency_state` + EMERGENCY без merge. Подробности — §3.4.
  - **Волна 2.3 (COMMENTS-12):** идемпотентный retry `/cis/returned` — `Service/Marking/ReturnWorker`
    + `marking_return_queue.attempts` (симметрия sell; §2.9, 2.1); 203 на любом endpoint ГИС МТ
    → `activate()` централизованно в `CdnService::onEmergency203()` (2.2, §3.4);
    `marking_emergency_state`: `expireIfStale()` в начале `check()` (auto-expire 7/3 суток, 2.3)
    + race-safe `activate()` (портативный ON DUPLICATE KEY UPDATE, 2.5); параметры очередей
    (lease/max_attempts/deadlock_max) — из config `sell_queue`/`return_queue` (2.4,
    `MARKING_LEASE_MINUTES`/`MARKING_MAX_ATTEMPTS`/`MARKING_DEADLOCK_MAX`); метрики
    `marking_sell_idempotent_skip_total` (2.6), `marking_{sell,return}_sold_cap_exceeded_total`
    (кап 10000, 2.8), `marking_emergency_auto_expired_total` (2.3). Подробности —
    `ИТОГИ_ВОЛНЫ_2.md` §5.3 и `ОТЧЕТ_ВОЛНЫ_2_3.md`.
  - **Волна 2.4 (COMMENTS-13…17):** `cron/expire_emergency.php` (flock, try/catch, exit 1) —
    почасовой авто-expire аварийного режима; throttle убран из `check()`/`checkHybrid()`
    (cron — primary path); `__unknown__` → transient-флаг `transientEmergency203`;
    backfill legacy-zombie SQL в DDL; аудит-таблица `marking_sell_pending_ack`
    (`Models/MarkingSellPendingAck`: record/pending/acknowledge/cleanupOlderThan,
    SQLite `INSERT OR IGNORE`, kill-switch `MARKING_USE_PENDING_ACK`).
  - **Cron-строки (crontab, COMMENTS-15 §6):**
    `0 * * * * /usr/bin/php /path/to/cron/expire_emergency.php >> /var/log/marking-cron.log 2>&1`
    и ежедневный cleanup подтверждённых записей audit-таблицы (`sql/cleanup_pending_ack.sql`,
    idempotent DELETE `acked_at < NOW() - INTERVAL 30 DAY AND acked_at IS NOT NULL`) —
    отдельная crontab-строка: `30 3 * * * mysql veira-souz < /path/to/sql/cleanup_pending_ack.sql`
    (эквивалент `MarkingSellPendingAck::cleanupOlderThan(30)`; COMMENTS-17 вариант B).
  - **Мониторинг cron (COMMENTS-15 §6, рекомендация):** `MAILTO=ops@company.com` в crontab
    ИЛИ systemd-таймер с `OnFailure=` (алерт при nonzero exit скрипта). Скрипт возвращает
    `exit 1` при исключении — сигнал для мониторинга; успех логируется
    `MarkingLogger::info('expire_emergency_cron_ok')`.
  - **Health-endpoint (COMMENTS-15 §5 wire-up, AUDIT §5 #2 — закрыто итерацией 06.10):**
    `GET /health/marking.php` → JSON `{ok, active_emergencies[{inn, minutes_since_last_203,
    started_at}], alerts[]}` на основе `MarkingEmergencyState::getActiveWithLastSeen()`
    (fallback — прямой SELECT `marking_emergency_state WHERE is_active=1`); HTTP 503 при
    «протухшем» last_seen (порог env `MARKING_HEALTH_STALE_MINUTES`, default 180 мин) или
    внутренней ошибке — опрашивать каждые 5 мин.
  - **Операционный деплой-чеклист:** вынесен в `docs/sprints/01/DEPLOY.md`
    (env-переменные, миграции, cron+мониторинг, health, smoke, rollback) — T12 закрыт.

---

## 10. Заключение

**Проект готов к Шагу 1** с following приоритетами:
1. Заявка на sandbox (онлайн + офлайн) + проверка токена в ЛК ЧЗ (Q4a).
2. Запрос официального JSON Атол тега 1260.
3. Уточнение у заказчика: норм-акты по группам (Q1a), регионы с ЧЗ ID (Q2a).

**После утверждения SPEC.md** можно начинать Шаг 3 (код) в указанном порядке реализации.

**Главные исправления по docs/sprints/01/Запрос_заказчику_ответ.md:**
1. `/cdn/info` не выдает токен — токен из `.env`/config или `/auth/permissive-access`
2. `marking_emergency_state` таблица добавлена в §2
3. `marking_return_queue` таблица добавлена в §2.9
4. `skipped` в ENUM убрана или переопределена через `skip_reason`
5. `item_id` в check_history → NULL allowed
6. §5-§8 artifacts исправлены: KPI текст, BV код убран, `marking_check_total` добавлен
7. Дубликат §2.5 в Plan удален
8. Roadmap #19-#23 добавлены
9. Q7 исправлен на `/auth/permissive-access`
10. `packageType=GROUP` pre-check ограничение добавлено
11. `sold()` заменен на `sold(skip, limit)`

---
*Этот документ создан на основе COMMENTS.md, COMMENTS-2.md, COMMENTS-3.md, COMMENTS-4.md, COMMENTS-5.md и docs/sprints/01/Запрос_заказчику_ответ.md. Последнее обновление — 05.10.2026 (волна 2.3, COMMENTS-12).*