<?php

namespace Service\Marking;

/**
 * Тонкая HTTP-абстракция для тестовости (в тестах — FakeHttpClient).
 * Реализация по умолчанию: CurlHttpClient (curl, как везде в проекте).
 */
interface HttpClient
{
    /**
     * Выполнить запрос.
     *
     * @param string      $method  GET|POST
     * @param string      $url     Полный URL.
     * @param array       $headers Список заголовков "Name: value".
     * @param string|null $body    Тело запроса (JSON).
     * @param array       $options ['timeout' => float, 'connect_timeout' => float]
     */
    public function request(
        string $method,
        string $url,
        array $headers = [],
        ?string $body = null,
        array $options = []
    ): HttpResponse;
}