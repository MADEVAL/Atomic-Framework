<?php
declare(strict_types=1);

namespace Tests\Engine\Telemetry\Reporters;

use Tests\Engine\Telemetry\Support\StatsDbTestCase;

final class MigrationsReporterTest extends StatsDbTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // tests/bootstrap.php does not run the config loader, so the
        // migration directory keys the catalog resolves are missing.
        \Engine\Atomic\Core\App::instance()->set('MIGRATIONS_CORE', 'Atomic/Core/Database/Migrations/');
        \Engine\Atomic\Core\App::instance()->set('MIGRATIONS', 'database/migrations/');
    }

    public function test_report_lists_framework_migrations_with_contract_fields(): void
    {
        $report = (new \Engine\Atomic\Telemetry\Reporters\MigrationsReporter())->report();

        self::assertIsBool($report['legacy_mode']);
        self::assertNotEmpty($report['entries']);

        $names = array_column($report['entries'], 'name');
        // Discovery inventory contains the framework update migrations (the
        // initial baseline tables are intentionally excluded there, exactly
        // like migrations/status shows).
        self::assertContains('create_telemetry_stats_tables', $names);
        self::assertContains('create_scheduler_stats_tables', $names);
        self::assertContains('add_session_user_columns', $names);

        foreach ($report['entries'] as $entry) {
            self::assertArrayHasKey('source', $entry);
            self::assertArrayHasKey('file', $entry);
            self::assertArrayHasKey('status', $entry);
            self::assertArrayHasKey('integrity', $entry);
            self::assertContains($entry['status'], ['applied', 'pending']);
            self::assertContains($entry['integrity'], [
                'verified', 'modified', 'legacy/unverified',
                'legacy published alias', 'file unavailable', 'not applied',
            ]);
        }
    }

    public function test_source_returns_the_file_of_a_listed_migration(): void
    {
        $source = (new \Engine\Atomic\Telemetry\Reporters\MigrationsReporter())->source('framework', 'create_telemetry_stats_tables');

        self::assertNotNull($source);
        self::assertSame('1_create_telemetry_stats_tables.php', $source->file);
        self::assertStringContainsString('telemetry_counters', $source->code);
        self::assertFalse($source->truncated);
    }

    public function test_source_is_null_for_unknown_migrations_and_path_tricks(): void
    {
        $reporter = new \Engine\Atomic\Telemetry\Reporters\MigrationsReporter();

        self::assertNull($reporter->source('framework', 'no_such_migration'));
        self::assertNull($reporter->source('framework', '../../../../composer'));
        self::assertNull($reporter->source('../app', 'create_telemetry_stats_tables'));
    }

    public function test_snapshot_reports_pending_and_attention_counts(): void
    {
        $snapshot = (new \Engine\Atomic\Telemetry\Reporters\MigrationsReporter())->snapshot();

        // Nothing applied under the isolated test prefix → everything pending.
        self::assertSame(0, $snapshot['attention']);
        self::assertSame(false, $snapshot['legacy_mode']);
        self::assertGreaterThan(0, $snapshot['pending']);
    }
}
