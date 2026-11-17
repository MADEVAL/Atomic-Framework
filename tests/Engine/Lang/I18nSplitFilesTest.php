<?php
declare(strict_types=1);

namespace Tests\Engine\Lang;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ID;
use Engine\Atomic\Lang\I18n;
use Engine\Atomic\Theme\Theme;
use PHPUnit\Framework\TestCase;

class I18nSplitFilesTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atomic_i18n_split_' . bin2hex(random_bytes(6));
        mkdir($this->root . '/locales/en', 0777, true);
        mkdir($this->root . '/locales/ru', 0777, true);
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
            'languages' => ['en', 'ru', 'uk'],
            'default'   => 'en',
            'url_mode'  => 'none',
            'ttl'       => 0,
            'cookie'    => 'lang',
            'session'   => 'lang',
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

    public function test_folder_files_resolve_under_their_basename(): void
    {
        $this->put('en/auth.php', ['forgot' => ['title' => 'Forgot password']]);
        $this->put('en/common.php', ['save' => 'Save']);
        file_put_contents($this->root . '/locales/en/extra.json', '{"hint":"From JSON"}');

        $i18n = I18n::instance();
        $this->assertSame('Forgot password', $i18n->t('auth.forgot.title', [], null, 'en'));
        $this->assertSame('Save', $i18n->t('common.save', [], null, 'en'));
        $this->assertSame('From JSON', $i18n->t('extra.hint', [], null, 'en'));
        $this->assertSame('forgot.title', $i18n->t('forgot.title', [], null, 'en'));
    }

    public function test_single_language_file_still_works(): void
    {
        $this->put('en.php', ['auth' => ['forgot' => ['title' => 'Single file']]]);

        $this->assertSame('Single file', I18n::instance()->t('auth.forgot.title', [], null, 'en'));
    }

    public function test_single_language_file_overrides_folder_files(): void
    {
        $this->put('en/auth.php', ['forgot' => ['title' => 'Folder', 'body' => 'Folder body']]);
        $this->put('en.php', ['auth' => ['forgot' => ['title' => 'Single']]]);

        $i18n = I18n::instance();
        $this->assertSame('Single', $i18n->t('auth.forgot.title', [], null, 'en'));
        $this->assertSame('Folder body', $i18n->t('auth.forgot.body', [], null, 'en'));
    }

    public function test_missing_key_falls_back_to_default_language_per_key(): void
    {
        $this->put('en/auth.php', ['forgot' => ['title' => 'Forgot', 'body' => 'Body EN']]);
        $this->put('ru/auth.php', ['forgot' => ['title' => 'Забыли']]);

        $i18n = I18n::instance();
        $this->assertSame('Забыли', $i18n->t('auth.forgot.title', [], null, 'ru'));
        $this->assertSame('Body EN', $i18n->t('auth.forgot.body', [], null, 'ru'));
    }

    public function test_explicit_domain_file_is_merged_at_root(): void
    {
        $this->put('en/dashboard.php', ['title' => 'Dashboard', 'dashboard' => ['lifted' => 'Lifted']]);
        $this->put('en/common.php', ['save' => 'Save']);

        $i18n = I18n::instance();
        $this->assertSame('Dashboard', $i18n->t('title', [], 'dashboard', 'en'));
        $this->assertSame('Lifted', $i18n->t('lifted', [], 'dashboard', 'en'));
        $this->assertSame('Save', $i18n->t('common.save', [], 'dashboard', 'en'));
        // Without a domain argument the same file is an ordinary group.
        $this->assertSame('Dashboard', $i18n->t('dashboard.title', [], null, 'en'));
    }

    public function test_default_domain_file_keeps_root_semantics(): void
    {
        $this->put('en/default.php', ['hello' => 'Hello']);

        $this->assertSame('Hello', I18n::instance()->t('hello', [], null, 'en'));
    }

    public function test_invalid_basenames_and_non_arrays_are_ignored(): void
    {
        $this->put('en/Bad-Name.php', ['x' => 'bad']);
        $this->put('en/UPPER.php', ['x' => 'bad']);
        file_put_contents($this->root . '/locales/en/scalar.php', "<?php\nreturn 'oops';\n");
        file_put_contents($this->root . '/locales/en/notes.txt', 'ignored');
        $this->put('en/ok.php', ['x' => 'good']);

        $i18n = I18n::instance();
        $this->assertSame('good', $i18n->t('ok.x', [], null, 'en'));
        $this->assertSame('Bad-Name.x', $i18n->t('Bad-Name.x', [], null, 'en'));
        $this->assertSame('UPPER.x', $i18n->t('UPPER.x', [], null, 'en'));
        $this->assertSame('scalar', $i18n->t('scalar', [], null, 'en'));
    }

    public function test_plural_uses_named_form_then_falls_back_to_plural(): void
    {
        $this->put('ru.php', [
            'item'      => '{count} предмет',
            'items'     => '{count} предметов',
            'items.few' => '{count} предмета',
        ]);

        $i18n = I18n::instance();
        $this->assertSame('1 предмет', $i18n->tn('item', 'items', 1, [], null, 'ru'));
        $this->assertSame('3 предмета', $i18n->tn('item', 'items', 3, [], null, 'ru'));
        $this->assertSame('5 предметов', $i18n->tn('item', 'items', 5, [], null, 'ru'));
    }

    public function test_ukrainian_uses_its_own_plural_rule(): void
    {
        $this->put('uk.php', [
            'item'       => '{count} предмет',
            'items'      => '{count} предметів',
            'items.few'  => '{count} предмети',
            'items.many' => '{count} предметів',
        ]);

        $i18n = I18n::instance();
        $this->assertSame('21 предмет', $i18n->tn('item', 'items', 21, [], null, 'uk'));
        $this->assertSame('3 предмети', $i18n->tn('item', 'items', 3, [], null, 'uk'));
        $this->assertSame('11 предметів', $i18n->tn('item', 'items', 11, [], null, 'uk'));
        $this->assertSame('25 предметів', $i18n->tn('item', 'items', 25, [], null, 'uk'));
    }

    public function test_plural_many_form_is_used_when_defined(): void
    {
        $this->put('ru.php', ['item' => 'one', 'items' => 'plural', 'items.many' => 'many']);

        $i18n = I18n::instance();
        $this->assertSame('many', $i18n->tn('item', 'items', 5, [], null, 'ru'));
        $this->assertSame('plural', $i18n->tn('item', 'items', 2, [], null, 'ru'));
    }

    public function test_plural_english_ignores_named_forms(): void
    {
        $this->put('en.php', ['item' => 'one', 'items' => 'other', 'items.few' => 'few']);

        $i18n = I18n::instance();
        $this->assertSame('one', $i18n->tn('item', 'items', 1, [], null, 'en'));
        $this->assertSame('other', $i18n->tn('item', 'items', 3, [], null, 'en'));
    }

    private function put(string $relative, array $data): void
    {
        file_put_contents(
            $this->root . '/locales/' . $relative,
            "<?php\nreturn " . var_export($data, true) . ";\n"
        );
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
