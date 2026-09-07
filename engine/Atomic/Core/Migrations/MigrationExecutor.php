<?php
declare(strict_types=1);

namespace Engine\Atomic\Core\Migrations;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\ID;

class MigrationExecutor
{
    public function batch_id(): string
    {
        return ID::uuid_v4();
    }

    public function up(array $migration): void
    {
        $this->execute($migration, 'up', 'Migration');
    }

    public function down(array $migration): void
    {
        $this->execute($migration, 'down', 'Rollback');
    }

    private function execute(array $migration, string $direction, string $operation): void
    {
        $definition = include $migration['path'];
        if (!is_array($definition) || !isset($definition[$direction]) || !is_callable($definition[$direction])) {
            throw new \RuntimeException("Invalid migration structure in {$migration['path']}.");
        }
        if ($definition[$direction]() === false) {
            throw new \RuntimeException(
                "{$operation} '{$migration['source']}:{$migration['migration']}' returned failure."
            );
        }
    }
}
