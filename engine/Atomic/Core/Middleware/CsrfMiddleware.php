<?php
declare(strict_types=1);
namespace Engine\Atomic\Core\Middleware;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\Response;

final class CsrfMiddleware implements MiddlewareInterface
{
    private const TOKEN_KEY = 'SESSION.csrf_token';
    private const HEADER_NAME = 'X-CSRF-Token';
    private const FIELD_NAME = '_csrf_token';

    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(private string $header = self::HEADER_NAME, private string $field = self::FIELD_NAME) {}

    public function handle(\Base $atomic): bool
    {
        $method = strtoupper((string)$atomic->get('VERB'));

        if (in_array($method, self::SAFE_METHODS, true)) {
            return true;
        }

        $stored_token = $atomic->get(self::TOKEN_KEY);

        if (!is_string($stored_token) || $stored_token === '') {
            return true;
        }

        $request_token = $this->extract_token($atomic);

        if ($request_token === null || !hash_equals($stored_token, $request_token)) {
            Response::instance()->send_json_error(
                'CSRF token mismatch',
                Response::STATUS_FORBIDDEN
            );
            return false;
        }

        return true;
    }

    private function extract_token(\Base $atomic): ?string
    {
        $headers = $atomic->get('HEADERS');
        $header_name = 'HTTP_' . strtoupper(str_replace('-', '_', $this->header));

        if (isset($headers[$this->header]) && is_string($headers[$this->header])) {
            return $headers[$this->header];
        }

        if (isset($_SERVER[$header_name]) && is_string($_SERVER[$header_name])) {
            return $_SERVER[$header_name];
        }

        $body = $atomic->get('BODY');
        if (is_string($body) && $body !== '') {
            $parsed = json_decode($body, true);
            if (is_array($parsed) && isset($parsed[$this->field]) && is_string($parsed[$this->field])) {
                return $parsed[$this->field];
            }
        }

        $post = $atomic->get('POST');
        if (is_array($post) && isset($post[$this->field]) && is_string($post[$this->field])) {
            return $post[$this->field];
        }

        return null;
    }
}
