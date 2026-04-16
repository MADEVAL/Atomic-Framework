<?php
declare(strict_types=1);

namespace Tests\Engine\Core;

use Engine\Atomic\Core\Redactor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class RedactorTest extends TestCase
{
    public function test_set_home_path_makes_ready(): void
    {
        Redactor::set_home_path('/home/testuser');
        $this->assertTrue(Redactor::is_ready());
        $this->assertSame('/home/testuser', Redactor::get_home_path());
    }

    public function test_set_home_path_trims_trailing_slashes(): void
    {
        Redactor::set_home_path('/home/testuser///');
        $this->assertSame('/home/testuser', Redactor::get_home_path());
    }

    public function test_set_home_path_trims_backslashes(): void
    {
        Redactor::set_home_path('C:\\Users\\testuser\\\\');
        $this->assertSame('C:\\Users\\testuser', Redactor::get_home_path());
    }

    public function test_set_home_path_ignores_empty_string(): void
    {
        Redactor::set_home_path('/home/before');
        Redactor::set_home_path('');
        $this->assertSame('/home/before', Redactor::get_home_path());
    }

    public function test_set_home_path_ignores_duplicate(): void
    {
        Redactor::set_home_path('/home/user');
        Redactor::set_home_path('/home/user');
        $this->assertSame('/home/user', Redactor::get_home_path());
    }

    public function test_sync_from_hive_uses_HOME(): void
    {
        $f3 = \Base::instance();
        $f3->set('HOME', '/from/hive');
        Redactor::sync_from_hive($f3);
        $this->assertSame('/from/hive', Redactor::get_home_path());
    }

    public function test_sync_from_hive_falls_back_to_ROOT_parent(): void
    {
        $f3 = \Base::instance();
        $f3->set('HOME', '');
        $f3->set('ROOT', '/var/www/public/');
        Redactor::sync_from_hive($f3);
        $this->assertSame('/var/www', Redactor::get_home_path());
    }

    public function test_sync_from_hive_noop_when_both_empty(): void
    {
        Redactor::set_home_path('/keep/this');
        $f3 = \Base::instance();
        $f3->set('HOME', '');
        $f3->set('ROOT', '');
        Redactor::sync_from_hive($f3);
        $this->assertSame('/keep/this', Redactor::get_home_path());
    }

    public function test_sanitize_string_masks_home_path(): void
    {
        Redactor::set_home_path('/home/user');
        $result = Redactor::sanitize_string('File at /home/user/app/config.php');
        $this->assertSame('File at [HOME]/app/config.php', $result);
    }

    public function test_sanitize_string_masks_forward_and_backslash_variants(): void
    {
        Redactor::set_home_path('/home/user');
        $this->assertStringContainsString('[HOME]', Redactor::sanitize_string('/home/user/file.txt'));
        $this->assertStringContainsString('[HOME]', Redactor::sanitize_string('\\home\\user\\file.txt'));
    }

    #[DataProvider('sensitiveKeyProvider')]
    public function test_is_sensitive_key_matches(string $key): void
    {
        $this->assertTrue(
            Redactor::is_sensitive_key($key),
            "Expected '$key' to be detected as sensitive"
        );
    }

    public static function sensitiveKeyProvider(): array
    {
        return [
            'password'         => ['password'],
            'PASSWORD'         => ['PASSWORD'],
            'DB_PASSWORD'      => ['DB_PASSWORD'],
            'passwd'           => ['passwd'],
            'secret'           => ['secret'],
            'APP_SECRET'       => ['APP_SECRET'],
            'pass'             => ['pass'],
            'api_key'          => ['api_key'],
            'API_KEY'          => ['API_KEY'],
            'apikey'           => ['apikey'],
            'api-key'          => ['api-key'],
            'access_token'     => ['access_token'],
            'refresh_token'    => ['refresh_token'],
            'TOKEN'            => ['TOKEN'],
            'auth'             => ['auth'],
            'x_auth'           => ['x_auth'],
            'authorization'    => ['authorization'],
            'bearer'           => ['bearer'],
            'credential'       => ['credential'],
            'login'            => ['login'],
            'username'         => ['username'],
            'user'             => ['user'],
            'REMOTE_ADDR'      => ['REMOTE_ADDR'],
            'SERVER_ADDR'      => ['SERVER_ADDR'],
            'home'             => ['home'],
            'encryption'       => ['encryption'],
            'hmac'             => ['hmac'],
            'nonce'            => ['nonce'],
            'signature'        => ['signature'],
            'private_key'      => ['private_key'],
            'cookie'           => ['cookie'],
            'session'          => ['session'],
            'set_cookie'       => ['set_cookie'],
            'set-cookie'       => ['set-cookie'],
            'dsn'              => ['dsn'],
            'db'               => ['db'],
            'database'         => ['database'],
            'chat_id'          => ['chat_id'],
            'chat-id'          => ['chat-id'],
            'APP_UUID'         => ['APP_UUID'],
            'client_id'        => ['client_id'],
            'x-api-key'        => ['x-api-key'],
            'x_api_key'        => ['x_api_key'],
            'x-auth'           => ['x-auth'],
            'x_csrf'           => ['x_csrf'],
            'x-xsrf'          => ['x-xsrf'],
            'seed'             => ['seed'],
            'KEY'              => ['KEY'],
            'APP_KEY'          => ['APP_KEY'],
        ];
    }

    #[DataProvider('nonSensitiveKeyProvider')]
    public function test_is_sensitive_key_rejects(string $key): void
    {
        $this->assertFalse(
            Redactor::is_sensitive_key($key),
            "Expected '$key' to NOT be detected as sensitive"
        );
    }

    public static function nonSensitiveKeyProvider(): array
    {
        return [
            'user_agent'    => ['user_agent'],
            'author'        => ['author'],
            'authority'     => ['authority'],
            'keyboard'      => ['keyboard'],
            'debug'         => ['debug'],
            'content_type'  => ['content_type'],
            'method'        => ['method'],
            'status'        => ['status'],
            'x_author'      => ['x_author'],
        ];
    }

    public function test_is_sensitive_key_integer_returns_false(): void
    {
        $this->assertFalse(Redactor::is_sensitive_key(0));
        $this->assertFalse(Redactor::is_sensitive_key(99));
    }

    public function test_sanitize_string_masks_authorization_header(): void
    {
        $input  = 'Authorization: Bearer abc123secret';
        $result = Redactor::sanitize_string($input);
        $this->assertStringNotContainsString('abc123secret', $result);
        $this->assertStringContainsString('[MASKED]', $result);
    }

    public function test_sanitize_string_masks_basic_auth(): void
    {
        $input  = 'Basic dXNlcjpwYXNz';
        $result = Redactor::sanitize_string($input);
        $this->assertStringNotContainsString('dXNlcjpwYXNz', $result);
        $this->assertStringContainsString('[MASKED]', $result);
    }

    public function test_sanitize_string_masks_url_credentials(): void
    {
        $input  = 'mysql://root:s3cret@localhost/db';
        $result = Redactor::sanitize_string($input);
        $this->assertStringNotContainsString('s3cret', $result);
        $this->assertStringContainsString('[MASKED]', $result);
    }

    public function test_sanitize_string_masks_inline_key_value(): void
    {
        $input  = 'password=hunter2';
        $result = Redactor::sanitize_string($input);
        $this->assertStringNotContainsString('hunter2', $result);
        $this->assertStringContainsString('[MASKED]', $result);
    }

    public function test_sanitize_string_masks_inline_token(): void
    {
        $input  = 'token=abc123xyz';
        $result = Redactor::sanitize_string($input);
        $this->assertStringNotContainsString('abc123xyz', $result);
    }

    public function test_sanitize_string_masks_json_style_sensitive_pairs(): void
    {
        $input  = '{"handler":"Engine\\\\Atomic\\\\Queue\\\\Tests\\\\Test@failure","data":{"params":{"id":123,"type":"test","apikey":"API_KEY_123"}}}';
        $result = Redactor::sanitize_string($input);
        $this->assertStringNotContainsString('API_KEY_123', $result);
        $this->assertStringContainsString('"apikey":"[MASKED]"', $result);
    }

    public function test_sanitize_string_leaves_harmless_string_alone(): void
    {
        Redactor::set_home_path('/unlikely/test/path/xyz');
        $input = 'Just a normal log message with no secrets';
        $this->assertSame($input, Redactor::sanitize_string($input));
    }

    public function test_normalize_string(): void
    {
        $this->assertSame('hello', Redactor::normalize('hello'));
    }

    public function test_normalize_integer(): void
    {
        $this->assertSame(42, Redactor::normalize(42));
    }

    public function test_normalize_float(): void
    {
        $this->assertSame(3.14, Redactor::normalize(3.14));
    }

    public function test_normalize_bool(): void
    {
        $this->assertSame(true, Redactor::normalize(true));
        $this->assertSame(false, Redactor::normalize(false));
    }

    public function test_normalize_null(): void
    {
        $this->assertNull(Redactor::normalize(null));
    }

    public function test_normalize_simple_array(): void
    {
        $data = ['name' => 'test', 'count' => 5];
        $result = Redactor::normalize($data);
        $this->assertSame('test', $result['name']);
        $this->assertSame(5, $result['count']);
    }

    public function test_normalize_array_masks_sensitive_keys(): void
    {
        $data = [
            'username' => 'admin',
            'password' => 'secret123',
            'api_key'  => 'sk-abc',
            'status'   => 'ok',
        ];
        $result = Redactor::normalize($data);
        $this->assertSame('[MASKED]', $result['username']);
        $this->assertSame('[MASKED]', $result['password']);
        $this->assertSame('[MASKED]', $result['api_key']);
        $this->assertSame('ok', $result['status']);
    }

    public function test_normalize_nested_array_masks_deeply(): void
    {
        $data = [
            'config' => [
                'db' => [
                    'host' => 'localhost',
                    'password' => 'root',
                ],
            ],
        ];
        $result = Redactor::normalize($data);
        $this->assertSame('[MASKED]', $result['config']['db']['password']);
        $this->assertSame('localhost', $result['config']['db']['host']);
    }

    public function test_normalize_array_sensitive_key_with_nested_array_traverses_structure(): void
    {
        $data = [
            'credentials' => [
                'username' => 'andri',
                'token'    => 'abc123',
                'role'     => 'admin',
            ],
            'status' => 'ok',
        ];

        $result = Redactor::normalize($data);

        $this->assertIsArray($result['credentials'], 'Nested array under sensitive key must remain traversable');

        $this->assertSame('[MASKED]', $result['credentials']['username']);
        $this->assertSame('[MASKED]', $result['credentials']['token']);

        $this->assertSame('admin', $result['credentials']['role']);

        $this->assertSame('ok', $result['status']);
    }

    public function test_normalize_array_sensitive_key_with_nested_object_traverses_structure(): void
    {
        $inner         = new \stdClass();
        $inner->token  = 'bearer-xyz';
        $inner->issued = '2026-04-07';

        $data = [
            'session' => $inner,
            'method'  => 'GET',
        ];

        $result = Redactor::normalize($data);

        $this->assertIsArray($result['session'], 'Nested object under sensitive key must remain traversable');
        $this->assertArrayHasKey('__object__', $result['session']);

        $this->assertSame('[MASKED]', $result['session']['properties']['token']);

        $this->assertSame('2026-04-07', $result['session']['properties']['issued']);

        $this->assertSame('GET', $result['method']);
    }

    public function test_normalize_array_truncation(): void
    {
        $big = array_fill(0, 1500, 'x');
        $result = Redactor::normalize($big, 0, 6, 1000);
        $this->assertArrayHasKey('[[truncated]]', $result);
        $this->assertCount(1001, $result); // 1000 items + truncated marker
    }

    public function test_normalize_numeric_keys_not_masked(): void
    {
        $data = ['value1', 'value2', 'value3'];
        $result = Redactor::normalize($data);
        $this->assertSame('value1', $result[0]);
        $this->assertSame('value2', $result[1]);
        $this->assertSame('value3', $result[2]);
    }

    public function test_normalize_max_depth_default(): void
    {
        $deep = ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => ['g' => 'end']]]]]]];
        $result = Redactor::normalize($deep);
        $this->assertSame('[[max_depth]]', $result['a']['b']['c']['d']['e']['f']);
    }

    public function test_normalize_max_depth_custom(): void
    {
        $deep = ['a' => ['b' => ['c' => 'end']]];
        $result = Redactor::normalize($deep, 0, 2, 1000);
        $this->assertSame('[[max_depth]]', $result['a']['b']);
    }

    public function test_normalize_max_depth_one(): void
    {
        $data = ['nested' => ['value' => 1]];
        $result = Redactor::normalize($data, 0, 1, 1000);
        $this->assertSame('[[max_depth]]', $result['nested']);
    }

    public function test_normalize_stdclass(): void
    {
        $obj = new \stdClass();
        $obj->name = 'test';
        $result = Redactor::normalize($obj);
        $this->assertIsArray($result);
        $this->assertSame('stdClass', $result['__object__']);
        $this->assertSame('test', $result['properties']['name']);
    }

    public function test_normalize_stdclass_masks_sensitive_props(): void
    {
        $obj = new \stdClass();
        $obj->password = 's3cret';
        $obj->status   = 'active';
        $result = Redactor::normalize($obj);
        $this->assertSame('[MASKED]', $result['properties']['password']);
        $this->assertSame('active', $result['properties']['status']);
    }

    public function test_normalize_object_sensitive_prop_with_nested_array_traverses_structure(): void
    {
        $obj = new \stdClass();
        $obj->credentials = [
            'username' => 'andri',
            'token'    => 'abc123',
            'expires'  => '2026-04-07',
        ];
        $obj->status = 'ok';

        $result = Redactor::normalize($obj);
        $props  = $result['properties'];

        $this->assertIsArray($props['credentials'], 'Nested array under sensitive key must remain traversable');

        $this->assertSame('[MASKED]', $props['credentials']['username']);
        $this->assertSame('[MASKED]', $props['credentials']['token']);

        $this->assertSame('2026-04-07', $props['credentials']['expires']);

        $this->assertSame('ok', $props['status']);
    }

    public function test_normalize_object_sensitive_prop_with_nested_object_traverses_structure(): void
    {
        $inner          = new \stdClass();
        $inner->secret  = 'top-secret';
        $inner->created = '2026-01-01';

        $outer          = new \stdClass();
        $outer->session = $inner;
        $outer->debug   = 'trace';

        $result = Redactor::normalize($outer);
        $props  = $result['properties'];

        $this->assertIsArray($props['session'], 'Nested object under sensitive key must remain traversable');
        $this->assertArrayHasKey('__object__', $props['session']);

        $this->assertSame('[MASKED]', $props['session']['properties']['secret']);

        $this->assertSame('2026-01-01', $props['session']['properties']['created']);

        $this->assertSame('trace', $props['debug']);
    }

    public function test_normalize_object_sensitive_prop_scalar_is_masked(): void
    {
        $obj           = new \stdClass();
        $obj->password = 'hunter2';
        $obj->api_key  = 'sk-live-xyz';

        $result = Redactor::normalize($obj);
        $this->assertSame('[MASKED]', $result['properties']['password']);
        $this->assertSame('[MASKED]', $result['properties']['api_key']);
    }

    public function test_normalize_empty_object(): void
    {
        $obj = new \stdClass();
        $result = Redactor::normalize($obj);
        $this->assertSame('stdClass', $result['__object__']);
        $this->assertArrayNotHasKey('properties', $result);
    }

    public function test_normalize_datetime(): void
    {
        $dt = new \DateTimeImmutable('2024-01-15T10:30:00+00:00');
        $result = Redactor::normalize($dt);
        $this->assertIsString($result);
        $this->assertStringContainsString('2024-01-15', $result);
        $this->assertStringContainsString('10:30:00', $result);
    }

    public function test_normalize_datetime_mutable(): void
    {
        $dt = new \DateTime('2025-06-01T00:00:00+00:00');
        $result = Redactor::normalize($dt);
        $this->assertIsString($result);
        $this->assertStringContainsString('2025-06-01', $result);
    }

    public function test_normalize_resource(): void
    {
        $fh = fopen('php://memory', 'r');
        $result = Redactor::normalize($fh);
        fclose($fh);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('__resource__', $result);
    }

    public function test_normalize_string_with_sensitive_values_inside_array(): void
    {
        $data = [
            'log' => 'Authorization: Bearer sk-live-abc123',
            'debug' => 'password=hunter2&user=admin',
        ];
        $result = Redactor::normalize($data);
        $this->assertStringNotContainsString('sk-live-abc123', $result['log']);
        $this->assertStringNotContainsString('hunter2', $result['debug']);
    }

    public function test_normalize_mixed_array_with_objects_and_scalars(): void
    {
        $obj = new \stdClass();
        $obj->id = 1;
        $data = [
            'items' => [$obj, 'plain', 42, null, true],
        ];
        $result = Redactor::normalize($data);
        $this->assertSame('stdClass', $result['items'][0]['__object__']);
        $this->assertSame('plain', $result['items'][1]);
        $this->assertSame(42, $result['items'][2]);
        $this->assertNull($result['items'][3]);
        $this->assertTrue($result['items'][4]);
    }
}
