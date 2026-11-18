<?php
declare(strict_types=1);

namespace Tests\Engine\Telemetry\View;

use Engine\Atomic\Telemetry\Data\Dump;
use Engine\Atomic\Telemetry\Data\ExceptionGroup;
use Engine\Atomic\Telemetry\Data\ExceptionsReport;
use Engine\Atomic\Telemetry\Data\QuotaReport;
use Engine\Atomic\Telemetry\Data\Range;
use Engine\Atomic\Telemetry\Data\SecurityReport;
use Engine\Atomic\Telemetry\View\Links;
use Engine\Atomic\Telemetry\View\Templates;
use Engine\Atomic\Telemetry\View\Ui;
use PHPUnit\Framework\TestCase;

/** The history tabs (Exceptions, Security, Quota) and their charts. */
final class HistoryViewsTest extends TestCase
{
    private function render(string $view, mixed ...$args): \DOMXPath
    {
        $templates = new Templates(dirname(__DIR__, 4) . '/engine/Atomic/Theme/Builtin/Telemetry/views');
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><body>' . $templates->render($view, new Ui(), ...$args) . '</body>');
        libxml_clear_errors();

        return new \DOMXPath($doc);
    }

    private static function group(array $overrides = []): array
    {
        return $overrides + [
            'id' => 'fp1',
            'class' => 'App\\Errors\\PaymentFailed',
            'message' => 'Card declined',
            'file' => 'app/Pay.php',
            'line' => 12,
            'code' => 502,
            'level' => 'error',
            'count' => 3,
            'total' => 1200,
            'first_seen_h' => 30,
            'last_seen_m' => 5,
            'usage' => [0, 2, 1],
            'path' => '/checkout',
            'dump_id' => '9e07ed2e-4b24-4b9e-ad68-beaa8e142344',
            'trace' => '#0 app/Pay.php(12): charge()',
        ];
    }

    public function test_spark_and_chart_draw_the_same_paths_as_the_client(): void
    {
        $ui = new Ui();
        $spark = (string)$ui->spark([0, 2, 1]);
        self::assertStringContainsString('d="M0,24 L0.0,23.0 L48.0,2.0 L96.0,12.5 L96,24Z"', $spark);
        self::assertStringContainsString('d="M0.0,23.0 L48.0,2.0 L96.0,12.5"', $spark);

        $chart = (string)$ui->chart([1, 1000], Range::Week, height: 100, unit: ' denied', marks: [0, 2], mark_label: 'lockouts');
        self::assertStringContainsString('style="--h:100px"', $chart);
        self::assertStringContainsString('<title>1,000 denied, 2 lockouts</title>', $chart);
        self::assertStringContainsString('<span>7d ago</span><span>3d</span><span>now</span>', $chart);
        // An empty series is drawn flat instead of an invalid path.
        self::assertStringContainsString('class="chart-line" d="M0.0,118.0 L480.0,118.0"', (string)$ui->chart([], Range::Day));
    }

    public function test_hbars_list_the_largest_first(): void
    {
        $ui = new Ui();
        $html = (string)$ui->hbars(['a' => 1, 'b' => 0, 'c' => 4]);
        self::assertLessThan(strpos($html, '>a<'), strpos($html, '>c<'));
        self::assertStringNotContainsString('>b<', $html);
        self::assertSame('<div class="muted">None.</div>', (string)$ui->hbars([]));
    }

    public function test_exception_groups_report_like_the_old_drawer(): void
    {
        $group = ExceptionGroup::from_array(self::group());

        self::assertSame('PaymentFailed', $group->short_class());
        self::assertTrue($group->is_severe());
        self::assertFalse($group->is_fresh());
        self::assertSame("App\\Errors\\PaymentFailed: Card declined\nat app/Pay.php:12\nRequest: /checkout\nHTTP status: 502\nSeen 1200 times, last 5m 0s ago\n\n#0 app/Pay.php(12): charge()", $group->report());
        self::assertSame(self::group(), $group->to_array());
    }

    public function test_exceptions_tab_filters_rows_and_opens_the_hive_dump(): void
    {
        $report = ExceptionsReport::from_array([
            'groups' => [self::group(), self::group(['id' => 'fp2', 'code' => 404, 'first_seen_h' => 2, 'dump_id' => null, 'usage' => [1, 0, 0], 'count' => 1])],
            'lifetime' => ['occurrences' => 5000, 'distinct' => 2],
        ]);
        $xpath = $this->render('exceptions', $report, Range::Day, new Links('/t'));

        self::assertSame('all server', $xpath->query('//*[@data-row="fp1"]')->item(0)->getAttribute('data-keys'));
        self::assertSame('all client fresh', $xpath->query('//*[@data-row="fp2"]')->item(0)->getAttribute('data-keys'));
        self::assertStringContainsString('4 occurrences, last 24h', $xpath->query('//*[@class="summary"]')->item(0)->textContent);
        self::assertSame('5,000 occurrences', trim($xpath->query('//*[@class="lifetime"]/span[2]')->item(0)->textContent));
        self::assertSame('/t/part/dump?id=9e07ed2e-4b24-4b9e-ad68-beaa8e142344', $xpath->query('//template//*[@data-modal]')->item(0)->getAttribute('data-modal'));
        self::assertSame(['502', '404'], array_map(static fn (\DOMElement $bar): string => $bar->firstChild->textContent,
            iterator_to_array($xpath->query('//*[@class="overview"]/div[3]/*[@class="hbar"]'))));
    }

    public function test_dump_popup_shows_the_json(): void
    {
        $xpath = $this->render('dump', new Dump('abc', ['user' => ['id' => 5]]));

        self::assertSame('abc', $xpath->query('//h3')->item(0)->textContent);
        self::assertStringContainsString('"id": 5', $xpath->query('//pre')->item(0)->textContent);
    }

    private static function security(): SecurityReport
    {
        return SecurityReport::from_array([
            'sessions' => [
                ['session_id' => '[MASKED]', 'user' => 'ann', 'ip' => '10.0.0.1', 'agent' => 'Firefox', 'created_ago' => 7200, 'last_ago' => 600, 'impersonating' => null, 'current' => false],
                ['session_id' => '[MASKED]', 'user' => 'bob', 'ip' => '10.0.0.2', 'agent' => 'Chrome', 'created_ago' => 60, 'last_ago' => 5, 'impersonating' => 'admin-1', 'current' => true],
            ],
            'lockouts' => [
                ['identifier' => 'eve@x.io', 'count' => 2, 'max' => 5, 'first_ago' => 30, 'locked' => false, 'remaining' => 0],
                ['identifier' => 'mal@x.io', 'count' => 5, 'max' => 5, 'first_ago' => 90, 'locked' => true, 'remaining' => 300],
            ],
            'failed' => [1, 0, 6],
            'lock_events' => [0, 0, 1],
            'top_failed' => ['mal@x.io' => 5, 'eve@x.io' => 2],
            'lifetime' => ['failed_logins' => 40, 'lockouts' => 3],
            'policy' => [
                'lockout' => ['max_attempts' => 5, 'window' => 900, 'lockout' => 600],
                'password' => ['min_length' => 8, 'mixed_case' => true, 'numbers' => false, 'symbols' => true],
                'csrf' => ['key' => 'SESSION.csrf_token'],
                'session' => ['driver' => 'db', 'lifetime' => 7200],
            ],
        ]);
    }

    public function test_security_tab_switches_between_sessions_and_lockouts(): void
    {
        $xpath = $this->render('security', self::security(), Range::Day);

        self::assertSame('sessions', $xpath->query('//*[@data-list][@data-views]')->item(0)->getAttribute('data-default'));
        self::assertSame('pill is-on', $xpath->query('//*[@data-filter="sessions"]')->item(0)->getAttribute('class'));
        self::assertSame('list hide', $xpath->query('//*[@data-when="lockouts"]')->item(0)->getAttribute('class'));
        // Most recent session first; masked ids can't key rows, so rows are keyed by position.
        self::assertSame(['s0', 's1'], array_map(static fn (\DOMElement $row): string => $row->getAttribute('data-row'), iterator_to_array($xpath->query('//*[@data-when="sessions"]//*[@data-row]'))));
        self::assertSame('bob (you)', $xpath->query('//*[@data-row="s0"]//*[contains(@class, "row-title")]')->item(0)->textContent);
        self::assertSame('warn', $xpath->query('//*[@data-row="s0"]//*[@data-tone]')->item(0)->getAttribute('data-tone'));
        // Locked identifiers first.
        self::assertSame('locked 5m 0s', $xpath->query('//*[@data-row="l0"]//*[contains(@class, "sec-la")]')->item(0)->textContent);
        self::assertSame('is-hot', explode(' ', $xpath->query('//*[@data-count="lockouts"]')->item(0)->getAttribute('class'))[1] ?? '');
        self::assertStringContainsString('1 locked', $xpath->query('//*[@class="summary"]')->item(0)->textContent);
        self::assertStringContainsString('impersonation active', $xpath->query('//*[@class="summary"]')->item(0)->textContent);
        self::assertSame('min 8 chars, mixed case, symbols', self::security()->policy->password_rules());
        self::assertSame('7 failed · 1 lockouts', trim($xpath->query('//*[@class="big"]')->item(0)->textContent));
    }

    public function test_quota_tab_lists_lowest_balance_first_with_plans(): void
    {
        $tier = ['name' => 'free', 'credits' => 500, 'period' => 2592000, 'pacing' => [['limit' => 20, 'window' => 60]], 'operations' => ['pdf.render' => ['cost' => 2, 'pacing' => []]]];
        $scope = static fn (string $name, int $balance, array $denials, array $recent = []): array => [
            'scope' => $name, 'tier' => 'free', 'balance' => $balance, 'credits' => 500, 'ttl' => 3600,
            'denials' => $denials + ['insufficient_balance' => 0, 'pacing' => 0, 'not_entitled' => 0, 'duplicate_reservation' => 0],
            'pacing' => [['key' => '_tier', 'window' => 60, 'limit' => 20, 'used' => 20]],
            'recent' => $recent, 'denied' => [0, 1, 2],
        ];
        $report = QuotaReport::from_array([
            'tiers' => [$tier],
            'scopes' => [$scope('acme', 400, []), $scope('beta', 10, ['pacing' => 3], [['ts' => '2026-11-20 10:00:00', 'op' => 'pdf.render', 'reason' => 'insufficient_balance']])],
            'lifetime' => ['denied' => 9, 'renewals' => 2],
        ]);
        $xpath = $this->render('quota', $report, Range::Day);

        self::assertSame(['beta', 'acme'], array_map(static fn (\DOMElement $row): string => $row->getAttribute('data-row'), iterator_to_array($xpath->query('//*[@data-row]'))));
        self::assertSame('all denied', $xpath->query('//*[@data-row="beta"]')->item(0)->getAttribute('data-keys'));
        self::assertSame('Pacing', $xpath->query('//*[@class="hbar"]/span')->item(0)->textContent);
        self::assertSame('500 credits / 30 days · 20 per 60s', $xpath->query('//*[@class="quota-plan"]/span')->item(0)->textContent);
        self::assertSame('plan', $xpath->query('//template//*[@class="quota-pace"]/span')->item(0)->textContent);
        self::assertSame('Insufficient balance', $xpath->query('//template//*[@class="kv is-bad"]/*[@class="kv-v"]')->item(0)->textContent);
        self::assertStringContainsString('3 denied', $xpath->query('//*[@class="summary"]')->item(0)->textContent);
    }
}
