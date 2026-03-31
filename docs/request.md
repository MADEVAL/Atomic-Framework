## HTTP Request Helpers ##

All helpers return:
['ok'=>bool,'status'=>int,'headers'=>array,'body'=>string,'error'=>string,'engine'=>string|null,'cached'=>bool,'url'=>string]

GET with query and headers
```php
$res = remote_get('https://api.example.com/users', [
    'query' => ['page' => 2, 'limit' => 50],
    'headers' => [
        'Authorization' => 'Bearer '.$token,
        'Accept'        => 'application/json',
    ],
]);
if ($res['ok']) {
    $data = json_decode($res['body'], true);
}
```

GET with timeout, retries, proxy and a forced engine
```php
$res = remote_get('https://status.example.com/health', [
    'timeout'       => 5,          
    'retries'       => 2,          
    'retry_delay_ms'=> 200,        
    'proxy'         => 'http://user:pass@proxy.local:8080',
]);
```

POST (application/x-www-form-urlencoded)
```php
$res = remote_post('https://auth.example.com/login', [
    'username' => 'gs',
    'password' => 'secret',
], [

    'headers' => ['Accept' => 'application/json'],
]);
```
POST JSON
```php
$payload = ['title' => 'Hello', 'published' => true];

$res = remote_post('https://api.example.com/posts', json_encode($payload), [
    'headers' => [
        'Content-Type'  => 'application/json',
        'Accept'        => 'application/json',
    ],
    'raw' => true, // not to url-encode arrays
]);
```

PUT raw text/binary
```php
$csv = "id,name\n1,Alice\n2,Bob\n";

$res = remote_put('https://api.example.com/upload/report.csv', $csv, [
    'headers' => [
        'Content-Type' => 'text/csv; charset=utf-8',
    ],
]);
```

HEAD 
```php
$head = remote_head('https://cdn.example.com/file.zip');
if ($head['ok']) {
    $len = $head['headers']['content-length'] ?? null;
}
```

Multipart file upload (cURL)
```php
$file = new \CURLFile('/path/to/photo.jpg', 'image/jpeg', 'photo.jpg'); // TODO Web send ???

$res = remote_post('https://api.example.com/media', null, [
    'raw'     => true,
    'content' => ['file' => $file, 'album' => 'summer-2025'],
    'headers' => ['Accept' => 'application/json'],
]);

if (!$res['ok']) {
    error_log("HTTP error {$res['status']}: {$res['error']} from {$res['url']}");
} else {
    $ct = $res['headers']['content-type'] ?? '';
    if (str_starts_with(strtolower($ct), 'application/json')) {
        $data = json_decode($res['body'], true);
    } else {
        $data = $res['body'];
    }
}
```