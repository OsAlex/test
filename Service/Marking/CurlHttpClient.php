<?php

namespace Service\Marking;

/** curl-реализация HttpClient (в стиле существующих вызовов проекта: curl_setopt_array). */
final class CurlHttpClient implements HttpClient
{
    public function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        array $options = []
    ): HttpResponse {
        $headers = $body !== null && !array_key_exists('Content-Type', $headers)
            ? array_merge($headers, ['Content-Type: application/json; charset=utf-8'])
            : $headers;

        $defaults = [
            CURLOPT_URL            => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $options['timeout'] ?? 10,
            CURLOPT_CONNECTTIMEOUT => $options['connect_timeout'] ?? ($options['timeout'] ?? 10),
            CURLOPT_HTTP_VERSION   => CURL_HTTP_VERSION_1_1,
        ];
        if (strtoupper($method) === 'GET') {
            $defaults[CURLOPT_HTTPGET] = true;
        } else {
            $defaults[CURLOPT_POST] = true;
            $defaults[CURLOPT_POSTFIELDS] = $body ?? '';
        }

        $ch = curl_init();
        $pp = curl_setopt_array($ch, $defaults);
        if (!$pp) {
            curl_close($ch);

            return new HttpResponse(0, '', 0, 'curl: curl_setopt_array failed for ' . $url);
        }

        $start  = microtime(true);
        $result = curl_exec($ch);
        $time_ms = (int) round((microtime(true) - $start) * 1000);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error  = curl_errno($ch) ? curl_error($ch) : null;
        curl_close($ch);

        return new HttpResponse($status, (string) $result, $time_ms, $error);
    }
}