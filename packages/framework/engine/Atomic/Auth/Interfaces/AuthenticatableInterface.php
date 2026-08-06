<?php
declare(strict_types=1);
namespace Engine\Atomic\Auth\Interfaces;

if (!defined('ATOMIC_START')) exit;

interface AuthenticatableInterface
{
    public function get_auth_id(): string;
    public function get_password_hash(): ?string;
}
