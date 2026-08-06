<?php
declare(strict_types=1);
namespace Engine\Atomic\Core\Middleware;

if (!defined('ATOMIC_START')) exit;

interface MiddlewareInterface
{
    /**
     * Handle the incoming request.
     *
     * Return true to pass to the next middleware in the chain.
     * To abort: send a response (reroute / json_error) which calls exit internally.
     * If this method returns false, the chain stops and the controller action is not called.
     */
    public function handle(\Base $atomic): bool;
}
