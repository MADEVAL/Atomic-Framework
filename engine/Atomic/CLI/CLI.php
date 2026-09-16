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
use Engine\Atomic\Core\RouteLoader;

class CLI {
    private const ROUTE_SCOPE_ALL = 'all';
    private const ROUTE_SCOPE_WEB = 'web';
    private const ROUTE_SCOPE_API = 'api';
    private const ROUTE_SCOPE_CLI = 'cli';
    private const ROUTE_SCOPE_WEBSOCKET = 'websocket';
    private const ROUTE_SCOPE_TELEMETRY = 'telemetry';
    private const ROUTE_SOURCE_ALL = 'all';
    private const ROUTE_SOURCE_FRAMEWORK = 'framework';
    private const ROUTE_SOURCE_CUSTOM = 'custom';
    private const ROUTE_SOURCE_APP = 'app';
    private const ROUTE_SOURCE_PLUGIN = 'plugin';
    private const ROUTE_SOURCES = [
        self::ROUTE_SOURCE_ALL,
        self::ROUTE_SOURCE_FRAMEWORK,
        self::ROUTE_SOURCE_CUSTOM,
        self::ROUTE_SOURCE_APP,
        self::ROUTE_SOURCE_PLUGIN,
    ];
    private const ROUTE_SCOPE_LABELS = [
        self::ROUTE_SCOPE_WEB => 'Web',
        self::ROUTE_SCOPE_API => 'API',
        self::ROUTE_SCOPE_CLI => 'CLI',
        self::ROUTE_SCOPE_WEBSOCKET => 'WebSocket',
        self::ROUTE_SCOPE_TELEMETRY => 'Telemetry',
    ];

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
            $command_width = $this->help_command_width([$groups[$topic]]);
            $this->write_help_commands(
                $groups[$topic]['commands'],
                $command_width,
            );
            if ($topic === 'system') {
                $this->output->writeln();
                $this->write_route_commands(false, $command_width);
            }
            return;
        }

        $this->output->writeln('Atomic Help');
        $this->output->writeln('Usage: php atomic ' . CommandCatalog::display('help'));
        $this->output->writeln();

        $command_width = $this->help_command_width($groups);
        foreach ($groups as $topic => $group) {
            $this->output->section($group['title']);
            $this->write_help_commands($group['commands'], $command_width);
            $this->output->writeln();
            if ($topic === 'system') {
                $this->write_route_commands(false, $command_width);
                $this->output->writeln();
            }
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

        foreach ($this->route_command_rows() as [$command]) {
            $width = max($width, strlen($command));
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

    /**
     * List registered routes.
     *
     * Scope is one of all, web, api, cli, websocket, or telemetry.
     * Source is one of all, framework, custom, app, or plugin.
     *
     * @param list<string> $args
     */
    public function list_routes(array $args = []): void {
        $filters = $this->route_filters($args);
        if ($filters === null) {
            return;
        }

        [$scope, $source] = $filters;
        $this->load_route_scope($scope);
        $routes = array_values(array_filter($this->registered_routes(), static function (array $route) use ($scope, $source): bool {
            $scope_matches = $scope === self::ROUTE_SCOPE_ALL || $route['type'] === $scope;
            $source_matches = $source === self::ROUTE_SOURCE_ALL
                || ($source === self::ROUTE_SOURCE_CUSTOM && $route['source'] !== self::ROUTE_SOURCE_FRAMEWORK)
                || $route['source'] === $source;
            return $scope_matches && $source_matches;
        }));

        $this->output->writeln('Atomic Routes');
        $this->output->writeln('Usage: php atomic ' . CommandCatalog::display('routes'));
        $this->output->writeln();

        if ($routes === []) {
            $this->output->writeln('  (no routes)');
            return;
        }

        foreach ($this->group_routes($routes) as $group) {
            $this->output->section($group['title']);
            $this->output->aligned_rows(array_map(
                static fn(array $route): array => [$route['method'], $route['path'], $route['handler']],
                $group['routes'],
            ));
            $this->output->writeln();
        }
    }

    /**
     * @param list<array{type: string, source: string, method: string, path: string, handler: string}> $routes
     * @return list<array{title: string, routes: list<array{type: string, source: string, method: string, path: string, handler: string}>}>
     */
    private function group_routes(array $routes): array
    {
        $groups = [];
        foreach ($routes as $route) {
            $key = $route['type'] . ':' . $route['source'];
            $groups[$key] ??= [
                'title' => (self::ROUTE_SCOPE_LABELS[$route['type']] ?? ucfirst($route['type']))
                    . ' ' . ucfirst($route['source']) . ' Routes',
                'routes' => [],
            ];
            $groups[$key]['routes'][] = $route;
        }
        return array_values($groups);
    }

    private function load_route_scope(string $scope): void
    {
        $loader = RouteLoader::instance();
        $types = $scope === self::ROUTE_SCOPE_ALL ? $loader->get_route_types() : [$scope];
        foreach ($types as $type) {
            if ($loader->has_route_type($type)) {
                $this->atomic->register_routes_for($type);
            }
        }
    }

    /** @return list<array{type: string, source: string, method: string, path: string, handler: string}> */
    private function registered_routes(): array
    {
        $result = [];
        foreach ((array)$this->atomic->get('ROUTES', []) as $path => $route_types) {
            foreach ((array)$route_types as $request_type => $methods) {
                foreach ((array)$methods as $method => $definition) {
                    $handler = is_array($definition) ? ($definition[0] ?? '') : $definition;
                    $handler = is_string($handler) ? $handler : get_debug_type($handler);
                    $result[] = [
                        'type' => $this->route_type((string)$path, (int)$request_type),
                        'source' => $this->route_source($handler),
                        'method' => strtoupper((string)$method),
                        'path' => '/' . ltrim((string)$path, '/'),
                        'handler' => $handler,
                    ];
                }
            }
        }

        foreach ((array)$this->atomic->get('WS_ROUTES', []) as $path => $definition) {
            $handler = is_array($definition) ? ($definition['handler'] ?? '') : '';
            $handler = is_string($handler) ? $handler : get_debug_type($handler);
            $result[] = [
                'type' => self::ROUTE_SCOPE_WEBSOCKET,
                'source' => $this->route_source($handler),
                'method' => 'MESSAGE',
                'path' => '/' . ltrim((string)$path, '/'),
                'handler' => $handler,
            ];
        }

        usort($result, static fn(array $a, array $b): int => [$a['type'], $a['path'], $a['method']] <=> [$b['type'], $b['path'], $b['method']]);
        return $result;
    }

    private function route_type(string $path, int $request_type): string
    {
        if ($request_type === \Base::REQ_CLI) {
            return self::ROUTE_SCOPE_CLI;
        }

        $segment = strtolower(explode('/', ltrim($path, '/'))[0] ?? '');
        return in_array($segment, [self::ROUTE_SCOPE_API, self::ROUTE_SCOPE_TELEMETRY], true)
            ? $segment
            : self::ROUTE_SCOPE_WEB;
    }

    private function route_source(string $handler): string
    {
        if (str_starts_with($handler, 'App\\')) {
            return self::ROUTE_SOURCE_APP;
        }
        if (str_starts_with($handler, 'Engine\\Atomic\\Plugins\\')) {
            return self::ROUTE_SOURCE_PLUGIN;
        }
        return str_starts_with($handler, 'Engine\\Atomic\\')
            ? self::ROUTE_SOURCE_FRAMEWORK
            : self::ROUTE_SOURCE_PLUGIN;
    }

    /**
     * @param list<string> $args
     * @return array{0: string, 1: string}|null
     */
    private function route_filters(array $args): ?array
    {
        $scopes = $this->route_scopes();
        $scope = self::ROUTE_SCOPE_ALL;
        $source = self::ROUTE_SOURCE_ALL;

        foreach ($args as $arg) {
            $arg = strtolower(trim($arg));
            if ($arg === '' || $arg === '--') {
                continue;
            }

            if (str_starts_with($arg, '--scope=')) {
                $arg = substr($arg, 8);
            } elseif (str_starts_with($arg, '--source=')) {
                $arg = substr($arg, 9);
                if (!in_array($arg, self::ROUTE_SOURCES, true)) {
                    $this->invalid_route_filter($arg, 'source');
                    return null;
                }
                $source = $arg;
                continue;
            }

            if (in_array($arg, ['ws'], true)) {
                $arg = self::ROUTE_SCOPE_WEBSOCKET;
            }

            if (in_array($arg, $scopes, true)) {
                if ($scope !== self::ROUTE_SCOPE_ALL) {
                    $this->invalid_route_filter($arg, 'scope');
                    return null;
                }
                $scope = $arg;
                continue;
            }

            if (in_array($arg, self::ROUTE_SOURCES, true)) {
                if ($source !== self::ROUTE_SOURCE_ALL) {
                    $this->invalid_route_filter($arg, 'source');
                    return null;
                }
                $source = $arg;
                continue;
            }

            $this->invalid_route_filter($arg, 'scope or source');
            return null;
        }

        return [$scope, $source];
    }

    private function invalid_route_filter(string $value, string $kind): void
    {
        $this->output->failure("Unknown route {$kind} '{$value}'.");
        $this->output->usage('routes');
        $this->write_route_commands(true);
    }

    private function write_route_commands(bool $error = false, int $command_width = 0): void
    {
        if ($error) {
            $this->output->error_list('Available Route Commands:', []);
            $this->output->aligned_rows($this->route_command_rows(), error: true);
            return;
        }

        $this->output->section('Available Route Commands');
        $this->output->aligned_rows($this->route_command_rows(), [$command_width]);
    }

    /** @return list<array{0: string, 1: string}> */
    private function route_command_rows(): array
    {
        $descriptions = [
            self::ROUTE_SCOPE_ALL => 'List routes from every type and source',
            self::ROUTE_SCOPE_WEB => 'List web routes',
            self::ROUTE_SCOPE_API => 'List API routes',
            self::ROUTE_SCOPE_CLI => 'List CLI routes',
            self::ROUTE_SCOPE_WEBSOCKET => 'List WebSocket routes',
            self::ROUTE_SCOPE_TELEMETRY => 'List telemetry routes',
        ];
        $rows = [['php atomic routes', 'List routes from every type and source']];
        foreach ($this->route_scopes() as $scope) {
            $rows[] = ['php atomic routes/' . $scope, $descriptions[$scope] ?? "List {$scope} routes"];
        }

        $source_descriptions = [
            self::ROUTE_SOURCE_FRAMEWORK => 'framework',
            self::ROUTE_SOURCE_CUSTOM => 'custom application and plugin',
            self::ROUTE_SOURCE_APP => 'application',
            self::ROUTE_SOURCE_PLUGIN => 'plugin',
        ];
        foreach ($source_descriptions as $source => $description) {
            $rows[] = [
                'php atomic routes/all/' . $source,
                "List {$description} routes from every type",
            ];
        }

        return $rows;
    }

    /** @return list<string> */
    private function route_scopes(): array
    {
        return array_values(array_unique(array_merge(
            [
                self::ROUTE_SCOPE_ALL,
                self::ROUTE_SCOPE_WEB,
                self::ROUTE_SCOPE_API,
                self::ROUTE_SCOPE_CLI,
                self::ROUTE_SCOPE_WEBSOCKET,
                self::ROUTE_SCOPE_TELEMETRY,
            ],
            RouteLoader::instance()->get_route_types(),
        )));
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
