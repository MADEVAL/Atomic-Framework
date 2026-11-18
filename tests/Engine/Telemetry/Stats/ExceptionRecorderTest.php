<?php
declare(strict_types=1);

namespace Tests\Engine\Telemetry\Stats;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Hook\Hook;
use Engine\Atomic\Telemetry\Stats\ExceptionRecorder;
use Engine\Atomic\Telemetry\Stats\StatsQuery;
use Engine\Atomic\Telemetry\Stats\StatsRecorder;
use Tests\Engine\Telemetry\Support\StatsDbTestCase;

final class ExceptionRecorderTest extends StatsDbTestCase
{
    public function test_registrar_uses_structured_source_and_never_parses_trace_text(): void
    {
        $base = \Base::instance();
        $old_exception = $base->get('EXCEPTION');
        $old_trace = $base->get('ERROR.trace');
        $method = new \ReflectionMethod(\Engine\Atomic\Core\ExceptionHandlerRegistrar::class, 'record_exception_stats');
        $payloads = [];
        Hook::instance()->add_action(ExceptionRecorder::HOOK_RECORDED, function (array $p) use (&$payloads): void { $payloads[] = $p; }, 10, 1);
        try {
            $base->set('EXCEPTION', null);
            foreach (['incidental name:123 at C:/a path/file.php:99', [['file' => 'C:/a path/file.php', 'line' => 99]]] as $trace) {
                $base->set('ERROR.trace', $trace);
                $method->invoke(null, $base, ['code' => 500, 'text' => 'name:123', 'level' => 'error', 'path' => '/', 'dump_id' => null]);
            }
            self::assertNull($payloads[0]['file']);
            self::assertNull($payloads[0]['line']);
            self::assertSame('C:/a path/file.php', $payloads[1]['file']);
            self::assertSame(99, $payloads[1]['line']);
            $exception = new \RuntimeException('name:123');
            $base->set('EXCEPTION', $exception);
            $method->invoke(null, $base, ['code' => 500, 'text' => '', 'level' => 'error', 'path' => '/', 'dump_id' => null]);
            self::assertSame($exception->getFile(), $payloads[2]['file']);
            self::assertSame($exception->getLine(), $payloads[2]['line']);
            StatsRecorder::instance()->flush();
            $groups = (new \Engine\Atomic\Telemetry\Reporters\ExceptionsReporter())->report()['groups'];
            self::assertCount(3, $groups);
            self::assertContains('C:/a path/file.php', array_column($groups, 'file'));
            $unknown = array_values(array_filter($groups, static fn (array $g): bool => $g['class'] === 'PHP Error' && $g['file'] !== 'C:/a path/file.php'))[0];
            self::assertNull($unknown['file']);
            self::assertNull($unknown['line']);
            self::assertSame(sha1('PHP Error|name:123|:0'), $unknown['id']);
        } finally {
            Hook::instance()->remove_action(ExceptionRecorder::HOOK_RECORDED);
            $base->set('EXCEPTION', $old_exception);
            $base->set('ERROR.trace', $old_trace);
        }
    }

    public function test_capture_writes_group_occurrence_and_counter(): void
    {
        ExceptionRecorder::capture([
            'class' => 'RuntimeException',
            'message' => 'boom happened',
            'file' => '/app/src/Service.php',
            'line' => 42,
            'code' => 500,
            'level' => 'error',
            'path' => '/api/items',
            'dump_id' => null,
        ]);
        StatsRecorder::instance()->flush();

        $report = (new \Engine\Atomic\Telemetry\Reporters\ExceptionsReporter())->report('24h');
        self::assertCount(1, $report['groups']);
        $group = $report['groups'][0];
        self::assertSame('RuntimeException', $group['class']);
        self::assertSame('boom happened', $group['message']);
        self::assertSame(42, $group['line']);
        self::assertSame(500, $group['code']);
        self::assertSame(1, $group['total']);
        self::assertSame(1, $group['count']);
        self::assertSame(1, $report['lifetime']['distinct']);
        self::assertSame(1, $report['lifetime']['occurrences']);
    }

    public function test_capture_accumulates_totals_for_the_same_fingerprint(): void
    {
        $payload = [
            'class' => 'LogicException',
            'message' => 'same crash',
            'file' => '/app/src/Other.php',
            'line' => 7,
            'code' => 500,
            'level' => 'error',
            'path' => '/',
            'dump_id' => null,
        ];
        ExceptionRecorder::capture($payload);
        StatsRecorder::instance()->flush();
        ExceptionRecorder::capture($payload);
        StatsRecorder::instance()->flush();

        $report = (new \Engine\Atomic\Telemetry\Reporters\ExceptionsReporter())->report('24h');
        self::assertCount(1, $report['groups']);
        self::assertSame(2, $report['groups'][0]['total']);
        self::assertSame(2, $report['lifetime']['occurrences']);
    }

    public function test_capture_fires_hook_and_is_isolated_from_listener_failures(): void
    {
        $received = null;
        $hook = Hook::instance();
        $hook->add_action(ExceptionRecorder::HOOK_RECORDED, static function (array $payload) use (&$received): void {
            $received = $payload;
        }, 10, 1);

        try {
            ExceptionRecorder::capture([
                'class' => 'DomainException',
                'message' => 'hooked',
                'file' => '/app/x.php',
                'line' => 1,
                'code' => 500,
                'level' => 'error',
                'path' => '/',
                'dump_id' => null,
            ]);

            self::assertIsArray($received);
            self::assertSame('DomainException', $received['class']);
        } finally {
            $hook->remove_action(ExceptionRecorder::HOOK_RECORDED);
        }
    }
}
