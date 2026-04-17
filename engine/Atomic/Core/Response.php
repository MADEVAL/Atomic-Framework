<?php
declare(strict_types=1);
namespace Engine\Atomic\Core;

if (!defined( 'ATOMIC_START' ) ) exit;

use Engine\Atomic\Core\Log;
use Engine\Atomic\Core\Methods as AM;
use Engine\Atomic\Core\Traits\Singleton;

class Response {
    use Singleton;

    protected App $atomic;

    private function __construct()
    {
        $this->atomic = App::instance();
    }

    public function send_json(mixed $data, int $status = 200, bool $terminate = true): void
    {
        $this->json_response($data, $status, $terminate);
    }

    public function send_json_error(string $msg, int $status = 400, array $extra = [], bool $terminate = true): void
    {
        $this->json_response(['error' => $msg] + $extra, $status, $terminate);
    }

    public function send_json_success(array $data = [], int $status = 200, bool $terminate = true): void
    {
        $this->json_response(['success' => true] + $data, $status, $terminate);
    }

    public function json_response(mixed $data, int $status = 200, bool $terminate = true): void
    {
        $this->atomic->status($status);

        if (!headers_sent()) {
            $encoding = (string)(AM::instance()->get_encoding() ?: 'UTF-8');
            header('Content-Type: application/json; charset=' . $encoding);
        }

        echo $this->atomic_json_encode($data);

        if ($terminate) {
            exit;
        }
    }

    public function atomic_json_encode(mixed $data, int $flags = 0, int $depth = 512): string
    {
        $base = JSON_UNESCAPED_UNICODE
              | JSON_UNESCAPED_SLASHES
              | JSON_PRESERVE_ZERO_FRACTION;

        if (defined('JSON_THROW_ON_ERROR')) {
            $base |= JSON_THROW_ON_ERROR;
        }

        return json_encode($data, $base | $flags, $depth);
    }

}