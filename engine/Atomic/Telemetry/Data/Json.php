<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Data;

if (!defined('ATOMIC_START')) exit;

final class Json
{
    private const INDENT = '  ';

    public static function pretty(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            return '';
        }

        // PHP indents by four spaces, JavaScript by two.
        return (string)preg_replace_callback('/^(?: {4})+/m', static fn (array $m): string => str_repeat(self::INDENT, intdiv(strlen($m[0]), 4)), $json);
    }
}
