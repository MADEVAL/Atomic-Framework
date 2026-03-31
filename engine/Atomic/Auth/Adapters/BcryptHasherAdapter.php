<?php
declare(strict_types=1);
namespace Engine\Atomic\Auth\Adapters;

if (!defined('ATOMIC_START')) exit;

class BcryptHasherAdapter
{
    public function verify(string $password, string $hash): bool
    {
        return \Bcrypt::instance()->verify($password, $hash);
    }
}
