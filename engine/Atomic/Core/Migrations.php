<?php
declare(strict_types=1);

namespace Engine\Atomic\Core;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\CLI\Console\Output;
use Engine\Atomic\CLI\Console\Input;
use Engine\Atomic\CLI\Style;
use Engine\Atomic\Core\Migrations\LegacyMigrationAdopter;
use Engine\Atomic\Core\Migrations\MigrationCatalog;
use Engine\Atomic\Core\Migrations\MigrationExecutor;
use Engine\Atomic\Core\Migrations\MigrationHistory;
use Engine\Atomic\Core\Migrations\MigrationLedger;
use Engine\Atomic\Core\Migrations\MigrationPublisher;

class Migrations
{
    private bool $successful = true;

    public function __construct(
        private readonly Output $output,
        private readonly MigrationCatalog $catalog,
        private readonly MigrationLedger $ledger,
        private readonly MigrationHistory $history,
        private readonly LegacyMigrationAdopter $adopter,
        private readonly MigrationExecutor $executor,
        private readonly MigrationPublisher $publisher,
        private readonly ?Input $input = null,
    ) {}

    public function was_successful(): bool
    {
        return $this->successful;
    }

    public function db(): bool
    {
        $this->successful = true;
        try {
            if ($this->ledger->ensure()) {
                return true;
            }
            $this->successful = false;
            $this->errln(Style::error_label() . ' ' . Style::bold('Database is not ready.'));
        } catch (\Throwable $e) {
            $this->successful = false;
            $this->errln(Style::error_label() . ' ' . Style::bold('Error creating migrations table:') . ' ' . $e->getMessage());
        }
        return false;
    }

    public function create(string $name, string $template = ''): void
    {
        $this->successful = true;
        if (!$this->db()) {
            return;
        }
        try {
            $this->publisher->create($name, $template);
        } catch (\Throwable $e) {
            $this->successful = false;
            $this->errln(Style::error_label() . ' ' . $e->getMessage());
        }
    }

    public function publish_from_plugin(string $plugin_name): void
    {
        $this->successful = $this->publisher->publish_from_plugin($plugin_name);
    }

    public function publish_from_framework(bool $all = false): void
    {
        $this->publisher->publish_from_framework($all);
    }

    public function publish_framework(string $migration_name): void
    {
        $this->publisher->publish_framework($migration_name);
    }

    public function publish(string $source_path): void
    {
        $this->publisher->publish($source_path);
    }

    public function migrate(?int $steps = null): void
    {
        $this->successful = true;
        if ($steps !== null && $steps < 0) {
            $this->successful = false;
            $this->errln(Style::error_label() . ' ' . Style::bold('Migration steps cannot be negative.'));
            return;
        }
        if (!$this->db()) {
            return;
        }
        $this->warn_if_legacy_mode();
        try {
            $this->ledger->synchronized(function () use ($steps): void {
                $migrations = $this->catalog->discover();
                $rows = $this->ledger->rows();
                if (!$this->confirm_applied_checksum_mismatches($this->history->find_checksum_mismatches($migrations, $rows))) {
                    $this->successful = false;
                    return;
                }

                $pending = array_values(array_filter(
                    $migrations,
                    fn(array $migration): bool => $this->history->find_applied_row($migration, $rows, $migrations) === null
                ));
                if ($pending === []) {
                    $this->outln(Style::success_label() . ' ' . Style::bold('No new migrations to apply.'));
                    return;
                }
                if ($steps !== null) {
                    $pending = array_slice($pending, 0, max(0, $steps));
                }
                if (!$this->confirm_framework_checksum_mismatches($pending)) {
                    $this->successful = false;
                    return;
                }
                if (!$this->confirm_modified_published_copies($pending)) {
                    $this->successful = false;
                    return;
                }

                $batch_uuid = $this->executor->batch_id();
                foreach ($pending as $migration) {
                    $this->executor->up($migration);
                    $this->ledger->record($migration, $batch_uuid);
                    $this->outln(
                        Style::success_label() . ' '
                        . Style::bold("Migration '{$migration['source']}:{$migration['migration']}'")
                        . ' applied successfully.'
                    );
                }
            });
        } catch (\Throwable $e) {
            $this->successful = false;
            $this->errln(Style::error_label() . ' ' . Style::bold('Error applying migrations:') . ' ' . $e->getMessage());
        }
    }

    public function rollback(int|string|null $mode = null): void
    {
        $this->successful = true;
        if ($mode !== null && $mode !== 'batch'
            && (!preg_match('/^[1-9][0-9]*$/', (string)$mode))) {
            $this->successful = false;
            $this->errln(Style::error_label() . ' Incorrect usage: rollback requires a positive integer or batch.');
            return;
        }
        if (!$this->db()) {
            return;
        }
        $this->warn_if_legacy_mode();
        $mode = $mode === null ? 1 : (is_numeric($mode) ? (int)$mode : $mode);

        try {
            $this->ledger->synchronized(function () use ($mode): void {
                $migrations = $this->catalog->discover();
                if (is_int($mode)) {
                    $count = $mode;
                } else {
                    $latest = $this->ledger->latest();
                    if ($latest === null) {
                        $this->no_migrations_to_pop();
                        return;
                    }
                    $count = $this->ledger->count_batch((string)$latest->batch_uuid);
                }

                $rows = $this->ledger->rows([], ['order' => 'id DESC', 'limit' => $count]);
                if ($rows === []) {
                    $this->no_migrations_to_pop();
                    return;
                }
                $resolved = [];
                $mismatches = [];
                foreach ($rows as $row) {
                    $migration = $this->history->resolve_applied_migration($row, $migrations);
                    if ($migration === null) {
                        throw new \RuntimeException(
                            "Migration file for '{$row->migration}' is unavailable from source '"
                            . (($row->source ?? null) ?: 'legacy') . "'."
                        );
                    }
                    $resolved[] = ['row' => $row, 'migration' => $migration];
                    if (!$this->history->checksum_matches($row, $migration)) {
                        $mismatches[] = ['row' => $row, 'migration' => $migration];
                    }
                }
                if (!$this->confirm_applied_checksum_mismatches($mismatches)) {
                    $this->successful = false;
                    return;
                }
                foreach ($resolved as $item) {
                    $row = $item['row'];
                    $migration = $item['migration'];
                    $this->executor->down($migration);
                    $this->ledger->erase((int)$row->id);
                    $this->outln(
                        Style::success_label() . ' '
                        . Style::bold("Migration '{$migration['source']}:{$migration['migration']}'")
                        . ' popped back successfully.'
                    );
                }
            });
        } catch (\Throwable $e) {
            $this->successful = false;
            $this->errln(Style::error_label() . ' ' . Style::bold('Error rolling back migrations:') . ' ' . $e->getMessage());
        }
    }

    public function status(): void
    {
        $this->successful = true;
        if (!$this->db()) {
            return;
        }
        $this->warn_if_legacy_mode();
        $migrations = $this->catalog->discover();
        $rows = $this->ledger->rows();

        $this->outln();
        $this->outln(Style::bold('Migration List:'));
        $reported = [];
        foreach ($migrations as $migration) {
            $row = $this->history->find_applied_row($migration, $rows, $migrations);
            $integrity = 'not applied';
            if ($row !== null) {
                $reported[(int)$row->id] = true;
                $stored = (string)($row->checksum ?? '');
                $aliased = (string)($row->source ?? '') !== '' && (
                    (string)$row->source !== $migration['source']
                    || (string)$row->migration !== $migration['migration']
                );
                $integrity = $aliased
                    ? 'legacy published alias'
                    : ($stored === '' ? 'legacy/unverified' : (hash_equals($stored, $migration['checksum']) ? 'verified' : 'modified'));
            }
            $this->print_status($migration['source'], $migration['migration'], $row, $integrity);
        }
        foreach ($rows as $row) {
            if (!isset($reported[(int)$row->id])) {
                $integrity = (string)($row->checksum ?? '') === '' ? 'legacy/unverified' : 'file unavailable';
                $this->print_status((string)($row->source ?? '') ?: 'legacy', (string)$row->migration, $row, $integrity);
            }
        }
    }

    public function upgrade(bool $dry_run = false): bool
    {
        $this->successful = true;
        if (!$dry_run && !$this->db()) {
            return false;
        }
        try {
            return $this->ledger->synchronized(function () use ($dry_run): bool {
                $rows = $dry_run ? $this->ledger->preview_rows() : $this->ledger->rows();
                $migrations = $this->catalog->discover($rows);
                $legacy_count = count(array_filter(
                    $rows,
                    static fn(object $row): bool => (string)($row->source ?? '') === ''
                        || (string)($row->checksum ?? '') === '',
                ));
                $pending_count = count(array_filter(
                    $migrations,
                    fn(array $migration): bool => $this->history->find_applied_row($migration, $rows, $migrations) === null,
                ));
                $legacy_schema_columns = $this->ledger->legacy_schema_columns();

                $this->print_upgrade_summary(
                    $legacy_count,
                    $pending_count,
                    $legacy_schema_columns,
                    $dry_run,
                );
                if ($dry_run) {
                    foreach ($this->ledger->schema_upgrade_plan() as $change) {
                        $this->outln('Would: ' . $change);
                    }
                }
                $legacy = array_values(array_filter($migrations, function (array $migration) use ($rows): bool {
                    foreach ($rows as $row) {
                        if (((string)($row->source ?? '') === '' || (string)($row->checksum ?? '') === '')
                            && ((string)$row->migration === $migration['migration']
                                || $this->history->published_name_matches((string)$row->migration, $migration['migration']))) {
                            return true;
                        }
                    }
                    return false;
                }));
                if (!$this->confirm_modified_published_copies($legacy, $dry_run, true)
                    || !$this->confirm_framework_checksum_mismatches($legacy, $dry_run, true)) {
                    $this->successful = false;
                    return false;
                }
                $upgraded = $this->adopter->upgrade($migrations, $rows, $dry_run);
                if ($upgraded && !$dry_run) {
                    $this->ledger->finalize_schema();
                    $this->outln(
                        Style::success_label() . ' '
                        . Style::bold('Migration history upgraded; source and checksum columns are now NOT NULL.')
                    );
                } elseif ($upgraded) {
                    $this->outln(Style::bold('No database changes were made during this dry run.'));
                    if ($legacy_schema_columns !== []) {
                        $this->output->warning_box(
                            Style::yellow('Dry run complete - upgrade is still required', true),
                            [
                                'The migration history remains in legacy mode.',
                                Style::bold('Run: php atomic migrations/upgrade'),
                            ],
                        );
                    }
                }
                return $upgraded;
            });
        } catch (\Throwable $e) {
            $this->successful = false;
            $this->errln(Style::error_label() . ' ' . Style::bold('Migration history upgrade failed:') . ' ' . $e->getMessage());
            return false;
        }
    }

    private function print_status(string $source, string $name, ?object $row, string $integrity): void
    {
        $status = $row === null ? 'pending' : 'applied';
        $label = $row === null ? Style::warning_label() : Style::success_label();
        $this->outln(Style::bold('Source:') . ' ' . Style::bold($source));
        $this->outln(Style::bold('File:') . ' ' . Style::bold($name));
        $this->outln('  ' . Style::bold('Status:') . ' ' . $label . ' ' . Style::bold($status));
        $this->outln('  ' . Style::bold('Integrity:') . ' ' . Style::bold($integrity));
        $this->outln('  ' . Style::bold('Batch UUID:') . ' ' . Style::bold((string)($row->batch_uuid ?? '-')));
        $this->outln('  ' . Style::bold('Applied At:') . ' ' . Style::bold((string)($row->applied_at ?? '-')));
        $this->outln();
    }

    private function no_migrations_to_pop(): void
    {
        $this->errln(Style::warning_label() . ' ' . Style::bold('No migrations found to pop.'));
    }

    private function outln(string $message = ''): void
    {
        $this->output->writeln($message);
    }

    private function errln(string $message): void
    {
        $this->output->err($message);
    }

    private function warn_if_legacy_mode(): void
    {
        if (!$this->ledger->is_legacy_mode()) {
            return;
        }

        $this->output->warning_box(Style::yellow('Migration history is in legacy mode', true), [
            'The migration ledger still uses the legacy nullable schema.',
            Style::bold('Preview: php atomic migrations/upgrade --dry-run'),
            Style::bold('Apply:   php atomic migrations/upgrade'),
        ]);
    }

    /** @param list<string> $legacy_schema_columns */
    private function print_upgrade_summary(
        int $legacy_count,
        int $pending_count,
        array $legacy_schema_columns,
        bool $dry_run,
    ): void
    {
        $this->output->writeln();
        $this->output->section($dry_run ? 'Migration upgrade preview' : 'Migration upgrade');
        $this->output->writeln(str_repeat('-', 28));
        $this->output->field(
            'Legacy records to adopt',
            $legacy_count > 0 ? Style::yellow((string)$legacy_count, true) : '0',
        );
        $this->output->field(
            'Pending migrations remaining',
            $pending_count > 0 ? Style::yellow((string)$pending_count, true) : '0',
        );
        $this->output->field('Migrations executed by upgrade', '0');
        $this->output->field(
            'Columns still nullable',
            $legacy_schema_columns === []
                ? 'none'
                : Style::yellow(implode(', ', $legacy_schema_columns), true),
        );
        $this->output->field(
            'Upgrade required',
            ($legacy_count > 0 || $legacy_schema_columns !== [])
                ? Style::yellow('YES', true)
                : Style::green('NO', true),
        );
        $this->output->field(
            'Schema finalization',
            $legacy_schema_columns === []
                ? Style::green('complete', true)
                : Style::yellow('source/checksum -> NOT NULL', true),
        );
        $this->output->field(
            'Database changes',
            $dry_run
                ? 'none (dry run)'
                : Style::yellow('adoption and schema finalization', true),
        );
        if ($dry_run) {
            $this->output->field('Next', Style::bold('php atomic migrations/upgrade'));
            $this->output->field('Then', Style::bold('php atomic migrations/migrate'));
        }
        $this->output->writeln();
    }

    /** @param list<array{source: string, migration: string, path: string, checksum: string}> $pending */
    private function confirm_modified_published_copies(array $pending, bool $dry_run = false, bool $adopting = false): bool
    {
        $pending_ids = [];
        foreach ($pending as $migration) {
            $pending_ids[$migration['source']][$migration['migration']] = true;
        }

        $conflicts = array_values(array_filter(
            $this->catalog->modified_published_copies(),
            static fn(array $conflict): bool => isset(
                $pending_ids[$conflict['application']['source']][$conflict['application']['migration']]
            ) || isset(
                $pending_ids[$conflict['owner']['source']][$conflict['owner']['migration']]
            ),
        ));
        if ($conflicts === []) {
            return true;
        }

        $this->errln(Style::warning_label() . ' ' . Style::bold('Modified published migration detected.'));
        foreach ($conflicts as $conflict) {
            $application = $conflict['application'];
            $owner = $conflict['owner'];
            $this->errln(
                "Application migration '{$application['migration']}' matches the name of "
                . "'{$owner['source']}:{$owner['migration']}', but its contents differ."
            );
            $this->errln('  Application file: ' . $application['path']);
            $this->errln('  Owner file: ' . $owner['path']);
            $this->errln('  Application checksum: ' . $application['checksum']);
            $this->errln('  Owner checksum: ' . $owner['checksum']);
        }
        $this->errln('The application file may be a modified copy of the owner migration.');
        if ($adopting) {
            $this->errln('Upgrade will adopt plugin ownership and the owner checksum. Later rollback uses the plugin original when available. No migration runs during upgrade.');
        }

        if ($dry_run) {
            $this->errln('Applying this upgrade will require confirmation of the checksum mismatch.');
            return true;
        }

        return $this->confirm_with_warning($adopting
            ? 'Adopt this history despite the checksum mismatch?'
            : 'Continue with these migrations despite the mismatch?');
    }

    /** @param list<array{row: object, migration: array}> $mismatches */
    private function confirm_applied_checksum_mismatches(array $mismatches): bool
    {
        if ($mismatches === []) {
            return true;
        }

        $this->output->warning_box(Style::yellow('Migration checksum mismatch detected', true));
        foreach ($mismatches as $mismatch) {
            $row = $mismatch['row'];
            $migration = $mismatch['migration'];
            $this->errln("Migration '{$migration['source']}:{$migration['migration']}' was changed after it was applied.");
            $this->errln('  File: ' . $migration['path']);
            $this->errln('  Recorded checksum: ' . (string)($row->checksum ?? ''));
            $this->errln('  Current checksum:  ' . $migration['checksum']);
        }
        $this->errln('The current file will be used if you continue.');

        return $this->confirm_with_warning('Continue despite the checksum mismatch?');
    }

    /** @param list<array> $pending */
    private function confirm_framework_checksum_mismatches(array $pending, bool $dry_run = false, bool $adopting = false): bool
    {
        $mismatches = array_values(array_filter(
            $pending,
            static fn(array $migration): bool => isset($migration['framework_checksum'])
                && !hash_equals($migration['framework_checksum'], $migration['checksum']),
        ));
        if ($mismatches === []) {
            return true;
        }

        $this->output->warning_box(Style::yellow('Published framework migration differs from its source', true));
        foreach ($mismatches as $migration) {
            $this->errln("Migration '{$migration['migration']}' has a checksum mismatch.");
            $this->errln('  Published file: ' . $migration['path']);
            $this->errln('  Framework source: ' . ($migration['framework_path'] ?? '(unavailable)'));
            $this->errln('  Framework checksum: ' . $migration['framework_checksum']);
            $this->errln('  Published checksum: ' . $migration['checksum']);
        }
        $this->errln($adopting
            ? 'Upgrade will record the framework owner checksum. Later operations check the published file against it. No migration runs during upgrade.'
            : 'The published file will be executed if you continue.');

        if ($dry_run) {
            $this->errln('Applying this upgrade will require confirmation of the checksum mismatch.');
            return true;
        }

        return $this->confirm_with_warning('Continue despite the checksum mismatch?');
    }

    private function confirm_with_warning(string $question): bool
    {
        if ($this->input === null || !$this->input->is_interactive()) {
            $this->errln(Style::error_label() . ' Migration aborted: confirmation requires interactive input.');
            return false;
        }

        $this->output->prompt($question . ' [y/N]: ');
        $answer = strtolower($this->input->read_line());
        if ($answer !== 'y' && $answer !== 'yes') {
            $this->errln(Style::error_label() . ' Aborted.');
            return false;
        }

        return true;
    }

}
