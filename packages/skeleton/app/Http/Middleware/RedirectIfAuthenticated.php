<?php
declare(strict_types=1);
namespace App\Http\Middleware;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\Middleware\MiddlewareInterface;
use Engine\Atomic\Auth\Auth;
use Engine\Atomic\Http\Response as HttpResponse;

class RedirectIfAuthenticated implements MiddlewareInterface
{
    public function handle(\Base $atomic): bool
    {
        if (Auth::instance()->get_current_user() !== null) {
            $atomic->reroute('/dashboard');
            return false;
        }
        return true;
    }

    public function process(mixed $request, callable $next): HttpResponse
    {
        if (Auth::instance()->get_current_user() !== null) {
            return HttpResponse::redirect('/dashboard');
        }
        return $next($request);
    }
}
