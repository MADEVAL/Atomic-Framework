<?php
declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;

class BootstrapTest extends TestCase
{
    public function test_bootstrap_constants_defined(): void
    {
        $this->assertTrue(defined('ATOMIC_START'));
        $this->assertTrue(defined('ATOMIC_FRAMEWORK'));
        $this->assertTrue(defined('ATOMIC_ENGINE'));
        $this->assertTrue(defined('ATOMIC_VENDOR'));
        $this->assertTrue(defined('ATOMIC_DIR'));
    }

    public function test_framework_engine_path_exists(): void
    {
        $this->assertDirectoryExists(ATOMIC_ENGINE);
        $this->assertDirectoryExists(ATOMIC_ENGINE . 'Atomic');
    }

    public function test_vendor_autoload_exists(): void
    {
        $this->assertFileExists(ATOMIC_VENDOR . 'autoload.php');
    }

    public function test_app_instance_available(): void
    {
        $atomic = \Engine\Atomic\Core\App::instance();
        $this->assertNotNull($atomic);
    }
}
