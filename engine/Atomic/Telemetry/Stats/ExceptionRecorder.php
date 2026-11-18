<?php
declare(strict_types=1);
namespace Engine\Atomic\Telemetry\Stats;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Hook\Hook;

final class ExceptionRecorder
{
    public const HOOK_RECORDED = 'atomic_exception_recorded';

    /**
     * @param array{class:string,message:string,file:?string,line:?int,code:int,
     *     level:string,path:string,dump_id:?string,trace?:?string} $payload
     */
    public static function capture(array $payload): void
    {
        try {
            self::record($payload);
        } catch (\Throwable $e) {
            \Engine\Atomic\Core\Log::debug('[Stats] exception capture failed: ' . $e->getMessage());
        }

        try {
            Hook::instance()->do_action(self::HOOK_RECORDED, $payload);
        } catch (\Throwable $e) {
            // Listener failures must not affect error handling.
        }
    }

    private static function record(array $payload): void
    {
        if (!StatsConnection::enabled()) {
            return;
        }

        $now = time();
        $class = mb_substr((string)($payload['class'] ?? 'unknown'), 0, 191);
        $message = mb_substr((string)($payload['message'] ?? ''), 0, 2000);
        $file = mb_substr((string)($payload['file'] ?? ''), 0, 255);
        $line = max(0, (int)($payload['line'] ?? 0));
        $fingerprint = sha1($class . '|' . $message . '|' . $file . ':' . $line);

        StatsRecorder::instance()->record('exceptions.occurrences', 1, ['fp' => $fingerprint]);
        StatsRecorder::instance()->record_exception_group($fingerprint, [
            'class' => $class,
            'message' => $message,
            'file' => $file,
            'line' => $line,
            'code' => (int)($payload['code'] ?? 0),
            'level' => mb_substr((string)($payload['level'] ?? 'error'), 0, 32),
            'path' => mb_substr((string)($payload['path'] ?? ''), 0, 255),
            'dump_id' => $payload['dump_id'] ?? null,
            'trace' => isset($payload['trace']) ? mb_substr((string)$payload['trace'], 0, 4000) : null,
            'first_seen' => $now,
            'last_seen' => $now,
        ]);
        StatsRecorder::instance()->record_event('exceptions.occurrence', ['fp' => $fingerprint], [
            'path' => (string)($payload['path'] ?? ''),
            'dump_id' => $payload['dump_id'] ?? null,
        ]);
    }
}
