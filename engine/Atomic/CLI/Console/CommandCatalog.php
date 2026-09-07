<?php
declare(strict_types=1);

namespace Engine\Atomic\CLI\Console;

if (!defined('ATOMIC_START')) exit;

final class CommandCatalog
{
    /**
     * Command metadata is the source of truth for help, suggestions, and usage output.
     *
     * @var array<string, array{topic: string, arguments: string, description: string, visible: bool}>
     */
    private const COMMANDS = [
        'init' => ['topic' => 'project', 'arguments' => '', 'description' => 'Initialize new project (dirs, .env, keys)', 'visible' => true],
        'init/key' => ['topic' => 'project', 'arguments' => '', 'description' => 'Regenerate APP_UUID, APP_KEY, APP_ENCRYPTION_KEY', 'visible' => true],
        'init/guide' => ['topic' => 'project', 'arguments' => '', 'description' => 'Print the full manual setup guide (no interaction)', 'visible' => true],
        'logs/rotate' => ['topic' => 'project', 'arguments' => '', 'description' => 'Delete php error log files beyond the most recent 10', 'visible' => true],
        'plugin/make' => ['topic' => 'plugins', 'arguments' => '<PluginName>', 'description' => 'Create a user plugin scaffold', 'visible' => true],
        'plugin/deps' => ['topic' => 'plugins', 'arguments' => 'install [PluginName]', 'description' => 'Install enabled plugin Composer dependencies', 'visible' => true],
        'access/user/create' => ['topic' => 'authentication', 'arguments' => '<guard> <username> [roles] [--role=role] [--secret=secret] [--force]', 'description' => 'Create config auth user', 'visible' => true],
        'access/user/reset' => ['topic' => 'authentication', 'arguments' => '<guard> <username> [--secret=secret]', 'description' => 'Reset config auth user secret', 'visible' => true],
        'access/user/list' => ['topic' => 'authentication', 'arguments' => '', 'description' => 'List config auth users', 'visible' => true],
        'migrations/create' => ['topic' => 'migrations', 'arguments' => '<name>', 'description' => 'Create a migration', 'visible' => false],
        'migrations/init' => ['topic' => 'migrations', 'arguments' => '', 'description' => 'Create/verify the migrations tracking table', 'visible' => true],
        'migrations/migrate' => ['topic' => 'migrations', 'arguments' => '[steps]', 'description' => 'Run database migrations', 'visible' => true],
        'migrations/rollback' => ['topic' => 'migrations', 'arguments' => '[steps|batch]', 'description' => 'Roll back database migrations', 'visible' => false],
        'migrations/status' => ['topic' => 'migrations', 'arguments' => '', 'description' => 'List migrations by owner and integrity state', 'visible' => true],
        'migrations/upgrade' => ['topic' => 'migrations', 'arguments' => '[--dry-run]', 'description' => 'Adopt legacy migration history', 'visible' => true],
        'migrations/publish' => ['topic' => 'migrations', 'arguments' => '<framework|plugin-name>', 'description' => 'Publish framework updates or plugin migrations', 'visible' => false],
        'cache/invalidate' => ['topic' => 'cache', 'arguments' => '', 'description' => 'Invalidate cache by advancing generation', 'visible' => true],
        'cache/clear' => ['topic' => 'cache', 'arguments' => '', 'description' => 'Physically delete cache files/keys where supported', 'visible' => true],
        'cache/prune' => ['topic' => 'cache', 'arguments' => '', 'description' => 'Remove expired/corrupt cache entries where supported', 'visible' => true],
        'help' => ['topic' => 'system', 'arguments' => '[topic]', 'description' => 'View this help (or help <topic>)', 'visible' => true],
        'health' => ['topic' => 'system', 'arguments' => '', 'description' => 'Check application health and configuration', 'visible' => true],
        'version' => ['topic' => 'system', 'arguments' => '', 'description' => 'View versions F3, PHP and Atomic', 'visible' => true],
        'routes' => ['topic' => 'system', 'arguments' => '', 'description' => 'View routes list', 'visible' => true],
        'classes' => ['topic' => 'system', 'arguments' => '', 'description' => 'View classes list', 'visible' => true],
        'custom-hive' => ['topic' => 'system', 'arguments' => '', 'description' => 'View custom HIVE', 'visible' => true],
        'db/truncate' => ['topic' => 'system', 'arguments' => '<table_name>', 'description' => 'Truncate a database table', 'visible' => false],
        'db/queue' => ['topic' => 'queue', 'arguments' => '', 'description' => 'Create tables for queues', 'visible' => true],
        'queue/worker' => ['topic' => 'queue', 'arguments' => '<queue_name>', 'description' => 'Run queue worker', 'visible' => true],
        'queue/test' => ['topic' => 'queue', 'arguments' => '<success|failed|timeout|cancel_requested|cancelled|all> [queue_name]', 'description' => 'Queue test jobs', 'visible' => true],
        'queue/monitor' => ['topic' => 'queue', 'arguments' => '', 'description' => 'Run queue monitor', 'visible' => true],
        'queue/retry' => ['topic' => 'queue', 'arguments' => '[<job_uuid>|<queue_name>]', 'description' => 'Retry failed tasks', 'visible' => true],
        'queue/cancel' => ['topic' => 'queue', 'arguments' => '<job_uuid>', 'description' => 'Request cancellation for a job by UUID', 'visible' => true],
        'queue/delete' => ['topic' => 'queue', 'arguments' => '<job_uuid>', 'description' => 'Delete a job by UUID', 'visible' => true],
        'file/csv2pdf' => ['topic' => 'files', 'arguments' => '<input.csv> <output.pdf>', 'description' => 'Convert CSV to PDF', 'visible' => true],
        'file/xls2pdf' => ['topic' => 'files', 'arguments' => '<input.xls> <output.pdf>', 'description' => 'Convert XLS to PDF', 'visible' => true],
        'schedule/run' => ['topic' => 'scheduler', 'arguments' => '', 'description' => 'Run all due scheduled tasks', 'visible' => true],
        'schedule/work' => ['topic' => 'scheduler', 'arguments' => '', 'description' => 'Run scheduler daemon', 'visible' => true],
        'schedule/list' => ['topic' => 'scheduler', 'arguments' => '', 'description' => 'List all scheduled tasks', 'visible' => true],
        'schedule/test' => ['topic' => 'scheduler', 'arguments' => '', 'description' => 'Test scheduler configuration', 'visible' => true],
        'schedule/help' => ['topic' => 'scheduler', 'arguments' => '', 'description' => 'Show scheduler help', 'visible' => true],
    ];

    /** @var array<string, string> */
    private const TOPIC_TITLES = [
        'project' => 'Project',
        'plugins' => 'Plugins',
        'authentication' => 'Authentication',
        'migrations' => 'Migrations',
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
                if ($command['topic'] === $topic && $command['visible']) {
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
