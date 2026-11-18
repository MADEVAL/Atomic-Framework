<?php
declare(strict_types=1);

namespace Tests\Engine\Telemetry\View;

use Engine\Atomic\Telemetry\Data\MigrationEntry;
use Engine\Atomic\Telemetry\Data\MigrationIntegrity;
use Engine\Atomic\Telemetry\Data\MigrationSource;
use Engine\Atomic\Telemetry\Data\MigrationsReport;
use Engine\Atomic\Telemetry\View\Links;
use Engine\Atomic\Telemetry\View\Templates;
use Engine\Atomic\Telemetry\View\Ui;
use PHPUnit\Framework\TestCase;

final class MigrationsViewTest extends TestCase
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

    private static function entry(string $name, ?string $batch, MigrationIntegrity $integrity, ?string $at = null, ?string $file = 'f.php'): MigrationEntry
    {
        return new MigrationEntry('app', $name, $file, $batch !== null || $at !== null, $integrity, $batch, $at);
    }

    public function test_rows_are_grouped_pending_first_then_newest_batch(): void
    {
        $xpath = $this->render('migrations', new MigrationsReport(false, [
            self::entry('old', 'aaaaaaaa-1', MigrationIntegrity::Verified, '2026-01-01 10:00:00'),
            self::entry('new', 'bbbbbbbb-2', MigrationIntegrity::Modified, '2026-02-01 10:00:00'),
            self::entry('todo', null, MigrationIntegrity::NotApplied),
        ]), new Links('/telemetry'));

        $order = array_map(
            static fn (\DOMElement $node): string => $node->getAttribute('data-group-head') ?: $node->getAttribute('data-row'),
            iterator_to_array($xpath->query('//*[@data-group-head] | //*[@data-row]')),
        );
        self::assertSame(['pending', 'app|todo', 'bbbbbbbb-2', 'app|new', 'aaaaaaaa-1', 'app|old'], $order);
        self::assertSame('all applied attention', $xpath->query('//*[@data-row="app|new"]')->item(0)->getAttribute('data-keys'));
        self::assertSame('bbbbbbbb-2', $xpath->query('//*[@data-row="app|new"]')->item(0)->getAttribute('data-group'));
        self::assertSame('1', $xpath->query('//*[@data-badge="m-badge"]')->item(0)->getAttribute('data-value'));
        self::assertStringContainsString('1 need attention', $xpath->query('//*[@class="summary"]')->item(0)->textContent);
        self::assertStringContainsString('compare the file with version control', $xpath->query('//*[@data-row="app|new"]/template')->item(0)->textContent);
    }

    public function test_the_view_button_opens_the_source_popup(): void
    {
        $xpath = $this->render('migrations', new MigrationsReport(true, [self::entry('one', 'b', MigrationIntegrity::Verified, '2026-01-01 10:00:00')]), new Links('/tl'));

        self::assertSame('/tl/part/migration-source?source=app&name=one', $xpath->query('//*[@data-row]/span//button')->item(0)->getAttribute('data-modal'));
        self::assertStringContainsString('legacy mode', $xpath->query('//*[@class="note"]')->item(0)->textContent);
        self::assertSame(0, $xpath->query('//*[@class="list-foot"]')->length);
    }

    public function test_a_missing_file_has_no_view_button(): void
    {
        $xpath = $this->render('migrations', new MigrationsReport(false, [self::entry('gone', 'b', MigrationIntegrity::FileUnavailable, '2026-01-01 10:00:00', null)]), new Links('/tl'));

        self::assertSame(0, $xpath->query('//*[@data-modal]')->length);
        self::assertSame('bad', $xpath->query('//*[@data-row]/*[@class="mig-int"]')->item(0)->getAttribute('data-tone'));
        self::assertStringContainsString('The migration file is not available.', $xpath->query('//template')->item(0)->textContent);
    }

    public function test_source_popup_shows_path_and_line_count(): void
    {
        $xpath = $this->render('migration-source', new MigrationSource('one', 'one.php', "<?php\nreturn [];", true));

        self::assertSame('modal-in', $xpath->query('/html/body/div')->item(0)->getAttribute('class'));
        self::assertSame('2 lines · first 256 KB', trim($xpath->query('//*[@class="sub"]')->item(0)->textContent));
        self::assertSame("<?php\nreturn [];", $xpath->query('//pre')->item(0)->textContent);
    }

    public function test_entry_serializes_to_the_migrations_endpoint_shape(): void
    {
        self::assertSame([
            'source' => 'app', 'name' => 'x', 'file' => 'f.php', 'status' => 'applied',
            'integrity' => 'legacy/unverified', 'batch_uuid' => 'b', 'applied_at' => '2026-01-01 10:00:00',
        ], self::entry('x', 'b', MigrationIntegrity::Legacy, '2026-01-01 10:00:00')->to_array());
    }
}
