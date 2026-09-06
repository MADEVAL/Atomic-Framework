<?php
declare(strict_types=1);

namespace Engine\Atomic\Core\Migrations;

if (!defined('ATOMIC_START')) exit;

class FrameworkMigrationGroups
{
    public const FRAMEWORK = 'framework';

    public const INITIAL = 'initial';
    public const UPDATES = 'updates';

    public const STORAGE_MIGRATION = 'atomic_create_storage_tables';
    public const SESSIONS_MIGRATION = 'atomic_create_session_table';
    public const QUEUE_MIGRATION = 'atomic_create_queue_tables';
    public const MUTEX_MIGRATION = 'atomic_create_mutex_table';

    /**
     * Framework migration directories under MIGRATIONS_CORE.
     *
     * Every PHP file inside a group directory belongs to that group.
     * Files that are not in a group directory are ungrouped and are not
     * published or discovered.
     *
     * @return list<string>
     */
    public static function manifest(): array
    {
        return [
            self::INITIAL,
            self::UPDATES,
        ];
    }

    public function directory_for(string $core, string $group): string
    {
        return rtrim($core, '/\\') . DIRECTORY_SEPARATOR . $group;
    }

    /**
     * @return list<array{group: string, migration: string, path: string, version?: int}>
     */
    private function inventory(string $core, string $directory): array
    {
        $items = [];

        $path = $this->directory_for($core, $directory);
        if (!is_dir($path)) {
            return [];
        }

        foreach (array_filter(
            glob($path . DIRECTORY_SEPARATOR . '*.php') ?: [],
            static fn(string $file): bool => basename($file) !== 'index.php'
        ) as $file) {
            $name = basename($file, '.php');
            $item = [
                'group' => $directory,
                'migration' => $name,
                'path' => $file,
            ];

            if ($directory === self::UPDATES) {
                if (!preg_match('/^(\d+?)_(.+)$/', $name, $matches)) {
                    throw new \RuntimeException(
                        "Framework update migration '{$name}' must start with a numeric version."
                    );
                }

                $item['version'] = (int) $matches[1];
                $item['migration'] = $matches[2];
            }

            $items[] = $item;
        }

        usort(
            $items,
            static function (array $left, array $right): int {
                return isset($left['version'])
                    ? [$left['version'], $left['migration']] <=> [$right['version'], $right['migration']]
                    : $left['migration'] <=> $right['migration'];
            }
        );

        foreach ($items as $index => $item) {
            if (!isset($item['version'])) {
                continue;
            }

            if (isset($items[$index - 1]['version']) && $items[$index - 1]['version'] === $item['version']) {
                throw new \RuntimeException(
                    "Duplicate framework update migration version '{$item['version']}'."
                );
            }
        }

        return $items;
    }

    /** @return list<array{group: string, migration: string, path: string}> */
    public function initial_inventory(string $core): array
    {
        return $this->inventory($core, self::INITIAL);
    }

    /** @return list<array{group: string, migration: string, path: string, version: int}> */
    public function update_inventory(string $core): array
    {
        return $this->inventory($core, self::UPDATES);
    }

    /**
     * @return array{group: string, path: string}|null
     */
    private function locate(string $core, string $directory, string $migration_name): ?array
    {
        $name = basename($migration_name, '.php');
        foreach ($this->inventory($core, $directory) as $item) {
            if ($item['migration'] === $name) {
                return ['group' => $item['group'], 'path' => $item['path']];
            }
        }

        return null;
    }

    /** @return array{group: string, path: string}|null */
    public function locate_initial(string $core, string $migration_name): ?array
    {
        return $this->locate($core, self::INITIAL, $migration_name);
    }

    /** @return array{group: string, path: string}|null */
    public function locate_update(string $core, string $migration_name): ?array
    {
        return $this->locate($core, self::UPDATES, $migration_name);
    }

    public function group_from_path(string $path): ?string
    {
        $resolved = realpath($path) ?: $path;
        $group = basename(dirname($resolved));

        return in_array($group, self::manifest(), true) ? $group : null;
    }

}
