<?php
declare(strict_types=1);
namespace Engine\Atomic\Core\Middleware;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\Guard;
use Engine\Atomic\Core\Request;
use Engine\Atomic\Core\Response;

final class RoleMiddleware implements MiddlewareInterface
{
    public function __construct(private ?string $role = null) {}

    public function handle(\Base $atomic): bool
    {
        $roles = array_values(array_filter(
            array_map('trim', explode(',', (string)$this->role)),
            static fn (string $role): bool => $role !== ''
        ));

        if ($roles !== [] && Guard::has_any_role($roles)) {
            return true;
        }

        $request = Request::instance();
        $response = Response::instance();
        if ($request->wants_json($atomic)) {
            $response->send_json_error('Forbidden', Response::STATUS_FORBIDDEN, [], false);
            return false;
        }

        $response->send_text('Forbidden', Response::STATUS_FORBIDDEN, false);
        return false;
    }
}
