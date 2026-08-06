<?php
declare(strict_types=1);
namespace Engine\Atomic\Auth\Interfaces;

if (!defined('ATOMIC_START')) exit;

interface LoginInterface
{
    public function login_by_id(string $auth_id, array $context = []): void;
}
