<?php
declare(strict_types=1);

namespace Engine\Atomic\Core\Migrations;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\ID;

class MigrationExecutor
{
    private const OPERATION_MIGRATION = 'Migration';
    private const OPERATION_ROLLBACK = 'Rollback';

    public function __construct(
        private readonly MigrationContext $context,
    ) {}

    public function batch_id(): string
    {
        return ID::uuid_v4();
    }

    public function up(array $migration): void
    {
        $this->execute($migration, MigrationDirection::UP, self::OPERATION_MIGRATION);
    }

    public function down(array $migration): void
    {
        $this->execute($migration, MigrationDirection::DOWN, self::OPERATION_ROLLBACK);
    }

    private function execute(array $migration, MigrationDirection $direction, string $operation): void
    {
        $definition = include $migration['path'];

        if ($definition instanceof Migration) {
            try {
                $definition->run($direction, $this->context);
            } catch (\Throwable $e) {
                throw new \RuntimeException(
                    "{$operation} '{$migration['source']}:{$migration['migration']}' failed: {$e->getMessage()}",
                    0,
                    $e,
                );
            }
            return;
        }

        $key = $direction->value;
        if (!is_array($definition) || !isset($definition[$key]) || !is_callable($definition[$key])) {
            throw new \RuntimeException("Invalid migration structure in {$migration['path']}.");
        }
        if ($definition[$key]() === false) {
            throw new \RuntimeException(
                "{$operation} '{$migration['source']}:{$migration['migration']}' returned failure."
            );
        }
    }
}
