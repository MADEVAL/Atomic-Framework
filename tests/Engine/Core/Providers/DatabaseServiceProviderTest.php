<?php
declare(strict_types=1);

namespace Tests\Engine\Core\Providers;

use Engine\Atomic\Core\ConnectionManager;
use Engine\Atomic\Core\Providers\DatabaseServiceProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\PlatformGuard;
use Tests\Support\TestConfig;

class DatabaseServiceProviderTest extends TestCase
{
    protected function setUp(): void
    {
        PlatformGuard::requireMySql();
        // Re-apply test config: ConfigLoader rebuilds the hive with empty DB creds.
        TestConfig::apply(\Base::instance(), ['app_uuid' => false]);
        ConnectionManager::instance()->close();
    }

    protected function tearDown(): void
    {
        ConnectionManager::instance()->close();
    }

    private function open_mysql_connections(): array
    {
        $ref = new ReflectionClass(ConnectionManager::instance());
        $prop = $ref->getProperty('mysql_connections');

        return $prop->getValue(ConnectionManager::instance());
    }

    public function test_boot_does_not_open_database_connections(): void
    {
        (new DatabaseServiceProvider())->boot();

        $this->assertSame([], $this->open_mysql_connections());
    }

    public function test_connection_manager_opens_database_lazily_after_boot(): void
    {
        (new DatabaseServiceProvider())->boot();
        $this->assertSame([], $this->open_mysql_connections());

        $db = ConnectionManager::instance()->get_db(false);

        $this->assertInstanceOf(\DB\SQL::class, $db);
        $this->assertCount(1, $this->open_mysql_connections());
    }
}
