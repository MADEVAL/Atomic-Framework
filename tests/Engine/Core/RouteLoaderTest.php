<?php
declare(strict_types=1);

namespace Tests\Engine\Core;

use Engine\Atomic\Core\RouteLoader;
use PHPUnit\Framework\TestCase;

class RouteLoaderTest extends TestCase
{
    private RouteLoader $loader;
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->loader = RouteLoader::instance();
        $this->tmpDir = sys_get_temp_dir() . '/atomic_route_test_' . uniqid();
        mkdir($this->tmpDir . '/framework', 0777, true);
        mkdir($this->tmpDir . '/app', 0777, true);
    }

    protected function tearDown(): void
    {
        $this->remove_dir($this->tmpDir);
    }

    private function remove_dir(string $dir): void
    {
        if (!is_dir($dir)) return;
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->remove_dir($path) : unlink($path);
        }
        rmdir($dir);
    }

    public function test_configure_paths(): void
    {
        $result = $this->loader->configure_paths('/fw/', '/app/');
        $this->assertSame($this->loader, $result);
    }

    public function test_get_files_for_web(): void
    {
        // Create framework route files
        file_put_contents($this->tmpDir . '/framework/web.php', '<?php // web');
        file_put_contents($this->tmpDir . '/framework/web.error.php', '<?php // error');

        $this->loader->configure_paths(
            $this->tmpDir . '/framework/',
            $this->tmpDir . '/app/'
        );

        $files = $this->loader->get_files_for('web');
        $this->assertCount(2, $files);
        $this->assertStringContainsString('web.php', $files[0]);
        $this->assertStringContainsString('web.error.php', $files[1]);
    }

    public function test_get_files_for_api(): void
    {
        file_put_contents($this->tmpDir . '/app/api.php', '<?php // api');

        $this->loader->configure_paths(
            $this->tmpDir . '/framework/',
            $this->tmpDir . '/app/'
        );

        $files = $this->loader->get_files_for('api');
        $this->assertCount(1, $files);
        $this->assertStringContainsString('api.php', $files[0]);
    }

    public function test_get_files_for_cli(): void
    {
        file_put_contents($this->tmpDir . '/framework/cli.php', '<?php // cli');
        file_put_contents($this->tmpDir . '/app/cli.php', '<?php // app cli');

        $this->loader->configure_paths(
            $this->tmpDir . '/framework/',
            $this->tmpDir . '/app/'
        );

        $files = $this->loader->get_files_for('cli');
        $this->assertCount(2, $files);
    }

    public function test_get_files_for_telemetry(): void
    {
        $this->loader->configure_paths(
            $this->tmpDir . '/framework/',
            $this->tmpDir . '/app/'
        );

        $files = $this->loader->get_files_for('telemetry');
        $this->assertCount(0, $files);
    }

    public function test_invalid_request_type(): void
    {
        $this->loader->configure_paths('/fw/', '/app/');
        $this->expectException(\InvalidArgumentException::class);
        $this->loader->get_files_for('invalid');
    }

    public function test_case_insensitive(): void
    {
        $this->loader->configure_paths(
            $this->tmpDir . '/framework/',
            $this->tmpDir . '/app/'
        );

        $files = $this->loader->get_files_for('WEB');
        $this->assertIsArray($files);
    }

    public function test_framework_and_app_files_merged(): void
    {
        file_put_contents($this->tmpDir . '/framework/api.php', '<?php // fw api');
        file_put_contents($this->tmpDir . '/app/api.php', '<?php // app api');

        $this->loader->configure_paths(
            $this->tmpDir . '/framework/',
            $this->tmpDir . '/app/'
        );

        $files = $this->loader->get_files_for('api');
        $this->assertCount(2, $files);
        // Framework files come first
        $this->assertStringContainsString('framework', $files[0]);
        $this->assertStringContainsString('app', $files[1]);
    }
}
