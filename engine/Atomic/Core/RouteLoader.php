<?php
declare(strict_types=1);
namespace Engine\Atomic\Core;
if (!defined('ATOMIC_START')) exit;

class RouteLoader extends \Prefab
{
    protected string $frameworkRoutesPath = '';
    protected string $appRoutesPath = '';

    protected const ROUTE_TYPE_MAP = [
        'web'       => ['web.php', 'web.error.php'],
        'api'       => ['api.php'],
        'cli'       => ['cli.php'],
        'telemetry' => ['telemetry.php'],
    ];

    public function configurePaths(string $frameworkRoutesPath, string $appRoutesPath): self
    {
        $this->frameworkRoutesPath = $frameworkRoutesPath;
        $this->appRoutesPath = $appRoutesPath;
        return $this;
    }

    public function getFilenamesFor(string $requestType): array
    {
        $requestType = strtolower(trim($requestType));

        if (!isset(self::ROUTE_TYPE_MAP[$requestType])) {
            throw new \InvalidArgumentException(
                "Invalid request type: '{$requestType}'. "
                . "Valid types: " . implode(', ', array_keys(self::ROUTE_TYPE_MAP))
            );
        }

        return self::ROUTE_TYPE_MAP[$requestType];
    }

    public function getFilesFor(string $requestType): array
    {
        $requestType = strtolower(trim($requestType));

        if (!isset(self::ROUTE_TYPE_MAP[$requestType])) {
            throw new \InvalidArgumentException(
                "Invalid request type: '{$requestType}'. "
                . "Valid types: " . implode(', ', array_keys(self::ROUTE_TYPE_MAP))
            );
        }

        $fileNames = self::ROUTE_TYPE_MAP[$requestType];
        $files = [];

        foreach ($fileNames as $fileName) {
            $path = $this->frameworkRoutesPath . $fileName;
            if (file_exists($path)) {
                $files[] = $path;
            }
        }

        foreach ($fileNames as $fileName) {
            $path = $this->appRoutesPath . $fileName;
            if (file_exists($path)) {
                $files[] = $path;
            }
        }

        return $files;
    }
}
