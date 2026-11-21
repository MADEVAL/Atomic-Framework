<?php
declare(strict_types=1);

namespace Tests\Engine\Codes;

use Engine\Atomic\Codes\Code;
use Engine\Atomic\Codes\CodeCatalog;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ID;
use Engine\Atomic\Lang\I18n;
use Engine\Atomic\Theme\Theme;
use Engine\Atomic\Validator\ValidationError;
use PHPUnit\Framework\TestCase;
use Tests\Support\Codes\AppCodes;

final class CodeCatalogPlainCodes
{
    public const PRODUCT_PRICE_MIN = '1571';
    public const LIMIT = 42;
    public const FLAGS = ['a'];
    public const SERVER_ERROR = '500';
}

final class CodeCatalogDuplicateCodes
{
    public const FIRST = '700';
    public const SECOND = '700';
    public const OTHER = '701';
}

final class CodeCatalogTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atomic_code_catalog_' . bin2hex(random_bytes(6));
        mkdir($this->root . '/locales', 0777, true);
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
            'languages' => ['en', 'ru'],
            'default'   => 'en',
            'url_mode'  => 'none',
            'ttl'       => 0,
            'cookie'    => 'lang',
            'session'   => 'lang',
        ]);

        $this->put('en', [
            'errors' => ['codes' => [
                'forbidden'         => 'Forbidden',
                'server_error'      => 'Server error',
                'product_price_min' => 'Price must be at least {min}',
            ]],
        ]);
        $this->put('ru', [
            'errors' => ['codes' => [
                'forbidden'         => 'Доступ запрещён',
                'server_error'      => 'Ошибка сервера',
                'product_price_min' => 'Цена должна быть не меньше {min}',
            ]],
        ]);

        Theme::reset();
        $this->reset_i18n();
    }

    protected function tearDown(): void
    {
        Theme::reset();
        $this->reset_i18n();
        if ($this->root !== '') {
            $this->remove_dir($this->root);
        }
    }

    public function test_default_catalog_reads_framework_code(): void
    {
        $this->assertSame(Code::class, (new CodeCatalog())->codes_class);
    }

    public function test_unknown_codes_class_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CodeCatalog('No\Such\Codes');
    }

    public function test_code_name_returns_constant_name_for_known_value(): void
    {
        $this->assertSame('PRODUCT_PRICE_MIN', (new CodeCatalog(CodeCatalogPlainCodes::class))->code_name('1571'));
        $this->assertSame('FORBIDDEN', (new CodeCatalog())->code_name(Code::FORBIDDEN));
    }

    public function test_code_name_counts_int_constants_and_skips_others(): void
    {
        $codes = new CodeCatalog(CodeCatalogPlainCodes::class);
        $this->assertSame('LIMIT', $codes->code_name('42'));
        $this->assertNull($codes->code_name('Array'));
    }

    public function test_code_name_finds_constant_declared_in_used_trait(): void
    {
        $this->require_trait_constants();
        $codes = new CodeCatalog(AppCodes::class);
        $this->assertSame('PRODUCT_PRICE_MIN', $codes->code_name('1571'));
        $this->assertSame('FORBIDDEN', $codes->code_name('403'));
    }

    public function test_code_name_returns_null_for_unknown_value(): void
    {
        $this->assertNull((new CodeCatalog(CodeCatalogPlainCodes::class))->code_name('9999'));
    }

    public function test_translation_key_uses_lowercase_constant_name(): void
    {
        $this->assertSame('errors.codes.product_price_min', (new CodeCatalog(CodeCatalogPlainCodes::class))->translation_key('1571'));
        $this->assertSame('errors.codes.forbidden', (new CodeCatalog())->translation_key(Code::FORBIDDEN));
    }

    public function test_translation_key_falls_back_to_server_error(): void
    {
        $this->assertSame('errors.codes.server_error', (new CodeCatalog(CodeCatalogPlainCodes::class))->translation_key('9999'));
        $this->assertSame('errors.codes.server_error', (new CodeCatalog(CodeCatalogDuplicateCodes::class))->translation_key('9999'));
    }

    public function test_custom_prefix_is_used(): void
    {
        $codes = new CodeCatalog(Code::class, prefix: 'api.codes.');
        $this->assertSame('api.codes.forbidden', $codes->translation_key('403'));
        $this->assertSame('api.codes.server_error', $codes->translation_key('9999'));
    }

    public function test_duplicate_codes_lists_values_held_by_several_constants(): void
    {
        $codes = new CodeCatalog(CodeCatalogDuplicateCodes::class);
        $this->assertSame(['700' => ['FIRST', 'SECOND']], $codes->duplicate_codes());
        $this->assertSame('FIRST', $codes->code_name('700'));
        $this->assertSame([], (new CodeCatalog())->duplicate_codes());
    }

    public function test_message_uses_current_language_with_vars(): void
    {
        $this->assertSame('Forbidden', (new CodeCatalog())->message(Code::FORBIDDEN));
        $this->assertSame('Price must be at least 0', (new CodeCatalog(CodeCatalogPlainCodes::class))->message('1571', ['min' => 0]));

        $this->set_current_language('ru');
        $this->assertSame('Доступ запрещён', (new CodeCatalog())->message(Code::FORBIDDEN));
    }

    public function test_message_uses_explicit_language_with_vars(): void
    {
        $this->assertSame('Цена должна быть не меньше 5', (new CodeCatalog(CodeCatalogPlainCodes::class))->message('1571', ['min' => 5], lang: 'ru'));
        $this->assertSame('Ошибка сервера', (new CodeCatalog())->message('9999', lang: 'ru'));
    }

    public function test_message_uses_injected_i18n(): void
    {
        $this->set_current_language('ru');
        $codes = new CodeCatalog(Code::class, i18n: I18n::instance());
        $this->assertSame('Доступ запрещён', $codes->message(Code::FORBIDDEN));
    }

    public function test_validation_error_message_end_to_end(): void
    {
        $this->require_trait_constants();
        $error = new ValidationError('Product', 'price', 'MIN', ['min' => 0]);
        $codes = new CodeCatalog(AppCodes::class);

        $this->assertSame('Price must be at least 0', $error->message($codes));
        $this->assertSame('Цена должна быть не меньше 0', $error->message($codes, lang: 'ru'));
    }

    public function test_validation_error_message_defaults_to_framework_code(): void
    {
        $error = new ValidationError('Product', 'price', 'MIN', ['min' => 0]);

        $this->assertSame('Server error', $error->message());
    }

    private function require_trait_constants(): void
    {
        if (PHP_VERSION_ID < 80200) {
            $this->markTestSkipped('Constants in traits require PHP 8.2.');
        }
    }

    private function set_current_language(string $lang): void
    {
        (new \ReflectionProperty(I18n::class, 'current'))->setValue(I18n::instance(), $lang);
    }

    private function put(string $lang, array $data): void
    {
        file_put_contents(
            $this->root . '/locales/' . $lang . '.php',
            "<?php\nreturn " . var_export($data, true) . ";\n"
        );
    }

    private function reset_i18n(): void
    {
        (new \ReflectionProperty(I18n::class, 'instance'))->setValue(null, null);
    }

    private function remove_dir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            is_dir($path) ? $this->remove_dir($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
