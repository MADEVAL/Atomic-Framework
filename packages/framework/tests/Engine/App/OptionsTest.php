<?php
declare(strict_types=1);

namespace Tests\Engine\App;

use Engine\Atomic\App\Models\Options;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ID;
use PHPUnit\Framework\TestCase;
use Tests\Support\Wait;

class OptionsTest extends TestCase
{
    private string $uuid = '';
    private string $prefix = '';

    protected function setUp(): void
    {
        if (App::instance()->get('DB') === null) {
            $this->markTestSkipped('Options storage not available.');
        }

        $this->uuid = ID::uuid_v4();
        App::instance()->set('APP_UUID', $this->uuid);
        $this->prefix = 'atomic_test_options_' . bin2hex(random_bytes(6)) . '.';
    }

    protected function tearDown(): void
    {
        if ($this->uuid !== '' && $this->prefix !== '' && App::instance()->get('DB') !== null) {
            Options::delete_option_like($this->prefix . '%');
            Options::delete_option_like('other.' . $this->prefix . '%');
        }

        parent::tearDown();
    }

    public function test_delete_expired_option_like_removes_only_expired_matching_options(): void
    {
        Options::set_option($this->prefix . 'expired', 'expired', 1);
        Options::set_option($this->prefix . 'fresh', 'fresh', 60);
        Options::set_option('other.' . $this->prefix . 'expired', 'other-expired', 1);

        $this->assertTrue(Wait::until(
            fn (): bool => Options::delete_expired_option_like($this->prefix . '%')
                && $this->countRawOptions($this->prefix . 'expired') === 0,
            4
        ));

        $this->assertSame(0, $this->countRawOptions($this->prefix . 'expired'));
        $this->assertSame(1, $this->countRawOptions($this->prefix . 'fresh'));
        $this->assertSame(1, $this->countRawOptions('other.' . $this->prefix . 'expired'));
    }

    private function countRawOptions(string $key): int
    {
        $config = Options::resolveConfiguration();
        $table = str_replace('`', '``', (string) $config['table']);
        $rows = $config['db']->exec(
            'SELECT COUNT(*) AS count FROM `' . $table . '` WHERE uuid = ? AND `key` = ?',
            [$this->uuid, $key]
        );

        return (int)($rows[0]['count'] ?? 0);
    }
}
