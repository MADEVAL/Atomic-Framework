<?php
declare(strict_types=1);
namespace App\Http\Models;

if (!defined('ATOMIC_START')) exit;

use DB\SQL\Schema;
use Engine\Atomic\App\Model;
use Engine\Atomic\Auth\Interfaces\AuthenticatableInterface;
use Engine\Atomic\Auth\Interfaces\HasRolesInterface;

class User extends Model implements AuthenticatableInterface, HasRolesInterface
{
    protected $table = 'users';
    protected $db = 'DB';
    protected $fieldConf = [
        'uuid' => [
            'type' => Schema::DT_VARCHAR128,
            'nullable' => false,
            'unique' => true,
        ],
        'name' => [
            'type' => Schema::DT_VARCHAR256,
            'nullable' => true,
        ],
        'email' => [
            'type' => Schema::DT_VARCHAR256,
            'nullable' => false,
            'unique' => true,
        ],
        'password' => [
            'type' => Schema::DT_VARCHAR256,
            'nullable' => true,
        ],
        'role' => [
            'type' => Schema::DT_VARCHAR128,
            'nullable' => true,
        ],
        'created_at' => [
            'type' => Schema::DT_TIMESTAMP,
            'nullable' => true,
        ],
        'updated_at' => [
            'type' => Schema::DT_TIMESTAMP,
            'nullable' => true,
        ],
    ];

    public function get_auth_id(): string
    {
        return (string)$this->uuid;
    }

    public function get_password_hash(): ?string
    {
        $hash = $this->password ?? null;
        return empty($hash) ? null : $hash;
    }

    public function get_role_slugs(): array
    {
        $role = $this->role ?? null;
        return $role ? [(string)$role] : [];
    }
}
