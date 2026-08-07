<?php
declare(strict_types=1);
namespace App\Http\Middleware;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\Middleware\MiddlewareInterface;
use Engine\Atomic\Core\Response;
use Engine\Atomic\Http\Response as HttpResponse;
use Engine\Atomic\Auth\Auth;

class RequireRole implements MiddlewareInterface
{
    /** @var string[] */
    private array $roles;

    public function __construct(?string $roles = null)
    {
        $this->roles = $roles !== null ? explode(',', $roles) : [];
    }

    public function handle(\Base $atomic): bool
    {
        $user = Auth::instance()->get_current_user();
        if ($user === null) {
            $atomic->reroute('/login');
            return false;
        }

        $userRoles = $user->get_role_slugs();
        foreach ($this->roles as $required) {
            if (in_array($required, $userRoles, true)) {
                return true;
            }
        }

        Response::instance()->send_json_error('Forbidden', 403);
    }

    public function process(mixed $request, callable $next): HttpResponse
    {
        $user = Auth::instance()->get_current_user();
        if ($user === null) {
            return new HttpResponse(302, '', ['Location' => '/login']);
        }

        $userRoles = $user->get_role_slugs();
        foreach ($this->roles as $required) {
            if (in_array($required, $userRoles, true)) {
                return $next($request);
            }
        }

        return HttpResponse::json(['error' => 'Forbidden'], 403);
    }
}
