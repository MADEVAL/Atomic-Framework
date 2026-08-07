<?php
declare(strict_types=1);

namespace Engine\Atomic\Security\Middleware;
if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\Middleware\MiddlewareInterface;
use Engine\Atomic\Http\Response;

final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    private const DEFAULT_HEADERS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'DENY',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
    ];

    /** @var array<string, string> */
    private array $headers;

    /** @param array<string, string> $headers */
    public function __construct(array $headers = [])
    {
        $this->headers = array_merge(self::DEFAULT_HEADERS, $headers);
    }

    public function handle(\Base $atomic): bool { return true; }

    public function process(mixed $request, callable $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        foreach ($this->headers as $name => $value) {
            $response = $response->withHeader($name, $value);
        }

        return $response;
    }
}