<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';

class SettingsService
{
    private static array $cache = [];
    private static bool  $loaded = false;

    private static function load(): void
    {
        if (self::$loaded) return;
        $rows = Database::fetchAll("SELECT `key`, `value` FROM system_settings");
        foreach ($rows as $row) {
            self::$cache[$row['key']] = $row['value'];
        }
        self::$loaded = true;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::load();
        return self::$cache[$key] ?? $default;
    }

    public static function float(string $key, float $default = 0.0): float
    {
        return (float) self::get($key, $default);
    }

    public static function int(string $key, int $default = 0): int
    {
        return (int) self::get($key, $default);
    }
}
