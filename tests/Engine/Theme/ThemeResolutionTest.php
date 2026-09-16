<?php
declare(strict_types=1);

namespace Tests\Engine\Theme;

use Engine\Atomic\Core\App;
use Engine\Atomic\Theme\Theme;
use PHPUnit\Framework\TestCase;

final class ThemeResolutionTest extends TestCase
{
    private string $customRoot = '';

    protected function setUp(): void
    {
        $app = App::instance();
        $app->set('DOMAIN', 'http://localhost:8000/');
        $app->set('ROOT', ATOMIC_DIR . '/packages/skeleton/public');
        $app->set('THEME.envname', 'default');

        $this->customRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atomic_theme_resolution_' . bin2hex(random_bytes(6));
        mkdir($this->customRoot, 0777, true);
        Theme::reset();
    }

    protected function tearDown(): void
    {
        Theme::reset();
        if ($this->customRoot !== '' && is_dir($this->customRoot)) {
            $this->remove_directory($this->customRoot);
        }
    }

    public function test_builtin_theme_falls_back_to_internal_framework_directory(): void
    {
        App::instance()->set('ENQ_UI_FIX', ATOMIC_DIR . '/packages/skeleton/public/themes');

        $theme = Theme::instance('default');

        $this->assertSame(
            realpath(ATOMIC_ENGINE . 'Atomic/Theme/Builtin/default'),
            realpath($theme->get_theme_dir())
        );
        $this->assertSame($theme->get_theme_dir(), App::instance()->get('UI'));
        $this->assertSame('http://localhost:8000/themes/', App::instance()->get('THEME._url'));
        $this->assertSame('http://localhost:8000/__atomic/themes/default', $theme->get_theme_url());
    }

    public function test_custom_theme_overrides_builtin_theme_with_the_same_name(): void
    {
        $customTheme = $this->customRoot . DIRECTORY_SEPARATOR . 'default';
        mkdir($customTheme, 0777, true);
        file_put_contents($customTheme . DIRECTORY_SEPARATOR . 'functions.atom.php', "<?php\n");
        file_put_contents($customTheme . DIRECTORY_SEPARATOR . 'theme.json', '{"name":"Custom default"}');
        App::instance()->set('ENQ_UI_FIX', $this->customRoot);

        $theme = Theme::instance('default');

        $this->assertSame(realpath($customTheme), realpath($theme->get_theme_dir()));
        $this->assertSame($theme->get_theme_dir(), App::instance()->get('UI'));
        $this->assertSame('http://localhost:8000/themes/', App::instance()->get('THEME._url'));
        $this->assertSame('http://localhost:8000/themes/default', $theme->get_theme_url());
        $this->assertSame('Custom default', $theme->get_theme_meta()['name']);
    }

    public function test_empty_custom_theme_directory_does_not_override_builtin_theme(): void
    {
        $emptyCustomTheme = $this->customRoot . DIRECTORY_SEPARATOR . 'default';
        mkdir($emptyCustomTheme, 0777, true);
        App::instance()->set('ENQ_UI_FIX', $this->customRoot);

        $theme = Theme::instance('default');

        $this->assertTrue($theme->is_builtin());
        $this->assertSame(
            realpath(ATOMIC_ENGINE . 'Atomic/Theme/Builtin/default'),
            realpath($theme->get_theme_dir())
        );
        $this->assertSame($theme->get_theme_dir(), App::instance()->get('UI'));
    }

    /**
     * @dataProvider invalid_theme_names
     */
    public function test_invalid_theme_name_is_rejected_before_path_resolution(string $themeName): void
    {
        App::instance()->set('ENQ_UI_FIX', $this->customRoot);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid theme name');

        Theme::instance($themeName);
    }

    public static function invalid_theme_names(): array
    {
        return [
            'empty' => [''],
            'traversal' => ['../default'],
            'nested traversal' => ['theme/../../default'],
            'slash' => ['custom/theme'],
            'backslash' => ['custom\\theme'],
            'dot' => ['default.theme'],
            'space' => ['default theme'],
        ];
    }

    public function test_custom_root_without_theme_uses_builtin_theme(): void
    {
        App::instance()->set('ENQ_UI_FIX', $this->customRoot);

        $theme = Theme::instance('default');

        $this->assertSame(
            realpath(ATOMIC_ENGINE . 'Atomic/Theme/Builtin/default'),
            realpath($theme->get_theme_dir())
        );
    }

    private function remove_directory(string $directory): void
    {
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) ? $this->remove_directory($path) : unlink($path);
        }
        rmdir($directory);
    }
}
