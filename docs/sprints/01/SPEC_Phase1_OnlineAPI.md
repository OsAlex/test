# SPEC: Фаза 1 — Online API (CDN + ГИС МТ проверка кодов)

**Версия:** 1.0  
**Дата:** 2026 (уточнено при ревизии документации 06.10.2026; было: 2025)
**Статус:** Черновик  
**Зависимости:** Доступ к тестовому контуру ГИС МТ, токен X-API-KEY

---

## 1. Цель фазы

Реализовать клиент онлайн-проверки кодов маркировки через CDN API ГИС МТ:
- Получение и кэширование списка CDN-площадок
- Проверка здоровья CDN-площадок и выбор оптимальной
- Проверка кодов маркировки (`/codes/check`)
- Парсинг и валидация ответа (все поля таблицы п.136 cz_developer_notes.md)
- История запросов (create/receive)

---

## 2. Функциональные требования

### 2.1 CDN Service (`Service/CdnService.php`)

#### 2.1.1 Получение списка CDN-площадок
- **Метод:** `GET /api/v4/true-api/cdn/info`
- **Заголовки:** `X-API-KEY`, `Content-Type: application/json`
- **Ответ:** `{ code: 0, description: "ok", hosts: [{host: "https://cdn01.am.crptech.ru"}, ...] }`
- **Кэширование:**
  - TTL: до следующего успешного обновления (мин 6ч, макс 7ч)
  - Рандомная задержка при обновлении: 0-10 минут
  - Фоллбэк: дефолтный список `cdn01.crpt.ru` … `cdn11.crpt.ru` при недоступности

#### 2.1.2 Проверка здоровья CDN
- **Метод:** `GET /api/v4/true-api/cdn/health/check` (на конкретный хост)
- **Заголовки:** `Content-Type: application/json`, `Connection: close`, `X-API-KEY`
- **Таймаут:** 2 сек (настройка до 10 сек при проблемах)
- **Поведение:** установить HTTPS, выполнить запрос, **сразу закрыть соединение**
- **Ответ:** `{ code: 0, description: "ok", avgTimeMs: <ms> }`
- **Обработка таймаута:** пометить площадку недоступной на 15 минут

#### 2.1.3 Выбор CDN-площадки
- Приоритет: минимальный `avgTimeMs` от `/cdn/health/check`
- Блокировка площадки на 15 мин при:
  - 3 неудачных ответа за 1.5 сек подряд
  - HTTP 429 (повторная ошибка)
  - HTTP 5xx (повторная ошибка)
- **Исключение:** HTTP 5xx с кодом 5000 в теле — **не** помечать недоступной, повторить запрос
- Разблокировка: только после успешного `/cdn/health/check`

### 2.2 Auth Service (`Service/GisMtAuthService.php`)

#### 2.2.1 Получение токена
- **Автоматический:** `POST /api/v3/true-api/auth/permissive-access`
  - Тело: `{ "data": "<BASE64, подписано УКЭП>" }`
- **Ручной:** из ЛК ГИС МТ (Профиль → «Токен для ККТ»)
- **Хранение:** в БД/конфиге с полем `expires_at`
- **Срок действия:** до **01.10.2026** (автопродление на стороне ГИС МТ)
- **Один токен на ИНН** — используется на всех кассах
- **Блокировка при публикации** в открытых источниках

#### 2.2.2 Валидация токена
- Проверка `expires_at` перед запросом
- При 401 — попытка обновить через `/cdn/info` (проверка валидности)

### 2.3 Code Check Service (`Service/CodeCheckService.php`)

#### 2.3.1 Проверка кодов
- **Метод:** `POST /api/v4/true-api/codes/check`
- **Тело:**
```json
{
  "codes": ["<КМ с экранированным GS (\\u001d)>"],
  "fiscalDriveNumber": "16 цифр ФН",
  "timeZone": 1..11  // défaut: 2 (МСК), влияет на smp/mrp
}
```
- **Экранирование:** символ GS (ASCII 029) → `\u001d`
- **Заголовки:** `Content-Type: application/json`, `X-API-KEY`, `Connection: keep-alive` (на время чека)

#### 2.3.2 Парсинг ответа
Все поля из таблицы п.136 `cz_developer_notes.md`:
| Поле | Тип | Обязательное | Описание |
|------|-----|--------------|----------|
| cis | string | ✅ | КМ из запроса |
| found | bool | ✅ | КИ найден в ГИС МТ |
| valid | bool | ✅ | Валидность структуры КМ |
| verified | bool | ✅ | Криптопроверка КМ |
| printView | string | | КМ без крипто-подписи |
| gtin | string | | Код товара |
| groupIds | int[] | | Товарные группы |
| realizable | bool | ✅ | В обороте |
| utilised | bool | ✅ | Нанесён на упаковку |
| isBlocked | bool | ✅ | Продажа заблокирована |
| expireDate | string | | Срок годности (ISO) |
| productionDate | string | | Дата производства |
| errorCode | int | ✅ | Код ошибки (0-11) |
| isTracking | bool | | Прослеживаемость |
| sold | bool | | Выведен из оборота |
| packageType | string | | UNIT/GROUP/BUNDLE/PRODUCT_SET |
| producerInn | string | | ИНН производителя (не для молочки РБ) |
| grayZone | bool | | Табак: серая зона |
| mrp | number | | МРЦ/МЦ в копейках |
| smp | number | | ЕМЦ табака в копейках |
| ogvs | string[] | | ОГВ блокирующие (RAR,FTS,FNS,RSHN,RPN,MVD,RZN,VETRF,RD,FSSP) |
| isOwner | bool | | КМ принадлежит запросившему |
| parent | string | | КИ агрегата |
| variableExpirations | object | | Вариативные сроки (молочка) |
| productWeight | number | | Переменный вес (молочка) |
| prVetDocument | string | | Ветдокумент (молочка) |

#### 2.3.3 Коды ошибок errorCode
| Код | Значение | Действие |
|-----|----------|----------|
| 0 | Нет ошибки | OK |
| 1 | Ошибка валидации КМ | Предупреждение кассиру |
| 2 | Нет GTIN | Предупреждение |
| 3 | Нет серийного номера | Предупреждение |
| 4 | Недопустимые символы | Предупреждение |
| 5-7 | Ошибка крипто-подписи | Предупреждение |
| 8 | Не верифицирован в стране эмитента | Предупреждение |
| 9 | AI не поддерживаются | Предупреждение |
| 10 | КМ не найден в ГИС МТ | Запрет продажи (found=false) |
| 11 | Не найден в трансгране | Предупреждение |

#### 2.3.4 История запросов
- **Создать:** `POST /api/v4/true-api/codes/check/history/create` (до 100 reqId за раз, 1 раз/мин)
- **Получить:** `GET /api/v4/true-api/codes/check/history/receive?queryId=<UUID>` (1 раз/мин, повторить через 30с при IN_PROGRESS)

### 2.4 Orchestrator (`Service/MarkingCheckService.php`)

#### 2.4.1 Основной метод
```php
public function checkMarkingCodes(array $codes, string $fiscalDriveNumber, int $timeZone = 2): MarkingCheckResult
```
1. Получить рабочую CDN-площадку через `CdnService`
2. Вызвать `CodeCheckService::check()` на выбранной CDN
3. При ошибках переключения — повторить на следующей CDN
4. Вернуть агрегированный результат с флагами запрета продажи

#### 2.4.2 Логика запрета продажи (Online)
Проверить каждый код на соответствие таблице п.4 `cz_developer_notes.md`:
| № | Случай | Условие онлайн |
|---|--------|----------------|
| 1 | Нет сведений в ГИС МТ | `found=false` ИЛИ `utilised=false` |
| 2 | Нарушение формата | `verified=false` |
| 3 | Выведен из оборота | `sold=true` |
| 4 | Блокировка ОГВ | `isBlocked=true` (ogvs указывает ОГВ) |
| 5 | Нет сведений о вводе | `realizable=false` И `sold=false` (не для табака grayZone) |
| 6 | Просрочен | дата проверки >= `expireDate` (табак — локальное время без ТЗ) |
| 7 | Цена ≠ МРЦ (табак) | цена ≠ `mrp` (AI 8005) |
| 8 | Цена < ЕМЦ (табак) | цена < `smp` |
| 9 | Цена < МЦ (НСП) | цена < `mrp` (id=16,12) |
| 10 | Цена < МЦ (вода,пиво,духи,одежда,шины) | цена < `mrp` |

---

## 3. Нефункциональные требования

### 3.1 Производительность
- Параллельная проверка здоровья CDN (Promise.all / curl_multi)
- Keep-alive соединение к CDN на время чека (idle timeout ГИС МТ = 180 сек)
- Таймаут всего цикла проверки ≤ 1.5 сек (до перехода на офлайн)

### 3.2 Надёжность
- Логирование всех запросов/ответов (требование методички)
- Retry с экспоненциальным backoff для сетевых ошибок
- Circuit breaker для CDN-площадок (15 мин блокировка)

### 3.3 Безопасность
- Токен хранить зашифрованным
- Не логировать токен
- HTTPS only, проверка сертификатов

---

## 4. Интерфейсы (DTO)

```php
// DTO/CDN/Platform.php
class CdnPlatform {
    public string $host;
    public int $avgTimeMs = 0;
    public DateTime|null $blockedUntil = null;
    public bool $isHealthy = true;
}

// DTO/CodeCheck/Request.php
class CodeCheckRequest {
    public array $codes;           // КМ с экранированным GS
    public string $fiscalDriveNumber; // 16 цифр
    public int $timeZone = 2;      // 1..11
}

// DTO/CodeCheck/Response.php
class CodeCheckResponse {
    public int $code;
    public string $description;
    public array $codes;           // CodeCheckCodeResult[]
    public string $reqId;
    public int $reqTimestamp;
}

class CodeCheckCodeResult {
    public string $cis;
    public bool $found;
    public bool $valid;
    public bool $verified;
    public ?string $printView;
    public ?string $gtin;
    public array $groupIds;
    public bool $realizable;
    public bool $utilised;
    public bool $isBlocked;
    public ?string $expireDate;
    public ?string $productionDate;
    public int $errorCode;
    public bool $isTracking;
    public bool $sold;
    public ?string $packageType;
    public ?string $producerInn;
    public bool $grayZone;
    public ?int $soldUnitCount;
    public ?int $innerUnitCount;
    public ?int $mrp;              // копейки
    public ?int $smp;              // копейки
    public array $ogvs;
    public ?string $message;
    public ?object $variableExpirations;
    public ?float $productWeight;
    public ?string $prVetDocument;
    public ?bool $isOwner;
    public ?string $parent;
    public ?int $eliminationState;
}

// DTO/MarkingCheckResult.php
class MarkingCheckResult {
    public bool $success;
    public array $results;         // MarkingCodeResult[] (по каждому КМ)
    public array $prohibitions;    // SaleProhibition[] — случаи запрета
    public bool $hasBlockingProhibitions; // true если есть запрет продажи
    public string $cdnHost;        // использованная CDN
    public int $responseTimeMs;
}
```

---

## 5. Конфигурация (`config/marking.php`)

```php
return [
    'gis_mt' => [
        'test_host' => 'https://markirovka.sandbox.crptech.ru',
        'prod_host' => 'https://cdn.crpt.ru',
        'token' => env('GIS_MT_TOKEN'),
        'token_expires' => '2026-10-01',
        'inn' => env('COMPANY_INN'),
    ],
    'cdn' => [
        'default_hosts' => [
            'https://cdn01.crpt.ru', 'https://cdn02.crpt.ru', ..., 'https://cdn11.crpt.ru'
        ],
        'cache_ttl_min' => 6 * 3600,
        'cache_ttl_max' => 7 * 3600,
        'health_check_timeout' => 2,
        'health_check_max_timeout' => 10,
        'block_duration' => 15 * 60,           // 15 минут
        'switch_threshold_sec' => 1.5,         // 1.5 сек
        'switch_max_failures' => 3,            // 3 раза подряд
    ],
    'code_check' => [
        'timeout' => 1.5,                      // общий таймаут проверки
        'connection_idle_timeout' => 180,      // keep-alive
    ],
    'lm_chz' => [
        'host' => 'http://127.0.0.1:5995',
        'login' => env('LM_CHZ_LOGIN'),
        'password' => env('LM_CHZ_PASSWORD'),
        'fn_number' => env('FISCAL_DRIVE_NUMBER'), // X-ClientId
    ],
];
```

---

## 6. Тестовые сценарии (из Приложения 2 методички)

| № | Сценарий | DataMatrix | Ожидаемый результат |
|---|----------|------------|---------------------|
| 1 | Признак нанесения | `utilised=false` | Предупреждение |
| 2 | Ввод в оборот | `realizable=false, utilised=true, sold=false` | Предупреждение |
| 3 | Табак исключение | `realizable=false, grayZone=true` | Продажа разрешена |
| 4 | Вывод из оборота | `sold=true` | Запрет |
| 5 | Блокировка ОГВ | `isBlocked=true` | Запрет + проверить ЛМ ЧЗ |
| 6 | Просрочка | `expireDate` в прошлом | Запрет |
| 7 | МРЦ (блок) | МРЦ ≠ 199000 коп. | Предупреждение |
| 8 | МРЦ (пачка) | МРЦ ≠ 14500 коп. | Предупреждение |
| 9 | Несуществующий код | `found=false` | Запрет |
| 10 | Некорректный криптохвост | `verified=false` | Запрет |
| 11 | HTTP 504 | — | Переключение CDN |
| 12 | HTTP 203 | — | Аварийная ситуация |
| 13 | HTTP 500 | — | Переключение CDN |
| 14 | Задержка 2 сек | — | Офлайн после 1.5 сек |
| 15 | HTTP 500 (code 5000) | — | Не блокировать CDN, повторить |
| 16 | Мин. цена НСП | `mrp=20000` | Предупреждение при цене < 200 руб. |
| 17 | Мин. цена АТП | `mrp=20000` | Предупреждение при цене < 200 руб. |

---

## 7. Приёмка (Definition of Done)

- [ ] `CdnService` получает список CDN, кэширует, обновляет по расписанию
- [ ] `CdnService` проверяет здоровье, выбирает оптимальную, блокирует/разблокирует
- [ ] `GisMtAuthService` выдаёт валидный токен, обрабатывает 401
- [ ] `CodeCheckService` отправляет запрос с корректным экранированием GS
- [ ] `CodeCheckService` парсит **все** поля ответа (таблица п.136)
- [ ] `MarkingCheckService` оркестрирует: CDN → проверка → анализ запретов
- [ ] Unit-тесты для каждого сервиса (моки HTTP)
- [ ] Интеграционные тесты на sandbox контуре (минимум сценарии 1, 4, 5, 6, 9, 10, 11, 14, 15)
- [ ] Логирование запросов/ответов в `Logger`
- [ ] Обработка HTTP 203 (аварийная ситуация) — флаг отключения проверок

---

## 8. Риски и митигация

| Риск | Вероятность | Влияние | Митигация |
|------|-------------|---------|-----------|
| Нет доступа к sandbox | Высокая | Блокер | Запросить доступ заранее |
| Токен истёк/невалиден | Средняя | Высокое | Авто-рефреш, алертинг |
| CDN недоступны все | Низкая | Критическое | Фоллбэк на дефолтный список, офлайн режим |
| ЛМ ЧЗ не установлен | Средняя | Высокое | Параллельно Фаза 2, заглушка для онлайн |
| Неверное экранирование GS | Средняя | Высокое | Unit-тесты на реальных КМ |

---

## 9. Оценка трудоёмкости

| Задача | Оценка (чел.-дней) |
|--------|---------------------|
| CdnService (список, здоровье, кэш, выбор) | 2 |
| GisMtAuthService (токен, валидация) | 1 |
| CodeCheckService (запрос, парсинг, ошибки) | 2 |
| MarkingCheckService (оркестратор, запреты) | 2 |
| DTO, конфиг, исключения | 1 |
| Unit-тесты | 2 |
| Интеграционные тесты (sandbox) | 2 |
| **Итого** | **12 чел.-дней** |

---

*Готов к ревью и уточнению перед началом реализации.*