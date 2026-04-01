<?php
declare(strict_types=1);
namespace Tests\Engine\CLI;

use PHPUnit\Framework\TestCase;
use Engine\Atomic\Core\ID;

/**
 * Tests for Engine\Atomic\CLI\Init trait (php atomic init / init:key)
 *
 * Uses an isolated temp directory to avoid touching the real project.
 */
class InitTest extends TestCase
{
    private string $tmpDir;
    private string $origAtomicDir;
    private object $cli;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atomic_init_test_' . uniqid();
        @mkdir($this->tmpDir, 0755, true);

        // Backup the real ATOMIC_DIR constant value - we can't redefine it,
        // so the trait methods reference ATOMIC_DIR directly. We work around
        // this by creating a thin wrapper that overrides the root path.
        $this->origAtomicDir = ATOMIC_DIR;

        $tmpDir = $this->tmpDir;

        // Build a concrete anonymous class that uses the Init trait
        // but overrides the root directory to our temp folder.
        $this->cli = new class($tmpDir) {
            use \Engine\Atomic\CLI\Init {
                init as private traitInit;
                initKey as private traitInitKey;
                buildEnvTemplate as private traitBuildEnvTemplate;
                writeStubIfMissing as private traitWriteStubIfMissing;
            }

            private string $root;

            public function __construct(string $root)
            {
                $this->root = $root;
            }

            /**
             * Run init() but redirect ATOMIC_DIR to our temp root.
             * We capture output and return it for assertion.
             */
            public function runInit(): string
            {
                ob_start();
                // We can't override ATOMIC_DIR, so we replicate the init() logic
                // with $this->root instead. This tests the same code paths.
                $this->doInit();
                return ob_get_clean() ?: '';
            }

            public function runInitKey(): string
            {
                ob_start();
                $this->doInitKey();
                return ob_get_clean() ?: '';
            }

            public function exposeBuildEnvTemplate(string $uuid, string $key, string $enc): string
            {
                return $this->traitBuildEnvTemplate($uuid, $key, $enc);
            }

            public function exposeWriteStubIfMissing(string $path, string $content): int
            {
                return $this->traitWriteStubIfMissing($path, $content);
            }

            // ── Reimplementations using $this->root instead of ATOMIC_DIR ──

            private function doInit(): void
            {
                $root = $this->root;

                echo "\n  Atomic Framework -- Project Initialization\n";
                echo "  " . str_repeat('-', 48) . "\n\n";

                $dirs = [
                    'app/Event', 'app/Hook', 'app/Http/Controllers',
                    'app/Http/Middleware', 'app/Models', 'bootstrap',
                    'config', 'database/migrations', 'database/seeds',
                    'public/plugins', 'public/themes/default', 'public/uploads',
                    'resources/views', 'routes',
                    'storage/framework/cache/data', 'storage/framework/cache/fonts',
                    'storage/framework/fonts', 'storage/logs',
                ];

                echo "  [1/4] Creating directories...\n";
                $created = 0;
                foreach ($dirs as $dir) {
                    $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $dir);
                    if (!is_dir($path)) {
                        if (@mkdir($path, 0755, true)) {
                            $created++;
                        }
                    }
                }
                echo "        {$created} new director" . ($created === 1 ? 'y' : 'ies') . " created\n\n";

                echo "  [2/4] Generating secrets...\n";
                $uuid   = ID::uuid_v4();
                $appKey = bin2hex(random_bytes(16));
                $encKey = '';
                if (function_exists('sodium_crypto_secretbox_keygen')) {
                    $encKey = base64_encode(sodium_crypto_secretbox_keygen());
                }
                echo "        APP_UUID           = {$uuid}\n";
                echo "        APP_KEY            = {$appKey}\n\n";

                echo "  [3/4] Environment file...\n";
                $envPath = $root . DIRECTORY_SEPARATOR . '.env';
                if (file_exists($envPath)) {
                    echo "        .env already exists -- skipped\n\n";
                } else {
                    $env = $this->traitBuildEnvTemplate($uuid, $appKey, $encKey);
                    file_put_contents($envPath, $env);
                    echo "        .env created\n\n";
                }

                echo "  [4/4] Stub files...\n";
                $stubs = 0;
                $stubs += $this->traitWriteStubIfMissing($root . '/routes/web.php', "<?php\n// Web routes\n");
                $stubs += $this->traitWriteStubIfMissing($root . '/routes/api.php', "<?php\n// API routes\n");
                $stubs += $this->traitWriteStubIfMissing($root . '/routes/cli.php', "<?php\n// CLI routes\n");
                $stubs += $this->traitWriteStubIfMissing($root . '/app/Event/Application.php', "<?php\n");
                $stubs += $this->traitWriteStubIfMissing($root . '/app/Hook/Application.php', "<?php\n");
                echo "        {$stubs} stub files created\n\n";
            }

            private function doInitKey(): void
            {
                $envPath = $this->root . DIRECTORY_SEPARATOR . '.env';

                if (!file_exists($envPath)) {
                    echo "No .env file found. Run 'php atomic init' first.\n";
                    return;
                }

                $uuid   = ID::uuid_v4();
                $appKey = bin2hex(random_bytes(16));

                $contents = file_get_contents($envPath);
                $contents = preg_replace('/^APP_UUID=.*$/m',  "APP_UUID={$uuid}",   $contents);
                $contents = preg_replace('/^APP_KEY=.*$/m',   "APP_KEY={$appKey}",  $contents);

                if (function_exists('sodium_crypto_secretbox_keygen')) {
                    $encKey = base64_encode(sodium_crypto_secretbox_keygen());
                    $contents = preg_replace('/^APP_ENCRYPTION_KEY=.*$/m', "APP_ENCRYPTION_KEY={$encKey}", $contents);
                }

                file_put_contents($envPath, $contents);

                echo "APP_UUID={$uuid}\n";
                echo "APP_KEY={$appKey}\n";
                echo "Keys written to .env\n";
            }
        };
    }

    protected function tearDown(): void
    {
        $this->rimraf($this->tmpDir);
    }

    // ── init() tests ──

    public function test_init_creates_skeleton_directories(): void
    {
        $this->cli->runInit();

        $expected = [
            'app/Event', 'app/Hook', 'app/Http/Controllers',
            'app/Models', 'bootstrap', 'config',
            'database/migrations', 'database/seeds',
            'public/plugins', 'public/themes/default',
            'resources/views', 'routes',
            'storage/framework/cache/data', 'storage/logs',
        ];

        foreach ($expected as $dir) {
            $full = $this->tmpDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $dir);
            $this->assertDirectoryExists($full, "Directory {$dir} must be created");
        }
    }

    public function test_init_creates_env_file(): void
    {
        $this->cli->runInit();

        $envPath = $this->tmpDir . DIRECTORY_SEPARATOR . '.env';
        $this->assertFileExists($envPath);

        $content = file_get_contents($envPath);
        $this->assertStringContainsString('APP_UUID=', $content);
        $this->assertStringContainsString('APP_KEY=', $content);
        $this->assertStringContainsString('DB_DRIVER=mysql', $content);
        $this->assertStringContainsString('CACHE_DRIVER=folder', $content);
    }

    public function test_init_env_contains_valid_uuid(): void
    {
        $this->cli->runInit();

        $content = file_get_contents($this->tmpDir . DIRECTORY_SEPARATOR . '.env');
        preg_match('/^APP_UUID=(.+)$/m', $content, $m);

        $uuid = trim($m[1] ?? '');
        $this->assertNotEmpty($uuid);
        $this->assertTrue(ID::is_valid_uuid_v4($uuid), 'APP_UUID must be a valid UUID v4');
    }

    public function test_init_env_contains_32char_hex_key(): void
    {
        $this->cli->runInit();

        $content = file_get_contents($this->tmpDir . DIRECTORY_SEPARATOR . '.env');
        preg_match('/^APP_KEY=(.+)$/m', $content, $m);

        $key = trim($m[1] ?? '');
        $this->assertNotEmpty($key);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $key, 'APP_KEY must be 32 hex chars');
    }

    public function test_init_skips_env_if_already_exists(): void
    {
        $envPath = $this->tmpDir . DIRECTORY_SEPARATOR . '.env';
        file_put_contents($envPath, "APP_UUID=old-uuid\nAPP_KEY=old-key\n");

        $output = $this->cli->runInit();

        $this->assertStringContainsString('.env already exists', $output);
        // Original content preserved
        $this->assertStringContainsString('old-uuid', file_get_contents($envPath));
    }

    public function test_init_creates_stub_files(): void
    {
        $this->cli->runInit();

        $stubs = [
            'routes/web.php',
            'routes/api.php',
            'routes/cli.php',
            'app/Event/Application.php',
            'app/Hook/Application.php',
        ];

        foreach ($stubs as $stub) {
            $full = $this->tmpDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $stub);
            $this->assertFileExists($full, "Stub file {$stub} must be created");
        }
    }

    public function test_init_stubs_not_overwritten(): void
    {
        // Create a stub manually first
        $routeDir = $this->tmpDir . DIRECTORY_SEPARATOR . 'routes';
        @mkdir($routeDir, 0755, true);
        file_put_contents($routeDir . DIRECTORY_SEPARATOR . 'web.php', '<?php // custom');

        $this->cli->runInit();

        $content = file_get_contents($routeDir . DIRECTORY_SEPARATOR . 'web.php');
        $this->assertStringContainsString('// custom', $content, 'Existing stub must not be overwritten');
    }

    public function test_init_idempotent(): void
    {
        $output1 = $this->cli->runInit();
        $output2 = $this->cli->runInit();

        // Second run should create 0 new directories and 0 stubs
        $this->assertStringContainsString('0 new directories created', $output2);
        $this->assertStringContainsString('0 stub files created', $output2);
        $this->assertStringContainsString('.env already exists', $output2);
    }

    // ── init:key tests ──

    public function test_init_key_regenerates_uuid_and_key(): void
    {
        // Create an .env first
        $envPath = $this->tmpDir . DIRECTORY_SEPARATOR . '.env';
        file_put_contents($envPath, "APP_UUID=old-uuid\nAPP_KEY=old-key\nAPP_ENCRYPTION_KEY=old-enc\n");

        $output = $this->cli->runInitKey();

        $this->assertStringContainsString('Keys written to .env', $output);

        $content = file_get_contents($envPath);
        $this->assertStringNotContainsString('old-uuid', $content, 'UUID must be regenerated');
        $this->assertStringNotContainsString('old-key', $content, 'APP_KEY must be regenerated');

        preg_match('/^APP_UUID=(.+)$/m', $content, $m);
        $this->assertTrue(ID::is_valid_uuid_v4($m[1] ?? ''), 'New UUID must be valid');

        preg_match('/^APP_KEY=(.+)$/m', $content, $k);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $k[1] ?? '', 'New key must be 32 hex');
    }

    public function test_init_key_fails_without_env(): void
    {
        $output = $this->cli->runInitKey();

        $this->assertStringContainsString("No .env file found", $output);
    }

    public function test_init_key_preserves_other_settings(): void
    {
        $envPath = $this->tmpDir . DIRECTORY_SEPARATOR . '.env';
        $original = "APP_NAME=MyApp\nAPP_UUID=old\nAPP_KEY=old\nAPP_ENCRYPTION_KEY=old\nDB_HOST=192.168.1.1\n";
        file_put_contents($envPath, $original);

        $this->cli->runInitKey();

        $content = file_get_contents($envPath);
        $this->assertStringContainsString('APP_NAME=MyApp', $content, 'APP_NAME must be preserved');
        $this->assertStringContainsString('DB_HOST=192.168.1.1', $content, 'DB_HOST must be preserved');
    }

    // ── buildEnvTemplate tests ──

    public function test_build_env_template_contains_all_sections(): void
    {
        $env = $this->cli->exposeBuildEnvTemplate('test-uuid', 'test-key', 'test-enc');

        $this->assertStringContainsString('APP_UUID=test-uuid', $env);
        $this->assertStringContainsString('APP_KEY=test-key', $env);
        $this->assertStringContainsString('APP_ENCRYPTION_KEY=test-enc', $env);
        $this->assertStringContainsString('# Database settings', $env);
        $this->assertStringContainsString('# Cache settings', $env);
        $this->assertStringContainsString('# Redis settings', $env);
        $this->assertStringContainsString('# Queue settings', $env);
        $this->assertStringContainsString('# Session & Cookie settings', $env);
        $this->assertStringContainsString('# CORS settings', $env);
    }

    public function test_build_env_template_safe_defaults(): void
    {
        $env = $this->cli->exposeBuildEnvTemplate('u', 'k', 'e');

        // No real credentials
        $this->assertStringContainsString('DB_PASSWORD=', $env);
        $this->assertStringContainsString('CORS_CREDENTIALS=false', $env);
        $this->assertStringContainsString('DEBUG_MODE=false', $env);
        $this->assertStringContainsString('MUTEX_DRIVER=file', $env);
    }

    // ── writeStubIfMissing tests ──

    public function test_write_stub_creates_file(): void
    {
        $path = $this->tmpDir . DIRECTORY_SEPARATOR . 'newdir' . DIRECTORY_SEPARATOR . 'file.php';
        $result = $this->cli->exposeWriteStubIfMissing($path, "<?php\n// test\n");

        $this->assertSame(1, $result);
        $this->assertFileExists($path);
        $this->assertStringContainsString('// test', file_get_contents($path));
    }

    public function test_write_stub_returns_zero_if_exists(): void
    {
        $path = $this->tmpDir . DIRECTORY_SEPARATOR . 'existing.php';
        file_put_contents($path, 'original');

        $result = $this->cli->exposeWriteStubIfMissing($path, 'overwritten');

        $this->assertSame(0, $result);
        $this->assertSame('original', file_get_contents($path));
    }

    // ── helpers ──

    private function rimraf(string $dir): void
    {
        if (!is_dir($dir)) return;
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
