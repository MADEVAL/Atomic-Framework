<?php
declare(strict_types=1);
namespace App\Http\Middleware;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\Middleware\MiddlewareInterface;
use Engine\Atomic\Auth\Auth;

class EnsureEmailIsVerified implements MiddlewareInterface
{
    public function handle(\Base $atomic): bool
    {
        $user = Auth::instance()->get_current_user();
        if ($user === null) {
            $atomic->reroute('/login');
            return false;
        }

        if (method_exists($user, 'hasVerifiedEmail') && !$user->hasVerifiedEmail()) {
            $atomic->reroute('/email/verify');
            return false;
        }

        return true;
    }

    public function process(mixed $request, callable $next): \Engine\Atomic\Http\Response
    {
        throw new \RuntimeException('Not yet migrated to process() pattern');
    }
}
