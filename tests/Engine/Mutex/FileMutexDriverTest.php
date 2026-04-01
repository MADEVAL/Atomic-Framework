<?php
declare(strict_types=1);

namespace Tests\Engine\Mutex;

use Engine\Atomic\Mutex\FileMutexDriver;
use PHPUnit\Framework\TestCase;

class FileMutexDriverTest extends TestCase
{
    private FileMutexDriver $driver;

    protected function setUp(): void
    {
        $this->driver = new FileMutexDriver();
    }

    public function test_driver_name(): void
    {
        $this->assertSame('file', $this->driver->get_name());
    }

    public function test_is_available(): void
    {
        $this->assertTrue($this->driver->is_available());
    }

    public function test_acquire_and_release(): void
    {
        $name = 'test_lock_' . uniqid();
        $token = bin2hex(random_bytes(16));

        $this->assertTrue($this->driver->acquire($name, $token, 60));
        $this->assertTrue($this->driver->exists($name));
        $this->assertSame($token, $this->driver->get_token($name));

        $this->assertTrue($this->driver->release($name, $token));
        $this->assertFalse($this->driver->exists($name));
    }

    public function test_acquire_fails_when_locked(): void
    {
        $name = 'test_double_' . uniqid();
        $token1 = bin2hex(random_bytes(16));
        $token2 = bin2hex(random_bytes(16));

        $this->assertTrue($this->driver->acquire($name, $token1, 60));
        $this->assertFalse($this->driver->acquire($name, $token2, 60));

        $this->driver->release($name, $token1);
    }

    public function test_release_wrong_token(): void
    {
        $name = 'test_wrongtoken_' . uniqid();
        $token = bin2hex(random_bytes(16));
        $wrongToken = bin2hex(random_bytes(16));

        $this->driver->acquire($name, $token, 60);
        $this->assertFalse($this->driver->release($name, $wrongToken));

        $this->driver->release($name, $token);
    }

    public function test_force_release(): void
    {
        $name = 'test_force_' . uniqid();
        $token = bin2hex(random_bytes(16));

        $this->driver->acquire($name, $token, 60);
        $this->assertTrue($this->driver->force_release($name));
        $this->assertFalse($this->driver->exists($name));
    }

    public function test_expired_lock_can_be_acquired(): void
    {
        $name = 'test_expire_' . uniqid();
        $token1 = bin2hex(random_bytes(16));
        $token2 = bin2hex(random_bytes(16));

        $this->driver->acquire($name, $token1, 1);
        sleep(2);
        $this->assertTrue($this->driver->acquire($name, $token2, 60));

        $this->driver->release($name, $token2);
    }

    public function test_get_token_nonexistent(): void
    {
        $this->assertNull($this->driver->get_token('nonexistent_' . uniqid()));
    }

    public function test_exists_nonexistent(): void
    {
        $this->assertFalse($this->driver->exists('nonexistent_' . uniqid()));
    }
}
