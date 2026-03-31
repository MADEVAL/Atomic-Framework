<?php
declare(strict_types=1);
namespace Engine\Atomic\Auth\Interfaces;

if (!defined('ATOMIC_START')) exit;

interface AuthSessionInterface
{
    public function start(string $uuid = ''): void;
    public function is_started(): bool;
    public function destroy(): void;
}
