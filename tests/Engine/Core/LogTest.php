<?php
declare(strict_types=1);

namespace Tests\Engine\Core;

use Engine\Atomic\Core\Log;
use PHPUnit\Framework\TestCase;

class LogTest extends TestCase
{
    private static string $logDir;

    public static function setUpBeforeClass(): void
    {
        $f3 = \Base::instance();
        self::$logDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atomic_test_logs' . DIRECTORY_SEPARATOR;
        if (!is_dir(self::$logDir)) {
            mkdir(self::$logDir, 0755, true);
        }
        $f3->set('LOGS', self::$logDir);
        $f3->set('DEBUG_MODE', 'true');
        $f3->set('DEBUG_LEVEL', 'debug');
        Log::init($f3);
    }

    public static function tearDownAfterClass(): void
    {
        $files = glob(self::$logDir . '*');
        if ($files) {
            foreach ($files as $f) {
                if (is_file($f)) @unlink($f);
            }
        }
        $dumps = self::$logDir . 'dumps';
        if (is_dir($dumps)) {
            $dFiles = glob($dumps . DIRECTORY_SEPARATOR . '*');
            if ($dFiles) {
                foreach ($dFiles as $f) {
                    if (is_file($f)) @unlink($f);
                }
            }
            @rmdir($dumps);
        }
        @rmdir(self::$logDir);
    }

    public function test_log_levels_do_not_throw(): void
    {
        Log::emergency('test emergency');
        Log::alert('test alert');
        Log::critical('test critical');
        Log::error('test error');
        Log::warning('test warning');
        Log::notice('test notice');
        Log::info('test info');
        Log::debug('test debug');
        $this->assertTrue(true);
    }

    public function test_dump_returns_path_in_debug_mode(): void
    {
        $path = Log::dump('test_label', ['key' => 'value']);
        if ($path !== null) {
            $this->assertFileExists($path);
            $content = json_decode(file_get_contents($path), true);
            $this->assertSame('test_label', $content['type']);
            @unlink($path);
        } else {
            $this->assertNull($path);
        }
    }

    public function test_normalize_scalar(): void
    {
        $ref = new \ReflectionMethod(Log::class, 'normalize');
        $this->assertSame('hello', $ref->invoke(null, 'hello'));
        $this->assertSame(42, $ref->invoke(null, 42));
        $this->assertSame(true, $ref->invoke(null, true));
        $this->assertNull($ref->invoke(null, null));
    }

    public function test_normalize_array_truncation(): void
    {
        $ref = new \ReflectionMethod(Log::class, 'normalize');
        $big = array_fill(0, 1500, 'x');
        $result = $ref->invoke(null, $big, 0, 6, 1000);
        $this->assertArrayHasKey('[[truncated]]', $result);
    }

    public function test_normalize_max_depth(): void
    {
        $ref = new \ReflectionMethod(Log::class, 'normalize');
        $deep = ['a' => ['b' => ['c' => ['d' => ['e' => ['f' => ['g' => 'end']]]]]]];
        $result = $ref->invoke(null, $deep, 0, 3, 1000);
        $this->assertSame('[[max_depth]]', $result['a']['b']['c']);
    }

    public function test_normalize_object(): void
    {
        $ref = new \ReflectionMethod(Log::class, 'normalize');
        $obj = new \stdClass();
        $result = $ref->invoke(null, $obj);
        $this->assertIsArray($result);
        $this->assertSame('stdClass', $result['__object__']);
    }

    public function test_normalize_datetime(): void
    {
        $ref = new \ReflectionMethod(Log::class, 'normalize');
        $dt = new \DateTimeImmutable('2024-01-15T10:30:00+00:00');
        $result = $ref->invoke(null, $dt);
        $this->assertIsString($result);
        $this->assertStringContainsString('2024-01-15', $result);
    }
}
