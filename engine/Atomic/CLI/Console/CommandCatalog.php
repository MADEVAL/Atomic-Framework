<?php
declare(strict_types=1);

namespace Engine\Atomic\CLI\Console;

if (!defined('ATOMIC_START')) exit;

final class CommandCatalog
{
    /**
     * Command metadata is the source of truth for help, suggestions, and usage output.
     *
     * Every CLI route registered by the framework must be listed here so that
     * help stays complete. Descriptions stay short: one line, no trailing period.
     *
     * @var array<string, array{topic: string, arguments: string, description: string}>
     */
    private const COMMANDS = [
        'init' => ['topic' => 'project', 'arguments' => '', 'description' => 'Initialize new project (dirs, .env, keys)'],
        'init/key' => ['topic' => 'project', 'arguments' => '', 'description' => 'Regenerate APP_UUID, APP_KEY, APP_ENCRYPTION_KEY'],
        'init/guide' => ['topic' => 'project', 'arguments' => '', 'description' => 'Print the full manual setup guide (no interaction)'],
        'logs/rotate' => ['topic' => 'project', 'arguments' => '', 'description' => 'Delete php error log files beyond the most recent 10'],
        'plugin/make' => ['topic' => 'plugins', 'arguments' => '<PluginName>', 'description' => 'Create a user plugin scaffold'],
        'plugin/deps' => ['topic' => 'plugins', 'arguments' => 'install [PluginName]', 'description' => 'Install enabled plugin Composer dependencies'],
        'access/user/create' => ['topic' => 'authentication', 'arguments' => '<guard> <username> [roles] [--role=role] [--secret=secret] [--force]', 'description' => 'Create config auth user'],
        'access/user/reset' => ['topic' => 'authentication', 'arguments' => '<guard> <username> [--secret=secret]', 'description' => 'Reset config auth user secret'],
        'access/user/list' => ['topic' => 'authentication', 'arguments' => '', 'description' => 'List config auth users'],
        'migrations/create' => ['topic' => 'migrations', 'arguments' => '<name>', 'description' => 'Create a migration file'],
        'migrations/init' => ['topic' => 'migrations', 'arguments' => '', 'description' => 'Create/verify the migrations tracking table'],
        'migrations/migrate' => ['topic' => 'migrations', 'arguments' => '[steps]', 'description' => 'Run database migrations'],
        'migrations/rollback' => ['topic' => 'migrations', 'arguments' => '[steps|batch]', 'description' => 'Roll back database migrations'],
        'migrations/status' => ['topic' => 'migrations', 'arguments' => '', 'description' => 'List migrations by owner and integrity state'],
        'migrations/upgrade' => ['topic' => 'migrations', 'arguments' => '[--dry-run]', 'description' => 'Adopt legacy migration history'],
        'migrations/publish' => ['topic' => 'migrations', 'arguments' => '<framework|plugin-name>', 'description' => 'Publish framework or plugin migrations'],
        'db/tables' => ['topic' => 'database', 'arguments' => '', 'description' => 'List database tables'],
        'db/sessions' => ['topic' => 'database', 'arguments' => '', 'description' => 'Publish the session table migration'],
        'db/queue' => ['topic' => 'database', 'arguments' => '', 'description' => 'Publish the queue tables migration'],
        'db/users' => ['topic' => 'database', 'arguments' => '', 'description' => 'Publish the user tables migration'],
        'db/pages' => ['topic' => 'database', 'arguments' => '', 'description' => 'Publish the page tables migration'],
        'db/storage' => ['topic' => 'database', 'arguments' => '', 'description' => 'Publish the storage tables migration'],
        'db/mutex' => ['topic' => 'database', 'arguments' => '', 'description' => 'Publish the mutex table migration'],
        'db/truncate' => ['topic' => 'database', 'arguments' => '<table_name>', 'description' => 'Delete all rows from a table'],
        'db/truncate/queue' => ['topic' => 'database', 'arguments' => '', 'description' => 'Delete all rows from the queue tables'],
        'seed/users' => ['topic' => 'seeding', 'arguments' => '', 'description' => 'Seed the bundled users'],
        'seed/roles' => ['topic' => 'seeding', 'arguments' => '', 'description' => 'Seed the bundled roles'],
        'seed/pages' => ['topic' => 'seeding', 'arguments' => '', 'description' => 'Seed the bundled pages'],
        'cache/invalidate' => ['topic' => 'cache', 'arguments' => '', 'description' => 'Invalidate cache by advancing generation'],
        'cache/clear' => ['topic' => 'cache', 'arguments' => '', 'description' => 'Physically delete cache files/keys where supported'],
        'cache/prune' => ['topic' => 'cache', 'arguments' => '', 'description' => 'Remove expired/corrupt cache entries where supported'],
        'redis/clear' => ['topic' => 'cache', 'arguments' => '', 'description' => 'Alias of cache/clear for the Redis cache'],
        'help' => ['topic' => 'system', 'arguments' => '[topic]', 'description' => 'View this help (or help <topic>)'],
        'health' => ['topic' => 'system', 'arguments' => '', 'description' => 'Check application health and configuration'],
        'version' => ['topic' => 'system', 'arguments' => '', 'description' => 'View versions F3, PHP and Atomic'],
        'routes' => ['topic' => 'system', 'arguments' => '', 'description' => 'List registered routes by type and source'],
        'classes' => ['topic' => 'system', 'arguments' => '', 'description' => 'View classes list'],
        'custom-hive' => ['topic' => 'system', 'arguments' => '', 'description' => 'View custom HIVE'],
        'queue/worker' => ['topic' => 'queue', 'arguments' => '<queue_name>', 'description' => 'Run queue worker'],
        'queue/test' => ['topic' => 'queue', 'arguments' => '<success|failed|timeout|cancel_requested|cancelled|all> [queue_name]', 'description' => 'Queue test jobs'],
        'queue/test/monitor' => ['topic' => 'queue', 'arguments' => '[queue_name]', 'description' => 'Seed queue monitor test cases'],
        'queue/monitor' => ['topic' => 'queue', 'arguments' => '', 'description' => 'Run queue monitor'],
        'queue/retry' => ['topic' => 'queue', 'arguments' => '[<job_uuid>|<queue_name>]', 'description' => 'Retry failed tasks'],
        'queue/cancel' => ['topic' => 'queue', 'arguments' => '<job_uuid>', 'description' => 'Request cancellation for a job by UUID'],
        'queue/delete' => ['topic' => 'queue', 'arguments' => '<job_uuid>', 'description' => 'Delete a job by UUID'],
        'file/csv2pdf' => ['topic' => 'files', 'arguments' => '<input.csv> <output.pdf> [header]', 'description' => 'Convert CSV to PDF'],
        'file/xls2pdf' => ['topic' => 'files', 'arguments' => '<input.xls> <output.pdf> [header]', 'description' => 'Convert XLS to PDF'],
        'schedule/run' => ['topic' => 'scheduler', 'arguments' => '', 'description' => 'Run all due scheduled tasks'],
        'schedule/work' => ['topic' => 'scheduler', 'arguments' => '[seconds]', 'description' => 'Run scheduler daemon'],
        'schedule/list' => ['topic' => 'scheduler', 'arguments' => '', 'description' => 'List all scheduled tasks'],
        'schedule/test' => ['topic' => 'scheduler', 'arguments' => '[expression]', 'description' => 'Test scheduler configuration'],
        'schedule/help' => ['topic' => 'scheduler', 'arguments' => '', 'description' => 'Show scheduler help'],
    ];

    /** @var array<string, string> */
    private const TOPIC_TITLES = [
        'project' => 'Project',
        'plugins' => 'Plugins',
        'authentication' => 'Authentication',
        'migrations' => 'Migrations',
        'database' => 'Database',
        'seeding' => 'Seeding',
        'cache' => 'Cache',
        'system' => 'System',
        'queue' => 'Queue',
        'scheduler' => 'Scheduler',
        'files' => 'Files',
    ];

    /** @return array<string, array{title: string, commands: list<array{name: string, arguments: string, description: string}>}> */
    public static function topics(): array
    {
        $topics = [];
        foreach (self::TOPIC_TITLES as $topic => $title) {
            $commands = [];
            foreach (self::COMMANDS as $name => $command) {
                if ($command['topic'] === $topic) {
                    $commands[] = [
                        'name' => $name,
                        'arguments' => $command['arguments'],
                        'description' => $command['description'],
                    ];
                }
            }

            if ($commands !== []) {
                $topics[$topic] = ['title' => $title, 'commands' => $commands];
            }
        }

        return $topics;
    }

    /** @return array{name: string, topic: string, arguments: string, description: string}|null */
    public static function find(string $command): ?array
    {
        $command = self::normalize($command);
        $definition = self::COMMANDS[$command] ?? null;

        return $definition === null ? null : ['name' => $command] + $definition;
    }

    public static function display(string $command): string
    {
        $command = self::normalize($command);
        $definition = self::COMMANDS[$command] ?? null;
        return $definition === null || $definition['arguments'] === ''
            ? $command
            : $command . ' ' . $definition['arguments'];
    }

    private static function normalize(string $command): string
    {
        return strtolower(trim($command, " \t\n\r\0\x0B/"));
    }
}
