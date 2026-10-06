<?php
/**
 * Честный Знак (ЧЗ) — конфигурация интеграции (config-first, Запрос_заказчику_ответ.md).
 *
 * Источники значений:
 *  - .env (MARKING_*): api_key, срок действия ключа, ИНН, режимы.
 *  - Этот файл: хосты, тайминги CDN, батчи, тег 1260 по группам, пороги алертов.
 *
 * Чтение: \Service\Marking\MarkingConfig::get('cdn.switch_threshold_sec')
 *
 * КЛАССИФИКАЦИЯ ЗНАЧЕНИЙ (Запрос_заказчику_ответ.md):
 *  - safe:      можно менять без рисков (тайминги, пороги, батчи).
 *  - TODO-step2: требуется данные заказчика (регионы, срок sandbox-токена).
 *  - TODO-step3: блокирует прод (официальный JSON Атола, живой ЛМ ЧЗ, prod-токен).
 */

$env = $_ENV['MARKING_ENV'] ?? (getenv('MARKING_ENV') ?: 'test');

return [
    // test | prod
    'env' => $env,

    // Общий рубильник маркировки в чеках (офлайн до письменного утверждения SPEC.md).
    'enable' => filter_var($_ENV['MARKING_ENABLE'] ?? (getenv('MARKING_ENABLE') ?: 'off'), FILTER_VALIDATE_BOOL),

    // Гибридный режим (онлайн + офлайн ЛМ ЧЗ). Включать только после развёртывания ЛМ ЧЗ.
    'hybrid' => filter_var($_ENV['MARKING_HYBRID'] ?? (getenv('MARKING_HYBRID') ?: 'off'), FILTER_VALIDATE_BOOL),

    // X-API-KEY ГИС МТ. НИКОГДА не логировать целиком (только sha1 / первые 8 символов, SPEC §1.3).
    // Токен: из .env ИЛИ POST /api/v3/true-api/auth/permissive-access (УКЭП). НЕ через /cdn/info!
    'api_key' => $_ENV['MARKING_API_KEY'] ?? (getenv('MARKING_API_KEY') ?: ''),
    // ISO-дата (Y-m-d) или Y-m-d H:i:s. prod: 01.10.2026 per методичка v17.
    'api_key_expires_at' => $_ENV['MARKING_API_KEY_EXPIRES_AT'] ?? (getenv('MARKING_API_KEY_EXPIRES_AT') ?: ''),
    'inn' => $_ENV['MARKING_INN'] ?? (getenv('MARKING_INN') ?: ''),

    // SPEC §1.2: чз-часовой пояс по умолчанию (MSK). Диапазон 1..11.
    'default_timezone' => 2,
    // TODO-step2 (Q2a): маппинг касса_id => ID ЧЗ, как только заказчик пришлёт список регионов.
    // Если все точки в MSK — оставляем пустым, все кассы = 2.
    'timezones' => [
        'by_cassa' => [],
    ],

    // COMMENTS-9 Блок 4: override хостов из .env (mock-сервер для интеграционных прогонов):
    //   MARKING_AUTH_HOST   — хост ГИС МТ (auth / cdn/info)
    //   MARKING_LM_CHZ_HOST — хост ЛМ ЧЗ
    // Значение может содержать схему (http://127.0.0.1:18080) — mock-сервер на http.
    'hosts' => [
        'test' => [
            'auth' => $_ENV['MARKING_AUTH_HOST'] ?? (getenv('MARKING_AUTH_HOST') ?: 'https://markirovka.sandbox.crptech.ru'),
            // TODO-step2 (Q4a): CDN-хост sandbox берётся из ответа /cdn/info (hosts[]).
            'cdn' => null,
            // ЛМ ЧЗ 2.0 на локальном тестовом хосте.
            'lm_chz' => $_ENV['MARKING_LM_CHZ_HOST'] ?? (getenv('MARKING_LM_CHZ_HOST') ?: 'http://127.0.0.1:5995'),
        ],
        'prod' => [
            'auth' => $_ENV['MARKING_AUTH_HOST'] ?? (getenv('MARKING_AUTH_HOST') ?: 'https://markirovka.crpt.ru'),
            // prod-CDN: список хостов из /cdn/info (default_hosts ниже — фоллбэк).
            'cdn' => null,
            // TODO-step3: адрес живого ЛМ ЧЗ на прод-хосте (пока локальный).
            'lm_chz' => $_ENV['MARKING_LM_CHZ_HOST'] ?? (getenv('MARKING_LM_CHZ_HOST') ?: 'http://127.0.0.1:5995'),
        ],
    ],

    'cdn' => [
        // Фоллбэк, если /cdn/info недоступен (safe: менять можно).
        'default_hosts' => [
            'cdn01.crpt.ru', 'cdn02.crpt.ru', 'cdn03.crpt.ru', 'cdn04.crpt.ru', 'cdn05.crpt.ru',
            'cdn06.crpt.ru', 'cdn07.crpt.ru', 'cdn08.crpt.ru', 'cdn09.crpt.ru', 'cdn10.crpt.ru',
            'cdn11.crpt.ru',
        ],
        // Кеш /cdn/info: 6 часов + джиттер 0..10 минут (safe).
        'info_cache_ttl' => 21600,
        'info_cache_ttl_jitter' => 600,
        // Health-check таймауты, сек: (connect, total) — safe.
        'health_check_timeout' => 2,
        'health_check_max' => 10,
        // Хост блокируется на 15 минут после switch_max_failures сбоев.
        'block_duration' => 900,
        // Гибридный барьер: оба ответа должны прийти до 1.5 c (SPEC §2.3/§3.4).
        'switch_threshold_sec' => 1.5,
        'switch_max_failures' => 3,
        // Сколько хостов пробовать за один цикл проверки.
        'candidates' => 3,
        // Протокол CDN.
        'scheme' => 'https',
    ],

    'code_check' => [
        // Таймаут POST /codes/check, сек (совпадает с барьером 1.5 c).
        'timeout' => 1.5,
        // Keep-alive connection pool, сек.
        'keep_alive' => 180,
        // До 100 КМ за запрос; >100 — батчи (SPEC §1.7). Проверить 30/100 на sandbox.
        'batch_size' => 100,
        // Пишущие партия и частота записи history.
        'history_batch' => 100,
        'history_rate' => 60,
    ],

    // SPEC §1.5: тег 1260 = additionalAttribute; 1262 = "030" (код ФНС) для ВСЕХ групп;
    // 1263 = ДД.ММ.ГГГГ (приказ ФНС ЕД-7-20/662@); 1264 = номер норм-акта по группе.
    // ГИП: if group_id отсутствует в by_group — fallback на 'default'.
    'tag1260' => [
        'default' => ['1262' => '030', '1263' => '28.12.2018', '1264' => '1955'],
        'by_group' => [
            3  => ['1263' => '28.12.2018', '1264' => '1875'], // табак
            12 => ['1263' => '28.12.2018', '1264' => '1944'], // АТП (антипожарные textiles)
            16 => ['1263' => '28.12.2018', '1264' => '1951'], // НСП
            6  => ['1263' => '28.12.2018', '1264' => '1955'], // вода
            7  => ['1263' => '30.11.2019', '1264' => '1749'], // пиво
            15 => ['1263' => '15.12.2020', '1264' => '2099'], // молочка
            9  => ['1263' => '28.12.2018', '1264' => '1956'], // одежда
            10 => ['1263' => '28.12.2018', '1264' => '1958'], // шины
            5  => ['1263' => '28.12.2018', '1264' => '1957'], // духи
        ],
    ],

    // COMMENTS-9 Fix #8: гейт ATOL tag1260.
    // 1 = использовать placeholder (mock-режим, рабочее приближение SPEC §1.5);
    // 0 = только официальный JSON Атола (ДККТ 10.10.8.24) по tag1260_example_path.
    // В ПРОДЕ при placeholder=1 чек НЕ уходит на ККТ (MarkingCheckService::assertAtolExampleAvailable).
    'atol' => [
        'tag1260_placeholder' => filter_var($_ENV['MARKING_ATOL_PLACEHOLDER'] ?? (getenv('MARKING_ATOL_PLACEHOLDER') ?: '1'), FILTER_VALIDATE_BOOLEAN),
        // Путь к официальному JSON, полученному от Атола (TODO-step3).
        'tag1260_example_path' => $_ENV['MARKING_ATOL_EXAMPLE_PATH'] ?? (getenv('MARKING_ATOL_EXAMPLE_PATH') ?: ''),
    ],

    // COMMENTS-9 Fix #6: уровни ProhibitionLevel — конфигурируемо.
    // COMMENTS-10 2.5 (Q8 закрыт по PIOT §4): verified_false → BLOCK (sale без подтверждённого КИ
    // запрещён — касса должна запросить у кассира сканирование). errorCode 5..7 (крипто-подпись) —
    // WARN из error_code_levels (PIOT §4). Оператор может переключить одной строкой, если ответит иначе.
    'prohibitions' => [
        'error_code_levels' => [
            0 => 'INFO',
            1 => 'WARN', 2 => 'WARN', 3 => 'WARN', 4 => 'WARN',
            5 => 'WARN', // TO_VERIFY
            6 => 'WARN', // TO_VERIFY
            7 => 'WARN', // TO_VERIFY
            8 => 'WARN', 9 => 'WARN',
            10 => 'BLOCK',
            11 => 'WARN',
        ],
        // Пер-код флаги /codes/check (max по уровню: flag сильнее errorCode — только в сторону запрета).
        'flags' => [
            'found_false'      => 'BLOCK',
            'verified_false'   => 'BLOCK', // COMMENTS-10 2.5 / Q8: PIOT §4 — продажа без верифицированного КИ запрещена
            'utilised_false'   => 'WARN',
            'realizable_false' => 'WARN',
            'sold_true'        => 'BLOCK',
            'is_blocked_true'  => 'BLOCK',
        ],
        // COMMENTS-10 2.5: пуст — Q8 закрыт (verified_false → BLOCK, PIOT §4); errorCode 5..7
        // покрыты error_code_levels (WARN). Контроль — см. SPEC §1.6 (раздел TO_VERIFY).
        'to_verify' => [],
    ],

    'lm_chz' => [
        // Авторизация ЛМ ЧЗ токеном ГИС МТ (Bearer). true — если ЛМ ЧЗ запущен с токеном.
        'use_token_auth' => filter_var($_ENV['MARKING_LM_CHZ_USE_TOKEN_AUTH'] ?? (getenv('MARKING_LM_CHZ_USE_TOKEN_AUTH') ?: 'off'), FILTER_VALIDATE_BOOL),
        // До 100 КИ за запрос /outCheck (SPEC §1.7).
        'out_check_batch_size' => 100,
        // Таймаут ЛМ ЧЗ, сек.
        'timeout' => 2,
    ],

    // COMMENTS-12 2.4: параметры очередей воркеров (lease, число обработок, deadlock-retry)
    // — из .env (MARKING_*), переопределяемо без правки кода. Default'ы сохранены из волны 2.2.
    'sell_queue' => [
        // COMMENTS-11 #1: lease, минут (heartbeat_at = NOW + lease при claim/extendLease).
        'lease_minutes' => (int) ($_ENV['MARKING_LEASE_MINUTES'] ?? (getenv('MARKING_LEASE_MINUTES') ?: 15)),
        // COMMENTS-11 #1: максимум обработок строки (attempts = число claim'ов).
        'max_attempts' => (int) ($_ENV['MARKING_MAX_ATTEMPTS'] ?? (getenv('MARKING_MAX_ATTEMPTS') ?: 3)),
        // COMMENTS-11 #5: максимум попыток claim при deadlock (экспоненциальный backoff + jitter).
        'deadlock_max' => (int) ($_ENV['MARKING_DEADLOCK_MAX'] ?? (getenv('MARKING_DEADLOCK_MAX') ?: 5)),
    ],
    'return_queue' => [
        // COMMENTS-12 2.1: attempts в return-очереди — симметрия sell (идемпотентный /cis/returned).
        'lease_minutes' => (int) ($_ENV['MARKING_LEASE_MINUTES'] ?? (getenv('MARKING_LEASE_MINUTES') ?: 15)),
        'max_attempts' => (int) ($_ENV['MARKING_MAX_ATTEMPTS'] ?? (getenv('MARKING_MAX_ATTEMPTS') ?: 3)),
        'deadlock_max' => (int) ($_ENV['MARKING_DEADLOCK_MAX'] ?? (getenv('MARKING_DEADLOCK_MAX') ?: 5)),
    ],

    // COMMENTS-16 §6: audit-таблица для retry (MarkingSellPendingAck) — Q10 fix.
    'audit' => [
        // Kill-switch: 1 = retry через audit-таблицу (Q10 fix), 0 = legacy soldIntersection.
        // Дефолт = true (включено). Откат: MARKING_USE_PENDING_ACK=0 + reload PHP-FPM.
        'use_pending_ack' => filter_var($_ENV['MARKING_USE_PENDING_ACK'] ?? (getenv('MARKING_USE_PENDING_ACK') ?: '1'), FILTER_VALIDATE_BOOLEAN),
        // Retention дней для cleanupOlderThan. Минимум 7 дней (защита от env=0/отрицательных).
        'retention_days' => max(7, (int) ($_ENV['MARKING_AUDIT_RETENTION_DAYS'] ?? (getenv('MARKING_AUDIT_RETENTION_DAYS') ?: 30))),
    ],

    'log' => [
        'path' => dirname(__DIR__) . '/logs/marking.log',
        'level' => 'INFO', // DEBUG|INFO|WARN|ERROR
    ],

    'metrics' => [
        'path' => dirname(__DIR__) . '/logs/marking_metrics.log',
    ],

    // SPEC §5 пороги алертов.
    'alerts' => [
        // WARN-алерт при warn/total > 5% за 5 минут.
        'warn_ratio' => 0.05,
        // CRITICAL при EMERGENCY_TIMEOUT > 5% за 5 минут.
        'emergency_ratio' => 0.05,
        'window_seconds' => 300,
        // Возвраты в pending дольше 15 минут — WARN.
        'return_pending_seconds' => 900,
    ],
];