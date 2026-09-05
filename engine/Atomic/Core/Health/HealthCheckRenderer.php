<?php

declare(strict_types=1);

namespace Engine\Atomic\Core\Health;

if (!defined('ATOMIC_START')) {
    exit;
}

use Engine\Atomic\CLI\Console\Output;

final class HealthCheckRenderer
{
    public static function cli_report(array $report): string
    {
        $stream = fopen('php://memory', 'r+');
        if ($stream === false) {
            return '';
        }

        try {
            self::render_cli($report, new Output($stream, $stream));
            rewind($stream);
            return stream_get_contents($stream) ?: '';
        } finally {
            fclose($stream);
        }
    }

    public static function web_json(array $report): string
    {
        return json_encode(
            ['status' => ($report['healthy'] ?? false) ? 'ok' : 'unhealthy'],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
        );
    }

    public static function web_status(array $report): int
    {
        return ($report['healthy'] ?? false) ? 200 : 503;
    }

    public static function render_cli(array $report, ?Output $output = null): void
    {
        $output ??= new Output();
        $output->section('Atomic Framework Health Check');
        $output->writeln(str_repeat('=', 29));

        $sections = [
            'Required' => (array)($report['required'] ?? []),
            'Highly recommended' => (array)($report['recommended'] ?? []),
            'Optional' => (array)($report['optional'] ?? []),
        ];

        foreach ($sections as $title => $checks) {
            if ($checks === []) {
                continue;
            }

            $output->writeln();
            $output->section($title);
            foreach ($checks as $check) {
                $output->check(
                    (string)($check['status'] ?? 'fail'),
                    (string)($check['name'] ?? 'Unknown'),
                    (string)($check['message'] ?? ''),
                );
                $suggestion = trim((string)($check['suggestion'] ?? ''));
                if ($suggestion !== '') {
                    $output->writeln('         Fix: ' . $suggestion);
                }
            }
        }

        $output->writeln();
        $output->health_status((bool)($report['healthy'] ?? false));
    }

    public static function render_web(array $report): void
    {
        http_response_code(self::web_status($report));
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        echo self::web_json($report);
    }
}
