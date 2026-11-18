<?php
declare(strict_types=1);

namespace Tests\Engine\Telemetry\View;

use Engine\Atomic\Telemetry\Data\EnvGroup;
use Engine\Atomic\Telemetry\Data\EnvItem;
use Engine\Atomic\Telemetry\Data\Environment;
use Engine\Atomic\Telemetry\Data\Job;
use Engine\Atomic\Telemetry\Data\JobEvent;
use Engine\Atomic\Telemetry\Data\JobState;
use Engine\Atomic\Telemetry\Data\Json;
use Engine\Atomic\Telemetry\Data\QueueTotals;
use Engine\Atomic\Telemetry\Data\Summary;
use Engine\Atomic\Telemetry\View\Highlight;
use Engine\Atomic\Telemetry\View\Links;
use Engine\Atomic\Telemetry\View\Templates;
use Engine\Atomic\Telemetry\View\Ui;
use PHPUnit\Framework\TestCase;

/** Dashboard, Queues and the job timeline fragment. */
final class QueuesViewTest extends TestCase
{
    private const NOW = 1795046400 + 10 * 3600 + 120; // 10:02 UTC

    private function render(string $view, mixed ...$args): \DOMXPath
    {
        $templates = new Templates(dirname(__DIR__, 4) . '/engine/Atomic/Theme/Builtin/Telemetry/views');
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><body>' . $templates->render($view, new Ui(), ...$args) . '</body>');
        libxml_clear_errors();

        return new \DOMXPath($doc);
    }

    private static function job(string $uuid, string $state, string $queue, int $created_at, array $extra = []): Job
    {
        return Job::from_row($uuid, $extra + [
            'state' => $state,
            'queue' => $queue,
            'attempts' => 1,
            'max_attempts' => 3,
            'timeout' => 20,
            'retry_delay' => 2,
            'created_at' => $created_at,
            'created_at_formatted' => gmdate('Y-m-d H:i:s', $created_at),
            'payload' => ['handler' => 'App\\Jobs\\Mail@handle', 'data' => ['id' => 5, 'ok' => true]],
        ]);
    }

    private static function totals(array $counts): QueueTotals
    {
        return QueueTotals::from_snapshot($counts);
    }

    public function test_job_rows_map_stored_rows(): void
    {
        $job = self::job('u1', 'canceled', 'mail', self::NOW - 7200, ['exception' => ['message' => 'SMTP down', 'file' => 'a.php', 'line' => 3, 'trace_string' => '#0 a.php(3): x()']]);

        self::assertSame(JobState::Cancelled, $job->state);
        self::assertSame('App\\Jobs\\Mail@handle', $job->title());
        self::assertSame('2h ago', $job->ago(self::NOW));
        self::assertSame("SMTP down\nat a.php:3\n\n#0 a.php(3): x()", $job->error?->text());
        self::assertStringContainsString("Payload:\n{\n  \"handler\"", $job->report());
    }

    public function test_lanes_colour_each_hour_by_its_worst_job(): void
    {
        $midnight = intdiv(self::NOW, 86400) * 86400;
        $xpath = $this->render('queues', self::totals(['failed' => 1, 'completed' => 40]), new Links('/t'), self::NOW,
            self::job('a', 'completed', 'mail', $midnight + 3 * 3600 + 5),
            self::job('b', 'failed', 'mail', $midnight + 3 * 3600 + 9),
            self::job('c', 'completed', 'jobs', $midnight + 9 * 3600),
            self::job('d', 'completed', 'jobs', $midnight - 60));

        $mail = $xpath->query('//*[@data-search-toggle="mail"]/span[@class="queue-cells"]/i');
        self::assertSame(24, $mail->length);
        self::assertSame('st-failed', $mail->item(3)->getAttribute('class'));
        self::assertSame('03:00 · 1 failed, 1 completed', $mail->item(3)->getAttribute('title'));
        self::assertSame('is-empty', $mail->item(4)->getAttribute('class'));
        self::assertSame('is-future', $mail->item(11)->getAttribute('class'));
        $jobs = $xpath->query('//*[@data-search-toggle="jobs"]/span[@class="queue-cells"]/i');
        self::assertSame('--o:0.6', $jobs->item(9)->getAttribute('style'));
        self::assertSame('10', trim($xpath->query('//*[contains(@class, "is-now")]')->item(0)->textContent));
        self::assertStringContainsString('3 jobs', $xpath->query('//*[@class="summary"]')->item(0)->textContent);
    }

    public function test_pills_show_server_totals_and_rows_load_their_timeline(): void
    {
        $xpath = $this->render('queues', self::totals(['failed' => 7, 'completed' => 90]), new Links('/t'), self::NOW,
            self::job('9e07ed2e-4b24-4b9e-ad68-beaa8e142344', 'failed', 'mail', self::NOW));

        $failed = $xpath->query('//*[@data-count="failed"]')->item(0);
        self::assertSame('7', $failed->getAttribute('data-total'));
        self::assertSame('7', trim($failed->textContent));
        self::assertSame('97', $xpath->query('//*[@data-count="all"]')->item(0)->getAttribute('data-total'));
        self::assertSame('/t/part/job-events?driver=db&uuid=9e07ed2e-4b24-4b9e-ad68-beaa8e142344', $xpath->query('//template//*[@data-load]')->item(0)->getAttribute('data-load'));
    }

    public function test_job_events_render_a_timeline(): void
    {
        $xpath = $this->render('job-events', new JobEvent(1, 'Job was created', '2026-11-20 10:00:00', 'abcdef123456'), new JobEvent(4, 'Job failed', '2026-11-20 10:00:01', 'abcdef123456'));

        self::assertSame(2, $xpath->query('//*[@class="step"]')->length);
        self::assertSame('bad', $xpath->query('//*[@class="step"][2]/i')->item(0)->getAttribute('data-tone'));
        self::assertStringContainsString('batch abcdef12', $xpath->query('//small')->item(0)->textContent);
        self::assertSame('No telemetry events for this job.', trim($this->render('job-events')->query('//div')->item(0)->textContent));
    }

    public function test_dashboard_links_each_problem_to_its_tab(): void
    {
        $summary = Summary::from_array(['queues' => ['failed' => 2, 'completed' => 5], 'migrations' => ['pending' => 1]]);
        $environment = new Environment([
            'php' => new EnvGroup('php', [new EnvItem('version', '8.4'), new EnvItem('extensions', null, ['zlib', 'Core', 'pdo'])]),
            'atomic' => new EnvGroup('atomic', [new EnvItem('debug_mode', true), new EnvItem('env', '')]),
        ]);
        $xpath = $this->render('dashboard', $summary, $environment);

        self::assertSame('2 failed jobs', trim($xpath->query('//*[contains(@class, "dash-big")]')->item(0)->textContent));
        self::assertSame(['queues', 'migrations'], array_map(static fn (\DOMElement $row): string => $row->getAttribute('data-goto'), iterator_to_array($xpath->query('//*[@class="dash-attn-row"]'))));
        self::assertSame('failed', $xpath->query('//*[contains(@class, "dash-seg st-failed")]')->item(0)->getAttribute('data-goto-filter'));
        self::assertSame(['Core', 'pdo', 'zlib'], array_map(static fn (\DOMElement $chip): string => $chip->textContent, iterator_to_array($xpath->query('//*[@data-clip]/span'))));
        self::assertStringContainsString("Atomic\n  Debug mode: yes\n  Environment: -", $environment->text());
        self::assertSame(['php' => ['version' => '8.4', 'extensions' => ['zlib', 'Core', 'pdo']], 'f3' => null, 'atomic' => ['debug_mode' => true, 'env' => ''], 'system' => null, 'db' => null], $environment->to_array());
    }

    public function test_json_and_traces_are_highlighted_like_the_client(): void
    {
        self::assertSame("{\n  \"a\": [\n    1,\n    true\n  ]\n}", Json::pretty(['a' => [1, true]]));
        self::assertSame('<span class="syn-punc">{</span><span class="syn-key">&quot;k&quot;</span><span class="syn-punc">:</span> <span class="syn-str">&quot;&lt;b&gt;&quot;</span><span class="syn-punc">}</span>',
            str_replace(["\n", '  '], '', (string)Highlight::json(['k' => '<b>'])));
        self::assertSame("<span class=\"syn-punc\">#0</span> <span class=\"syn-key\">x()</span>\n   <span class=\"syn-punc\">a.php:3</span>\n{main}",
            (string)Highlight::trace("#0 a.php(3): x()\n{main}"));
    }
}
