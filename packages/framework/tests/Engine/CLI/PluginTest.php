<?php
declare(strict_types=1);
namespace Tests\Engine\CLI;

use Engine\Atomic\App\PluginManager;
use Engine\Atomic\Core\App;
use Engine\Atomic\CLI\Console\Input;
use Engine\Atomic\CLI\Console\Output;
use PHPUnit\Framework\TestCase;
use Tests\Support\ReflectionHelper;
use Tests\Support\StreamCapture;
use Tests\Support\TempPath;

class PluginTest extends TestCase
{
    private string $tmp_dir;

    protected function setUp(): void
    {
        $this->tmp_dir = rtrim(TempPath::make_dir('atomic_plugin_test_'), DIRECTORY_SEPARATOR);
    }

    protected function tearDown(): void
    {
        TempPath::remove($this->tmp_dir);
    }

    public function test_plugin_make_creates_user_plugin_scaffold(): void
    {
        $cli = $this->make_cli(['SamplePlugin']);
        $cli->plugin_make();

        $base = $this->tmp_dir . DIRECTORY_SEPARATOR . 'SamplePlugin';

        $this->assertFileDoesNotExist($base . DIRECTORY_SEPARATOR . 'plugin.php');
        $this->assertFileExists($base . DIRECTORY_SEPARATOR . 'SamplePlugin.php');
        $this->assertFileExists($base . DIRECTORY_SEPARATOR . 'composer.json');
        $this->assertFileExists($base . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR . 'api.php');
        $composer = json_decode(file_get_contents($base . DIRECTORY_SEPARATOR . 'composer.json'), true);
        $this->assertSame('app/sample-plugin', $composer['name']);
        $this->assertSame([
            'App\\Plugins\\SamplePlugin\\' => './',
        ], $composer['autoload']['psr-4']);
    }

    public function test_plugin_make_is_idempotent(): void
    {
        $cli = $this->make_cli(['SamplePlugin']);
        $cli->plugin_make();
        $cli->plugin_make();

        $base = $this->tmp_dir . DIRECTORY_SEPARATOR . 'SamplePlugin';

        $this->assertFileDoesNotExist($base . DIRECTORY_SEPARATOR . 'plugin.php');
        $this->assertFileExists($base . DIRECTORY_SEPARATOR . 'SamplePlugin.php');
        $this->assertFileExists($base . DIRECTORY_SEPARATOR . 'composer.json');
        $this->assertFileExists($base . DIRECTORY_SEPARATOR . 'routes' . DIRECTORY_SEPARATOR . 'api.php');
    }

    public function test_plugin_make_rejects_invalid_names(): void
    {
        $cli = $this->make_cli(['123-tools']);
        $cli->plugin_make();

        $this->assertFileDoesNotExist($this->tmp_dir . DIRECTORY_SEPARATOR . 'Plugin123Tools' . DIRECTORY_SEPARATOR . 'Plugin123Tools.php');
    }

    public function test_plugin_make_creates_loadable_plugin(): void
    {
        $class_name = 'LoadablePlugin';
        $cli = $this->make_cli([$class_name]);
        $cli->plugin_make();

        $this->reset_plugin_manager();
        App::atomic()->set('USER_PLUGINS', $this->tmp_dir);

        $manager = PluginManager::instance();
        $manager->load_plugins(["App\\Plugins\\{$class_name}\\{$class_name}"]);

        $this->assertTrue($manager->has($class_name));
        $this->assertSame($class_name, $manager->get($class_name)->get_plugin_name());
    }

    private function make_cli(array $args): object
    {
        $stdout = StreamCapture::memory();
        $stderr = StreamCapture::memory();
        $stdin  = StreamCapture::memory('r');

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

    private function reset_plugin_manager(): void
    {
        ReflectionHelper::set(PluginManager::class, 'instance', null);
    }
}
