<?php
declare(strict_types=1);
namespace Engine\Atomic\CLI;

if (!defined('ATOMIC_START')) exit;

class Style
{
    private const RESET  = "\033[0m";
    private const BOLD   = "\033[1m";
    private const RED    = "\033[31m";
    private const GREEN  = "\033[32m";
    private const YELLOW = "\033[33m";
    private const CYAN   = "\033[36m";

    public static function red(string $text, bool $bold = false): string
    {
        return self::paint($text, self::RED, $bold);
    }

    public static function green(string $text, bool $bold = false): string
    {
        return self::paint($text, self::GREEN, $bold);
    }

    public static function yellow(string $text, bool $bold = false): string
    {
        return self::paint($text, self::YELLOW, $bold);
    }

    public static function cyan(string $text, bool $bold = false): string
    {
        return self::paint($text, self::CYAN, $bold);
    }

    public static function bold(string $text): string
    {
        return self::paint($text, null, true);
    }

    public static function warning_label(): string
    {
        return self::yellow('[WARNING]', true);
    }

    public static function error_label(): string
    {
        return self::red('[ERROR]', true);
    }

    public static function success_label(): string
    {
        return self::green('[OK]', true);
    }

    private static function paint(string $text, ?string $color, bool $bold): string
    {
        if (!Capabilities::supports_colors()) {
            return $text;
        }

        $prefix = '';
        if ($bold) {
            $prefix .= self::BOLD;
        }
        if ($color !== null) {
            $prefix .= $color;
        }

        return $prefix . $text . self::RESET;
    }
}
