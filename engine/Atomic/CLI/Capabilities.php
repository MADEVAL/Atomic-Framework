<?php
declare(strict_types=1);
namespace Engine\Atomic\CLI;

if (!defined('ATOMIC_START')) exit;

class Capabilities
{
    public static function supports_colors(mixed $stream = null): bool
    {
        if ($stream === null) {
            if (!defined('STDOUT')) {
                return false;
            }
            $stream = STDOUT;
        }

        if (!is_resource($stream)) {
            return false;
        }

        if (self::env_has_flag('NO_COLOR')) {
            return false;
        }

        if (self::env_has_flag('FORCE_COLOR') || self::env_has_flag('CLICOLOR_FORCE')) {
            return true;
        }

        $is_msys_terminal = self::is_msys_terminal();

        $isTty = false;
        if (function_exists('stream_isatty')) {
            $isTty = @stream_isatty($stream);
        } elseif (function_exists('posix_isatty')) {
            $isTty = @posix_isatty($stream);
        }

        if (!$isTty && !$is_msys_terminal) {
            return false;
        }

        if (self::env_value('CLICOLOR') === '0') {
            return false;
        }

        $term = strtolower(self::env_value('TERM'));
        if ($term === 'dumb') {
            return false;
        }

        if (PHP_OS_FAMILY === 'Windows'
            && function_exists('sapi_windows_vt100_support')
            && @sapi_windows_vt100_support($stream)
        ) {
            return true;
        }

        if (self::has_terminal_hints()) {
            return true;
        }

        return self::is_supported_terminal($term);
    }

    private static function env_has_flag(string $name): bool
    {
        return '' !== (self::env_value($name)[0] ?? '');
    }

    private static function env_value(string $name): string
    {
        $value = $_SERVER[$name] ?? getenv($name);
        return $value === false ? '' : (string) $value;
    }

    private static function is_msys_terminal(): bool
    {
        return in_array(strtoupper(self::env_value('MSYSTEM')), ['MINGW32', 'MINGW64'], true);
    }

    private static function has_terminal_hints(): bool
    {
        return self::env_value('TERM_PROGRAM') === 'Hyper'
            || self::env_value('COLORTERM') !== ''
            || self::env_value('ANSICON') !== ''
            || strtoupper(self::env_value('ConEmuANSI')) === 'ON';
    }

    private static function is_supported_terminal(string $term): bool
    {
        return preg_match('/^((screen|xterm|vt100|vt220|putty|rxvt|ansi|cygwin|linux).*)|(.*-256(color)?(-bce)?)$/', $term) === 1;
    }
}
