<?php
declare(strict_types=1);

/**
 * Health-endpoint мониторинга аварийного режима ЧЗ (Sprint 01, бэклог AUDIT §5 п.2;
 * fix по COMMENTS-15 §5 — wire-up для MarkingEmergencyState::getActiveWithLastSeen()).
 *
 * GET /health/marking.php
 *
 * Возвращает JSON:
 * {
 *   "ok": true|false,
 *   "checked_at": "ISO-8601",
 *   "active_emergencies": [
 *     { "inn": "...", "minutes_since_last_203": 42, "started_at": "..." }
 *   ],
 *   "stale_after_minutes": 180,
 *   "alerts": ["emergency_stale:<inn>", ...]   // активная авария, но 203 не приходил > порога
 * }
 *
 * HTTP-код: 200 — сервис здоров (в т.ч. когда аварий нет);
 *           503 — есть активная авария с «протухшим» last_seen (цепочка наблюдения
 *                 разорвана) — сигнал для мониторинга (MAILTO / systemd OnFailure, SPEC §9).
 *
 * Порог: env MARKING_HEALTH_STALE_MINUTES (default 180 = 3 ч: cron почасовой
 * + двукратный запас на пропуск запуска).
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../Service/Marking/autoload_marking.php';

header('Content-Type: application/json; charset=utf-8');

/**
 * PDO из конфигурации проекта (Models/Base::getConfig(): DB_HOST/DB_USER/DB_PASS,
 * база veira-souz — см. SPEC §9 «Миграции БД»). Тестовый оверрайт Models\Base::$testPdo
 * поддерживается для интеграционных прогонов (тот же механизм, что в DbSmokeTest).
 */
function health_marking_pdo(): PDO
{
    $testPdo = null;
    if (class_exists(\Models\Base::class)) {
        $testPdo = \Models\Base::$testPdo ?? null;
    }
    if ($testPdo instanceof PDO) {
        return $testPdo;
    }
    $cfg = \Models\Base::getConfig();
    return new PDO(
        sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $cfg['DB_HOST'], $cfg['DB_NAME']),
        $cfg['DB_USER'],
        $cfg['DB_PASS'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
}

$staleAfterMinutes = (int) (getenv('MARKING_HEALTH_STALE_MINUTES') ?: 180);

$payload = [
    'ok' => true,
    'checked_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM),
    'active_emergencies' => [],
    'stale_after_minutes' => $staleAfterMinutes,
    'alerts' => [],
];

$httpCode = 200;

try {
    $pdo = health_marking_pdo();

    // Wire-up по fix-рецепту COMMENTS-15 §5. Запрос к marking_emergency_state
    // (DDL: sql/marking_tables.sql; колонки INT Unix — портативно MySQL/SQLite):
    //   minutes_since_last_203 = (NOW - last_seen_at)/60; NULL last_seen_at → fallback на started_at.
    // Эквивалент контракта MarkingEmergencyState::getActiveWithLastSeen()
    // (wave-1 FINAL_WAVE_1_SUMMARY п. 2.7). Если класс модели доступен и метод
    // существует — используем его (единая точка правды); иначе — прямой SQL ниже.
    $rows = [];
    $stateClass = 'Models\\MarkingEmergencyState';
    if (class_exists($stateClass)) {
        $state = new $stateClass($pdo);
        if (method_exists($state, 'getActiveWithLastSeen')) {
            $rows = $state->getActiveWithLastSeen();
        }
    }
    if ($rows === []) {
        $stmt = $pdo->query(
            'SELECT inn, started_at, last_seen_at FROM marking_emergency_state WHERE is_active = 1'
        );
        $now = time();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $seen = $r['last_seen_at'] !== null ? (int) $r['last_seen_at'] : (int) $r['started_at'];
            $rows[] = [
                'inn' => $r['inn'],
                'started_at' => gmdate('Y-m-d\TH:i:s\Z', (int) $r['started_at']),
                'minutes_since_last_203' => intdiv(max(0, $now - $seen), 60),
            ];
        }
    }

    foreach ($rows as $row) {
        $inn = (string) ($row['inn'] ?? '__unknown__');
        $minutes = isset($row['minutes_since_last_203'])
            ? (int) $row['minutes_since_last_203']
            : null;

        $payload['active_emergencies'][] = [
            'inn' => $inn,
            'minutes_since_last_203' => $minutes,
            'started_at' => $row['started_at'] ?? null,
        ];

        if ($minutes !== null && $minutes > $staleAfterMinutes) {
            $payload['alerts'][] = "emergency_stale:{$inn}";
        }
    }

    if ($payload['alerts'] !== []) {
        $payload['ok'] = false;
        $httpCode = 503;
    }
} catch (Throwable $e) {
    $payload['ok'] = false;
    $payload['error'] = 'health_check_failed';
    $payload['alerts'][] = 'health_endpoint_internal_error';
    error_log('[health/marking] ' . $e->getMessage());
    $httpCode = 503;
}

http_response_code($httpCode);
echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
