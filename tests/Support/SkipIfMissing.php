<?php

declare(strict_types=1);

namespace Tests\Support;

trait SkipIfMissing
{
    protected function requirePcntl(): void     { PlatformGuard::requirePcntl(); }
    protected function requirePosix(): void     { PlatformGuard::requirePosix(); }
    protected function requireProcFS(): void    { PlatformGuard::requireProcFS(); }
    protected function requireMySql(): void     { PlatformGuard::requireMySql(); }
    protected function requireRedis(): void     { PlatformGuard::requireRedis(); }
    protected function requireMemcached(): void { PlatformGuard::requireMemcached(); }
    protected function requireSodium(): void    { PlatformGuard::requireSodium(); }
    protected function requireExtension(string $ext): void { PlatformGuard::requireExtension($ext); }
}
