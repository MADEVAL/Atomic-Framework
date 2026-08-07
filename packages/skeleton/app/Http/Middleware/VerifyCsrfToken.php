<?php
declare(strict_types=1);
namespace App\Http\Middleware;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\Middleware\MiddlewareInterface;

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

        if ($token === null || !\Engine\Atomic\Security\CsrfTokenManager::validateStatic($atomic, (string)$token)) {
            \Engine\Atomic\Core\App::instance()->send_json_error('CSRF token mismatch', 419);
            return false;
        }

        return true;
    }

    public function process(mixed $request, callable $next): \Engine\Atomic\Http\Response
    {
        throw new \RuntimeException('Not yet migrated to process() pattern');
    }
}
