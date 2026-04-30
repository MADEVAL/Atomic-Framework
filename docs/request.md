## HTTP Request Helpers

Atomic exposes global helpers for common outbound HTTP requests:

- `remote_get(string $url, array $args = [])`
- `remote_head(string $url, array $args = [])`
- `remote_post(string $url, mixed $data = null, array $args = [])`
- `remote_put(string $url, mixed $data = null, array $args = [])`

The underlying request client is `Engine\Atomic\Core\Request` and also supports `remote_patch()`, `remote_delete()`, `json()`, `is_json()`, `get_header()`, `raw_body()`, `parsed_body()`, `input()`, and `is_json_request()`.

All request methods return:

```php
[
    'ok'          => bool,
    'status'      => int,
    'headers'     => array,
    'raw_headers' => array,
    'body'        => string,
    'engine'      => string|null,
    'cached'      => bool,
    'error'       => string,
    'url'         => string,
    'request'     => array,
]
```

### GET with query and headers

```php
$res = remote_get('https://api.example.com/users', [
    'query' => ['page' => 2, 'limit' => 50],
    'headers' => [
        'Authorization' => 'Bearer ' . $token,
        'Accept' => 'application/json',
    ],
]);

if ($res['ok']) {
    $data = json_decode($res['body'], true);
}
```

### Timeout, retries, redirects, and proxy

```php
$res = remote_get('https://status.example.com/health', [
    'timeout' => 5,
    'retries' => 2,
    'retry_delay_ms' => 200,
    'follow' => true,
    'redirects' => 10,
    'proxy' => 'http://user:pass@proxy.local:8080',
]);
```

Defaults can be configured with `ATOMIC_HTTP_USERAGENT`, `ATOMIC_HTTP_RETRIES`, `ATOMIC_HTTP_TIMEOUT`, and `ATOMIC_HTTP_ENGINE`.

### Incoming request body

Use `Request` directly when a route needs to read JSON or form request bodies.

```php
use Engine\Atomic\Core\Request;

$request = Request::instance();

$body = $request->parsed_body();
$name = $request->input('name', 'Guest');

if ($request->is_json_request()) {
    $raw = $request->raw_body();
}
```

`parsed_body()` returns an array for `application/json`, `application/x-www-form-urlencoded`, and `multipart/form-data`. Invalid JSON and unsupported content types return an empty array.

### POST form data

Array payloads are encoded as `application/x-www-form-urlencoded` by default.

```php
$res = remote_post('https://auth.example.com/login', [
    'username' => 'gs',
    'password' => 'secret',
], [
    'headers' => ['Accept' => 'application/json'],
]);
```

### POST JSON

Pass `json => true` to encode an array payload as JSON and set the content type.

```php
$res = remote_post('https://api.example.com/posts', [
    'title' => 'Hello',
    'published' => true,
], [
    'json' => true,
    'headers' => ['Accept' => 'application/json'],
]);
```

String payloads are sent as-is:

```php
$payload = json_encode(['title' => 'Hello'], JSON_UNESCAPED_UNICODE);

$res = remote_post('https://api.example.com/posts', $payload, [
    'headers' => [
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
    ],
]);
```

### PUT raw text or binary

```php
$csv = "id,name\n1,Alice\n2,Bob\n";

$res = remote_put('https://api.example.com/upload/report.csv', $csv, [
    'headers' => [
        'Content-Type' => 'text/csv; charset=utf-8',
    ],
]);
```

### PATCH and DELETE

PATCH and DELETE are available on the request client.

```php
use Engine\Atomic\Core\Request;

$http = Request::instance();

$res = $http->remote_patch('https://api.example.com/users/123', [
    'name' => 'Alice',
], [
    'json' => true,
]);

$deleted = $http->remote_delete('https://api.example.com/users/123');
```

### HEAD

```php
$head = remote_head('https://cdn.example.com/file.zip');

if ($head['ok']) {
    $len = $head['headers']['content-length'] ?? null;
}
```

### Response helpers

Use the request client helpers when working with JSON responses and normalized headers.

```php
use Engine\Atomic\Core\Request;

$http = Request::instance();
$res = $http->remote_get('https://api.example.com/profile', [
    'headers' => ['Accept' => 'application/json'],
]);

if ($res['ok'] && $http->is_json($res)) {
    $profile = $http->json($res);
}

$contentType = $http->get_header($res, 'Content-Type');
```

`json()` returns `null` for empty or invalid JSON response bodies. Header names are looked up case-insensitively through the normalized lowercase response header map.

### Multipart file upload

For multipart uploads, pass raw content through `content`.

```php
$file = new \CURLFile('/path/to/photo.jpg', 'image/jpeg', 'photo.jpg');

$res = remote_post('https://api.example.com/media', null, [
    'raw' => true,
    'content' => ['file' => $file, 'album' => 'summer-2026'],
    'headers' => ['Accept' => 'application/json'],
]);

if (!$res['ok']) {
    error_log("HTTP error {$res['status']}: {$res['error']} from {$res['url']}");
} else {
    $http = \Engine\Atomic\Core\Request::instance();
    $data = $http->is_json($res)
        ? $http->json($res)
        : $res['body'];
}
```
