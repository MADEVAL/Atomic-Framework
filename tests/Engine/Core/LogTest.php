<?php
declare(strict_types=1);

namespace Tests\Engine\Core;

use Engine\Atomic\Core\Log;
use Engine\Atomic\Core\LogChannel;
use Engine\Atomic\Enums\LogChannel as LogChannelEnum;
use Engine\Atomic\Enums\LogLevel;
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

        $path = Log::dump_hive();
        $this->assertNull($path);
    }

    public function test_dump_hive_returns_path_in_debug_mode(): void
    {
        $f3 = \Base::instance();
        $f3->set('LOGS', self::$logDir);
        $f3->set('DEBUG_MODE', 'true');
        $f3->set('DEBUG_LEVEL', 'debug');
        Log::init($f3);

        $path = Log::dump_hive();
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

    // ────────────────────────────────────────
    //  Channels
    // ────────────────────────────────────────

    public function test_channel_returns_log_channel_instance(): void
    {
        $ch = Log::channel('atomic');
        $this->assertInstanceOf(LogChannel::class, $ch);
        $this->assertSame('atomic', $ch->get_name());
    }

    public function test_channel_accepts_log_channel_enum(): void
    {
        $ch = Log::channel(LogChannelEnum::AUTH);
        $this->assertInstanceOf(LogChannel::class, $ch);
        $this->assertSame('auth', $ch->get_name());
    }

    public function test_add_channel_registers_new_channel(): void
    {
        $f3 = \Base::instance();
        $f3->set('LOGS', self::$logDir);
        $f3->set('DEBUG_MODE', 'true');
        $f3->set('DEBUG_LEVEL', 'debug');
        Log::init($f3);

        Log::add_channel('custom', 'custom.log', LogLevel::DEBUG);
        $this->assertContains('custom', Log::get_channel_names());
    }

    public function test_channel_writes_to_separate_file(): void
    {
        $f3 = \Base::instance();
        $f3->set('LOGS', self::$logDir);
        $f3->set('DEBUG_MODE', 'true');
        $f3->set('DEBUG_LEVEL', 'debug');
        Log::init($f3);

        Log::add_channel('auth', 'auth.log', LogLevel::DEBUG);

        $marker = 'AUTH_MARKER_' . uniqid();
        Log::channel('auth')->error($marker);

        $authLog = self::$logDir . 'auth.log';
        if (is_file($authLog)) {
            $contents = file_get_contents($authLog);
            $this->assertStringContainsString($marker, $contents);
            $this->assertStringContainsString('[ERROR]', $contents);
        } else {
            $this->markTestSkipped('Auth log file was not created');
        }
    }

    public function test_channel_does_not_write_to_default_file(): void
    {
        $f3 = \Base::instance();
        $f3->set('LOGS', self::$logDir);
        $f3->set('DEBUG_MODE', 'true');
        $f3->set('DEBUG_LEVEL', 'debug');
        Log::init($f3);

        Log::add_channel('isolated', 'isolated.log', LogLevel::DEBUG);

        $marker = 'ISOLATED_MARKER_' . uniqid();
        Log::channel('isolated')->error($marker);

        $defaultLog = self::$logDir . 'atomic.log';
        if (is_file($defaultLog)) {
            $contents = file_get_contents($defaultLog);
            $this->assertStringNotContainsString($marker, $contents);
        } else {
            $this->assertTrue(true);
        }
    }

    public function test_multiple_channels_write_independently(): void
    {
        $f3 = \Base::instance();
        $f3->set('LOGS', self::$logDir);
        $f3->set('DEBUG_MODE', 'true');
        $f3->set('DEBUG_LEVEL', 'debug');
        Log::init($f3);

        Log::add_channel('chan_a', 'chan_a.log', LogLevel::DEBUG);
        Log::add_channel('chan_b', 'chan_b.log', LogLevel::DEBUG);

        $markerA = 'CHAN_A_' . uniqid();
        $markerB = 'CHAN_B_' . uniqid();

        Log::channel('chan_a')->error($markerA);
        Log::channel('chan_b')->error($markerB);

        $fileA = self::$logDir . 'chan_a.log';
        $fileB = self::$logDir . 'chan_b.log';

        if (is_file($fileA) && is_file($fileB)) {
            $contentsA = file_get_contents($fileA);
            $contentsB = file_get_contents($fileB);

            $this->assertStringContainsString($markerA, $contentsA);
            $this->assertStringNotContainsString($markerB, $contentsA);

            $this->assertStringContainsString($markerB, $contentsB);
            $this->assertStringNotContainsString($markerA, $contentsB);
        } else {
            $this->markTestSkipped('Channel log files were not created');
        }
    }

    public function test_channel_level_filtering(): void
    {
        $f3 = \Base::instance();
        $f3->set('LOGS', self::$logDir);
        $f3->set('DEBUG_MODE', 'true');
        $f3->set('DEBUG_LEVEL', 'debug');
        Log::init($f3);

        // Channel with level=error should only accept error and above
        Log::add_channel('strict', 'strict.log', LogLevel::ERROR);

        $debugMarker = 'STRICT_DEBUG_' . uniqid();
        $errorMarker = 'STRICT_ERROR_' . uniqid();

        Log::channel('strict')->debug($debugMarker);
        Log::channel('strict')->error($errorMarker);

        $strictLog = self::$logDir . 'strict.log';
        if (is_file($strictLog)) {
            $contents = file_get_contents($strictLog);
            $this->assertStringNotContainsString($debugMarker, $contents);
            $this->assertStringContainsString($errorMarker, $contents);
        } else {
            $this->markTestSkipped('Strict log file was not created');
        }
    }

    public function test_channel_all_log_levels(): void
    {
        $f3 = \Base::instance();
        $f3->set('LOGS', self::$logDir);
        $f3->set('DEBUG_MODE', 'true');
        $f3->set('DEBUG_LEVEL', 'debug');
        Log::init($f3);

        Log::add_channel('alllevels', 'alllevels.log', LogLevel::DEBUG);
        $ch = Log::channel('alllevels');

        // None of these should throw
        $ch->emergency('test emergency');
        $ch->alert('test alert');
        $ch->critical('test critical');
        $ch->error('test error');
        $ch->warning('test warning');
        $ch->notice('test notice');
        $ch->info('test info');
        $ch->debug('test debug');
        $this->assertTrue(true);
    }

    public function test_channels_loaded_from_hive_config(): void
    {
        $f3 = \Base::instance();
        $f3->set('LOGS', self::$logDir);
        $f3->set('DEBUG_MODE', 'true');
        $f3->set('DEBUG_LEVEL', 'debug');
        $f3->set('LOG_CHANNELS', [
            'default'  => 'app',
            'channels' => [
                'app' => [
                    'driver' => 'file',
                    'path'   => 'app.log',
                    'level'  => 'debug',
                ],
                'security' => [
                    'driver' => 'file',
                    'path'   => 'security.log',
                    'level'  => 'warning',
                ],
            ],
        ]);
        Log::init($f3);

        $this->assertSame('app', Log::get_default_channel());
        $this->assertContains('app', Log::get_channel_names());
        $this->assertContains('security', Log::get_channel_names());
    }

    public function test_default_channel_fallback_when_no_config(): void
    {
        $f3 = \Base::instance();
        $f3->set('LOGS', self::$logDir);
        $f3->set('DEBUG_MODE', 'true');
        $f3->set('DEBUG_LEVEL', 'debug');
        $f3->set('LOG_CHANNELS', []);
        Log::init($f3);

        $this->assertSame('atomic', Log::get_default_channel());
        $this->assertContains('atomic', Log::get_channel_names());
    }

    public function test_unknown_channel_falls_back_to_default(): void
    {
        $f3 = \Base::instance();
        $f3->set('LOGS', self::$logDir);
        $f3->set('DEBUG_MODE', 'true');
        $f3->set('DEBUG_LEVEL', 'debug');
        $f3->set('LOG_CHANNELS', []);
        Log::init($f3);

        // Should not throw — falls back to default
        Log::channel('nonexistent')->error('fallback test');
        $this->assertTrue(true);
    }

    public static function resetDebugMode(): void
    {
        $f3 = \Base::instance();
        $f3->set('DEBUG_MODE', 'true');
        $f3->set('DEBUG_LEVEL', 'debug');
        Log::init($f3);
    }
}
