<?php
/**
 * ЛМ ЧЗ 2.0 — локальный модуль (офлайн-авторизация), SPEC §3.5/§3.6.
 *
 * Endpoint'ы (локальный HTTP, hosts.{env}.lm_chz, по умолчанию http://127.0.0.1:5995):
 *  - POST /outCheck   — офлайн-валидация списка КИ (до 100, SPEC §1.7)
 *  - POST /cis/sell   — подтверждение продажи (confirm sale)
 *  - POST /cis/returned — возврат товара (SPEC §8)
 *  - GET  /cis/sold   — выгрузка проданных (бэкап/сверка), ПАГИНЦИЯ: sold(skip, limit)
 *
 * sold() — НЕ «подтверждение продажи» (подтверждение — sell()).
 * ЛМ ЧЗ НЕ возвращает found/verified/parent — см. SPEC §2.2/§3.5.
 */
class LmChzService
{
    public function __construct(
        private ?HttpClient $http = null,
        private ?string $baseUrl = null,
        private ?GisMtAuthService $auth = null,
    ) {
    }

    private function http(): HttpClient
    {
        return $this->http ?? new CurlHttpClient();
    }

    private function baseUrl(): string
    {
        $base = $this->baseUrl ?? (string) MarkingConfig::get('hosts.' . MarkingConfig::env() . '.lm_chz');

        return rtrim($base, '/');
    }

    private function timeout(): float
    {
        return (float) MarkingConfig::get('lm_chz.timeout', 2);
    }

    /** Headers ЛМ ЧЗ (Bearer — если use_token_auth). COMMENTS-9 Fix #5: публично для гибрида. */
    public function headers(): array
    {
        $headers = ['Content-Type: application/json; charset=utf-8'];
        if (MarkingConfig::get('lm_chz.use_token_auth', false)) {
            $token = $this->auth?->token() ?? (new GisMtAuthService())->token();
            $headers[] = 'Authorization: Bearer ' . $token;
        }

        return $headers;
    }

    /**
     * COMMENTS-9 Fix #5: сырой POST /outCheck (без батчинга) — для гибридного режима.
     * @throws HttpException
     */
    public function outCheckRaw(array $cisList): HttpResponse
    {
        $cis = MarkingCode::toCisList($cisList);
        $response = $this->http()->request(
            'POST',
            $this->baseUrl() . '/outCheck',
            $this->headers(),
            json_encode(['codes' => array_values($cis)], JSON_UNESCAPED_UNICODE),
            ['timeout' => $this->timeout()]
        );

        if ($response->error !== null && $response->status === 0) {
            throw new HttpException('ЛМ ЧЗ недоступен (' . $this->baseUrl() . '/outCheck): ' . $response->error);
        }

        return $response;
    }

    /**
     * COMMENTS-9 Fix #5: разобрать ответ /outCheck в MarkingCheckResult (source OFFLINE).
     * @return MarkingCheckResult|null null, если ответ невалидный (ветка не учитывается)
     */
    public function parseOutCheck(HttpResponse $response, array $cisList): ?MarkingCheckResult
    {
        if (!$response->ok()) {
            return null;
        }
        $data = $response->json();
        if (!is_array($data)) {
            return null;
        }
        // Контракт ЛМ ЧЗ: 'ok' — опциональный (реальные ответы — responseCode/results;
        // mock-сервер — ok/results). 'ok' = false явно → ответ невалидный.
        if (array_key_exists('ok', $data) && $data['ok'] !== true) {
            return null;
        }

        $cis = MarkingCode::toCisList($cisList);
        $per = [];
        foreach ($data['results'] ?? [] as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $code = (string) ($raw['cis'] ?? $raw['code'] ?? '');
            if ($code === '') {
                continue;
            }
            $status = strtoupper((string) ($raw['status'] ?? 'UNKNOWN'));
            if (in_array($status, ['CANCELLED', 'BANNED', 'RECALLED', 'BANN'], true)) {
                $level = MarkingStatus::BLOCK; // отозван/запрещён — офлайн-запрет
            } elseif (in_array($status, ['SOLD', 'DUPLICATE'], true) || $raw['sold'] === true) {
                $level = MarkingStatus::WARN; // дубль продажи — риск (SPEC §2.4 duplicate)
            } elseif (in_array($status, ['OK', 'VALID', 'VALIDATED'], true)) {
                $level = MarkingStatus::INFO;
            } else {
                $level = MarkingStatus::WARN; // неизвестный статус — консервативно
            }
            $per[$code] = [
                'code'              => $code,
                'error_code'        => null,
                'error_description' => $raw['description'] ?? $raw['errorDescription'] ?? null,
                'prohibition'       => $level,
                'found'             => null, // ЛМ ЧЗ не возвращает found/verified (SPEC §2.2)
                'verified'          => null,
                'sold'              => $raw['sold'] ?? null,
                'group_id'          => $raw['groupId'] ?? $raw['group_id'] ?? null,
                'package_type'      => null,
                'parent_cis'        => null, // ЛМ ЧЗ не возвращает parent (SPEC §3.5)
                'gtin'              => null,
            ];
        }
        foreach ($cis as $code) {
            if (!isset($per[$code])) {
                $per[$code] = [
                    'code' => $code, 'error_code' => null, 'error_description' => 'Код не возвращён /outCheck',
                    'prohibition' => MarkingStatus::WARN, 'found' => null, 'verified' => null, 'sold' => null,
                    'group_id' => null, 'package_type' => null, 'parent_cis' => null, 'gtin' => null,
                ];
            }
        }

        return new MarkingCheckResult(
            MarkingStatus::overall(array_column($per, 'prohibition')),
            MarkingStatus::SOURCE_OFFLINE,
            $per,
            null,
            $response->time_ms,
            null,
            []
        );
    }

    /** COMMENTS-9 Fix #5: базовый URL ЛМ ЧЗ публично (гибрид строит URL офлайн-ветки). */
    public function publicBaseUrl(): string
    {
        return $this->baseUrl();
    }

    /** Инициализация ЛМ ЧЗ (раз в сессию/на старте). */
    public function init(): array
    {
        return $this->post('/init', ['inn' => MarkingConfig::get('inn', '')]);
    }

    /** Статус ЛМ ЧЗ. */
    public function status(): array
    {
        $response = $this->http()->request('GET', $this->baseUrl() . '/status', $this->headers(), null, ['timeout' => $this->timeout()]);
        if (!$response->ok()) {
            throw new HttpException('ЛМ ЧЗ /status: HTTP ' . $response->status . ' ' . ($response->error ?? ''));
        }

        return $response->json() ?? [];
    }

    /**
     * Офлайн-проверка списка КИ: POST /outCheck (до 100 шт., батчи внутри).
     *
     * @return array{ok: bool, per_code: array<string, array{status: string, group_id: ?int, sold: ?bool}>, blocked: array<string>, sold: array<string>}
     */
    public function outCheck(array $cisList): array
    {
        $cis = MarkingCode::toCisList($cisList);
        if ($cis === []) {
            return ['ok' => true, 'per_code' => [], 'blocked' => [], 'sold' => []];
        }

        $perCode = [];
        $blocked = [];
        $sold = [];

        foreach (MarkingCode::chunks($cis, (int) MarkingConfig::get('lm_chz.out_check_batch_size', 100)) as $chunk) {
            $data = $this->post('/outCheck', ['codes' => $chunk]);
            foreach ($data['results'] ?? [] as $raw) {
                if (!is_array($raw)) {
                    continue;
                }
                $code = (string) ($raw['cis'] ?? $raw['code'] ?? '');
                if ($code === '') {
                    continue;
                }
                $status = strtoupper((string) ($raw['status'] ?? 'UNKNOWN'));
                $perCode[$code] = [
                    'status'     => $status,
                    'group_id'   => $raw['groupId'] ?? $raw['group_id'] ?? null,
                    'sold'       => $raw['sold'] ?? null,
                    'description'=> $raw['description'] ?? $raw['errorDescription'] ?? null,
                ];

                // ЛМ ЧЗ знает о запретах офлайн: отозван/запрещён → BLOCK.
                if (in_array($status, ['CANCELLED', 'BANNED', 'RECALLED', 'BANN'], true)) {
                    $blocked[] = $code;
                }
                // Дубль: КИ уже продан → WARN + алерт персонала (SPEC §2.4 «duplicate»).
                if (in_array($status, ['SOLD', 'DUPLICATE'], true) || ($raw['sold'] ?? null) === true) {
                    $sold[] = $code;
                }
            }
        }

        return ['ok' => true, 'per_code' => $perCode, 'blocked' => $blocked, 'sold' => $sold];
    }

    /**
     * Подтверждение продажи: POST /cis/sell.
     * @return array Ответ ЛМ ЧЗ (фискализация/авторизация выполнена).
     */
    public function sell(array $cisList): array
    {
        return $this->post('/cis/sell', ['codes' => MarkingCode::toCisList($cisList)]);
    }

    /**
     * Возврат товара: POST /cis/returned (SPEC §8, точка вызова — CheckService::refundCheck()).
     * @return array Ответ ЛМ ЧЗ.
     */
    public function returned(array $cisList): array
    {
        return $this->post('/cis/returned', ['codes' => MarkingCode::toCisList($cisList)]);
    }

    /**
     * SPEC §3.6: выгрузка проданных КИ — GET /cis/sold?skip=&limit= (для бэкапа/сверки).
     * НЕ «подтверждение продажи» (подтверждение — sell()).
     */
    public function sold(int $skip = 0, int $limit = 100): array
    {
        $limit = min(max($limit, 1), 100);
        $url = $this->baseUrl() . '/cis/sold?skip=' . max($skip, 0) . '&limit=' . $limit;
        $response = $this->http()->request('GET', $url, $this->headers(), null, ['timeout' => $this->timeout() * 5]);
        if (!$response->ok()) {
            throw new HttpException('ЛМ ЧЗ /cis/sold: HTTP ' . $response->status . ' ' . ($response->error ?? ''));
        }

        return $response->json() ?? [];
    }

    /**
     * @return array декодированный JSON ответа ЛМ ЧЗ
     * @throws HttpException
     */
    private function post(string $path, array $payload): array
    {
        $response = $this->http()->request(
            'POST',
            $this->baseUrl() . $path,
            $this->headers(),
            json_encode($payload, JSON_UNESCAPED_UNICODE),
            ['timeout' => $this->timeout()]
        );

        if ($response->error !== null && $response->status === 0) {
            throw new HttpException('ЛМ ЧЗ недоступен (' . $this->baseUrl() . $path . '): ' . $response->error);
        }
        if (!$response->ok()) {
            throw new HttpException('ЛМ ЧЗ ' . $path . ': HTTP ' . $response->status . ' ' . ($response->error ?? ''));
        }

        $data = $response->json();
        if ($data === null) {
            throw new HttpException('ЛМ ЧЗ ' . $path . ': невалидный JSON');
        }

        return $data;
    }
}