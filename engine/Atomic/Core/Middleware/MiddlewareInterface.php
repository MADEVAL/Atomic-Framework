<?php
declare(strict_types=1);
namespace Engine\Atomic\Core\Middleware;

if (!defined('ATOMIC_START')) exit;

interface MiddlewareInterface
{
    /**
     * Handle the incoming request (legacy pattern, deprecated in v0.4).
     *
     * Return true to pass to the next middleware in the chain.
     * To abort: send a response (reroute / json_error) which calls exit internally.
     * If this method returns false, the chain stops and the controller action is not called.
     *
     * @deprecated Use process() instead
     */
    public function handle(\Base $atomic): bool;

    /**
     * Process the incoming request (new pattern, v0.4+).
     *
     * Process the request and return a Response. Call $next($request)
     * to delegate to the next middleware in the pipeline.
     */
    public function process(mixed $request, callable $next): \Engine\Atomic\Http\Response;
}
