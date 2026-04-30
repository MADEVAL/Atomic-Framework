<?php
declare(strict_types=1);
namespace Engine\Atomic\Core;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\Log;
use Engine\Atomic\Core\Traits\Singleton;

final class Request
{
    use Singleton;

    private const HEADER_CONTENT_TYPE = 'Content-Type';
    private const SERVER_CONTENT_TYPE = 'CONTENT_TYPE';
    private const SERVER_HTTP_CONTENT_TYPE = 'HTTP_CONTENT_TYPE';
    private const CONTENT_TYPE_FORM = 'application/x-www-form-urlencoded';
    private const CONTENT_TYPE_JSON = 'application/json';
    private const CONTENT_TYPE_MULTIPART = 'multipart/form-data';
    private const METHOD_GET = 'GET';
    private const METHOD_HEAD = 'HEAD';
    private const METHOD_POST = 'POST';
    private const METHOD_PUT = 'PUT';
    private const METHOD_PATCH = 'PATCH';
    private const METHOD_DELETE = 'DELETE';

    private string $ua;
    private int $retries;
    private int $timeout;
    private ?string $engine;

    private function __construct()
    {
        $this->ua      = \defined('ATOMIC_HTTP_USERAGENT') ? (string)\ATOMIC_HTTP_USERAGENT : ('AtomicHTTP PHP/'.PHP_VERSION);
        $this->retries = \defined('ATOMIC_HTTP_RETRIES')   ? (int)\ATOMIC_HTTP_RETRIES       : 0;
        $this->timeout = \defined('ATOMIC_HTTP_TIMEOUT')   ? (int)\ATOMIC_HTTP_TIMEOUT       : ((int)ini_get('default_socket_timeout') ?: 30);
        $this->engine  = \defined('ATOMIC_HTTP_ENGINE')    ? strtolower((string)\ATOMIC_HTTP_ENGINE) : null;
    }

    public function raw_body(): string
    {
        $body = file_get_contents('php://input');
        return $body === false ? '' : $body;
    }

    public function parsed_body(?string $raw = null, ?string $content_type = null): array
    {
        $content_type = strtolower($content_type ?? $this->content_type());

        if (str_contains($content_type, self::CONTENT_TYPE_MULTIPART)) {
            return $_POST;
        }

        $raw ??= $this->raw_body();
        if ($raw === '') {
            return [];
        }

        if (str_contains($content_type, self::CONTENT_TYPE_JSON)) {
            try {
                $data = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
                return is_array($data) ? $data : [];
            } catch (\JsonException $e) {
                Log::error('JSON decode error: ' . $e->getMessage());
                return [];
            }
        }

        if (str_contains($content_type, self::CONTENT_TYPE_FORM)) {
            parse_str($raw, $data);
            return $data;
        }

        return [];
    }

    public function input(?string $key = null, mixed $default = null): mixed
    {
        $data = $this->parsed_body();

        if ($key === null) {
            return $data;
        }

        return $data[$key] ?? $default;
    }

    public function is_json_request(): bool
    {
        return str_contains(strtolower($this->content_type()), self::CONTENT_TYPE_JSON);
    }

    public function remote_get(string $url, array $args = []): array
    {
        if (!empty($args['query']) && is_array($args['query'])) {
            $url = $this->append_query($url, $args['query']);
        }
        return $this->send(self::METHOD_GET, $url, $args);
    }

    public function remote_head(string $url, array $args = []): array
    {
        if (!empty($args['query']) && is_array($args['query'])) {
            $url = $this->append_query($url, $args['query']);
        }
        return $this->send(self::METHOD_HEAD, $url, $args);
    }

    public function remote_post(string $url, array|string|null $data = null, array $args = []): array
    {
        $args = $this->prepare_body($data, $args);
        return $this->send(self::METHOD_POST, $url, $args);
    }

    public function remote_put(string $url, array|string|null $data = null, array $args = []): array
    {
        $args = $this->prepare_body($data, $args);
        return $this->send(self::METHOD_PUT, $url, $args);
    }

    public function remote_patch(string $url, array|string|null $data = null, array $args = []): array
    {
        $args = $this->prepare_body($data, $args);
        return $this->send(self::METHOD_PATCH, $url, $args);
    }

    public function remote_delete(string $url, array $args = []): array
    {
        return $this->send(self::METHOD_DELETE, $url, $args);
    }

    public function json(array $response, bool $assoc = true): mixed
    {
        $body = (string)($response['body'] ?? '');
        if ($body === '') {
            return null;
        }

        try {
            return json_decode($body, $assoc, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return null;
        }
    }

    public function is_json(array $response): bool
    {
        $header = $this->get_header($response, self::HEADER_CONTENT_TYPE);
        if (is_array($header)) {
            foreach ($header as $value) {
                if (stripos((string)$value, self::CONTENT_TYPE_JSON) !== false) {
                    return true;
                }
            }
            return false;
        }

        return is_string($header) && stripos($header, self::CONTENT_TYPE_JSON) !== false;
    }

    public function get_header(array $response, string $name): string|array|null
    {
        return $response['headers'][strtolower($name)] ?? null;
    }

    private function send(string $method, string $url, array $args): array
    {
        $web = \Web::instance();
        if ($this->engine) {
            $web->engine($this->engine); 
        }

        $headers = $this->build_headers($args['headers'] ?? []);
        $options = [
            'method'          => strtoupper($method),
            'header'          => $headers,
            'follow_location' => $args['follow']   ?? true,
            'max_redirects'   => $args['redirects']?? 10,
            'encoding'        => $args['encoding'] ?? 'gzip,deflate',
            'timeout'         => $args['timeout']  ?? $this->timeout,
            'user_agent'      => $args['user_agent'] ?? $this->ua,
        ];
        if (!empty($args['proxy']))   $options['proxy']   = (string)$args['proxy'];
        if (array_key_exists('content', $args)) {
                $options['content'] = $args['content']; 
        }

        $retries = (int)($args['retries'] ?? $this->retries);
        $delayMs = (int)($args['retry_delay_ms'] ?? 250);

        $attempt = 0;
        do {
            $resp   = $web->request($url, $options);
            $status = $this->status($resp['headers'] ?? []);
            $error  = (string)($resp['error'] ?? '');
            $temporary = $error !== '' || ($status >= 500 && $status < 600);
            if (!$temporary || $attempt >= $retries) break;
            usleep(max(0,$delayMs)*1000);
            $attempt++;
        } while (true);

        return [
            'ok'          => $status >= 200 && $status < 300,
            'status'      => $status,
            'headers'     => $this->headers_assoc($resp['headers'] ?? []),
            'raw_headers' => $resp['headers'] ?? [],
            'body'        => (string)($resp['body'] ?? ''),
            'engine'      => $resp['engine'] ?? null,
            'cached'      => (bool)($resp['cached'] ?? false),
            'error'       => (string)($resp['error'] ?? ''),
            'url'         => $url,
            'request'     => $resp['request'] ?? [],
        ];
        
    }

    private function build_headers(array $user): array
    {
        $looksLikeLines = isset($user[0]) && is_string($user[0]) && str_contains($user[0], ':');
        if ($looksLikeLines) {
            return $user;
        }

        $base = [
            'Accept-Encoding' => 'gzip,deflate',
            'Connection'      => 'close',
            'User-Agent'      => $this->ua,
        ];
        $assoc = array_merge($base, $user);

        $list = [];
        foreach ($assoc as $k => $v) {
            $list[] = $k . ': ' . $v;
        }
        return $list;
    }

    private function prepare_body(array|string|null $data, array $args): array
    {
        if (is_array($data)) {
            if (!empty($args['json'])) {
                $args['content'] = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $args['headers'][self::HEADER_CONTENT_TYPE] = $args['headers'][self::HEADER_CONTENT_TYPE] ?? self::CONTENT_TYPE_JSON;
            } elseif (empty($args['raw'])) {
                $args['content'] = http_build_query($data, '', '&', PHP_QUERY_RFC3986);
                $args['headers'][self::HEADER_CONTENT_TYPE] = $args['headers'][self::HEADER_CONTENT_TYPE] ?? self::CONTENT_TYPE_FORM;
            }
        } elseif (is_string($data)) {
            $args['content'] = $data;
        }

        return $args;
    }

    private function append_query(string $url, array $query): string
    {
        $qs = http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        if ($qs === '') return $url;
        return $url.(str_contains($url,'?') ? '&' : '?').$qs;
    }

    private function status(array $headers): int
    {
        foreach ($headers as $h) {
            if (preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#', $h, $m)) return (int)$m[1];
        }
        return 0;
    }

    private function headers_assoc(array $headers): array
    {
        $out = [];
        foreach ($headers as $line) {
            $pos = strpos($line, ':');
            if ($pos === false) continue;
            $k = strtolower(trim(substr($line, 0, $pos)));
            $v = trim(substr($line, $pos+1));
            if (!isset($out[$k])) $out[$k] = $v;
            else {
                if (is_array($out[$k])) $out[$k][] = $v;
                else $out[$k] = [$out[$k], $v];
            }
        }
        return $out;
    }

    private function content_type(): string
    {
        $type = $_SERVER[self::SERVER_CONTENT_TYPE]
            ?? $_SERVER[self::SERVER_HTTP_CONTENT_TYPE]
            ?? null;

        if ($type === null && class_exists(App::class, false)) {
            $type = App::atomic()->get('HEADERS.' . self::HEADER_CONTENT_TYPE);
        }

        return is_string($type) ? $type : '';
    }
}
