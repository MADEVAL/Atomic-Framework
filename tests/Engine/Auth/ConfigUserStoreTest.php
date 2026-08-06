<?php
declare(strict_types=1);

namespace Tests\Engine\Auth;

use Engine\Atomic\Auth\ConfigUserStore;
use PHPUnit\Framework\TestCase;
use Tests\Support\TempPath;

final class ConfigUserStoreTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = rtrim(TempPath::make_dir('atomic_config_user_store_'), DIRECTORY_SEPARATOR);
    }

    protected function tearDown(): void
    {
        TempPath::remove($this->root);
    }

    public function test_upsert_user_writes_storage_file_with_var_export_shape(): void
    {
        $store = new ConfigUserStore($this->root);

        $this->assertTrue($store->upsert_user(
            'telemetry',
            'viewer',
            '11111111-1111-4111-8111-111111111111',
            '$2y$10$hash',
            ['telemetry.viewer'],
        ));

        $content = (string)file_get_contents($store->path());
        $this->assertStringContainsString('return array (', $content);
        $this->assertTrue($store->exists('telemetry', 'viewer'));
        $this->assertSame(['telemetry.viewer'], $store->users('telemetry')['viewer']['roles']);
    }

    public function test_reset_secret_updates_existing_user(): void
    {
        $store = new ConfigUserStore($this->root);
        $store->upsert_user('telemetry', 'viewer', 'id', 'old-hash', []);

        $this->assertTrue($store->reset_secret('telemetry', 'viewer', 'new-hash'));
        $this->assertSame('new-hash', $store->users('telemetry')['viewer']['secret_hash']);
        $this->assertFalse($store->reset_secret('telemetry', 'missing', 'new-hash'));
    }

}
