<?php
declare(strict_types=1);
namespace Engine\Atomic\Core;
if (!defined('ATOMIC_START')) exit;

class RouteLoader extends \Prefab
{
    protected string $framework_routes_path = '';
    protected string $app_routes_path = '';

    protected const DEFAULT_ROUTE_TYPE_MAP = [
        'web'       => ['web.php', 'web.error.php'],
        'api'       => ['api.php'],
        'cli'       => ['cli.php'],
        'telemetry' => ['telemetry.php'],
    ];

    /** @var array<string, array<int, string>> */
    protected array $route_type_map = self::DEFAULT_ROUTE_TYPE_MAP;

    public function configure_paths(string $framework_routes_path, string $app_routes_path): self
    {
        $this->framework_routes_path = $framework_routes_path;
        $this->app_routes_path = $app_routes_path;
        return $this;
    }

    public function get_filenames_for(string $request_type): array
    {
        $request_type = strtolower(trim($request_type));

        if (!isset($this->route_type_map[$request_type])) {
            throw new \InvalidArgumentException(
                "Invalid request type: '{$request_type}'. "
                . "Valid types: " . implode(', ', array_keys($this->route_type_map))
            );
        }

        return $this->route_type_map[$request_type];
    }

    public function get_files_for(string $request_type): array
    {
        $request_type = strtolower(trim($request_type));

        $file_names = $this->get_filenames_for($request_type);
        $files = [];

        foreach ($file_names as $file_name) {
            $path = $this->framework_routes_path . $file_name;
            if (file_exists($path)) {
                $files[] = $path;
            }
        }

        foreach ($file_names as $file_name) {
            $path = $this->app_routes_path . $file_name;
            if (file_exists($path)) {
                $files[] = $path;
            }
        }

        return $files;
    }

    public function register_route_type(string $request_type, array|string $file_names): self
    {
        $request_type = strtolower(trim($request_type));
        if ($request_type === '') {
            throw new \InvalidArgumentException('Route type cannot be empty.');
        }

        $file_names = is_array($file_names) ? $file_names : [$file_names];
        $file_names = array_values(array_filter(array_map(static function ($file_name): string {
            return trim((string)$file_name);
        }, $file_names), static fn(string $file_name): bool => $file_name !== ''));

        if ($file_names === []) {
            throw new \InvalidArgumentException("Route type '{$request_type}' must define at least one file.");
        }

        $this->route_type_map[$request_type] = $file_names;
        return $this;
    }

    public function has_route_type(string $request_type): bool
    {
        return isset($this->route_type_map[strtolower(trim($request_type))]);
    }

    public function get_route_types(): array
    {
        return array_keys($this->route_type_map);
    }
}
