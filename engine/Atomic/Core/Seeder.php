<?php
declare(strict_types=1);
namespace Engine\Atomic\Core;

use Engine\Atomic\CLI\Console\Output;

class Seeder
{
    public static function run(string $source_path): void {
        $out = new Output();
        $resolved_source_path = realpath($source_path);
        if ($resolved_source_path === false || !is_file($resolved_source_path) || !is_readable($resolved_source_path)) {
            $out->err("Source file not found or not readable: {$source_path}");
            return;
        }
        $seed = require $resolved_source_path;
        if (!isset($seed['run']) || !is_callable($seed['run'])) {
            $out->err("Invalid seed file: 'run' function not found.");
            return;
        }
        try {
            $seed['run']();
        } catch (\Exception $e) {
            $out->err("Error executing seed from file '{$source_path}': " . $e->getMessage());
        }
    }
}