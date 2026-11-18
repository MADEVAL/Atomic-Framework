<?php
declare(strict_types=1);

namespace Tests\Engine\Telemetry\View;

use Engine\Atomic\Telemetry\Data\LogLine;
use Engine\Atomic\Telemetry\Data\LogPage;
use Engine\Atomic\Telemetry\Data\Tone;
use Engine\Atomic\Telemetry\View\Links;
use Engine\Atomic\Telemetry\View\Templates;
use Engine\Atomic\Telemetry\View\Ui;
use PHPUnit\Framework\TestCase;

final class LogsViewTest extends TestCase
{
    private function render(string $view, LogPage $page): \DOMXPath
    {
        $templates = new Templates(dirname(__DIR__, 4) . '/engine/Atomic/Theme/Builtin/Telemetry/views');
        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="utf-8"?><body>' . $templates->render($view, new Ui(), $page, new Links('/t')) . '</body>');
        libxml_clear_errors();

        return new \DOMXPath($doc);
    }

    private static function line(string $level, string $message, ?string $dump = null): LogLine
    {
        return LogLine::from_array(['ts' => '2026-11-20 14:05:09', 'level' => $level, 'message' => $message, 'dump_id' => $dump], 'error');
    }

    private static function page(string $channel, int $page = 1, int $last_page = 3, LogLine ...$lines): LogPage
    {
        return new LogPage($channel, '2026-11-20', ['2026-11-20', '2026-11-19'], $lines, $page, $last_page, 640, ['atomic' => 500, 'error' => 140]);
    }

    public function test_log_lines_read_like_the_old_tab(): void
    {
        $line = self::line('CRITICAL', 'Boom [HIVE] state', '9e07ed2e-4b24-4b9e-ad68-beaa8e142344');

        self::assertSame('error', $line->filter());
        self::assertSame(Tone::Bad, $line->status()->tone);
        self::assertSame('14:05:09', $line->time());
        self::assertSame(['Boom ', '[HIVE]', ' state'], $line->parts());
        self::assertSame('[2026-11-20 14:05:09] error.CRITICAL: Boom [HIVE] state [dump:9e07ed2e-4b24-4b9e-ad68-beaa8e142344]', $line->text());
        self::assertSame('info', LogLine::from_array(['message' => 'x'], 'atomic')->level);
    }

    public function test_tab_renders_rail_levels_and_the_next_page_marker(): void
    {
        $xpath = $this->render('logs', self::page('error', 1, 3, self::line('ERROR', 'A'), self::line('ERROR', 'A'), self::line('INFO', "two\nlines", 'abc')));

        self::assertSame('error|2026-11-20', $xpath->query('//*[@data-log-scope]')->item(0)->getAttribute('data-log-scope'));
        self::assertSame('log-main is-one-channel', $xpath->query('//*[contains(@class, "log-main")]')->item(0)->getAttribute('class'));
        self::assertSame('channel=error&date=2026-11-19', $xpath->query('//*[@class="log-item"][span="2026-11-19"]')->item(0)->getAttribute('data-tab-query'));
        self::assertSame('140', $xpath->query('//*[contains(@class, "log-item")][span="error"]/span[2]')->item(0)->textContent);
        self::assertSame('2', $xpath->query('//*[@data-count="error"]')->item(0)->textContent);
        self::assertSame('/t/part/log-lines?channel=error&date=2026-11-20&page=2', $xpath->query('//*[@data-more]')->item(0)->getAttribute('data-more'));
        self::assertSame('Scroll for older lines', $xpath->query('//*[@data-more-status]')->item(0)->textContent);
        // Identical lines get their own row ids.
        $rows = $xpath->query('//*[@data-row]');
        self::assertNotSame($rows->item(0)->getAttribute('data-row'), $rows->item(1)->getAttribute('data-row'));
        self::assertSame($rows->item(0)->getAttribute('data-key'), $rows->item(1)->getAttribute('data-key'));
        self::assertTrue($rows->item(2)->hasAttribute('data-multiline'));
        self::assertSame('/t/part/dump?id=abc', $xpath->query('//template//*[@data-modal]')->item(0)->getAttribute('data-modal'));
    }

    public function test_last_page_ends_the_log(): void
    {
        $xpath = $this->render('log-lines', self::page(LogPage::ALL, 3, 3, self::line('INFO', 'old')));

        self::assertSame(1, $xpath->query('//*[@data-row]')->length);
        self::assertSame(0, $xpath->query('//*[@data-more]')->length);
        self::assertSame('Start of log', $this->render('logs', self::page(LogPage::ALL, 1, 1, self::line('INFO', 'x')))->query('//*[@data-more-status]')->item(0)->textContent);
    }
}
