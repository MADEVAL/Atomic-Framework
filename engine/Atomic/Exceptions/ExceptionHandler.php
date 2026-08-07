<?php

declare(strict_types=1);

namespace Engine\Atomic\Exceptions;
if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\Config\V2\Config;
use Engine\Atomic\Http\Response;

final class ExceptionHandler
{
    /** @param object $logger Must have error() and warning() methods */
    public function __construct(
        private readonly Config $config,
        private readonly object $logger,
    ) {}

    /** @return Response Never calls exit/die */
    public function handle(\Throwable $e, bool $expectsJson = false): Response
    {
        $this->logger->error($e->getMessage(), [
            'exception' => $e::class,
            'file' => $e->getFile(),
            'line' => $e->getLine(),
            'trace' => $e->getTraceAsString(),
        ]);

        if ($e instanceof HttpException) {
            return $this->renderHttpException($e, $expectsJson);
        }

        return $this->renderServerError($e, $expectsJson);
    }

    private function renderHttpException(HttpException $e, bool $expectsJson): Response
    {
        if ($expectsJson) {
            return Response::json(['error' => $e->getMessage()], $e->statusCode());
        }

        $body = sprintf(
            '<html><body><h1>%d</h1><p>%s</p></body></html>',
            $e->statusCode(),
            htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'),
        );

        return Response::html($body, $e->statusCode());
    }

    private function renderServerError(\Throwable $e, bool $expectsJson): Response
    {
        if ($this->config->bool('DEBUG_MODE')) {
            $message = $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine();
            if ($expectsJson) {
                return Response::json(['error' => $message, 'trace' => $e->getTraceAsString()], 500);
            }
            $body = '<html><body><h1>500</h1><pre>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</pre></body></html>';
            return Response::html($body, 500);
        }

        if ($expectsJson) {
            return Response::json(['error' => 'Internal Server Error'], 500);
        }

        return Response::html('<html><body><h1>500</h1><p>Internal Server Error</p></body></html>', 500);
    }
}