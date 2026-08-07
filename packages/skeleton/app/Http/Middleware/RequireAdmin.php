<?php
declare(strict_types=1);
namespace App\Http\Middleware;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\Guard;
use Engine\Atomic\Core\Middleware\MiddlewareInterface;
use Engine\Atomic\Core\Response;
use Engine\Atomic\Enums\Role;
use Engine\Atomic\Http\Response as HttpResponse;

class RequireAdmin implements MiddlewareInterface
{
    public function handle(\Base $atomic): bool
    {
        if (Guard::has_role(Role::ADMIN)) {
            return true;
        }

        if ($atomic->get('VERB') === 'GET') {
            $atomic->reroute('/login');
            return false;
        }

        Response::instance()->send_json_error('Forbidden', 403);
        return false;
    }

    public function process(mixed $request, callable $next): HttpResponse
    {
        if (Guard::has_role(Role::ADMIN)) {
            return $next($request);
        }

        return HttpResponse::html('Forbidden', 403);
    }
}
