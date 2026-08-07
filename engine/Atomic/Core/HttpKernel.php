<?php
declare(strict_types=1);

namespace Engine\Atomic\Core;

if (!defined('ATOMIC_START')) exit;

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

    /** @param MiddlewareInterface|callable $middleware */
    public function appendMiddleware(MiddlewareInterface|callable $middleware): self
    {
        $this->middleware[] = $middleware;
        return $this;
    }

    /** @param MiddlewareInterface|callable $middleware */
    public function prependMiddleware(MiddlewareInterface|callable $middleware): self
    {
        array_unshift($this->middleware, $middleware);
        return $this;
    }

    public function setCoreHandler(callable $handler): self
    {
        $this->coreHandler = $handler;
        return $this;
    }

    public function handle(mixed $request): Response
    {
        $pipeline = $this->coreHandler;

        foreach (array_reverse($this->middleware) as $middleware) {
            $next = $pipeline;
            $mw = $middleware;
            $pipeline = function ($req) use ($mw, $next): Response {
                if ($mw instanceof MiddlewareInterface) {
                    if ($this->supportsProcess($mw)) {
                        return $mw->process($req, $next);
                    }
                    $atomic = \Base::instance();
                    $passed = $mw->handle($atomic);
                    if ($passed) {
                        return $next($req);
                    }
                    return Response::empty(204);
                }
                return $mw($req, $next);
            };
        }

        return $pipeline($request);
    }

    private function supportsProcess(MiddlewareInterface $mw): bool
    {
        $ref = new \ReflectionClass($mw);
        $method = $ref->getMethod('process');
        $declaringClass = $method->getDeclaringClass()->getName();

        if ($declaringClass === MiddlewareInterface::class) {
            return false;
        }

        $body = $this->getMethodBody($method);
        if ($body !== null && (
            str_contains($body, 'Not yet implemented')
            || str_contains($body, 'Not yet migrated')
            || str_contains($body, 'not yet implemented')
        )) {
            return false;
        }

        return true;
    }

    private function getMethodBody(\ReflectionMethod $method): ?string
    {
        $filename = $method->getFileName();
        if ($filename === false) {
            return null;
        }

        $startLine = $method->getStartLine();
        $endLine = $method->getEndLine();

        if ($startLine === false || $endLine === false) {
            return null;
        }

        $lines = file($filename);
        if ($lines === false) {
            return null;
        }

        return implode('', array_slice($lines, $startLine - 1, $endLine - $startLine + 1));
    }
}
