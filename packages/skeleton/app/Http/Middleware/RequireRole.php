<?php
declare(strict_types=1);
namespace App\Http\Middleware;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\Middleware\MiddlewareInterface;

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
        $user = \Engine\Atomic\Auth\Auth::instance()->get_current_user();
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

        \Engine\Atomic\Core\App::instance()->send_json_error('Forbidden', 403);
        return false;
    }

    public function process(mixed $request, callable $next): \Engine\Atomic\Http\Response
    {
        throw new \RuntimeException('Not yet migrated to process() pattern');
    }
}
