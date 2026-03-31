<?php
declare(strict_types=1);
namespace Engine\Atomic\Core;

if (!defined('ATOMIC_START')) exit;

use \Engine\Atomic\Core\ID;
use \Engine\Atomic\Core\Response;

class Log
{
    protected static \Log $logger;
    protected static bool $debugMode = false;
    protected static int $debug = 0;
    protected static string $dumpsDir = '';
    protected static ?\Base $atomic = null;

    public static function init(\Base $atomic): void
    {
        self::$atomic = $atomic; 
        self::$logger = new \Log('atomic.log');
        self::$debugMode = (bool)filter_var($atomic->get('DEBUG_MODE'), FILTER_VALIDATE_BOOLEAN);

        $lvl = strtolower((string)$atomic->get('DEBUG_LEVEL'));
        self::$debug = match ($lvl) {
            'debug', 'info' => 3,
            'warning'       => 2,
            'error'         => 1,
            default         => 0,
        };
        if (!self::$debugMode) self::$debug = 0;
        $atomic->set('DEBUG', self::$debug);
        $atomic->set('LOGGABLE', ''); 

        $logs = rtrim((string)$atomic->get('LOGS'), '/\\') . DIRECTORY_SEPARATOR;
        self::$dumpsDir = $logs . 'dumps' . DIRECTORY_SEPARATOR;
        $atomic->set('DUMPS', self::$dumpsDir);
    }

    protected static function levelInt(string $level): int
    {
        return match (strtolower($level)) {
            'debug', 'info', 'notice'                 => 3,
            'warning'                                 => 2,
            'error', 'critical', 'alert', 'emergency' => 1,
            default                                   => 0,
        };
    }

    protected static function write(string $level, string $message): void
    {
        if (!self::$debugMode) return;
        if (empty($message)) return;
        if (self::levelInt((string)$level) > self::$debug) return;
        self::$logger?->write('[' . strtoupper((string)$level) . '] ' . $message);
    }

    protected static function ensureDumpsDir(): bool
    {
        if (self::$dumpsDir === '') return false;
        if (is_dir(self::$dumpsDir)) return is_writable(self::$dumpsDir);
        @mkdir(self::$dumpsDir, 0775, true);
        return is_dir(self::$dumpsDir) && is_writable(self::$dumpsDir);
    }

    protected static function normalize(mixed $hive_data, int $depth = 0, int $maxDepth = 6, int $maxItems = 1000): mixed
    {
        if ($depth >= $maxDepth) return '[[max_depth]]';
        if (is_null($hive_data) || is_scalar($hive_data)) return $hive_data;

        if (is_array($hive_data)) {
            $out = [];
            $i = 0;
            foreach ($hive_data as $key => $value) {
                if ($i++ >= $maxItems) { $out['[[truncated]]'] = true; break; }
                $out[$key] = self::normalize($value, $depth+1, $maxDepth, $maxItems);
            }
            return $out;
        }

        if (is_object($hive_data)) {
            if ($hive_data instanceof \DateTimeInterface) return $hive_data->format(\DateTimeInterface::RFC3339_EXTENDED);
            $cls = get_class($hive_data);
            return ['__object__' => $cls];
        }

        if (is_resource($hive_data)) return ['__resource__' => get_resource_type($hive_data) ?: 'resource'];
        return '[[unsupported]]';
    }

    protected static function dumpToJson(string $filenameUuid, array $payload): ?string
    {
        if (!self::ensureDumpsDir()) {
            self::write('warning', '[HIVE] dumps dir not writable');
            return null;
        }

        $path = self::$dumpsDir . $filenameUuid . '.json';

        try {
            $json = Response::instance()->atomic_json_encode(
                self::normalize($payload),
                JSON_PRETTY_PRINT
            );
        } catch (\Throwable $e) {
            $json = json_encode(
                self::normalize($payload),
                  JSON_PRETTY_PRINT 
                | JSON_UNESCAPED_UNICODE 
                | JSON_UNESCAPED_SLASHES 
                | JSON_PRESERVE_ZERO_FRACTION 
                | (defined('JSON_PARTIAL_OUTPUT_ON_ERROR') ? JSON_PARTIAL_OUTPUT_ON_ERROR : 0)
            );
        }

        @file_put_contents($path, $json, LOCK_EX);
        return is_file($path) ? $path : null;
    }

    public static function dumpHive(): ?string
    {
        if (!self::$debugMode || !self::$atomic) return null;
        $atomic_instance = self::$atomic;

        $uuid = ID::uuid_v4();
        $payload = [
            'dump_id' => $uuid,
            'type'    => 'hive',
            'time'    => date('c'),
            'hive'    => $atomic_instance->hive(),
        ];
        $path = self::dumpToJson($uuid, $payload);
        if ($path !== null) self::write('debug', '[HIVE] dump_id=' . $uuid);
        return $path;
    }

    public static function dump(string $label, mixed $data): ?string
    {
        if (!self::$debugMode) return null;
        $uuid = ID::uuid_v4();
        $payload = [
            'dump_id' => $uuid,
            'type'    => $label,
            'time'    => date('c'),
            'data'    => $data,
        ];
        $path = self::dumpToJson($uuid, $payload);
        if ($path !== null) self::write('debug', '[' . $label . '] dump_id=' . $uuid);
        return $path;
    }

    public static function emergency(string $msg): void { self::write('emergency', $msg); }
    public static function alert(string $msg): void     { self::write('alert', $msg); }
    public static function critical(string $msg): void  { self::write('critical', $msg); }
    public static function error(string $msg): void     { self::write('error', $msg); }
    public static function warning(string $msg): void   { self::write('warning', $msg); }
    public static function notice(string $msg): void    { self::write('notice', $msg); }
    public static function info(string $msg): void      { self::write('info', $msg); }
    public static function debug(string $msg): void     { self::write('debug', $msg); }
}