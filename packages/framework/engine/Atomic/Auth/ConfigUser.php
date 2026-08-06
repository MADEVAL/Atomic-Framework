<?php
declare(strict_types=1);
namespace Engine\Atomic\Auth;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Auth\Interfaces\AuthenticatableInterface;
use Engine\Atomic\Auth\Interfaces\HasRolesInterface;

final class ConfigUser implements AuthenticatableInterface, HasRolesInterface
{
    /** @param string[] $roles */
    public function __construct(
        private string $auth_id,
        private string $username,
        private ?string $password_hash,
        private array $roles = [],
    ) {}

    public function get_auth_id(): string
    {
        return $this->auth_id;
    }

    public function get_username(): string
    {
        return $this->username;
    }

    public function get_password_hash(): ?string
    {
        return $this->password_hash;
    }

    public function get_role_slugs(): array
    {
        return $this->roles;
    }
}
