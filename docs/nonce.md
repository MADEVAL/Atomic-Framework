## Nonce ##

Atomic nonces are one-time tokens tied to the request IP and user agent.

Helpers:

```php
$token = create_nonce('delete-post', 1800);

if (!verify_nonce($_POST['nonce'] ?? '', 'delete-post')) {
    send_json_error('Invalid nonce', 403);
}
```

Class usage:

```php
use Engine\Atomic\Tools\Nonce;

$nonce = Nonce::instance();
$token = $nonce->create_nonce('api', 3600);
$valid = $nonce->verify_nonce($token, 'api');
```

### Behavior

- `create_nonce()` generates a random 32-char hex token
- token metadata is stored in the hive with TTL
- `verify_nonce()` checks token existence, IP, and user agent
- verification is destructive: the nonce is cleared after the check

Use the same action string for creation and verification.
