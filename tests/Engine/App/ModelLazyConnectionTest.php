<?php
declare(strict_types=1);

namespace Tests\Engine\App;

use Engine\Atomic\App\Model;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\ConnectionManager;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\PlatformGuard;
use Tests\Support\TestConfig;

class AtomicTestModelForLazyConnection extends Model
{
    protected $table = 'meta';
}

class ModelLazyConnectionTest extends TestCase
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

    public function test_constructing_model_opens_database_connection_lazily(): void
    {
        $this->assertSame([], $this->open_mysql_connections());

        new AtomicTestModelForLazyConnection();

        $this->assertCount(1, $this->open_mysql_connections());
        $this->assertInstanceOf(\DB\SQL::class, App::instance()->get('DB'));
    }

    public function test_model_construction_reuses_already_open_connection(): void
    {
        new AtomicTestModelForLazyConnection();
        $count_after_first = count($this->open_mysql_connections());

        new AtomicTestModelForLazyConnection();

        $this->assertSame($count_after_first, count($this->open_mysql_connections()));
    }
}
