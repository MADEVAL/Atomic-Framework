<?php
declare(strict_types=1);
namespace Engine\Atomic\Core;

if (!defined('ATOMIC_START')) exit;

use \Engine\Atomic\Core\Filesystem;
use \Engine\Atomic\Core\ID;
use \Engine\Atomic\Core\Response;
use \Engine\Atomic\Core\Redactor;
use \Engine\Atomic\Enums\LogChannel as LogChannelEnum;
use \Engine\Atomic\Enums\LogLevel;

class Log
{
    protected static bool $debug_mode = false;
    protected static string $dumps_dir = '';
    protected static ?\Base $atomic = null;

    /** @var array<string, array{logger: \Log, level: int, path: string}> */
    protected static array $channels = [];

    protected static string $default_channel = 'atomic';

    /** @var array<string, array{driver: string, path: string, level: string}> */
    protected static array $channel_configs = [];


    public static function init(\Base $atomic): void
    {
        self::$atomic = $atomic;
        self::$debug_mode = (bool)filter_var($atomic->get('DEBUG_MODE'), FILTER_VALIDATE_BOOLEAN);
        $debug_level = strtolower((string)$atomic->get('DEBUG_LEVEL'));

        $debug = 0;
        if (self::$debug_mode) {
            $debug = match ($debug_level) {
                'debug', 'info' => 3,
                'warning'       => 2,
                'error'         => 1,
                default         => 0,
            };
        }
        $atomic->set('DEBUG', $debug);

        $atomic->set('LOGGABLE', '');

        $logs = rtrim((string)$atomic->get('LOGS'), '/\\') . DIRECTORY_SEPARATOR;
        self::$dumps_dir = $logs . 'dumps' . DIRECTORY_SEPARATOR;
        $atomic->set('DUMPS', self::$dumps_dir);

        self::$channels = [];
        self::$channel_configs = [];
        self::$default_channel = 'atomic';
        $logging_config = $atomic->get('LOG_CHANNELS');
        if (is_array($logging_config) && !empty($logging_config)) {
            self::$default_channel = (string)($logging_config['default'] ?? 'atomic');
            $channels = $logging_config['channels'] ?? [];
            foreach ($channels as $name => $cfg) {
                self::$channel_configs[$name] = [
                    'driver' => (string)($cfg['driver'] ?? 'file'),
                    'path'   => (string)($cfg['path'] ?? $name . '.log'),
                    'level'  => strtolower((string)($cfg['level'] ?? 'debug')),
                ];
            }
        }

        if (!isset(self::$channel_configs[self::$default_channel])) {
            self::$channel_configs[self::$default_channel] = [
                'driver' => 'file',
                'path'   => 'atomic.log',
                'level'  => 'debug',
            ];
        }

        $default_cfg = self::$channel_configs[self::$default_channel];
        self::$channels[self::$default_channel] = self::build_channel($default_cfg);

        Redactor::init_from_hive($atomic);
    }

    public static function channel(string|LogChannelEnum $name): LogChannel
    {
        if ($name instanceof LogChannelEnum) {
            $name = $name->value;
        }

        return new LogChannel($name);
    }

    public static function resolve_channel(string $name): ?array
    {
        if (!isset(self::$channel_configs[$name])) {
            return null;
        }

        $cfg = self::$channel_configs[$name];

        if (isset(self::$channels[$name]) && self::$channels[$name]['path'] === self::resolve_dated_path($cfg['path'])) {
            return self::$channels[$name];
        }

        self::$channels[$name] = self::build_channel($cfg);

        return self::$channels[$name];
    }

    public static function add_channel(string $name, string $path, LogLevel $level = LogLevel::DEBUG): void
    {
        self::$channel_configs[$name] = [
            'driver' => 'file',
            'path'   => $path,
            'level'  => $level->value,
        ];
        unset(self::$channels[$name]);
    }

    public static function get_channel_names(): array
    {
        return array_keys(self::$channel_configs);
    }

    public static function get_default_channel(): string
    {
        return self::$default_channel;
    }

    public static function get_channel_path(string $name): ?string
    {
        return self::$channel_configs[$name]['path'] ?? null;
    }

    /** @param array{driver: string, path: string, level: string} $cfg */
    protected static function build_channel(array $cfg): array
    {
        $resolved_path = self::resolve_dated_path($cfg['path']);
        return [
            'logger' => new \Log($resolved_path),
            'level'  => LogLevel::from($cfg['level'])->to_int(),
            'path'   => $resolved_path,
        ];
    }

    protected static function resolve_dated_path(string $path): string
    {
        $date = date('Y-m-d');
        $info = pathinfo($path);
        $filename = (string)($info['filename'] ?? $path);
        $basename = preg_replace('/\.\d{4}-\d{2}-\d{2}$/', '', $filename);
        if (!is_string($basename) || $basename === '') {
            $basename = $filename;
        }

        $dated = $basename . '.' . $date;
        if (isset($info['extension']) && $info['extension'] !== '') {
            $dated .= '.' . $info['extension'];
        }

        $dirname = $info['dirname'] ?? '';
        if ($dirname === '' || $dirname === '.') {
            return $dated;
        }

        return $dirname . DIRECTORY_SEPARATOR . $dated;
    }

    public static function write_to_channel(string $channel, LogLevel $level, string $message): void
    {
        if (empty($message)) return;

        $ch = self::resolve_channel($channel);
        if ($ch === null) {
            $ch = self::resolve_channel(self::$default_channel);
            if ($ch === null) return;
        }

        if ($level->to_int() > $ch['level']) return;

        $ch['logger']->write('[' . strtoupper($level->value) . '] ' . Redactor::redact_string($message));
    }

    protected static function write(LogLevel $level, string $message): void
    {
        if (empty($message)) return;
        self::write_to_channel(self::$default_channel, $level, $message);
    }

    protected static function ensure_dumps_dir(): bool
    {
        if (self::$dumps_dir === '') return false;
        if (is_dir(self::$dumps_dir)) return is_writable(self::$dumps_dir);
        Filesystem::instance()->make_dir(self::$dumps_dir, 0775, true);
        return is_dir(self::$dumps_dir) && is_writable(self::$dumps_dir);
    }

    protected static function dump_to_json(string $filename_uuid, array $payload): ?string
    {
        if (!self::ensure_dumps_dir()) {
            self::write(LogLevel::WARNING, '[HIVE] dumps dir not writable');
            return null;
        }

        $path       = self::$dumps_dir . $filename_uuid . '.json';
        $normalized = Redactor::redact($payload);

        try {
            $json = Response::instance()->atomic_json_encode(
                $normalized,
                JSON_PRETTY_PRINT
            );
        } catch (\Throwable $e) {
            $json = json_encode(
                $normalized,
                  JSON_PRETTY_PRINT
                | JSON_UNESCAPED_UNICODE
                | JSON_UNESCAPED_SLASHES
                | JSON_PRESERVE_ZERO_FRACTION
                | (defined('JSON_PARTIAL_OUTPUT_ON_ERROR') ? JSON_PARTIAL_OUTPUT_ON_ERROR : 0)
            );
        }

        Filesystem::instance()->write($path, $json, false);
        return is_file($path) ? $path : null;
    }

    public static function dump_hive(): ?string
    {
        if (!self::$debug_mode || !self::$atomic) return null;
        $atomic_instance = self::$atomic;

        $uuid = ID::uuid_v4();
        $payload = [
            'dump_id' => $uuid,
            'type'    => 'hive',
            'time'    => date('c'),
            'hive'    => $atomic_instance->hive(),
        ];
        $path = self::dump_to_json($uuid, $payload);
        if ($path !== null) self::write(LogLevel::DEBUG, '[HIVE] dump_id=' . $uuid);
        return $path;
    }

    public static function dump(string $label, mixed $data): ?string
    {
        if (!self::$debug_mode) return null;
        $uuid = ID::uuid_v4();
        $payload = [
            'dump_id' => $uuid,
            'type'    => $label,
            'time'    => date('c'),
            'data'    => $data,
        ];
        $path = self::dump_to_json($uuid, $payload);
        if ($path !== null) self::write(LogLevel::DEBUG, '[' . $label . '] dump_id=' . $uuid);
        return $path;
    }

    public static function emergency(string $msg): void { self::write(LogLevel::EMERGENCY, $msg); }
    public static function alert(string $msg): void     { self::write(LogLevel::ALERT, $msg); }
    public static function critical(string $msg): void  { self::write(LogLevel::CRITICAL, $msg); }
    public static function error(string $msg): void     { self::write(LogLevel::ERROR, $msg); }
    public static function warning(string $msg): void   { self::write(LogLevel::WARNING, $msg); }
    public static function notice(string $msg): void    { self::write(LogLevel::NOTICE, $msg); }
    public static function info(string $msg): void      { self::write(LogLevel::INFO, $msg); }
    public static function debug(string $msg): void     { self::write(LogLevel::DEBUG, $msg); }
}
