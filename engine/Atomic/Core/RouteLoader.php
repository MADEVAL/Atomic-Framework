<?php
declare(strict_types=1);
namespace Engine\Atomic\Core;
if (!defined('ATOMIC_START')) exit;

class RouteLoader extends \Prefab
{
    protected string $framework_routes_path = '';
    protected string $app_routes_path = '';

    protected const ROUTE_TYPE_MAP = [
        'web'       => ['web.php', 'web.error.php'],
        'api'       => ['api.php'],
        'cli'       => ['cli.php'],
        'telemetry' => ['telemetry.php'],
    ];

    public function configure_paths(string $framework_routes_path, string $app_routes_path): self
    {
        $this->framework_routes_path = $framework_routes_path;
        $this->app_routes_path = $app_routes_path;
        return $this;
    }

    public function get_filenames_for(string $request_type): array
    {
        $request_type = strtolower(trim($request_type));

        if (!isset(self::ROUTE_TYPE_MAP[$request_type])) {
            throw new \InvalidArgumentException(
                "Invalid request type: '{$request_type}'. "
                . "Valid types: " . implode(', ', array_keys(self::ROUTE_TYPE_MAP))
            );
        }

        return self::ROUTE_TYPE_MAP[$request_type];
    }

    public function get_files_for(string $request_type): array
    {
        $request_type = strtolower(trim($request_type));

        if (!isset(self::ROUTE_TYPE_MAP[$request_type])) {
            throw new \InvalidArgumentException(
                "Invalid request type: '{$request_type}'. "
                . "Valid types: " . implode(', ', array_keys(self::ROUTE_TYPE_MAP))
            );
        }

        $fileNames = self::ROUTE_TYPE_MAP[$request_type];
        $files = [];

        foreach ($fileNames as $fileName) {
            $path = $this->framework_routes_path . $fileName;
            if (file_exists($path)) {
                $files[] = $path;
            }
        }

        foreach ($fileNames as $fileName) {
            $path = $this->app_routes_path . $fileName;
            if (file_exists($path)) {
                $files[] = $path;
            }
        }

        return $files;
    }
}
