<?php
declare(strict_types=1);

namespace Tests\Engine\Telemetry\View;

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
use Engine\Atomic\Telemetry\View\Templates;
use Engine\Atomic\Telemetry\View\Ui;
use PHPUnit\Framework\TestCase;

/** The server-rendered telemetry tabs (theme views/*.php) against typed data. */
final class TelemetryViewsTest extends TestCase
{
    private function render(string $view, mixed ...$args): \DOMXPath
    {
        $templates = new Templates(dirname(__DIR__, 4) . '/engine/Atomic/Theme/Builtin/Telemetry/views');
        $html = (string)$templates->render($view, new Ui(), ...$args);

        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><body>' . $html . '</body>');
        libxml_clear_errors();

        return new \DOMXPath($doc);
    }

    /**
     * @param list<PluginDependency> $deps
     * @param list<PluginRequirement> $requirements
     */
    private static function plugin(string $name, PluginState $state, ?string $error = null, array $deps = [], array $requirements = []): PluginInfo
    {
        return new PluginInfo(
            name: $name,
            class: 'App\\Plugins\\' . $name,
            version: '1.0.0',
            enabled: $state !== PluginState::Disabled,
            state: $state,
            load_order: $state === PluginState::Disabled ? null : 1,
            deps: $deps,
            error: $error,
            requirements: $requirements,
            migration_source: 'plugin:' . strtolower($name),
            has_migrations: false,
            construct: StepOutcome::Ok,
            register: $state === PluginState::Failed ? StepOutcome::Failed : StepOutcome::Ok,
            boot: $state === PluginState::Booted ? StepOutcome::Ok : StepOutcome::Skipped,
        );
    }

    private static function text(\DOMXPath $xpath, string $query): string
    {
        return trim((string)$xpath->query($query)->item(0)?->textContent);
    }

    public function test_plugins_rows_carry_their_filters_and_search_text(): void
    {
        $xpath = $this->render('plugins',
            self::plugin('Payments', PluginState::Booted),
            self::plugin('Search', PluginState::Failed, 'Host missing'),
            self::plugin('News', PluginState::Disabled));

        $rows = $xpath->query('//*[@data-row]');
        self::assertSame(3, $rows->length);
        self::assertSame('all active', $rows->item(0)->getAttribute('data-keys'));
        self::assertSame('all problem', $rows->item(1)->getAttribute('data-keys'));
        self::assertSame('all disabled', $rows->item(2)->getAttribute('data-keys'));
        self::assertSame('payments app\\plugins\\payments', $rows->item(0)->getAttribute('data-text'));
        self::assertSame('1', $xpath->query('//*[@data-badge="pl-badge"]')->item(0)->getAttribute('data-value'));

        $problems = $xpath->query('//*[@data-filter="problem"]/*[@data-count]')->item(0);
        self::assertSame('1', trim($problems->textContent));
        self::assertStringContainsString('is-hot', $problems->getAttribute('class'));
        self::assertSame('Showing 3 of 3 plugins', self::text($xpath, '//*[@class="list-foot"]'));
    }

    public function test_plugins_drawer_shows_error_lifecycle_and_dependencies(): void
    {
        $xpath = $this->render('plugins', self::plugin('Search', PluginState::Failed, 'Host missing',
            [new PluginDependency('App\\Plugins\\Core', PluginState::Registered)],
            [new PluginRequirement('vendor/es', true)]));

        $drawer = self::text($xpath, '//template');
        foreach (['Host missing', 'App\\Plugins\\Core', 'Registered', 'vendor/es', 'Missing', '(no migrations)', 'Not reached'] as $expected) {
            self::assertStringContainsString($expected, $drawer);
        }
    }

    public function test_plugins_problem_summary_hides_when_there_are_none(): void
    {
        $xpath = $this->render('plugins', self::plugin('Payments', PluginState::Booted));

        self::assertStringContainsString('hide', $xpath->query('//*[@data-hide-zero="problem"]')->item(0)->getAttribute('class'));
        self::assertSame(0, $xpath->query('//*[contains(@class, "is-hot")]')->length);
    }

    public function test_plugin_names_are_escaped(): void
    {
        $xpath = $this->render('plugins', self::plugin('<img src=x onerror=alert(1)>', PluginState::Booted));

        self::assertSame(0, $xpath->query('//img')->length);
        self::assertSame('<img src=x onerror=alert(1)>', $xpath->query('//*[@data-row]')->item(0)->getAttribute('data-row'));
    }

    public function test_an_empty_plugin_list_shows_the_empty_state(): void
    {
        $xpath = $this->render('plugins');

        $empty = $xpath->query('//*[@data-empty]')->item(0);
        self::assertSame('No plugins match.', trim($empty->textContent));
        self::assertStringNotContainsString('hide', $empty->getAttribute('class'));
    }

    public function test_mutex_lists_expired_locks_first_then_soonest_to_expire(): void
    {
        $xpath = $this->render('mutex', new MutexReport('file', [new MutexDriverInfo('file', true, true)], [
            new LockInfo('late', 't1', 10, 600, 590),
            new LockInfo('soon', 't2', 50, 60, 10),
            new LockInfo('stale', 't3', 900, 300, -600),
        ]));

        $ids = array_map(static fn (\DOMElement $row): string => $row->getAttribute('data-row'), iterator_to_array($xpath->query('//*[@data-row]')));
        self::assertSame(['stale', 'soon', 'late'], $ids);
        self::assertSame('all expired', $xpath->query('//*[@data-row="stale"]')->item(0)->getAttribute('data-keys'));
        self::assertSame('1', $xpath->query('//*[@data-badge="mx-badge"]')->item(0)->getAttribute('data-value'));
        self::assertStringContainsString('expired 10m 0s ago', self::text($xpath, '//*[@data-row="stale"]'));
        self::assertStringContainsString('longest held 15m 0s', self::text($xpath, '//*[@class="summary"]'));
    }

    public function test_mutex_redis_locks_have_no_age_or_ttl(): void
    {
        $xpath = $this->render('mutex', new MutexReport('redis', [new MutexDriverInfo('redis', true, true)], [
            new LockInfo('job', 'tok', null, null, 30),
        ]));

        $row = $xpath->query('//*[@data-row="job"]')->item(0);
        self::assertStringContainsString('30s left', $row->textContent);
        self::assertSame(0, $xpath->query('.//*[contains(@class, "meter")]', $row)->length);
        self::assertStringContainsString('unknown', $xpath->query('.//template', $row)->item(0)->textContent);
        self::assertStringContainsString('driver redis', self::text($xpath, '//*[@class="summary"]'));
    }

    public function test_rate_limits_list_limited_keys_first_without_a_drawer(): void
    {
        $xpath = $this->render('ratelimit',
            new RateKey('half', RateStrategy::Fixed, 10, 5, 60, 0),
            new RateKey('full', RateStrategy::Concurrency, 2, 2, 0, 3),
            new RateKey('most', RateStrategy::Sliding, 10, 9, 10, 0));

        $ids = array_map(static fn (\DOMElement $row): string => $row->getAttribute('data-row'), iterator_to_array($xpath->query('//*[@data-row]')));
        self::assertSame(['full', 'most', 'half'], $ids);
        self::assertSame('all limited concurrency', $xpath->query('//*[@data-row="full"]')->item(0)->getAttribute('data-keys'));
        self::assertStringContainsString('2 / 2 active', self::text($xpath, '//*[@data-row="full"]'));
        self::assertSame(0, $xpath->query('//template | //aside')->length);
        self::assertStringContainsString('is-static', $xpath->query('//*[@data-row="half"]')->item(0)->getAttribute('class'));
        self::assertStringContainsString('3 requests blocked in the last hour', self::text($xpath, '//*[@class="summary"]'));
        self::assertSame('1', $xpath->query('//*[@data-badge="rl-badge"]')->item(0)->getAttribute('data-value'));
    }

    public function test_rate_limits_without_keys_say_nothing_was_blocked(): void
    {
        self::assertSame('No request was blocked in the last 24 hours.', self::text($this->render('ratelimit'), '//*[@data-empty]'));
    }

    private static function job(string $id, bool $enabled, ?JobOutcome $last, ?string $error = null): ScheduledJob
    {
        return new ScheduledJob(
            id: $id,
            name: 'App\\Jobs\\' . $id . '::handle',
            command: 'App\\Jobs\\' . $id . '::handle',
            cron: '*/5 * * * *',
            human: 'Every 5 minutes',
            owner: null,
            enabled: $enabled,
            last_outcome: $last ?? JobOutcome::Never,
            last_status: $last?->value,
            last_run_at: $last === null ? null : 1700000000,
            duration: $last === null ? null : 0.9,
            next_runs: $enabled ? [new \DateTimeImmutable('2026-11-21T03:00:00+00:00')] : [],
            runs: $last === null ? [] : [new JobRun($last, 1700000000, 850.0, $error)],
        );
    }

    public function test_scheduled_rows_filter_by_last_outcome_and_paused_jobs_dim(): void
    {
        $xpath = $this->render('scheduled',
            self::job('Prune', true, JobOutcome::Failed, 'disk full'),
            self::job('Mail', false, JobOutcome::Ok),
            self::job('Fresh', true, null));

        self::assertSame('all failed', $xpath->query('//*[@data-row="Prune"]')->item(0)->getAttribute('data-keys'));
        self::assertSame('all paused', $xpath->query('//*[@data-row="Mail"]')->item(0)->getAttribute('data-keys'));
        self::assertSame('all never', $xpath->query('//*[@data-row="Fresh"]')->item(0)->getAttribute('data-keys'));
        self::assertStringContainsString('is-off', $xpath->query('//*[@data-row="Mail"]')->item(0)->getAttribute('class'));
        self::assertStringContainsString('1 with a failed last run', self::text($xpath, '//*[@class="summary"]'));
        self::assertStringContainsString('next run 2026-11-21 03:00:00 UTC', self::text($xpath, '//*[@class="summary"]'));
        self::assertSame('Prune::handle', self::text($xpath, '//*[@data-row="Prune"]//*[contains(@class, "row-title")]'));
    }

    public function test_scheduled_drawer_lists_runs_with_their_errors(): void
    {
        $xpath = $this->render('scheduled', self::job('Prune', true, JobOutcome::Failed, 'disk full'));

        $drawer = self::text($xpath, '//template');
        foreach (['Failed', 'Every 5 minutes UTC', 'Not set', '2026-11-21 03:00:00 UTC', 'Last 1 runs', 'failed · 850 ms', 'disk full', 'took 0.9s'] as $expected) {
            self::assertStringContainsString($expected, $drawer);
        }
    }
}
