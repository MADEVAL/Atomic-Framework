<?php
declare(strict_types=1);

namespace Tests\Integration\Theme;

use Engine\Atomic\Theme\Assets;
use Engine\Atomic\Theme\Theme;
use PHPUnit\Framework\TestCase;

/**
 * The redesigned Telemetry SPA receives arbitrary job payloads and exception
 * data through window.TELEMETRY_JOBS (injected by Assets::localize_script)
 * and renders them client-side with an esc() helper. The injection point must
 * hex-escape JSON so payloads cannot break out of the inline <script>
 * context (stored XSS).
 */
final class TelemetryJobListTemplateTest extends TestCase
{
    private function maliciousJob(): array
    {
        $malicious = '<script>alert(1)</script>';

        return [
            'driver' => 'db',
            'queue' => 'default</script><script>alert(2)</script>',
            'state' => 'failed',
            'uuid' => 'job-1',
            'created_at' => '1700000000',
            'created_at_formatted' => '2023-11-14',
            'max_attempts' => '3',
            'attempts' => '2',
            'timeout' => '30',
            'retry_delay' => '5',
            'payload' => ['note' => $malicious],
            'exception' => [
                'message' => $malicious,
                'file' => 'x.php',
                'line' => '1',
                'trace_string' => $malicious,
            ],
        ];
    }

    private function localizedScript(array $jobs): string
    {
        // resolve_src() needs a resolvable theme; boot the builtin default.
        \Engine\Atomic\Core\App::instance()->set('THEME.envname', 'default');
        Theme::instance('default');

        $assets = Assets::instance();
        $assets->enqueue_script('telemetry', 'telemetry.js');
        $assets->localize_script('telemetry', $jobs, 'TELEMETRY_JOBS');
        ob_start();
        $method = new \ReflectionMethod(Assets::class, 'print_script_recursive');
        $method->invoke($assets, 'telemetry', true, []);
        $html = (string)ob_get_clean();
        $assets->dequeue_script('telemetry');

        return $html;
    }

    public function test_injected_job_payload_is_hex_escaped_for_script_context(): void
    {
        $output = $this->localizedScript(['job-1' => $this->maliciousJob()]);

        $this->assertStringNotContainsString('<script>alert(1)', $output);
        $this->assertStringContainsString('\u003Cscript\u003E', $output);
        // The queue-name breakout attempt is neutralized the same way.
        $this->assertStringNotContainsString('</script><script>alert(2)', $output);
    }

    public function test_injected_payload_stays_parseable_json(): void
    {
        $job = $this->maliciousJob();
        $output = $this->localizedScript(['job-1' => $job]);

        $this->assertStringContainsString('TELEMETRY_JOBS', $output);

        // The emitted assignment is a JSON literal: decoding round-trips the
        // data (hex escapes decode back to the original characters).
        if (preg_match('/TELEMETRY_JOBS"\]=(\{.*\});/s', $output, $m)) {
            $decoded = json_decode($m[1], true);
            $this->assertIsArray($decoded);
            $this->assertSame('<script>alert(1)</script>', $decoded['job-1']['payload']['note'] ?? null);
            $this->assertSame('default</script><script>alert(2)</script>', $decoded['job-1']['queue'] ?? null);
        } else {
            $this->fail('localized assignment not found');
        }
    }

    public function test_server_side_injection_config_and_jobs_are_printed_before_the_script_tag(): void
    {
        $output = $this->localizedScript(['job-1' => $this->maliciousJob()]);

        $injection = strpos($output, 'TELEMETRY_JOBS');
        $src = strpos($output, 'src="');
        $this->assertNotFalse($injection);
        $this->assertNotFalse($src);
        $this->assertLessThan($src, $injection, 'localized data must precede the script tag');
    }
}
