<?php

declare(strict_types=1);

namespace Engine\Atomic\Core;

use Engine\Atomic\Core\Middleware\MiddlewareInterface;
use Engine\Atomic\Http\Response;

final class HttpKernel
{
    /** @var list<MiddlewareInterface|callable> */
    private array $middleware = [];

    /** @var callable */
    private $coreHandler;

    public function __construct(
        private readonly Container $container,
    ) {
        $this->coreHandler = fn() => Response::html('', 200);
    }

    /** @param string|callable|MiddlewareInterface $middleware */
    public function appendMiddleware(MiddlewareInterface|callable $middleware): self
    {
        $this->middleware[] = $middleware;
        return $this;
    }

    /** @param string|callable|MiddlewareInterface $middleware */
    public function prependMiddleware(MiddlewareInterface|callable $middleware): self
    {
        array_unshift($this->middleware, $middleware);
        return $this;
    }

    public function handle(mixed $request): Response
    {
        $pipeline = $this->coreHandler;

        foreach (array_reverse($this->middleware) as $middleware) {
            $next = $pipeline;
            $mw = $middleware;
            $pipeline = function ($req) use ($mw, $next) {
                if ($mw instanceof MiddlewareInterface) {
                    return $mw->process($req, $next);
                }
                return $mw($req, $next);
            };
        }

        return $pipeline($request);
    }
}
