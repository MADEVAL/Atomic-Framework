<?php
declare(strict_types=1);
namespace Engine\Atomic\Core;

if (!defined('ATOMIC_START')) exit;

final class Request
{
    protected App $atomic;
    private static ?self $instance = null;

    private string $ua;
    private int $retries;
    private int $timeout;
    private ?string $engine;

    private function __construct()
    {
        $this->atomic  = App::instance();
        $this->ua      = \defined('ATOMIC_HTTP_USERAGENT') ? (string)\ATOMIC_HTTP_USERAGENT : ('AtomicHTTP PHP/'.PHP_VERSION);
        $this->retries = \defined('ATOMIC_HTTP_RETRIES')   ? (int)\ATOMIC_HTTP_RETRIES       : 0;
        $this->timeout = \defined('ATOMIC_HTTP_TIMEOUT')   ? (int)\ATOMIC_HTTP_TIMEOUT       : ((int)ini_get('default_socket_timeout') ?: 30);
        $this->engine  = \defined('ATOMIC_HTTP_ENGINE')    ? strtolower((string)\ATOMIC_HTTP_ENGINE) : null;
    }

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function remote_get(string $url, array $args = []): array
    {
        if (!empty($args['query']) && is_array($args['query'])) {
            $url = $this->append_query($url, $args['query']);
        }
        return $this->send('GET', $url, $args);
    }

    public function remote_head(string $url, array $args = []): array
    {
        if (!empty($args['query']) && is_array($args['query'])) {
            $url = $this->append_query($url, $args['query']);
        }
        return $this->send('HEAD', $url, $args);
    }

    public function remote_post(string $url, array|string|null $data = null, array $args = []): array
    {
        if (is_array($data) && empty($args['raw'])) {
            $args['content'] = http_build_query($data, '', '&', PHP_QUERY_RFC3986);
            $args['headers']['Content-Type'] = $args['headers']['Content-Type'] ?? 'application/x-www-form-urlencoded';
        } elseif (is_string($data)) {
            $args['content'] = $data;
        }
        return $this->send('POST', $url, $args);
    }

    public function remote_put(string $url, array|string|null $data = null, array $args = []): array
    {
        if (is_array($data) && empty($args['raw'])) {
            $args['content'] = http_build_query($data, '', '&', PHP_QUERY_RFC3986);
            $args['headers']['Content-Type'] = $args['headers']['Content-Type'] ?? 'application/x-www-form-urlencoded';
        } elseif (is_string($data)) {
            $args['content'] = $data;
        }
        return $this->send('PUT', $url, $args);
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
}
