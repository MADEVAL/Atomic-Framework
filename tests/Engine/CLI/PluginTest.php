<?php
declare(strict_types=1);
namespace Tests\Engine\CLI;

use Engine\Atomic\CLI\Console\Input;
use Engine\Atomic\CLI\Console\Output;
use PHPUnit\Framework\TestCase;

class PluginTest extends TestCase
{
    private string $tmp_dir;

    protected function setUp(): void
    {
        $this->tmp_dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atomic_plugin_test_' . uniqid();
        mkdir($this->tmp_dir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->rimraf($this->tmp_dir);
    }

    public function test_plugin_make_creates_user_plugin_scaffold(): void
    {
        $cli = $this->make_cli(['SamplePlugin']);
        $cli->plugin_make();

        $base = $this->tmp_dir . DIRECTORY_SEPARATOR . 'SamplePlugin';

        $this->assertFileExists($base . DIRECTORY_SEPARATOR . 'plugin.php');
        $this->assertFileExists($base . DIRECTORY_SEPARATOR . 'SamplePlugin.php');
        $this->assertFileExists($base . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR . 'api.php');
        $this->assertStringContainsString(
            'PluginManager::instance()->register(new \\App\\Plugins\\SamplePlugin\\SamplePlugin());',
            file_get_contents($base . DIRECTORY_SEPARATOR . 'plugin.php')
        );
    }

    public function test_plugin_make_is_idempotent(): void
    {
        $cli = $this->make_cli(['SamplePlugin']);
        $cli->plugin_make();
        $cli->plugin_make();

        $base = $this->tmp_dir . DIRECTORY_SEPARATOR . 'SamplePlugin';

        $this->assertFileExists($base . DIRECTORY_SEPARATOR . 'plugin.php');
        $this->assertFileExists($base . DIRECTORY_SEPARATOR . 'SamplePlugin.php');
        $this->assertFileExists($base . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR . 'api.php');
    }

    public function test_plugin_make_rejects_invalid_names(): void
    {
        $cli = $this->make_cli(['123-tools']);
        $cli->plugin_make();

        $this->assertFileDoesNotExist($this->tmp_dir . DIRECTORY_SEPARATOR . 'Plugin123Tools' . DIRECTORY_SEPARATOR . 'Plugin123Tools.php');
    }

    private function make_cli(array $args): object
    {
        $stdout = fopen('php://memory', 'r+');
        $stderr = fopen('php://memory', 'r+');
        $stdin  = fopen('php://memory', 'r');

        $output = new Output($stdout, $stderr);
        $input  = new Input($output, $stdin);
        $tmp_dir = $this->tmp_dir;

        return new class($output, $input, $tmp_dir, $args) {
            use \Engine\Atomic\CLI\Plugin;

            protected object $atomic;
            protected Output $output;
            protected Input $input;

            public function __construct(Output $output, Input $input, string $tmp_dir, private array $args)
            {
                $this->output = $output;
                $this->input = $input;
                $this->atomic = new class($tmp_dir) {
                    public function __construct(private string $tmp_dir) {}

                    public function get(string $key): string
                    {
                        return $key === 'USER_PLUGINS' ? $this->tmp_dir : '';
                    }
                };
            }

            public function get_cli_args(): array
            {
                return $this->args;
            }
        };
    }

    private function rimraf(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($path);
    }
}
