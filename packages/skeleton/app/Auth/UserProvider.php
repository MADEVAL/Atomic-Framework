<?php
declare(strict_types=1);
namespace App\Auth;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Auth\Interfaces\AuthenticatableInterface;
use Engine\Atomic\Auth\Interfaces\UserProviderInterface;
use App\Http\Models\User;

class UserProvider implements UserProviderInterface
{
    public function find_by_credentials(array $credentials): ?AuthenticatableInterface
    {
        $email = $credentials['email'] ?? null;
        if (!$email) {
            return null;
        }

        $user = new User();
        $user->load(['email = ?', $email]);

        if ($user->dry()) {
            return null;
        }

        return $user;
    }

    public function find_by_id(string $auth_id): ?AuthenticatableInterface
    {
        $user = new User();
        $user->load(['uuid = ?', $auth_id]);

        if ($user->dry()) {
            return null;
        }

        return $user;
    }
}
