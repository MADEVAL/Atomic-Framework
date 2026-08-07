<?php
declare(strict_types=1);
namespace App\Http\Middleware;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\Middleware\MiddlewareInterface;
use Engine\Atomic\App\Controller;

class RedirectIfAuthenticated implements MiddlewareInterface
{
    public function handle(\Base $atomic): bool
    {
        if (\Engine\Atomic\Auth\Auth::instance()->get_current_user() !== null) {
            $atomic->reroute('/dashboard');
            return false;
        }
        return true;
    }

    public function process(mixed $request, callable $next): \Engine\Atomic\Http\Response
    {
        throw new \RuntimeException('Not yet migrated to process() pattern');
    }
}
