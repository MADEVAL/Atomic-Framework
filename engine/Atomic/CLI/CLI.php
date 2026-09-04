<?php
declare(strict_types=1);
namespace Engine\Atomic\CLI;

if (!defined( 'ATOMIC_START' ) ) exit;

use Engine\Atomic\CLI\Console\Input;
use Engine\Atomic\CLI\Console\Output;
use Engine\Atomic\CLI\Console\CommandSuggester;
use Engine\Atomic\CLI\Console\CommandCatalog;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Log;

class CLI {
    use Access;
    use DB;
    use File;
    use Init;
    use Migrations;
    use Plugin;
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

    public function help(?string $topic = null): void {
        $groups = CommandCatalog::topics();

        if ($topic !== null) {
            $topic = strtolower(trim($topic));
            if (!isset($groups[$topic])) {
                $this->output->failure("Unknown help topic '{$topic}'.");
                $this->output->err('');
                $this->output->error_list('Available topics:', array_keys($groups));
                return;
            }

            $this->output->writeln('Atomic Help: ' . $groups[$topic]['title']);
            $this->output->writeln('Usage: php atomic help ' . $topic);
            $this->output->writeln();
            $this->output->section($groups[$topic]['title'] . ' Commands');
            $this->write_help_commands(
                $groups[$topic]['commands'],
                $this->help_command_width([$groups[$topic]])
            );
            return;
        }

        $this->output->writeln('Atomic Help');
        $this->output->writeln('Usage: php atomic ' . CommandCatalog::display('help'));
        $this->output->writeln();

        $command_width = $this->help_command_width($groups);
        foreach ($groups as $group) {
            $this->output->section($group['title']);
            $this->write_help_commands($group['commands'], $command_width);
            $this->output->writeln();
        }
    }

    public function report_unknown_command(string $raw_command, string $command): void
    {
        $this->output->failure("Unknown command '{$raw_command}'.");
        $this->output->err('');

        $suggestions = (new CommandSuggester())->suggest(
            $command,
            $this->registered_cli_commands()
        );
        if ($suggestions !== []) {
            $commands = array_map(
                static fn(string $suggestion): string => 'php atomic ' . CommandCatalog::display($suggestion),
                $suggestions
            );
            $this->output->error_list('Did you mean?', $commands);
            $this->output->err('');
        }

        $this->output->error_hint('Help', 'php atomic help');
    }

    /** @return list<string> */
    private function registered_cli_commands(): array
    {
        $routes = $this->atomic->get('ROUTES');
        if (!is_array($routes)) {
            return [];
        }

        $commands = [];
        foreach (array_keys($routes) as $route) {
            if (!is_string($route) || str_contains($route, '@') || str_contains($route, '[')) {
                continue;
            }

            $commands[] = ltrim($route, '/');
        }

        return array_values(array_unique($commands));
    }

    /**
     * @param array<string, array{title: string, commands: list<array{name: string, arguments: string, description: string}>}> $groups
     */
    private function help_command_width(array $groups): int
    {
        $width = 0;
        foreach ($groups as $group) {
            foreach ($group['commands'] as $command) {
                $width = max($width, strlen(CommandCatalog::display($command['name'])));
            }
        }

        return $width;
    }

    /** @param list<array{name: string, arguments: string, description: string}> $commands */
    private function write_help_commands(array $commands, int $command_width): void
    {
        foreach ($commands as $command) {
            $label = CommandCatalog::display($command['name']);
            $this->output->writeln('  ' . str_pad($label, $command_width) . ' - ' . $command['description']);
        }
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
            'DEBUG', 'BASE', 'LANGUAGE', 'LANG', 'ENCODING', 'TZ',
            'APP_NAME', 'APP_KEY', 'DEBUG_MODE', 'DEBUG_LEVEL', 
            'CACHE', 'CACHE_CONFIG', 'AUTOLOAD', 'UI', 'TEMP', 'LOGS', 'LOCALES', 'FONTS', 'FONTS_TEMP',
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
            '/plugin/make',
            '/plugin/deps',
            '/access/user/create',
            '/access/user/reset',
            '/cache/invalidate',
            '/cache/clear',
            '/cache/prune',
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
