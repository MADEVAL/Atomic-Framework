<?php
declare(strict_types=1);

namespace Tests\Engine\Lang;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ID;
use Engine\Atomic\Lang\I18n;
use Engine\Atomic\Theme\Theme;
use PHPUnit\Framework\TestCase;

class I18nCacheTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atomic_i18n_cache_' . bin2hex(random_bytes(6));
        mkdir($this->root . '/locales/en', 0777, true);
        mkdir($this->root . '/themes/default', 0777, true);
        file_put_contents($this->root . '/themes/default/theme.json', '{}');

        $app = App::instance();
        $app->set('APP_UUID', ID::uuid_v4());
        $app->set('DOMAIN', 'https://test.example.com/');
        $app->set('ROOT', $this->root . '/public');
        $app->set('LOCALES', $this->root . '/locales');
        $app->set('ENQ_UI_FIX', $this->root . '/themes');
        $app->set('THEME.envname', 'default');
        $app->set('i18n', [
            'languages' => ['en'],
            'default' => 'en',
            'url_mode' => 'none',
            'ttl' => 60,
            'cookie' => 'lang',
            'session' => 'lang',
        ]);

        Theme::reset();
        $this->resetI18n();
    }

    protected function tearDown(): void
    {
        Theme::reset();
        $this->resetI18n();
        if ($this->root !== '') {
            $this->removeDir($this->root);
        }
    }

    public function test_domain_dictionary_is_reused_from_atomic_cache(): void
    {
        file_put_contents($this->root . '/locales/en/default.php', "<?php\nreturn ['hello' => 'Hello'];\n");

        $this->assertSame('Hello', I18n::instance()->t('hello', [], 'default', 'en'));

        file_put_contents($this->root . '/locales/en/default.php', "<?php\nreturn ['hello' => 'Changed'];\n");
        Theme::reset();
        $this->resetI18n();

        $this->assertSame('Hello', I18n::instance()->t('hello', [], 'default', 'en'));
    }

    private function resetI18n(): void
    {
        $property = new \ReflectionProperty(I18n::class, 'instance');
        $property->setValue(null, null);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
