<?php
declare(strict_types=1);
namespace Engine\Atomic\CLI;

if (!defined( 'ATOMIC_START' ) ) exit;

use Engine\Atomic\CLI\Console\Input;
use Engine\Atomic\CLI\Console\Output;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Log;

class CLI {
    use DB;
    use File;
    use Init;
    use Migrations;
    use Queue;
    use Scheduler;
    use Seeder;

    protected App    $atomic;
    protected Output $output;
    protected Input  $input;

    public function __construct() {
        $this->atomic = App::instance();
        $this->output = new Output();
        $this->input  = new Input($this->output);
    }

    public function help(): void {
        $this->output->writeln('Atomic Help');
        $this->output->writeln('  init               - Initialize new project (dirs, .env, keys)');
        $this->output->writeln('  init/key           - Regenerate APP_UUID, APP_KEY, APP_ENCRYPTION_KEY');
        $this->output->writeln('  init/guide         - Print the full manual setup guide (no interaction)');
        $this->output->writeln('  logs/rotate        - Delete php error log files beyond the most recent 10');
        $this->output->writeln('  help               - View this help');
        $this->output->writeln('  migrations/init    - Create/verify the migrations tracking table');
        $this->output->writeln('  migrations/migrate - Run database migrations');
        $this->output->writeln('  cache/clear        - Clear cache');
        $this->output->writeln('  version            - View versions F3, PHP and Atomic');
        $this->output->writeln('  routes             - View routes list');
        $this->output->writeln('  classes            - View classes list');
        $this->output->writeln('  custom-hive        - View custom HIVE');
        $this->output->writeln('  queue/db           - Create tables for queues');
        $this->output->writeln('  queue/worker       - Run queue worker');
        $this->output->writeln('  queue/monitor      - Run queue monitor');
        $this->output->writeln('  queue/retry [<job_uuid>|<queue_name>] - Retry failed tasks (optional UUID or queue name)');
        $this->output->writeln('  queue/delete       - Delete a job by UUID');
        $this->output->writeln('  queue/telemetry/db - Create table for queue telemetry');
        $this->output->writeln('  schedule/run       - Run all due scheduled tasks');
        $this->output->writeln('  schedule/work      - Run scheduler daemon');
        $this->output->writeln('  schedule/list      - List all scheduled tasks');
        $this->output->writeln('  schedule/test      - Test scheduler configuration');
        $this->output->writeln('  schedule/help      - Show scheduler help');
        $this->output->writeln('  file/csv2pdf       - Convert CSV to PDF');
        $this->output->writeln('  file/xls2pdf       - Convert XLS to PDF');
    }

    public function version(): void {
        $this->output->writeln('Fat-Free Framework Version: ' . App::atomic()::VERSION);
        $this->output->writeln('PHP CLI Version: ' . phpversion());
        $this->output->writeln('Atomic Version: ' . ATOMIC_VERSION);
    }

    public function list_routes(): void {
        $routes = $this->atomic->get('ROUTES');
        $groups = [
            'WEB/CLI'   => [],
            'WEB ERROR' => [],
            'API'       => []
        ];
        if (is_array($routes)) {
            foreach ($routes as $pattern => $routeList) {
                if (stripos($pattern, '/error/') !== false) {
                    $groups['WEB ERROR'][] = $pattern;
                } elseif (stripos($pattern, '/api/') !== false) {
                    $groups['API'][] = $pattern;
                } else {
                    $groups['WEB/CLI'][] = $pattern;
                }
            }
        }

        foreach ($groups as $groupName => $routesList) {
            $this->output->writeln("[{$groupName}]");
            if (empty($routesList)) {
                $this->output->writeln('  (no routes)');
            } else {
                foreach ($routesList as $r) {
                    $this->output->writeln('  ' . $r);
                }
            }
            $this->output->writeln();
        }
    }

    public function classes(): void {
        $this->output->writeln('Declared Classes:');
        $this->output->write(print_r(get_declared_classes(), true));
    }

    public function hive(): void {
        $this->output->write(print_r($this->atomic->hive(), true));
    }    

    public function custom_hive(): void {
        $keys = [
            'DEBUG', 'BASE', 'LANGUAGE', 'LANG', 'FALLBACK', 'ENCODING', 'TZ',
            'APP_NAME', 'APP_KEY', 'DEBUG_MODE', 'DEBUG_LEVEL', 
            'CACHE', 'AUTOLOAD', 'UI', 'TEMP', 'LOGS', 'LOCALES', 'FONTS', 'FONTS_TEMP',
            'QUEUE_DRIVER', 'QUEUE_NAME'
        ];
        $hive = $this->atomic->hive();
        $filteredHive = array_intersect_key($hive, array_flip($keys));
        $this->output->writeln('Custom Hive:');
        $this->output->write(print_r($filteredHive, true));
    }

    public function get_cli_args(): array {
        global $argv;
        return array_slice($argv, 2);
    }

    public static function is_cli(): bool
    {
        return php_sapi_name() === 'cli';
    }

    public static function is_user_root(): bool
    {
        return function_exists('posix_getuid') && posix_getuid() === 0;
    }

    public function check_root_warning(string $raw_command, string $command): bool {
        if (!self::is_user_root() || !$this->is_root_restricted_command($command)) {
            return false;
        }

        if ($this->input->is_interactive()) {
            $this->output->err(Style::warning_label() . " You are running '" . Style::bold($raw_command) . "' as root.");
            $this->output->err("Running as root may cause permission issues.");
            $this->output->prompt("Do you want to continue? [y/N]: ");

            $answer = strtolower($this->input->read_line());

            if ($answer !== 'y' && $answer !== 'yes') {
                $this->output->err(Style::error_label() . " Aborted.");
                return true;
            }
        } else {
            $msg = "Running '{$raw_command}' as root in non-interactive mode.";
            $this->output->err("[WARNING] {$msg}");
            Log::warning($msg);
        }

        return false;
    }

    public function is_root_restricted_command(string $command): bool {
        static $rootRestrictedCommands = [
            '/init',
            '/init/key',
            '/cache/clear',
            '/db/truncate',
            '/db/truncate/queue',
            '/migrations/init',
            '/migrations/create',
            '/migrations/migrate',
            '/migrations/rollback',
            '/seed/users',
            '/seed/roles',
            '/seed/pages',
            '/redis/clear',
            '/queue/worker',
            '/queue/test',
            '/schedule/work',
            '/schedule/run',
        ];
        return in_array($command, $rootRestrictedCommands, true);
    }
}
