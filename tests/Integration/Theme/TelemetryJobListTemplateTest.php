<?php
declare(strict_types=1);

namespace Tests\Integration\Theme;

use PHPUnit\Framework\TestCase;

/**
 * The Telemetry queue view renders arbitrary job payloads and exception data.
 * Both must be escaped to prevent stored XSS (П-13).
 */
final class TelemetryJobListTemplateTest extends TestCase
{
    private const TEMPLATE = ATOMIC_DIR . '/packages/skeleton/public/themes/Telemetry/partials/job-list.atom.php';

    private function render(array $data): string
    {
        ob_start();
        extract($data, EXTR_SKIP);
        require self::TEMPLATE;
        return (string)ob_get_clean();
    }

    private function maliciousJob(): array
    {
        $malicious = '<script>alert(1)</script>';

        return [
            'driver' => 'db',
            'queue' => 'default',
            'state' => 'failed',
            'uuid' => 'job-1',
            'created_at' => '1700000000',
            'created_at_formatted' => '2023-11-14',
            'process_start_ticks' => '',
            'max_attempts' => '3',
            'attempts' => '2',
            'timeout' => '30',
            'retry_delay' => '5',
            'priority' => '0',
            'cancel_requested_at' => '',
            'cancel_requested_at_formatted' => '',
            'cancelled_at' => '',
            'cancelled_at_formatted' => '',
            'reason' => '',
            'payload' => json_encode(['note' => $malicious]),
            'exception' => [
                'message' => $malicious,
                'file' => 'x.php',
                'line' => '1',
                'trace_string' => $malicious,
            ],
        ];
    }

    public function test_payload_output_is_escaped(): void
    {
        $output = $this->render([
            'jobs' => ['job-1' => $this->maliciousJob()],
            'pagination' => ['page' => 1, 'per_page' => 50, 'total' => 1, 'last_page' => 1],
        ]);

        $this->assertStringNotContainsString('<script>', $output);
        $this->assertStringContainsString('&lt;script&gt;', $output);
    }

    public function test_exception_json_output_is_hex_escaped(): void
    {
        $output = $this->render([
            'jobs' => ['job-1' => $this->maliciousJob()],
            'pagination' => ['page' => 1, 'per_page' => 50, 'total' => 1, 'last_page' => 1],
        ]);

        $this->assertStringNotContainsString('<script>', $output);
        $this->assertStringContainsString('\u003Cscript\u003E', $output);
    }
}
