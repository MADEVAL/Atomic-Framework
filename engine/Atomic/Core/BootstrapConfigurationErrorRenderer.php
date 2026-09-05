<?php

declare(strict_types=1);

namespace Engine\Atomic\Core;

if (!defined('ATOMIC_START')) {
    exit;
}

final class BootstrapConfigurationErrorRenderer
{
    /** @param list<string> $errors */
    public static function web_page(array $errors, bool $debug): string
    {
        $details = '';
        if ($debug) {
            $items = array_map(
                static fn(string $error): string => '<li>' . htmlspecialchars($error, ENT_QUOTES, 'UTF-8') . '</li>',
                $errors,
            );
            $details = '<p>Fix the following settings before starting the application:</p><ul>'
                . implode('', $items)
                . '</ul><p>Run <code>php atomic health</code> for a complete diagnosis. '
                . '<code>php atomic init</code> can create or repair the application configuration.</p>';
        } else {
            $details = '<p>The application is not configured correctly. Please contact the administrator.</p>';
        }

        return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Configuration Error | Atomic</title>'
            . '<style>body{font-family:system-ui,-apple-system,sans-serif;background:#f5f5f5;color:#222;margin:0;padding:2rem}'
            . '.box{max-width:680px;margin:8vh auto;background:#fff;border-top:4px solid #dc2626;border-radius:8px;padding:2rem;box-shadow:0 4px 16px #0001}'
            . 'h1{font-size:1.5rem;color:#b91c1c;margin-top:0}code{background:#f3f4f6;padding:.15rem .35rem;border-radius:4px}</style>'
            . '</head><body><main class="box"><h1>Application configuration error</h1>'
            . $details
            . '</main></body></html>';
    }

    /** @param list<string> $errors */
    public static function cli_message(array $errors): string
    {
        $lines = [
            '[Atomic] Application configuration error',
            str_repeat('-', 40),
        ];

        foreach ($errors as $error) {
            $lines[] = ' - ' . $error;
        }

        $lines[] = 'Diagnose: php atomic health';
        $lines[] = 'Repair: php atomic init';
        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    /** @param list<string> $errors */
    public static function render_web(array $errors, bool $debug): void
    {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
        echo self::web_page($errors, $debug);
    }

    /** @param list<string> $errors */
    public static function render_cli(array $errors): void
    {
        file_put_contents('php://stderr', self::cli_message($errors));
    }
}
