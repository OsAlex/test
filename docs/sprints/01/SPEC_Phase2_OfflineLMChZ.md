# SPEC: Фаза 2 — Офлайн-проверка (ЛМ ЧЗ 2.0)

**Версия:** 1.0  
**Дата:** 2025  
**Статус:** Черновик  
**Зависимости:** Фаза 1 (Online API), Установленный ЛМ ЧЗ 2.0 + Контроллер ≥ 1.6

---

## 1. Цель фазы

Реализовать клиент офлайн-проверки кодов идентификации (КИ) через Локальный Модуль «Честный Знак» (ЛМ ЧЗ):
- Инициализация ЛМ ЧЗ
- Мониторинг статуса и синхронизации
- Проверка КИ (`/cis/outCheck`)
- Вспомогательные методы: серые списки, регистрация продажи, возврат, список проданных
- Выделение КИ из КМ (парсинг Data Matrix)

---

## 2. Функциональные требования

### 2.1 LmChzService (`Service/LmChzService.php`)

#### 2.1.1 Базовые настройки
- **Хост:** `http://127.0.0.1:5995` (локально) или IP удалённого сервера
- **Авторизация:**
  - Basic Auth: `Authorization: Basic <base64(login:password)>`
  - **Альтернатива (v17):** Token от ТСПИоТ: `Authorization: Token <UUID>`
- **Заголовок:** `X-ClientId: <номер ФН>` (обязательный для всех методов)
- **Порт контроллера:** 50063 (не менять в GUI ЕСМ)

#### 2.1.2 Инициализация (`POST /api/v2/init`)
```json
{
  "token": "<x-api-key от ГИС МТ>",
  "clearSold": true|false,        // v13+: удалить БД проданных товаров
  "enableGreyList": true|false    // v10+: активация серых списков
}
```
- **Только для первичной настройки** установленного ЛМ ЧЗ
- При потере связи > 15 минут → статус `not_configured`
- При 10 днях офлайна → авто-инициализация после восстановления связи
- **Логи:**
  - Windows 32: `C:\Program Files (x86)\Regime\var\log`
  - Windows 64: `C:\Program Files\Regime\var\log`
  - Ubuntu/Debian: `/var/log/regime/`

#### 2.1.3 Проверка статуса (`GET /api/v2/status`)
**Ответ:**
```json
{
  "version": "2.x.x",
  "status": "not_configured|initialization|ready|failure|sync_error",
  "serviceUrl": "https://rsapi.crpt.ru",
  "operationMode": "online|offline",
  "name": "ЛМ ЧЗ",
  "lastUpdate": "ISO datetime",
  "lastSync": "ISO datetime",
  "inst": "UUID инстанса",
  "inn": "ИНН участника",
  "dbVersion": "версия БД",
  "dbState": {
    "min_price": { "docCount": 12345 },
    "blocked_gtin": { "docCount": 678 },
    "blocked_cis": { "docCount": 90 }
  },
  "isGreyGtin": true|false
}
```

**Критические статусы:**
| Статус | Значение | Действие |
|--------|----------|----------|
| `not_configured` | Не инициализирован | Запустить `/init` |
| `initialization` | Идёт загрузка БД | Ждать (может быть часами) |
| `ready` | Готов к работе | ✅ Работать |
| `failure` | Ошибка | Проверить логи, переустановить |
| `sync_error` | Нет синхронизации > 72ч | Проверить интернет, перезапустить |

**Правило 72 часов:** если `lastSync` старше 72 часов → `sync_error`, проверка невозможна.

#### 2.1.4 Проверка КИ (`POST /api/v2/cis/outCheck`)
```json
{
  "cis_list": [
    { "cis": "<КИ>", "pg": <товарная группа> }
  ]
}
```
- `pg` — товарная группа (id из таблицы п.177 cz_notes)
- **Авторизация:** Basic Auth ИЛИ Token от ТСПИоТ (v17+)

**Ответ:**
```json
{
  "results": [{
    "reqId": "UUID",
    "reqTimestamp": 1234567890000,
    "inst": "UUID",
    "version": "2.x.x",
    "codes": [{
      "cis": "<КИ>",
      "isBlocked": false,
      "isGreyGtin": false,
      "gtin": "04601653035829",
      "sold": false,
      "mrp": 14500,      // копейки (МРЦ/МЦ)
      "smp": 20000       // копейки (ЕМЦ табака)
    }]
  }]
}
```

**Ошибки при статусе ≠ ready:**
| Статус ЛМ ЧЗ | errorCode | Описание |
|--------------|-----------|----------|
| `not_configured` / `initialization` | 4045 | ЛМ ЧЗ не готов |
| `sync_error` | 4050 | Нет синхронизации > 72ч |

**Рекомендация методички:** логировать все запросы и ответы.

#### 2.1.5 Серые списки GTIN (`POST /api/v2/greyList`)
```json
{ "enableGreyList": true }
```
- Позволяет хранить GTIN вместо КИ (экономия диска)
- При `isBlocked=true` И `isGreyGtin=true` → **проверка только в онлайн**
- При инициализации с опцией: добавить в `/init` `"enableGreyList": true`

#### 2.1.6 Регистрация продажи (`POST /api/v2/cis/sell`)
```json
{ "cis_list": ["<КИ>", ...] }
```
- Ответ 201: `{ results: [{ success: true, cis: "..." }] }`
- КИ добавляются в БД проданных (удаляются через 30 дней)
- При частичной продаже — добавлять **после реализации последней части**
- Рекомендуется резервное копирование БД

#### 2.1.7 Возврат товаров (`POST /api/v2/cis/return`)
```json
{ "cis_list": ["<КИ>", ...] }
```
- Ответ 200: `{ results: [{ success: true, cis: "..." }] }`
- Удаляет КИ из БД проданных товаров

#### 2.1.8 Список проданных (`GET /api/v2/cis/sold?skip=0&limit=100`)
- `limit` до 1000
- Используется для резервного копирования БД
- Ответ: `{ total_count: 123, cis_list: [...] }`

---

### 2.2 MarkingCode Model (`Models/MarkingCode.php`)

#### 2.2.1 Выделение КИ из КМ
**Общий алгоритм (все группы кроме табака):**
- Разделить КМ по **первому** разделителю GS (ASCII 029, `\u001d`)
- Пример: `01048657365749062155esJWe\u001d93dGVz` → КИ: `01048657365749062155esJWe`

**Табачная продукция (29 символов):**
- Первые 21 символ (GTIN 14 + Serial 7), **без МРЦ** (последние 8 символов)
- Пример: `00000046233219!SX-RqRADpU7Cev` → КИ: `00000046233219!SX-RqR`

#### 2.2.2 Декодирование МРЦ/ЕМЦ из КМ пачки (Приложение 1)
**Алфавит 80 символов:**
```
0:A 1:B 2:C 3:D 4:E 5:F 6:G 7:H 8:I 9:J 10:K 11:L 12:M 13:N 14:O 15:P
16:Q 17:R 18:S 19:T 20:U 21:V 22:W 23:X 24:Y 25:Z 26:a 27:b 28:c 29:d 30:e 31:f
32:g 33:h 34:i 35:j 36:k 37:l 38:m 39:n 40:o 41:p 42:q 43:r 44:s 45:t 46:u 47:v
48:w 49:x 50:y 51:z 52:0 53:1 54:2 55:3 56:4 57:5 58:6 59:7 60:8 61:9
62:! 63:" 64:% 65:& 66:' 67:* 68:+ 69:- 70:. 71:/ 72:_ 73:, 74:: 75:; 76:= 77:< 78:> 79:?
```

**Кодирование (МРЦ в копейках → 4 символа):**
1. Делить на 80, остаток → символ в **начало** строки
2. Повторять пока целая часть > 0
3. Дополнять слева до 4 символов «A» (индекс 0)

**Декодирование (4 символа → копейки):**
- Для каждого символа (с конца, позиция от 0): `result += index(char) * 80^position`

**Пример:** МРЦ 14630 коп. → «ACW.»
**Получение МРЦ из КМ пачки:** декодировать символы **[22..25]** полного КМ

---

### 2.3 Orchestrator Integration (`Service/MarkingCheckService.php`)

#### 2.3.1 Логика онлайн/офлайн (п.5 методички)
```php
public function checkMarkingCodesHybrid(array $codes, string $fiscalDriveNumber): MarkingCheckResult
```
1. Извлечь КИ из каждого КМ через `MarkingCode::extractCis()`
2. **Параллельно запустить:**
   - Онлайн: `CodeCheckService::check()` (CDN)
   - Офлайн: `LmChzService::outCheck()` (ЛМ ЧЗ)
3. **Приоритет офлайн:** если `sold=true` из ЛМ ЧЗ → **запретить продажу** (независимо от онлайн)
4. **Таймаут онлайн 1.5 сек:** если нет ответа → решение по офлайн
5. Объединить результаты, применить таблицу запретов (п.4 cz_notes)
6. Вернуть агрегированный `MarkingCheckResult`

#### 2.3.2 Таблица запретов продажи (Офлайн специфика)
| № | Случай | Параметр ЛМ ЧЗ | Условие |
|---|--------|----------------|---------|
| 1 | Нет сведений | — | ЛМ ЧЗ не возвращает КИ → как `found=false` |
| 2 | Нарушение формата | — | Некорректный КИ |
| 3 | Выведен из оборота | `sold=true` | **Критично** — запрет всегда |
| 4 | Блокировка ОГВ | `isBlocked=true` | `ogvs` указывает ОГВ |
| 5 | Нет сведений о вводе | — | Не проверяется в офлайне (требуется онлайн) |
| 6 | Просрочен | — | Не в ответе ЛМ ЧЗ (требуется онлайн) |
| 7 | Цена ≠ МРЦ (табак) | `mrp` | Цена ≠ `mrp` |
| 8 | Цена < ЕМЦ (табак) | `smp` | Цена < `smp` |
| 9 | Цена < МЦ (НСП) | `mrp` | Цена < `mrp` (id=16,12) |
| 10 | Цена < МЦ (вода,пиво...) | `mrp` | Цена < `mrp` |

> **Важно:** в офлайне недоступны `found`, `verified`, `utilised`, `realizable`, `expireDate`, `isTracking`, `packageType`, `producerInn`, `isOwner`, `parent`, `variableExpirations`, `productWeight`, `prVetDocument`, `eliminationState`, `ogvs` (кроме через `isBlocked`+`isGreyGtin`).

---

## 3. Нефункциональные требования

### 3.1 Аппаратные (от методички п.2.2.1)
- CPU: 4 ядра
- RAM: 4 Гб
- Диск: 150 Мб (развёрнутый) + место под БД заблокированных сведений

### 3.2 Поддерживаемые ОС (п.2.2.2)
| ОС | x86 | x86_64 | ARM7 | ARM64 |
|----|-----|--------|------|-------|
| Windows 7 Ultimate | — | + | — | — |
| Windows 10/11 | + | + | — | — |
| Ubuntu 22.04 | — | + | — | + |
| Debian 11 | — | + | — | + |
| Android 5–15 | — | — | + | + |

### 3.3 Надёжность
- ЛМ ЧЗ — **один экземпляр на торговый объект** (на ИНН)
- Контроллер ЛМ ЧЗ — **на том же ПК** что и ЛМ ЧЗ (порт 50063)
- Службы: `esm-lm-controller`, `regime`, `yenisei` должны быть запущены
- Версии ЕСМ и lm-controller ≥ 1.6.2.0 (для протокола 2.0)

---

## 4. Интерфейсы (DTO)

```php
// DTO/LmChz/InitRequest.php
class LmChzInitRequest {
    public string $token;           // X-API-KEY от ГИС МТ
    public bool $clearSold = false;
    public bool $enableGreyList = false;
}

// DTO/LmChz/StatusResponse.php
class LmChzStatusResponse {
    public string $version;
    public string $status;          // not_configured|initialization|ready|failure|sync_error
    public string $serviceUrl;
    public string $operationMode;
    public string $name;
    public string $lastUpdate;
    public string $lastSync;
    public string $inst;
    public string $inn;
    public string $dbVersion;
    public DbState $dbState;
    public bool $isGreyGtin;
}

class DbState {
    public DocCount $min_price;
    public DocCount $blocked_gtin;
    public DocCount $blocked_cis;
}

class DocCount {
    public int $docCount;
}

// DTO/LmChz/OutCheckRequest.php
class LmChzOutCheckRequest {
    public array $cis_list;         // CisItem[]
}

class CisItem {
    public string $cis;
    public int $pg;                 // товарная группа
}

// DTO/LmChz/OutCheckResponse.php
class LmChzOutCheckResponse {
    public array $results;          // OutCheckResult[]
}

class OutCheckResult {
    public string $reqId;
    public int $reqTimestamp;
    public string $inst;
    public string $version;
    public array $codes;            // OutCheckCode[]
}

class OutCheckCode {
    public string $cis;
    public bool $isBlocked;
    public bool $isGreyGtin;
    public string $gtin;
    public bool $sold;
    public ?int $mrp;               // копейки
    public ?int $smp;               // копейки (ЕМЦ)
}

// DTO/LmChz/SellRequest.php
class LmChzSellRequest {
    public array $cis_list;         // string[]
}

class LmChzSellResponse {
    public array $results;          // [{success: bool, cis: string}]
}
```

---

## 5. Интеграция с циклом чека (CheckService)

### 5.1 Изменения в `CheckService::runAllActionByCheck()`

```php
public static function runAllActionByCheck(Check $check): array
{
    // 1. Получить КМ из товаров заказа
    $markingCodes = $check->getOrder()->getMarkingCodes();
    
    // 2. Гибридная проверка (онлайн + офлайн параллельно)
    $markingResult = MarkingCheckService::checkHybrid($markingCodes, $check->getCassa()->getFnNumber());
    
    // 3. Если есть блокирующие запреты → вернуть ошибку
    if ($markingResult->hasBlockingProhibitions) {
        return ['status' => false, 'text' => 'Запрет продажи: ' . $markingResult->getProhibitionMessages()];
    }
    
    // 4. Генерация JSON с тегом 1260 (дданные проверки)
    $check->make_json($markingResult->getTag1260Data());
    $check->save();
    
    // 5. Отправка на кассу
    $result = $check->send();
    if (isset($result->error)) { ... }
    
    // 6. Ожидание + запрос данных
    sleep(3);
    $check->requestDataFromCassa();
    
    // 7. После успешной фискализации → регистрация продажи в ЛМ ЧЗ
    foreach ($markingCodes as $code) {
        LmChzService::sell($code->getCis());
    }
    
    // 8. Сохранение в АТОЛ
    $check->saveToAtol();
    
    return ['status' => true, 'text' => 'Проведен чек с проверкой КМ: ' . $check->oc_id];
}
```

### 5.2 Тег 1260 в JSON чека (Check::make_json)

```php
// Онлайн проверка была успешной
$tag1265 = "UUID={$onlineResult->reqId}&Time={$onlineResult->reqTimestamp}";

// Офлайн проверка (если онлайн не ответил за 1.5 сек)
$tag1265 = "UUID={$offlineResult->reqId}&Time={$offlineResult->reqTimestamp}&Inst={$offlineResult->inst}&Ver={$offlineResult->version}";

// Добавление в items чека (тег 1260)
$info['items'][] = (object)[
    'type' => 'additionalAttribute',
    'name' => 'Отраслевой реквизит',
    'value' => (object)[
        'id' => '1260',
        'value' => (object)[
            'id' => '1262', 'value' => '030'
        ],
        // ... 1263, 1264, 1265
    ]
];
```

> **Примечание:** точная структура тега 1260 зависит от протокола Атол API v2 — уточнить в документации драйвера.

---

## 6. Конфигурация (расширение `config/marking.php`)

```php
'lm_chz' => [
    'host' => env('LM_CHZ_HOST', 'http://127.0.0.1:5995'),
    'login' => env('LM_CHZ_LOGIN'),
    'password' => env('LM_CHZ_PASSWORD'),
    'fn_number' => env('FISCAL_DRIVE_NUMBER'),    // X-ClientId
    'use_token_auth' => false,                    // true = Token от ТСПИоТ
    'token_provider' => 'esm',                    // источник токена
    'init' => [
        'clear_sold' => false,
        'enable_grey_list' => false,
    ],
    'timeouts' => [
        'connect' => 5,
        'read' => 10,
    ],
    'status_poll_interval' => 30,                 // сек при initialization
    'max_init_wait' => 3600,                      // макс. ожидание инициализации (1ч)
],
```

---

## 7. Тестовые сценарии (Офлайн специфика)

| № | Сценарий | Условие | Ожидаемое поведение |
|---|----------|---------|---------------------|
| 1 | ЛМ ЧЗ не инициализирован | status=not_configured | Ошибка 4045, требовать init |
| 2 | ЛМ ЧЗ инициализируется | status=initialization | Ошибка 4045, ждать ready |
| 3 | Нет синхронизации 72ч | status=sync_error | Ошибка 4050, запрет офлайн |
| 4 | КИ в БД проданных | sold=true | **Запрет продажи** (приоритет) |
| 5 | Блокировка + серая зона | isBlocked=true, isGreyGtin=true | Продажа **только онлайн** |
| 6 | Цена < МРЦ (табак) | mrp возвращён, цена < mrp | Предупреждение/запрет |
| 7 | Цена < ЕМЦ (табак) | smp возвращён, цена < smp | Предупреждение/запрет |
| 8 | Цена < МЦ (НСП) | mrp возвращён, цена < mrp | Предупреждение/запрет |
| 9 | Регистрация продажи | После фискализации | POST /cis/sell, успех 201 |
| 10 | Возврат | Отмена чека | POST /cis/return, успех 200 |

---

## 8. Приёмка (Definition of Done)

- [ ] `LmChzService::init()` — успешная инициализация с токеном ГИС МТ
- [ ] `LmChzService::status()` — парсинг всех полей, детекция `sync_error`
- [ ] `LmChzService::outCheck()` — проверка КИ, обработка ошибок 4045/4050
- [ ] `LmChzService::greyList()` — включение/выключение серых списков
- [ ] `LmChzService::sell()` / `return()` / `sold()` — CRUD БД проданных
- [ ] `MarkingCode::extractCis()` — корректное выделение КИ (общий + табак)
- [ ] `MarkingCode::decodeMrp()` — декодирование МРЦ из КМ пачки (алфавит 80)
- [ ] `MarkingCheckService::checkHybrid()` — параллельный онлайн/офлайн, таймаут 1.5с, приоритет sold
- [ ] Интеграция в `CheckService` — тег 1260, `/cis/sell` после фискализации
- [ ] Unit-тесты: парсинг КИ, декодирование МРЦ, маппинг статусов
- [ ] Интеграционные тесты на тестовом ЛМ ЧЗ (sandbox или локальный)

---

## 9. Риски и митигация

| Риск | Вероятность | Влияние | Митигация |
|------|-------------|---------|-----------|
| ЛМ ЧЗ не установлен на проде | Высокая | Блокер | Параллельно развернуть тестовый ЛМ ЧЗ |
| Долгая инициализация (часы) | Средняя | Высокое | Асинхронный мониторинг статуса, UI уведомления |
| Нет синхронизации 72ч | Средняя | Критическое | Алерт мониторинга, авто-перезапуск при восстановлении интернета |
| Контроллер на другом ПК | Низкая | Высокое | Жёсткое требование: контроллер на том же хосте |
| Версия протокола 1.0 vs 2.0 | Низкая | Критическое | Проверить версии ЕСМ ≥ 1.6, lm-controller ≥ 1.6 |

---

## 10. Оценка трудоёмкости

| Задача | Оценка (чел.-дней) |
|--------|---------------------|
| LmChzService (init, status, outCheck, sell, return, sold, greyList) | 3 |
| MarkingCode (extractCis, decodeMrp, validation) | 2 |
| MarkingCheckService::checkHybrid (оркестрация, таймауты, приоритеты) | 2 |
| Интеграция в Check/CheckService (тег 1260, sell после фискализации) | 2 |
| DTO, исключения, конфиг | 1 |
| Unit-тесты | 2 |
| Интеграционные тесты (ЛМ ЧЗ) | 2 |
| **Итого** | **14 чел.-дней** |

---

## 11. Зависимости от инфраструктуры

Для работы Фазы 2 **необходимо** на проде:
1. **ЛМ ЧЗ 2.0** установлен на сервере торгового объекта (один на ИНН)
2. **Контроллер ЛМ ЧЗ** ≥ 1.6 на **том же ПК** (порт 50063)
3. **ЕСМ** ≥ 1.6 на кассах (для ТСПИоТ токена)
4. **ДККТ** ≥ 10.10.8.24 из состава ЕСМ
5. **ККТ** на ФФД 1.2, параметр «Торговля маркированными товарами» = ВКЛ
6. **Сетевой доступ** от кассы к ЛМ ЧЗ: порт 50063 (или 5063)
7. **Интернет** для инициализации и синхронизации (rsapi.crpt.ru:443, 194.0.209.18:443)

---

*Готов к ревью после завершения Фазы 1.*