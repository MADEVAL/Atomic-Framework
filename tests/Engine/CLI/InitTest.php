<?php
declare(strict_types=1);
namespace Tests\Engine\CLI;

use PHPUnit\Framework\TestCase;
use Engine\Atomic\CLI\Console\Output;
use Engine\Atomic\CLI\Console\Input;
use Engine\Atomic\Core\ID;

class InitTest extends TestCase
{
    private string $tmpDir;
    private object $cli;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atomic_init_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);

        // Memory streams: non-TTY stdin → isInteractive() = false (no prompts issued)
        $stdout = fopen('php://memory', 'r+');
        $stderr = fopen('php://memory', 'r+');
        $stdin  = fopen('php://memory', 'r');

        $output = new Output($stdout, $stderr);
        $input  = new Input($output, $stdin);

        $this->cli = new class($output, $input) {
            use \Engine\Atomic\CLI\Init {
                // InitScaffold methods
                createSkeletonDirectories  as public exposeCreateDirs;
                createAppStubs             as public exposeCreateStubs;
                writeStubIfMissing         as public exposeWriteStub;
                generateEncryptionKey      as public exposeGenEncKey;
                // Init.php methods
                readKeysFromEnv            as public exposeReadEnvKeys;
                writeKeysToEnv             as public exposeWriteEnvKeys;
                readKeysFromPhpConfig      as public exposeReadPhpKeys;
                writeKeysToPhpConfig       as public exposeWritePhpKeys;
                areKeysValid               as public exposeAreKeysValid;
                findKeyMismatches          as public exposeFindMismatches;
                synchronizeApplicationKeys as public exposeSyncKeys;
            }

            protected Output $output;
            protected Input  $input;

            public function __construct(Output $output, Input $input)
            {
                $this->output = $output;
                $this->input  = $input;
            }
        };
    }

    protected function tearDown(): void
    {
        $this->rimraf($this->tmpDir);
    }

    // ── Fixtures ──────────────────────────────────────────────────────────────

    private function makeEnv(array $values): void
    {
        $content = '';
        foreach ($values as $k => $v) {
            $content .= "{$k}={$v}\n";
        }
        file_put_contents($this->tmpDir . DIRECTORY_SEPARATOR . '.env', $content);
    }

    private function makeEnvExample(array $values = []): void
    {
        $content = '';
        foreach ($values as $k => $v) {
            $content .= "{$k}={$v}\n";
        }
        file_put_contents($this->tmpDir . DIRECTORY_SEPARATOR . '.env.example', $content);
    }

    /**
     * Creates config/app.php with keys written in the format
     * writeKeysToPhpConfig's regex expects: 'key' => 'value'
     */
    private function makePhpConfig(array $data): void
    {
        $dir = $this->tmpDir . DIRECTORY_SEPARATOR . 'config';
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        $lines = "<?php\ndeclare(strict_types=1);\n\nreturn [\n";
        foreach ($data as $k => $v) {
            $lines .= "    '{$k}' => '{$v}',\n";
        }
        $lines .= "];\n";
        file_put_contents($dir . DIRECTORY_SEPARATOR . 'app.php', $lines);
    }

    private function validKeys(): array
    {
        return [
            'APP_UUID'           => ID::uuid_v4(),
            'APP_KEY'            => bin2hex(random_bytes(16)),
            'APP_ENCRYPTION_KEY' => base64_encode(random_bytes(32)),
        ];
    }

    // ── createSkeletonDirectories ─────────────────────────────────────────────

    public function test_create_dirs_makes_full_structure(): void
    {
        $this->cli->exposeCreateDirs($this->tmpDir);

        foreach ([
            'app/Event', 'app/Hook', 'app/Http/Controllers', 'app/Http/Middleware',
            'app/Models', 'bootstrap', 'config', 'database/migrations', 'database/seeds',
            'public/plugins', 'public/themes/default', 'public/uploads',
            'resources/views', 'routes',
            'storage/framework/cache/data', 'storage/framework/cache/fonts',
            'storage/framework/fonts', 'storage/logs',
        ] as $dir) {
            $this->assertDirectoryExists(
                $this->tmpDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $dir),
                "Expected directory: {$dir}"
            );
        }
    }

    public function test_create_dirs_returns_count_of_new_dirs(): void
    {
        $count = $this->cli->exposeCreateDirs($this->tmpDir);

        $this->assertGreaterThan(0, $count);
    }

    public function test_create_dirs_idempotent(): void
    {
        $this->cli->exposeCreateDirs($this->tmpDir);

        $count = $this->cli->exposeCreateDirs($this->tmpDir);

        $this->assertSame(0, $count, 'Second run must report zero new directories');
    }

    // ── createAppStubs ────────────────────────────────────────────────────────

    public function test_create_stubs_makes_all_files(): void
    {
        $this->cli->exposeCreateDirs($this->tmpDir);
        $this->cli->exposeCreateStubs($this->tmpDir);

        foreach ([
            'routes/web.php', 'routes/api.php', 'routes/cli.php',
            'app/Event/Application.php', 'app/Hook/Application.php',
        ] as $stub) {
            $this->assertFileExists(
                $this->tmpDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $stub),
                "Expected stub: {$stub}"
            );
        }
    }

    public function test_create_stubs_returns_correct_count(): void
    {
        $this->cli->exposeCreateDirs($this->tmpDir);

        $count = $this->cli->exposeCreateStubs($this->tmpDir);

        $this->assertSame(5, $count);
    }

    public function test_create_stubs_does_not_overwrite_existing(): void
    {
        $this->cli->exposeCreateDirs($this->tmpDir);
        file_put_contents($this->tmpDir . '/routes/web.php', '<?php // custom');

        $count = $this->cli->exposeCreateStubs($this->tmpDir);

        $this->assertSame(4, $count, 'Existing stub must be skipped');
        $this->assertStringContainsString(
            '// custom',
            file_get_contents($this->tmpDir . '/routes/web.php')
        );
    }

    // ── writeStubIfMissing ────────────────────────────────────────────────────

    public function test_write_stub_creates_file_and_parent_dirs(): void
    {
        $path   = $this->tmpDir . '/deep/nested/file.php';
        $result = $this->cli->exposeWriteStub($path, "<?php\n// hello\n");

        $this->assertSame(1, $result);
        $this->assertFileExists($path);
        $this->assertStringContainsString('// hello', file_get_contents($path));
    }

    public function test_write_stub_returns_zero_and_preserves_existing(): void
    {
        $path = $this->tmpDir . '/existing.php';
        file_put_contents($path, 'original');

        $result = $this->cli->exposeWriteStub($path, 'overwritten');

        $this->assertSame(0, $result);
        $this->assertSame('original', file_get_contents($path));
    }

    // ── generateEncryptionKey ─────────────────────────────────────────────────

    public function test_generate_enc_key_returns_valid_base64_sodium_key(): void
    {
        $key = $this->cli->exposeGenEncKey();

        if (!function_exists('sodium_crypto_secretbox_keygen')) {
            $this->assertSame('', $key, 'Empty string expected without sodium');
            return;
        }

        $this->assertNotEmpty($key);
        $decoded = base64_decode($key, true);
        $this->assertNotFalse($decoded, 'Must be valid base64');
        $this->assertSame(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, strlen($decoded));
    }

    // ── areKeysValid ──────────────────────────────────────────────────────────

    public function test_keys_valid_with_real_values(): void
    {
        $this->assertTrue($this->cli->exposeAreKeysValid($this->validKeys()));
    }

    public function test_keys_invalid_when_uuid_empty(): void
    {
        $keys = $this->validKeys();
        $keys['APP_UUID'] = '';

        $this->assertFalse($this->cli->exposeAreKeysValid($keys));
    }

    public function test_keys_invalid_when_app_key_empty(): void
    {
        $keys = $this->validKeys();
        $keys['APP_KEY'] = '';

        $this->assertFalse($this->cli->exposeAreKeysValid($keys));
    }

    public function test_keys_invalid_when_enc_key_empty(): void
    {
        $keys = $this->validKeys();
        $keys['APP_ENCRYPTION_KEY'] = '';

        $this->assertFalse($this->cli->exposeAreKeysValid($keys));
    }

    public function test_keys_invalid_with_known_placeholder_values(): void
    {
        $this->assertFalse($this->cli->exposeAreKeysValid([
            'APP_UUID'           => 'your-uuid-here',
            'APP_KEY'            => 'default-key',
            'APP_ENCRYPTION_KEY' => 'your-encryption-key-here',
        ]));
    }

    public function test_keys_invalid_with_your_key_here_placeholder(): void
    {
        $keys = $this->validKeys();
        $keys['APP_KEY'] = 'your-key-here';

        $this->assertFalse($this->cli->exposeAreKeysValid($keys));
    }

    // ── findKeyMismatches ─────────────────────────────────────────────────────

    public function test_no_mismatches_when_both_sets_equal(): void
    {
        $keys   = $this->validKeys();
        $result = $this->cli->exposeFindMismatches($keys, $keys);

        $this->assertEmpty($result);
    }

    public function test_finds_mismatched_keys(): void
    {
        $a = $this->validKeys();
        $b = $this->validKeys(); // independently generated → all differ

        $result = $this->cli->exposeFindMismatches($a, $b);

        $this->assertArrayHasKey('APP_UUID', $result);
        $this->assertArrayHasKey('APP_KEY',  $result);
    }

    public function test_finds_only_the_differing_key(): void
    {
        $a = $this->validKeys();
        $b = $a;
        $b['APP_KEY'] = bin2hex(random_bytes(16));

        $result = $this->cli->exposeFindMismatches($a, $b);

        $this->assertArrayHasKey('APP_KEY', $result);
        $this->assertArrayNotHasKey('APP_UUID', $result);
        $this->assertArrayNotHasKey('APP_ENCRYPTION_KEY', $result);
    }

    // ── readKeysFromEnv / writeKeysToEnv ──────────────────────────────────────

    public function test_read_env_keys_returns_empty_strings_when_no_file(): void
    {
        $result = $this->cli->exposeReadEnvKeys($this->tmpDir);

        $this->assertSame('', $result['APP_UUID']);
        $this->assertSame('', $result['APP_KEY']);
        $this->assertSame('', $result['APP_ENCRYPTION_KEY']);
    }

    public function test_read_env_keys_parses_all_three_fields(): void
    {
        $keys = $this->validKeys();
        $this->makeEnv($keys);

        $result = $this->cli->exposeReadEnvKeys($this->tmpDir);

        $this->assertSame($keys['APP_UUID'],           $result['APP_UUID']);
        $this->assertSame($keys['APP_KEY'],            $result['APP_KEY']);
        $this->assertSame($keys['APP_ENCRYPTION_KEY'], $result['APP_ENCRYPTION_KEY']);
    }

    public function test_write_env_keys_updates_existing_lines(): void
    {
        $this->makeEnv(['APP_UUID' => 'old-uuid', 'APP_KEY' => 'old-key', 'APP_ENCRYPTION_KEY' => 'old-enc']);
        $new = $this->validKeys();

        $this->cli->exposeWriteEnvKeys($this->tmpDir, $new);

        $result = $this->cli->exposeReadEnvKeys($this->tmpDir);
        $this->assertSame($new['APP_UUID'],           $result['APP_UUID']);
        $this->assertSame($new['APP_KEY'],            $result['APP_KEY']);
        $this->assertSame($new['APP_ENCRYPTION_KEY'], $result['APP_ENCRYPTION_KEY']);
    }

    public function test_write_env_keys_appends_missing_fields(): void
    {
        $this->makeEnv(['APP_NAME' => 'MyApp']); // no key fields

        $new = $this->validKeys();
        $this->cli->exposeWriteEnvKeys($this->tmpDir, $new);

        $content = file_get_contents($this->tmpDir . '/.env');
        $this->assertStringContainsString('APP_NAME=MyApp', $content, 'Existing field preserved');
        $this->assertStringContainsString('APP_UUID=' . $new['APP_UUID'], $content);
    }

    public function test_write_env_keys_noop_when_no_env_or_example(): void
    {
        $this->cli->exposeWriteEnvKeys($this->tmpDir, $this->validKeys());

        $this->assertFileDoesNotExist($this->tmpDir . '/.env');
    }

    public function test_write_env_keys_copies_example_when_env_missing(): void
    {
        $this->makeEnvExample([
            'APP_UUID' => '', 'APP_KEY' => '', 'APP_ENCRYPTION_KEY' => '',
            'DB_HOST'  => '127.0.0.1',
        ]);
        $new = $this->validKeys();

        $this->cli->exposeWriteEnvKeys($this->tmpDir, $new);

        $envPath = $this->tmpDir . '/.env';
        $this->assertFileExists($envPath);
        $content = file_get_contents($envPath);
        $this->assertStringContainsString('DB_HOST=127.0.0.1', $content, '.env.example content preserved');
        $this->assertStringContainsString('APP_UUID=' . $new['APP_UUID'], $content);
    }

    // ── readKeysFromPhpConfig / writeKeysToPhpConfig ──────────────────────────

    public function test_read_php_keys_returns_empty_strings_when_no_file(): void
    {
        $result = $this->cli->exposeReadPhpKeys($this->tmpDir);

        $this->assertSame('', $result['APP_UUID']);
        $this->assertSame('', $result['APP_KEY']);
        $this->assertSame('', $result['APP_ENCRYPTION_KEY']);
    }

    public function test_read_php_keys_parses_config_array(): void
    {
        $keys = $this->validKeys();
        $this->makePhpConfig([
            'uuid'           => $keys['APP_UUID'],
            'key'            => $keys['APP_KEY'],
            'encryption_key' => $keys['APP_ENCRYPTION_KEY'],
        ]);

        $result = $this->cli->exposeReadPhpKeys($this->tmpDir);

        $this->assertSame($keys['APP_UUID'],           $result['APP_UUID']);
        $this->assertSame($keys['APP_KEY'],            $result['APP_KEY']);
        $this->assertSame($keys['APP_ENCRYPTION_KEY'], $result['APP_ENCRYPTION_KEY']);
    }

    public function test_write_php_keys_updates_config_file(): void
    {
        $old = $this->validKeys();
        $this->makePhpConfig([
            'uuid'           => $old['APP_UUID'],
            'key'            => $old['APP_KEY'],
            'encryption_key' => $old['APP_ENCRYPTION_KEY'],
        ]);
        $new = $this->validKeys();

        $this->cli->exposeWritePhpKeys($this->tmpDir, $new);

        $content = file_get_contents($this->tmpDir . '/config/app.php');
        $this->assertStringContainsString("'uuid' => '{$new['APP_UUID']}'",           $content);
        $this->assertStringContainsString("'key' => '{$new['APP_KEY']}'",             $content);
        $this->assertStringContainsString("'encryption_key' => '{$new['APP_ENCRYPTION_KEY']}'", $content);
    }

    public function test_write_php_keys_does_nothing_when_file_absent(): void
    {
        // No config/app.php — must not throw; just emits a warning via output
        $this->cli->exposeWritePhpKeys($this->tmpDir, $this->validKeys());

        $this->assertFileDoesNotExist($this->tmpDir . '/config/app.php');
    }

    public function test_write_php_keys_preserves_file_when_keys_not_in_config(): void
    {
        $dir = $this->tmpDir . DIRECTORY_SEPARATOR . 'config';
        mkdir($dir, 0755, true);
        // Config file with no matching key entries
        $content = "<?php\ndeclare(strict_types=1);\n\nreturn [\n    'name' => 'MyApp',\n];\n";
        file_put_contents($dir . DIRECTORY_SEPARATOR . 'app.php', $content);

        $this->cli->exposeWritePhpKeys($this->tmpDir, $this->validKeys());

        // File content unchanged — regex found no keys to replace
        $this->assertSame($content, file_get_contents($dir . DIRECTORY_SEPARATOR . 'app.php'));
    }

    // ── synchronizeApplicationKeys ────────────────────────────────────────────

    public function test_sync_generates_new_keys_when_both_sources_empty(): void
    {
        $this->makeEnvExample(['APP_UUID' => '', 'APP_KEY' => '', 'APP_ENCRYPTION_KEY' => '']);
        $this->makePhpConfig(['uuid' => '', 'key' => '', 'encryption_key' => '']);

        $result = $this->cli->exposeSyncKeys($this->tmpDir);

        $this->assertTrue($result);
        $envKeys = $this->cli->exposeReadEnvKeys($this->tmpDir);
        $this->assertTrue(ID::is_valid_uuid_v4($envKeys['APP_UUID']), 'Generated UUID must be valid');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $envKeys['APP_KEY']);
    }

    public function test_sync_copies_env_keys_to_php_when_only_env_valid(): void
    {
        $keys = $this->validKeys();
        $this->makeEnv($keys);
        $this->makePhpConfig(['uuid' => '', 'key' => '', 'encryption_key' => '']);

        $result = $this->cli->exposeSyncKeys($this->tmpDir);

        $this->assertTrue($result);
        $phpKeys = $this->cli->exposeReadPhpKeys($this->tmpDir);
        $this->assertSame($keys['APP_UUID'], $phpKeys['APP_UUID']);
        $this->assertSame($keys['APP_KEY'],  $phpKeys['APP_KEY']);
    }

    public function test_sync_copies_php_keys_to_env_when_only_php_valid(): void
    {
        $keys = $this->validKeys();
        $this->makeEnv(['APP_UUID' => '', 'APP_KEY' => '', 'APP_ENCRYPTION_KEY' => '']);
        $this->makePhpConfig([
            'uuid'           => $keys['APP_UUID'],
            'key'            => $keys['APP_KEY'],
            'encryption_key' => $keys['APP_ENCRYPTION_KEY'],
        ]);

        $result = $this->cli->exposeSyncKeys($this->tmpDir);

        $this->assertTrue($result);
        $envKeys = $this->cli->exposeReadEnvKeys($this->tmpDir);
        $this->assertSame($keys['APP_UUID'], $envKeys['APP_UUID']);
        $this->assertSame($keys['APP_KEY'],  $envKeys['APP_KEY']);
    }

    public function test_sync_noop_when_both_sources_match(): void
    {
        $keys = $this->validKeys();
        $this->makeEnv($keys);
        $this->makePhpConfig([
            'uuid'           => $keys['APP_UUID'],
            'key'            => $keys['APP_KEY'],
            'encryption_key' => $keys['APP_ENCRYPTION_KEY'],
        ]);

        $result = $this->cli->exposeSyncKeys($this->tmpDir);

        $this->assertTrue($result);
        // Keys unchanged
        $this->assertSame($keys['APP_UUID'], $this->cli->exposeReadEnvKeys($this->tmpDir)['APP_UUID']);
    }

    public function test_sync_returns_false_on_mismatch_in_non_interactive_mode(): void
    {
        // Both sources have valid but different keys → mismatch
        // Non-interactive input → handleKeyMismatch returns false
        $a = $this->validKeys();
        $b = $this->validKeys();
        $this->makeEnv($a);
        $this->makePhpConfig([
            'uuid'           => $b['APP_UUID'],
            'key'            => $b['APP_KEY'],
            'encryption_key' => $b['APP_ENCRYPTION_KEY'],
        ]);

        $result = $this->cli->exposeSyncKeys($this->tmpDir);

        $this->assertFalse($result);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function rimraf(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($dir);
    }
}
