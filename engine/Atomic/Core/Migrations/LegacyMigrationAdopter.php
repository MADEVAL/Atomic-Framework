<?php
declare(strict_types=1);

namespace Engine\Atomic\Core\Migrations;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\CLI\Console\Output;
use Engine\Atomic\CLI\Style;

class LegacyMigrationAdopter
{
    public function __construct(
        private readonly MigrationLedger $ledger,
        private readonly MigrationHistory $history,
        private readonly Output $output,
    ) {}

    public function upgrade(array $migrations, array $rows, bool $dry_run): bool
    {
        $adoptions = [];
        foreach ($rows as $row) {
            $source = (string)($row->source ?? '');
            $checksum = (string)($row->checksum ?? '');
            if ($source !== '' && $checksum !== '') {
                continue;
            }

            $migration = $source === ''
                ? $this->history->resolve_legacy_migration((string)$row->migration, $migrations)
                : $this->history->resolve_sourced_migration($source, (string)$row->migration, $migrations);
            if ($migration === null) {
                throw new \RuntimeException(
                    "Cannot adopt legacy migration '{$row->migration}': its migration file is unavailable."
                );
            }
            $checksum = $migration['source'] === FrameworkMigrationGroups::FRAMEWORK
                && isset($migration['framework_checksum'])
                ? $migration['framework_checksum']
                : $migration['checksum'];
            $adoptions[] = [
                'id' => (int)$row->id,
                'old_migration' => (string)$row->migration,
                'source' => $migration['source'],
                'migration' => $migration['migration'],
                'checksum' => $checksum,
            ];
        }

        if ($adoptions === []) {
            $this->output->writeln(Style::success_label() . ' ' . Style::bold('Migration history is already upgraded.'));
            return true;
        }

        $this->assert_unique_identities($rows, $adoptions);
        foreach ($adoptions as $adoption) {
            $verb = $dry_run ? 'Would adopt' : 'Adopting';
            $this->output->writeln(
                $verb . " '{$adoption['old_migration']}' as "
                . "'{$adoption['source']}:{$adoption['migration']}'."
            );
        }
        if (!$dry_run) {
            $this->ledger->adopt($adoptions);
            $this->output->writeln(
                Style::success_label() . ' ' . Style::bold((string)count($adoptions))
                . ' legacy migration record(s) upgraded.'
            );
        }
        return true;
    }

    private function assert_unique_identities(array $rows, array $adoptions): void
    {
        $identities = [];
        foreach ($rows as $row) {
            if ((string)($row->source ?? '') !== '' && (string)($row->checksum ?? '') !== '') {
                $source = (string)$row->source;
                $migration = (string)$row->migration;
                $identities[$source][$migration] = (int)$row->id;
            }
        }
        foreach ($adoptions as $adoption) {
            $source = $adoption['source'];
            $migration = $adoption['migration'];
            if (isset($identities[$source][$migration]) && $identities[$source][$migration] !== $adoption['id']) {
                throw new \RuntimeException(
                    "Cannot adopt duplicate migration identity '{$adoption['source']}:{$adoption['migration']}'."
                );
            }
            $identities[$source][$migration] = $adoption['id'];
        }
    }
}
