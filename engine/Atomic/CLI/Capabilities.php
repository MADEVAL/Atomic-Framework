<?php
declare(strict_types=1);
namespace Engine\Atomic\CLI;

if (!defined('ATOMIC_START')) exit;

class Capabilities
{
    public static function supportsColors(mixed $stream = null): bool
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

        if (self::envHasFlag('NO_COLOR')) {
            return false;
        }

        if (self::envHasFlag('FORCE_COLOR') || self::envHasFlag('CLICOLOR_FORCE')) {
            return true;
        }

        $isMsysTerminal = self::isMsysTerminal();

        $isTty = false;
        if (function_exists('stream_isatty')) {
            $isTty = @stream_isatty($stream);
        } elseif (function_exists('posix_isatty')) {
            $isTty = @posix_isatty($stream);
        }

        if (!$isTty && !$isMsysTerminal) {
            return false;
        }

        if (self::envValue('CLICOLOR') === '0') {
            return false;
        }

        $term = strtolower(self::envValue('TERM'));
        if ($term === 'dumb') {
            return false;
        }

        if (PHP_OS_FAMILY === 'Windows'
            && function_exists('sapi_windows_vt100_support')
            && @sapi_windows_vt100_support($stream)
        ) {
            return true;
        }

        if (self::hasTerminalHints()) {
            return true;
        }

        return self::isSupportedTerminal($term);
    }

    private static function envHasFlag(string $name): bool
    {
        return '' !== (self::envValue($name)[0] ?? '');
    }

    private static function envValue(string $name): string
    {
        $value = $_SERVER[$name] ?? getenv($name);
        return $value === false ? '' : (string) $value;
    }

    private static function isMsysTerminal(): bool
    {
        return in_array(strtoupper(self::envValue('MSYSTEM')), ['MINGW32', 'MINGW64'], true);
    }

    private static function hasTerminalHints(): bool
    {
        return self::envValue('TERM_PROGRAM') === 'Hyper'
            || self::envValue('COLORTERM') !== ''
            || self::envValue('ANSICON') !== ''
            || strtoupper(self::envValue('ConEmuANSI')) === 'ON';
    }

    private static function isSupportedTerminal(string $term): bool
    {
        return preg_match('/^((screen|xterm|vt100|vt220|putty|rxvt|ansi|cygwin|linux).*)|(.*-256(color)?(-bce)?)$/', $term) === 1;
    }
}
