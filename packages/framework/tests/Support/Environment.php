<?php
declare(strict_types=1);

namespace Tests\Support;

final class Environment
{
    public const CLI_COLOR_KEYS = [
        'NO_COLOR',
        'FORCE_COLOR',
        'CLICOLOR_FORCE',
        'CLICOLOR',
        'TERM',
        'TERM_PROGRAM',
        'COLORTERM',
        'ANSICON',
        'ConEmuANSI',
        'MSYSTEM',
    ];

    public static function set(string $name, string $value): void
    {
        putenv($name . '=' . $value);
        $_SERVER[$name] = $value;
    }

    public static function clear(string $name): void
    {
        putenv($name);
        unset($_SERVER[$name]);
    }

    public static function clear_many(array $names): void
    {
        foreach ($names as $name) {
            self::clear((string)$name);
        }
    }

    public static function clear_cli_color(): void
    {
        self::clear_many(self::CLI_COLOR_KEYS);
    }
}
