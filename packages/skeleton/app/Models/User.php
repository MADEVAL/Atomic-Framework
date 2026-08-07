<?php
declare(strict_types=1);
namespace App\Models;

if (!defined('ATOMIC_START')) exit;

use DB\SQL\Schema;
use Engine\Atomic\App\Model;
use Engine\Atomic\Auth\Interfaces\AuthenticatableInterface;
use Engine\Atomic\Auth\Interfaces\HasRolesInterface;

final class User extends Model implements AuthenticatableInterface, HasRolesInterface
{
    protected $table = 'users';
    protected $db = 'DB';
    protected $fieldConf = [
        'uuid' => [
            'type' => Schema::DT_VARCHAR128,
            'nullable' => false,
            'unique' => true,
            'index' => true,
        ],
        'name' => [
            'type' => Schema::DT_VARCHAR256,
            'nullable' => false,
        ],
        'email' => [
            'type' => Schema::DT_VARCHAR256,
            'nullable' => false,
            'unique' => true,
        ],
        'password' => [
            'type' => Schema::DT_VARCHAR256,
            'nullable' => false,
        ],
        'role' => [
            'type' => Schema::DT_VARCHAR64,
            'nullable' => false,
            'default' => 'user',
        ],
        'email_verified_at' => [
            'type' => Schema::DT_TIMESTAMP,
            'nullable' => true,
        ],
        'remember_token' => [
            'type' => Schema::DT_VARCHAR128,
            'nullable' => true,
        ],
        'created_at' => [
            'type' => Schema::DT_TIMESTAMP,
            'nullable' => false,
            'default' => 'CURRENT_TIMESTAMP',
        ],
        'updated_at' => [
            'type' => Schema::DT_TIMESTAMP,
            'nullable' => false,
            'default' => 'CURRENT_TIMESTAMP',
        ],
    ];

    public function get_auth_id(): string
    {
        return (string)$this->uuid;
    }

    public function get_password_hash(): ?string
    {
        $hash = $this->password ?? null;
        return ($hash !== null && $hash !== '') ? $hash : null;
    }

    public function get_role_slugs(): array
    {
        $role = $this->role ?? null;
        return $role ? [(string)$role] : [];
    }

    public function hasVerifiedEmail(): bool
    {
        return $this->email_verified_at !== null;
    }
}
