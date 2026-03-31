<?php
declare(strict_types=1);
namespace Engine\Atomic\API;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\App;

trait Api   // For use in API controllers
{
    protected ?App $app = null;

    protected function app(): App
    {
        if (!$this->app) $this->app = App::instance();
        return $this->app;
    }

    protected function json(mixed $data, int $code = 200): void
    {
        $this->app()->jsonResponse($data, $code);
    }

    protected function fail(string $msg, int $code = 400, array $extra = []): void
    {
        $this->app()->jsonResponse(['error' => $msg] + $extra, $code);
    }

    protected function body(): array
    {
        $app = $this->app();
        $raw = file_get_contents('php://input') ?: '';
        $ct  = strtolower((string)$app->get('HEADERS.Content-Type'));
        if (str_contains($ct, 'application/json')) {
            $d = json_decode($raw, true);
            return is_array($d) ? $d : [];
        }
        $verb = $app->get('VERB');
        if ($verb === 'GET')  return (array)$app->get('GET');
        if ($verb === 'POST') return (array)$app->get('POST');
        return [];
    }

    protected function param(string $key, mixed $default = null): mixed
    {
        $p = (array)$this->app()->get('PARAMS');
        return $p[$key] ?? $default;
    }
}
