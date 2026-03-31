<?php
declare(strict_types=1);
namespace Engine\Atomic\Core;

class Seeder
{
    public static function run(string $source_path): void {
        if (!file_exists($source_path) || !is_readable($source_path)) {
            echo "Source file not found or not readable: $source_path\n";
            return;
        }
        $seed = require $source_path;
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