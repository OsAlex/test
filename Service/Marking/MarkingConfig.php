<?php

namespace Service\Marking;

/**
 * Доступ к config/marking.php (config-first, Запрос_заказчику_ответ.md).
 *
 * Отделённый класс-обёртка: в проекте нет фреймворка (composer.json — plain PHP 8,
 * PSR-4: Service\, Models\, ...), поэтому config('marking.*') из SPEC.md реализован
 * как MarkingConfig::get('marking.key').
 */
final class MarkingConfig
{
    private static ?array $cache = null;

    /** Полный массив конфигурации. */
    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = include dirname(__DIR__, 2) . '/config/marking.php';
        }

        return self::$cache;
    }

    /**
     * Значение по dot-пути: MarkingConfig::get('cdn.switch_threshold_sec').
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::all();
        foreach (explode('.', $key) as $part) {
            if (is_array($value) && array_key_exists($part, $value)) {
                $value = $value[$part];
            } else {
                return $default;
            }
        }

        return $value;
    }

    /** Актуальная среда: test|prod. */
    public static function env(): string
    {
        return (string) self::get('env', 'test');
    }

    /** Утилиты тестов: сброс кэша после смены $_ENV. */
    public static function resetCache(): void
    {
        self::$cache = null;
    }

    /**
     * Утилита тестов: override значения по dot-пути без смены $_ENV
     * (COMMENTS-9: MarkingConfig::set('atol.tag1260_placeholder', false)).
     */
    public static function set(string $key, mixed $value): void
    {
        $config = self::all();
        $parts = explode('.', $key);
        $end = array_pop($parts);
        $ref = &$config;
        foreach ($parts as $part) {
            if (!isset($ref[$part]) || !is_array($ref[$part])) {
                $ref[$part] = [];
            }
            $ref = &$ref[$part];
        }
        $ref[$end] = $value;
        self::$cache = $config;
    }
}