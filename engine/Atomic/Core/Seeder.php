<?php
declare(strict_types=1);
namespace Engine\Atomic\Core;

class Seeder
{
    public static function run(string $source_path): void {
        $resolved_source_path = realpath($source_path);
        if ($resolved_source_path === false || !is_file($resolved_source_path) || !is_readable($resolved_source_path)) {
            echo "Source file not found or not readable: $source_path\n";
            return;
        }
        $seed = require $resolved_source_path;
        if (!isset($seed['run']) || !is_callable($seed['run'])) {
            echo "Invalid seed file: 'run' function not found.\n";
            return;
        }
        try {
            $seed['run']();
        } catch (\Exception $e) {
            echo "Error executing seed from file '$source_path': " . $e->getMessage() . "\n";
        }
    }
}