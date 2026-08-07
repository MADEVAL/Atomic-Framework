<?php
declare(strict_types=1);
namespace App\Http\Middleware;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\Middleware\MiddlewareInterface;
use Engine\Atomic\Core\Response;
use Engine\Atomic\Http\Response as HttpResponse;
use Engine\Atomic\Security\CsrfTokenManager;

class VerifyCsrfToken implements MiddlewareInterface
{
    /** @return string[] */
    protected function excludedPaths(): array
    {
        return ['/api/webhooks/*'];
    }

    public function handle(\Base $atomic): bool
    {
        // GET/HEAD/OPTIONS are safe
        $verb = $atomic->get('VERB');
        if (in_array($verb, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return true;
        }

        $path = $atomic->get('PATH');
        foreach ($this->excludedPaths() as $pattern) {
            if (fnmatch($pattern, $path)) {
                return true;
            }
        }

        $token = $atomic->get('HEADERS.X-CSRF-TOKEN')
              ?? $atomic->get('POST._csrf_token');

        if ($token === null || !CsrfTokenManager::validateStatic($atomic, (string)$token)) {
            Response::instance()->send_json_error('CSRF token mismatch', 419, [], false);
            return false;
        }

        return true;
    }

    public function process(mixed $request, callable $next): HttpResponse
    {
        $verb = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if (in_array($verb, ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        foreach ($this->excludedPaths() as $pattern) {
            if (fnmatch($pattern, $path)) {
                return $next($request);
            }
        }

        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['_csrf_token'] ?? null;
        if ($token === null) {
            return HttpResponse::json(['error' => 'CSRF token missing'], 419);
        }

        if (!CsrfTokenManager::validateStatic(\Base::instance(), (string)$token)) {
            return HttpResponse::json(['error' => 'CSRF token mismatch'], 419);
        }

        return $next($request);
    }
}
