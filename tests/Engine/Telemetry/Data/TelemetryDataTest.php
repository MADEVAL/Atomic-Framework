<?php
declare(strict_types=1);

namespace Tests\Engine\Telemetry\Data;

use Engine\Atomic\Telemetry\Data\JobOutcome;
use Engine\Atomic\Telemetry\Data\JobRun;
use Engine\Atomic\Telemetry\Data\LockInfo;
use Engine\Atomic\Telemetry\Data\MutexDriverInfo;
use Engine\Atomic\Telemetry\Data\MutexReport;
use Engine\Atomic\Telemetry\Data\PluginDependency;
use Engine\Atomic\Telemetry\Data\PluginInfo;
use Engine\Atomic\Telemetry\Data\PluginRequirement;
use Engine\Atomic\Telemetry\Data\PluginState;
use Engine\Atomic\Telemetry\Data\RateKey;
use Engine\Atomic\Telemetry\Data\RateStrategy;
use Engine\Atomic\Telemetry\Data\ScheduledJob;
use Engine\Atomic\Telemetry\Data\StepOutcome;
use PHPUnit\Framework\TestCase;

/** The typed objects keep the JSON shape the endpoints always returned. */
final class TelemetryDataTest extends TestCase
{
    public function test_plugin_info_serializes_to_the_plugins_endpoint_shape(): void
    {
        $plugin = new PluginInfo(
            name: 'Search',
            class: 'App\\Search',
            version: '2.0.0',
            enabled: true,
            state: PluginState::Registered,
            load_order: 3,
            deps: [new PluginDependency('App\\Core', PluginState::Booted)],
            error: null,
            requirements: [new PluginRequirement('vendor/es', false)],
            migration_source: 'plugin:search',
            has_migrations: true,
            construct: StepOutcome::Ok,
            register: StepOutcome::Ok,
            boot: StepOutcome::Pending,
        );

        self::assertSame([
            'name' => 'Search',
            'class' => 'App\\Search',
            'version' => '2.0.0',
            'enabled' => true,
            'state' => 'registered',
            'load_order' => 3,
            'deps' => [['name' => 'App\\Core', 'state' => 'booted']],
            'error' => null,
            'requirements' => [['package' => 'vendor/es', 'missing' => false]],
            'migration_source' => 'plugin:search',
            'has_migrations' => true,
            'steps' => ['construct' => 'ok', 'register' => 'ok', 'boot' => 'registered'],
        ], $plugin->to_array());
    }

    public function test_failed_and_skipped_plugins_are_problems(): void
    {
        self::assertTrue(PluginState::Failed->is_problem());
        self::assertTrue(PluginState::Skipped->is_problem());
        self::assertFalse(PluginState::Booted->is_problem());
        self::assertFalse(PluginState::Disabled->is_problem());
    }

    public function test_mutex_report_serializes_and_finds_expired_locks(): void
    {
        $report = new MutexReport('file', [new MutexDriverInfo('file', true, true)], [
            new LockInfo('a', 't', 5, 60, 55),
            new LockInfo('b', 't', 90, 60, 0),
        ]);

        self::assertSame(['b'], array_map(static fn (LockInfo $lock): string => $lock->name, $report->expired()));
        self::assertSame([
            'driver' => 'file',
            'drivers' => [['name' => 'file', 'available' => true, 'active' => true]],
            'locks' => [
                ['name' => 'a', 'token' => 't', 'age' => 5, 'ttl' => 60, 'remaining' => 55],
                ['name' => 'b', 'token' => 't', 'age' => 90, 'ttl' => 60, 'remaining' => 0],
            ],
        ], $report->to_array());
    }

    public function test_rate_key_serializes_and_knows_when_it_is_limited(): void
    {
        $key = new RateKey('api:login', RateStrategy::Fixed, 5, 5, 30, 2);

        self::assertTrue($key->is_limited());
        self::assertSame(1.0, $key->usage());
        self::assertSame(['key' => 'api:login', 'strategy' => 'fixed', 'limit' => 5, 'used' => 5, 'reset_in' => 30, 'blocked_hour' => 2], $key->to_array());
        self::assertFalse((new RateKey('k', RateStrategy::Sliding, 10, 3, 0, 0))->is_limited());
    }

    public function test_job_outcome_maps_stored_statuses(): void
    {
        self::assertSame(JobOutcome::Never, JobOutcome::of(null));
        self::assertSame(JobOutcome::Timeout, JobOutcome::of('timeout'));
        self::assertSame(JobOutcome::Unknown, JobOutcome::of('exploded'));
        self::assertSame('Unknown (exploded)', JobOutcome::Unknown->text('exploded'));
        self::assertSame('never', JobOutcome::Never->key());
        self::assertTrue(JobOutcome::Timeout->is_failing());
        self::assertFalse(JobOutcome::Skipped->is_failing());
    }

    public function test_scheduled_job_serializes_to_the_scheduled_endpoint_shape(): void
    {
        $job = new ScheduledJob(
            id: 'abc',
            name: 'App\\Jobs\\Prune::handle',
            command: 'App\\Jobs\\Prune::handle',
            cron: '0 3 * * *',
            human: 'At 03:00',
            owner: null,
            enabled: true,
            last_outcome: JobOutcome::Failed,
            last_status: 'failed',
            last_run_at: 1700000000,
            duration: 1.5,
            next_runs: [new \DateTimeImmutable('2026-11-21T03:00:00+00:00')],
            runs: [new JobRun(JobOutcome::Failed, 1700000000, 1500.0, 'boom'), new JobRun(JobOutcome::Ok, 1699990000, 200.0, null)],
        );

        self::assertSame('Prune::handle', $job->display_name());
        self::assertSame([
            'id' => 'abc',
            'name' => 'App\\Jobs\\Prune::handle',
            'command' => 'App\\Jobs\\Prune::handle',
            'cron' => '0 3 * * *',
            'human' => 'At 03:00',
            'target' => 'App\\Jobs\\Prune::handle',
            'owner' => null,
            'enabled' => true,
            'state' => 'enabled',
            'last_outcome' => 'failed',
            'last_status' => 'failed',
            'last_run_at' => 1700000000,
            'dur' => 1.5,
            'next_runs' => ['2026-11-21T03:00:00+00:00'],
            'runs' => [
                ['s' => 'failed', 'h' => 2, 'at' => 1700000000, 'ms' => 1500.0, 'error' => 'boom'],
                ['s' => 'ok', 'h' => 1, 'at' => 1699990000, 'ms' => 200.0, 'error' => null],
            ],
        ], $job->to_array());
    }
}
