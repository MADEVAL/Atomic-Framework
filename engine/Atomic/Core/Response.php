<?php
declare(strict_types=1);
namespace Engine\Atomic\Core;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\Methods as AM;
use Engine\Atomic\Core\Traits\Singleton;

class Response {
    use Singleton;

    private const HEADER_LOCATION = 'Location';
    private const HEADER_CONTENT_TYPE = 'Content-Type';
    private const HEADER_TRANSFER_ENCODING = 'Transfer-Encoding';
    private const HEADER_HTTP_PREFIX = 'HTTP/';
    private const CONTENT_TYPE_HTML = 'text/html';

    public const STATUS_CONTINUE = 100;

    public const STATUS_OK = 200;
    public const STATUS_CREATED = 201;
    public const STATUS_ACCEPTED = 202;
    public const STATUS_NO_CONTENT = 204;

    public const STATUS_MOVED_PERMANENTLY = 301;
    public const STATUS_FOUND = 302;
    public const STATUS_SEE_OTHER = 303;
    public const STATUS_NOT_MODIFIED = 304;
    public const STATUS_TEMPORARY_REDIRECT = 307;
    public const STATUS_PERMANENT_REDIRECT = 308;

    public const STATUS_BAD_REQUEST = 400;
    public const STATUS_UNAUTHORIZED = 401;
    public const STATUS_FORBIDDEN = 403;
    public const STATUS_NOT_FOUND = 404;
    public const STATUS_METHOD_NOT_ALLOWED = 405;
    public const STATUS_UNPROCESSABLE_ENTITY = 422;
    public const STATUS_TOO_MANY_REQUESTS = 429;

    public const STATUS_INTERNAL_SERVER_ERROR = 500;
    public const STATUS_NOT_IMPLEMENTED = 501;
    public const STATUS_BAD_GATEWAY = 502;
    public const STATUS_SERVICE_UNAVAILABLE = 503;
    public const STATUS_GATEWAY_TIMEOUT = 504;

    private function __construct() {}

    public function send_json(mixed $data, int $status = self::STATUS_OK, bool $terminate = true): void
    {
        $this->json_response($data, $status, $terminate);
    }

    public function redirect(string $url, int $status = self::STATUS_FOUND, bool $terminate = true): void
    {
        App::instance()->status($status);

        if (!headers_sent()) {
            header(self::HEADER_LOCATION . ': ' . $url);
        }

        if ($terminate) {
            exit;
        }
    }

    public function send_html(string $html, int $status = self::STATUS_OK, bool $terminate = true): void
    {
        $this->send_content($html, self::CONTENT_TYPE_HTML, $status, $terminate);
    }

    public function send_text(string $text, int $status = self::STATUS_OK, bool $terminate = true): void
    {
        $this->send_content($text, 'text/plain', $status, $terminate);
    }

    public function send_json_error(string $msg, int $status = self::STATUS_BAD_REQUEST, array $extra = [], bool $terminate = true): void
    {
        $this->json_response(['error' => $msg] + $extra, $status, $terminate);
    }

    public function send_json_success(array $data = [], int $status = self::STATUS_OK, bool $terminate = true): void
    {
        $this->json_response(['success' => true] + $data, $status, $terminate);
    }

    public function json_response(mixed $data, int $status = self::STATUS_OK, bool $terminate = true): void
    {
        App::instance()->status($status);

        if (!headers_sent()) {
            $encoding = (string)(AM::instance()->get_encoding() ?: 'UTF-8');
            header('Content-Type: application/json; charset=' . $encoding);
        }

        echo $this->atomic_json_encode($data);

        if ($terminate) {
            exit;
        }
    }

    public function proxy(array $response, bool $terminate = true): void
    {
        App::instance()->status((int)($response['status'] ?? self::STATUS_OK));

        if (!headers_sent()) {
            foreach (($response['raw_headers'] ?? []) as $header) {
                if (!is_string($header) || $header === '') {
                    continue;
                }

                if (stripos($header, self::HEADER_HTTP_PREFIX) === 0) {
                    continue;
                }

                if (stripos($header, self::HEADER_TRANSFER_ENCODING . ':') === 0) {
                    continue;
                }

                header($header, false);
            }
        }

        echo (string)($response['body'] ?? '');

        if ($terminate) {
            exit;
        }
    }

    public function atomic_json_encode(mixed $data, int $flags = 0, int $depth = 512): string
    {
        $base = JSON_UNESCAPED_UNICODE
              | JSON_UNESCAPED_SLASHES
              | JSON_PRESERVE_ZERO_FRACTION
              | JSON_THROW_ON_ERROR;

        return json_encode($data, $base | $flags, $depth);
    }

    private function send_content(string $content, string $type, int $status, bool $terminate): void
    {
        App::instance()->status($status);

        if (!headers_sent()) {
            $encoding = (string)(AM::instance()->get_encoding() ?: 'UTF-8');
            header(self::HEADER_CONTENT_TYPE . ': ' . $type . '; charset=' . $encoding);
        }

        echo $content;

        if ($terminate) {
            exit;
        }
    }

}
