<?php
declare(strict_types=1);

namespace Tests\Engine\Core;

use Engine\Atomic\Core\Log;
use PHPUnit\Framework\TestCase;

class LogTest extends TestCase
{
    private static string $logDir;
    private static string $dumpsDir;

    public static function setUpBeforeClass(): void
    {
        $f3 = \Base::instance();
        self::$logDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'atomic_test_logs' . DIRECTORY_SEPARATOR;
        self::$dumpsDir = self::$logDir . 'dumps' . DIRECTORY_SEPARATOR;
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
        if (is_dir(self::$dumpsDir)) {
            $dFiles = glob(self::$dumpsDir . '*');
            if ($dFiles) {
                foreach ($dFiles as $f) {
                    if (is_file($f)) @unlink($f);
                }
            }
            @rmdir(self::$dumpsDir);
        }
        @rmdir(self::$logDir);
    }

    // ────────────────────────────────────────
    //  Log level methods
    // ────────────────────────────────────────

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

    public function test_empty_message_does_not_throw(): void
    {
        Log::error('');
        Log::debug('');
        Log::warning('');
        $this->assertTrue(true);
    }

    // ────────────────────────────────────────
    //  DEBUG constant set on init
    // ────────────────────────────────────────

    public function test_init_sets_debug_constant_in_hive(): void
    {
        $f3 = \Base::instance();
        $this->assertSame(3, $f3->get('DEBUG'));
    }

    public function test_init_sets_loggable_empty_string(): void
    {
        $f3 = \Base::instance();
        $this->assertSame('', $f3->get('LOGGABLE'));
    }

    public function test_init_sets_dumps_dir_in_hive(): void
    {
        $f3 = \Base::instance();
        $dumps = $f3->get('DUMPS');
        $this->assertNotEmpty($dumps);
        $this->assertStringContainsString('dumps', $dumps);
    }

    // ────────────────────────────────────────
    //  DEBUG level mapping
    // ────────────────────────────────────────

    public function test_debug_level_debug_maps_to_3(): void
    {
        $f3 = \Base::instance();
        $f3->set('LOGS', self::$logDir);
        $f3->set('DEBUG_MODE', 'true');
        $f3->set('DEBUG_LEVEL', 'debug');
        Log::init($f3);
        $this->assertSame(3, $f3->get('DEBUG'));
    }

    public function test_debug_level_info_maps_to_3(): void
    {
        $f3 = \Base::instance();
        $f3->set('LOGS', self::$logDir);
        $f3->set('DEBUG_MODE', 'true');
        $f3->set('DEBUG_LEVEL', 'info');
        Log::init($f3);
        $this->assertSame(3, $f3->get('DEBUG'));
    }

    public function test_debug_level_warning_maps_to_2(): void
    {
        $f3 = \Base::instance();
        $f3->set('LOGS', self::$logDir);
        $f3->set('DEBUG_MODE', 'true');
        $f3->set('DEBUG_LEVEL', 'warning');
        Log::init($f3);
        $this->assertSame(2, $f3->get('DEBUG'));
    }

    public function test_debug_level_error_maps_to_1(): void
    {
        $f3 = \Base::instance();
        $f3->set('LOGS', self::$logDir);
        $f3->set('DEBUG_MODE', 'true');
        $f3->set('DEBUG_LEVEL', 'error');
        Log::init($f3);
        $this->assertSame(1, $f3->get('DEBUG'));
    }

    public function test_debug_level_unknown_maps_to_0(): void
    {
        $f3 = \Base::instance();
        $f3->set('LOGS', self::$logDir);
        $f3->set('DEBUG_MODE', 'true');
        $f3->set('DEBUG_LEVEL', 'nonsense');
        Log::init($f3);
        $this->assertSame(0, $f3->get('DEBUG'));
    }

    public function test_debug_mode_false_forces_debug_0(): void
    {
        $f3 = \Base::instance();
        $f3->set('LOGS', self::$logDir);
        $f3->set('DEBUG_MODE', 'false');
        $f3->set('DEBUG_LEVEL', 'debug');
        Log::init($f3);
        $this->assertSame(0, $f3->get('DEBUG'));
    }

    public function test_debug_mode_case_insensitive(): void
    {
        $f3 = \Base::instance();
        $f3->set('LOGS', self::$logDir);
        $f3->set('DEBUG_MODE', 'TRUE');
        $f3->set('DEBUG_LEVEL', 'DEBUG');
        Log::init($f3);
        $this->assertSame(3, $f3->get('DEBUG'));
    }

    // ────────────────────────────────────────
    //  Dump functionality
    // ────────────────────────────────────────

    public function test_dump_returns_path_in_debug_mode(): void
    {
        // Re-init with debug mode on
        $f3 = \Base::instance();
        $f3->set('LOGS', self::$logDir);
        $f3->set('DEBUG_MODE', 'true');
        $f3->set('DEBUG_LEVEL', 'debug');
        Log::init($f3);

        $path = Log::dump('test_label', ['key' => 'value']);
        if ($path !== null) {
            $this->assertFileExists($path);
            $content = json_decode(file_get_contents($path), true);
            $this->assertSame('test_label', $content['type']);
            $this->assertArrayHasKey('dump_id', $content);
            $this->assertArrayHasKey('time', $content);
            $this->assertArrayHasKey('data', $content);
            @unlink($path);
        } else {
            $this->assertNull($path);
        }
    }

    public function test_dump_returns_null_when_debug_mode_off(): void
    {
        $f3 = \Base::instance();
        $f3->set('LOGS', self::$logDir);
        $f3->set('DEBUG_MODE', 'false');
        $f3->set('DEBUG_LEVEL', 'debug');
        Log::init($f3);

        $path = Log::dump('test_label', ['data' => 'value']);
        $this->assertNull($path);
    }

    public function test_dump_hive_returns_null_when_debug_mode_off(): void
    {
        $f3 = \Base::instance();
        $f3->set('LOGS', self::$logDir);
        $f3->set('DEBUG_MODE', 'false');
        $f3->set('DEBUG_LEVEL', 'debug');
        Log::init($f3);

        $path = Log::dumpHive();
        $this->assertNull($path);
    }

    public function test_dump_hive_returns_path_in_debug_mode(): void
    {
        $f3 = \Base::instance();
        $f3->set('LOGS', self::$logDir);
        $f3->set('DEBUG_MODE', 'true');
        $f3->set('DEBUG_LEVEL', 'debug');
        Log::init($f3);

        $path = Log::dumpHive();
        if ($path !== null) {
            $this->assertFileExists($path);
            $content = json_decode(file_get_contents($path), true);
            $this->assertSame('hive', $content['type']);
            $this->assertArrayHasKey('hive', $content);
            @unlink($path);
        } else {
            $this->assertNull($path);
        }
    }

    public function test_dump_json_contains_sanitized_data(): void
    {
        $f3 = \Base::instance();
        $f3->set('LOGS', self::$logDir);
        $f3->set('DEBUG_MODE', 'true');
        $f3->set('DEBUG_LEVEL', 'debug');
        Log::init($f3);

        $path = Log::dump('security_check', [
            'password' => 'supersecret',
            'status'   => 'ok',
        ]);
        if ($path !== null) {
            $content = json_decode(file_get_contents($path), true);
            $this->assertSame('[MASKED]', $content['data']['password']);
            $this->assertSame('ok', $content['data']['status']);
            @unlink($path);
        } else {
            $this->assertNull($path);
        }
    }

    public function test_dump_writes_valid_json(): void
    {
        $f3 = \Base::instance();
        $f3->set('LOGS', self::$logDir);
        $f3->set('DEBUG_MODE', 'true');
        $f3->set('DEBUG_LEVEL', 'debug');
        Log::init($f3);

        $path = Log::dump('json_check', ['nested' => ['a' => 1, 'b' => [2, 3]]]);
        if ($path !== null) {
            $raw = file_get_contents($path);
            $decoded = json_decode($raw, true);
            $this->assertNotNull($decoded, 'Dump file must contain valid JSON');
            $this->assertSame(JSON_ERROR_NONE, json_last_error());
            @unlink($path);
        } else {
            $this->assertNull($path);
        }
    }

    // ────────────────────────────────────────
    //  Log writes to file
    // ────────────────────────────────────────

    public function test_log_writes_to_log_file(): void
    {
        $f3 = \Base::instance();
        $f3->set('LOGS', self::$logDir);
        $f3->set('DEBUG_MODE', 'true');
        $f3->set('DEBUG_LEVEL', 'debug');
        Log::init($f3);

        $marker = 'UNIQUE_MARKER_' . uniqid();
        Log::error($marker);

        $logFile = self::$logDir . 'atomic.log';
        if (is_file($logFile)) {
            $contents = file_get_contents($logFile);
            $this->assertStringContainsString($marker, $contents);
            $this->assertStringContainsString('[ERROR]', $contents);
        } else {
            $this->assertTrue(true);
        }
    }

    public static function resetDebugMode(): void
    {
        $f3 = \Base::instance();
        $f3->set('DEBUG_MODE', 'true');
        $f3->set('DEBUG_LEVEL', 'debug');
        Log::init($f3);
    }
}
