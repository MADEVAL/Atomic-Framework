<?php
declare(strict_types=1);
namespace Engine\Atomic\Queue\Monitor;

if (!defined( 'ATOMIC_START' ) ) exit;

final class PosixProcessProbe implements ProcessProbeInterface
{
    private const EPERM = 1;
    private const ESRCH = 3;

    public function exists(int $pid): array
    {
        $result = \posix_kill($pid, 0);
        $error = \posix_get_last_error();

        if ($result === true) {
            return ['exists' => true, 'error' => null, 'is_permission_error' => false];
        }

        if ($error === self::EPERM) {
            return ['exists' => true, 'error' => $error, 'is_permission_error' => true];
        }

        return ['exists' => false, 'error' => $error ?: self::ESRCH, 'is_permission_error' => false];
    }

    public function signal(int $pid, int $signal): bool
    {
        return \posix_kill($pid, $signal);
    }

    public function sleep(int $seconds): void
    {
        \sleep($seconds);
    }

    public function usleep(int $microseconds): void
    {
        \usleep($microseconds);
    }
}
