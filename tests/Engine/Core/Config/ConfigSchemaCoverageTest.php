<?php
declare(strict_types=1);

namespace Tests\Engine\Core\Config;

use PHPUnit\Framework\TestCase;

/**
 * The bootstrap ConfigSchema block must be the single source of truth:
 * every key documented in .env.example needs a definition, and defaults must
 * match the documented values (П-17, П-18).
 */
final class ConfigSchemaCoverageTest extends TestCase
{
    private function bootstrapSource(): string
    {
        return (string)file_get_contents(ATOMIC_DIR . DIRECTORY_SEPARATOR . 'packages' . DIRECTORY_SEPARATOR . 'skeleton' . DIRECTORY_SEPARATOR . 'bootstrap' . DIRECTORY_SEPARATOR . 'app.php');
    }

    /** @return array<int, array{0: string, 1: string}> key → env value */
    private function envKeys(): array
    {
        $keys = [];
        foreach (file(ATOMIC_DIR . DIRECTORY_SEPARATOR . 'packages' . DIRECTORY_SEPARATOR . 'skeleton' . DIRECTORY_SEPARATOR . '.env.example', FILE_IGNORE_NEW_LINES) as $line) {
            if (preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/', trim($line), $m)) {
                $keys[] = [$m[1], trim($m[2])];
            }
        }
        return $keys;
    }

    public function test_every_env_example_key_has_schema_definition(): void
    {
        $source = $this->bootstrapSource();

        $missing = [];
        foreach ($this->envKeys() as [$key]) {
            if (!preg_match('/ConfigSchema::(?:string|int|bool|float|array|csv|enum)\(\'' . preg_quote($key, '/') . '\'/', $source)) {
                $missing[] = $key;
            }
        }

        $this->assertSame([], $missing, 'Keys missing ConfigSchema definitions in bootstrap/app.php');
    }

    public function test_schema_defaults_match_env_example_values(): void
    {
        $source = $this->bootstrapSource();

        $mismatches = [];
        foreach ($this->envKeys() as [$key, $envValue]) {
            if (!preg_match('/ConfigSchema::\w+\(\'' . preg_quote($key, '/') . '\'\)->default\((.+?)\);/', $source, $m)) {
                continue; // required / nullable keys have no default — nothing to compare
            }
            $defaultLiteral = trim($m[1]);

            if ($defaultLiteral === 'true' || $defaultLiteral === 'false') {
                $expected = filter_var($envValue, FILTER_VALIDATE_BOOLEAN);
                if ($expected !== ($defaultLiteral === 'true')) {
                    $mismatches[] = "{$key}: schema={$defaultLiteral} env={$envValue}";
                }
                continue;
            }

            if (is_numeric($defaultLiteral)) {
                if ((string)$defaultLiteral !== $envValue) {
                    $mismatches[] = "{$key}: schema={$defaultLiteral} env={$envValue}";
                }
                continue;
            }

            $defaultValue = trim($defaultLiteral, "'");
            if ($defaultValue !== $envValue) {
                $mismatches[] = "{$key}: schema='{$defaultValue}' env='{$envValue}'";
            }
        }

        $this->assertSame([], $mismatches, 'Schema defaults must match .env.example');
    }
}
