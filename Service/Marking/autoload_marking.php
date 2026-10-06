<?php

/**
 * Автозагрузка Service\Marking\* для CLI-скриптов (process_sells.php и т.п.).
 * На сервере работает и через vendor/autoload.php (PSR-4 "Service\\": "Service");
 * локально (без vendor) — вручную.
 */

$markingRoot = dirname(__DIR__, 2);

spl_autoload_register(function (string $class) use ($markingRoot): void {
    $prefix = 'Service\\Marking\\';
    if (str_starts_with($class, $prefix)) {
        $file = $markingRoot . '/Service/Marking/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

// Dotenv, если доступен (composer install выполнен).
if (class_exists(\Dotenv\Dotenv::class)) {
    try {
        \Dotenv\Dotenv::createImmutable($markingRoot)->load();
    } catch (\Throwable) {
        // .env может отсутствовать — значения config/marking.php берут из $_ENV/гетев.
    }
}