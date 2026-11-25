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
    private int $max_bytes;
    private ?string $engine;
    private readonly \Closure $public_addresses;
    private readonly \Closure $transport;

    public const ERROR_URL_NOT_ALLOWED = 'URL not allowed: only public http/https URLs are permitted';
    public const ERROR_TOO_LARGE = 'Response exceeds the size limit';
    public const ERROR_TIMEOUT = 'Request timed out';
    private const REDIRECT_STATUSES = [301, 302, 303, 307, 308];

    /**
     * @param (callable(string): list<string>)|null $public_addresses
     *        Validated addresses for one public-only hop, or [] to refuse it; defaults to PublicUrlGuard::from_config()->resolve().
     * @param (callable(string, array<int, mixed>): array{headers: list<string>, body: string, error: string, exceeded?: bool})|null $transport
     *        Runs one public-only hop with the given cURL options; defaults to cURL.
     *        `exceeded` reports a body larger than CURLOPT_MAXFILESIZE.
     */
    private function __construct(?callable $public_addresses = null, ?callable $transport = null)
    {
        $this->public_addresses = $public_addresses === null
            ? static fn(string $url): array => PublicUrlGuard::from_config()->resolve($url)
            : \Closure::fromCallable($public_addresses);
        $this->transport = $transport === null ? self::curl_transport(...) : \Closure::fromCallable($transport);
        $this->ua      = \defined('ATOMIC_HTTP_USERAGENT') ? (string)\ATOMIC_HTTP_USERAGENT : ('AtomicHTTP PHP/'.PHP_VERSION);
        $this->retries = \defined('ATOMIC_HTTP_RETRIES')   ? (int)\ATOMIC_HTTP_RETRIES       : 0;
        $this->timeout = \defined('ATOMIC_HTTP_TIMEOUT')   ? (int)\ATOMIC_HTTP_TIMEOUT       : ((int)ini_get('default_socket_timeout') ?: 30);
        $this->max_bytes = \defined('ATOMIC_HTTP_MAX_BYTES') ? (int)\ATOMIC_HTTP_MAX_BYTES  : 20 * 1024 * 1024;
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
            if (strlen($raw) > 65536) {
                return [];
            }
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

    public function wants_json(\Base $atomic): bool
    {
        $accept = strtolower((string)$atomic->get('HEADERS.Accept'));
        $requested_with = strtolower((string)$atomic->get('HEADERS.X-Requested-With'));
        $content_type = strtolower((string)$atomic->get('HEADERS.Content-Type'));

        return str_contains($accept, self::CONTENT_TYPE_JSON)
            || str_contains($content_type, self::CONTENT_TYPE_JSON)
            || $requested_with === 'xmlhttprequest';
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
        if (!empty($args['public_only'])) {
            return $this->send_public(strtoupper($method), $url, $args);
        }

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

        return $this->response($url, $status, $resp);
    }

    private function response(string $url, int $status, array $resp): array
    {
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

    /**
     * Like send(), but every hop must pass PublicUrlGuard and connects only to a
     * validated address. Redirects are followed here, never by cURL.
     */
    private function send_public(string $method, string $url, array $args): array
    {
        $headers = $this->build_headers($args['headers'] ?? []);
        // cURL sends these from its own options; the explicit headers would duplicate them.
        $headers = $this->without_headers($headers, ['Accept-Encoding', 'User-Agent']);

        $retries = (int)($args['retries'] ?? $this->retries);
        $delayMs = (int)($args['retry_delay_ms'] ?? 250);

        $attempt = 0;
        do {
            $resp   = $this->public_request($method, $url, $headers, $args);
            $status = $this->status($resp['headers']);
            $temporary = empty($resp['final']) && ($resp['error'] !== '' || ($status >= 500 && $status < 600));
            if (!$temporary || $attempt >= $retries) break;
            usleep(max(0,$delayMs)*1000);
            $attempt++;
        } while (true);

        $result = $this->response($url, $status, $resp);
        // A refused, aborted or failed transfer is never ok, whatever status came before it.
        $result['ok'] = $result['ok'] && $result['error'] === '';
        return $result;
    }

    /** @param list<string> $headers */
    private function public_request(string $method, string $url, array $headers, array $args): array
    {
        $follow   = (bool)($args['follow'] ?? true);
        $max_hops = (int)($args['redirects'] ?? 10);
        $timeout  = (int)($args['timeout'] ?? $this->timeout);
        $max_bytes = max(1, (int)($args['max_bytes'] ?? $this->max_bytes));
        $content  = $args['content'] ?? null;
        // A non-positive timeout means no limit, as in cURL.
        $deadline = $timeout > 0 ? microtime(true) + $timeout : null;
        $refused  = ['headers' => [], 'body' => '', 'error' => self::ERROR_URL_NOT_ALLOWED, 'final' => true, 'request' => []];

        for ($hop = 0; ; $hop++) {
            $addresses = ($this->public_addresses)($url);
            if ($addresses === []) {
                return $refused;
            }
            $remaining = $deadline === null ? 0 : (int)(($deadline - microtime(true)) * 1000);
            if ($deadline !== null && $remaining <= 0) {
                return ['headers' => [], 'body' => '', 'error' => self::ERROR_TIMEOUT, 'request' => []];
            }
            $address = str_contains($addresses[0], ':') ? '[' . $addresses[0] . ']' : $addresses[0];

            $options = [
                CURLOPT_PROTOCOLS      => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_PROXY          => '',
                // Pin the socket to the validated address; the URL keeps its hostname for Host and SNI.
                CURLOPT_CONNECT_TO     => ["::{$address}:"],
                CURLOPT_CONNECTTIMEOUT_MS => $remaining,
                CURLOPT_TIMEOUT_MS     => $remaining,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_ENCODING       => (string)($args['encoding'] ?? 'gzip,deflate'),
                CURLOPT_USERAGENT      => (string)($args['user_agent'] ?? $this->ua),
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_MAXFILESIZE    => $max_bytes,
            ];
            if ($method === self::METHOD_HEAD) {
                $options[CURLOPT_NOBODY] = true;
            }
            if ($content !== null) {
                $options[CURLOPT_POSTFIELDS] = $content;
            }

            $resp = ($this->transport)($url, $options);
            $resp['engine']  = 'cURL';
            $resp['request'] = array_merge([$method . ' ' . $url], $headers);
            if (!empty($resp['exceeded'])) {
                return ['body' => '', 'error' => self::ERROR_TOO_LARGE, 'final' => true] + $resp;
            }

            $status = $this->status($resp['headers']);
            $location = $this->headers_assoc($resp['headers'])['location'] ?? null;
            if ($resp['error'] !== '' || !$follow || !in_array($status, self::REDIRECT_STATUSES, true) || !is_string($location)) {
                return $resp;
            }
            if ($hop >= $max_hops) {
                return ['error' => 'Too many redirects', 'final' => true] + $resp;
            }
            $next = RedirectLocation::resolve($url, $location);
            if ($next === null) {
                return $refused;
            }

            if ($status === 303 || ($method === self::METHOD_POST && $status !== 307 && $status !== 308)) {
                $method  = $method === self::METHOD_HEAD ? self::METHOD_HEAD : self::METHOD_GET;
                $content = null;
                $headers = $this->without_headers($headers, ['Content-Type', 'Content-Length']);
            }
            if (!$this->keeps_credentials($url, $next)) {
                $headers = $this->without_headers($headers, ['Authorization', 'Cookie']);
            }
            $url = $next;
        }
    }

    /**
     * @param list<string> $lines
     * @param list<string> $names
     * @return list<string>
     */
    private function without_headers(array $lines, array $names): array
    {
        $names = array_map('strtolower', $names);
        return array_values(array_filter(
            $lines,
            static fn(string $line): bool => !in_array(strtolower(trim(strstr($line, ':', true) ?: '')), $names, true)
        ));
    }

    /**
     * Credentials survive a redirect on the same host, except an https -> http
     * downgrade or a port change outside https. An http -> https upgrade keeps them.
     */
    private function keeps_credentials(string $from, string $to): bool
    {
        $a = parse_url($from) ?: [];
        $b = parse_url($to) ?: [];
        $host = static fn(array $p): string => rtrim(strtolower($p['host'] ?? ''), '.');
        $scheme = static fn(array $p): string => strtolower($p['scheme'] ?? '');
        $port = static fn(array $p): int => $p['port'] ?? ($scheme($p) === 'https' ? 443 : 80);

        if ($host($a) !== $host($b) || ($scheme($a) === 'https' && $scheme($b) === 'http')) {
            return false;
        }
        return $scheme($a) !== $scheme($b) || $scheme($a) === 'https' || $port($a) === $port($b);
    }

    /**
     * @param array<int, mixed> $options
     * @return array{headers: list<string>, body: string, error: string, exceeded: bool}
     */
    private static function curl_transport(string $url, array $options): array
    {
        $headers = [];
        $body = '';
        $exceeded = false;
        $limit = (int)$options[CURLOPT_MAXFILESIZE];
        // The write callback sees decoded data; allow some expansion but stop gzip bombs.
        $options[CURLOPT_WRITEFUNCTION] = static function ($handle, string $chunk) use (&$body, &$exceeded, $limit): int {
            if (strlen($body) + strlen($chunk) > 4 * $limit) {
                $exceeded = true;
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        };
        // Content-Length can lie or be missing: also check the raw bytes on the wire.
        $options[CURLOPT_NOPROGRESS] = false;
        $options[CURLOPT_PROGRESSFUNCTION] = static function ($handle, $total, $now) use (&$exceeded, $limit): int {
            if ($total > $limit || $now > $limit) {
                $exceeded = true;
                return 1;
            }
            return 0;
        };
        $options[CURLOPT_HEADERFUNCTION] = static function ($handle, string $line) use (&$headers): int {
            $trimmed = trim($line);
            // A status line starts a new header block (e.g. after "100 Continue"); keep only the last.
            if (str_starts_with($trimmed, 'HTTP/')) {
                $headers = [];
            }
            if ($trimmed !== '') {
                $headers[] = $trimmed;
            }
            return strlen($line);
        };

        $handle = curl_init($url);
        if ($handle === false || !curl_setopt_array($handle, $options)) {
            return ['headers' => [], 'body' => '', 'error' => 'Failed to initialize cURL'];
        }
        $ok = curl_exec($handle) !== false;
        $exceeded = $exceeded || curl_errno($handle) === CURLE_FILESIZE_EXCEEDED;
        $error = $ok ? '' : (curl_error($handle) ?: 'Request failed');

        return ['headers' => $headers, 'body' => $body, 'error' => $error, 'exceeded' => $exceeded];
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
