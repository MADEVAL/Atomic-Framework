<?php
declare(strict_types=1);
namespace Engine\Atomic\Auth;

if (!defined('ATOMIC_START')) exit;

final class ConfigUserStore
{
    public function __construct(private ?string $root = null) {}

    public function path(): string
    {
        $root = $this->root ?? ATOMIC_DIR;
        return rtrim($root, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR . 'storage'
            . DIRECTORY_SEPARATOR . 'framework'
            . DIRECTORY_SEPARATOR . 'access_users.php';
    }

    /** @return array{guards: array<string, array{users: array<string, array<string, mixed>>}>} */
    public function read(): array
    {
        $path = $this->path();
        if (!is_file($path) || !is_readable($path)) {
            return ['guards' => []];
        }

        $data = require $path;
        if (!is_array($data)) {
            return ['guards' => []];
        }

        $guards = $data['guards'] ?? [];
        return ['guards' => is_array($guards) ? $this->normalize_guards($guards) : []];
    }

    public function exists(string $guard, string $username): bool
    {
        return isset($this->read()['guards'][$guard]['users'][$username]);
    }

    /** @return array<string, array<string, mixed>> */
    public function users(string $guard): array
    {
        return $this->read()['guards'][$guard]['users'] ?? [];
    }

    /** @return array<string, array{users: array<string, array<string, mixed>>}> */
    public function guards(): array
    {
        return $this->read()['guards'];
    }

    /** @param list<string> $roles */
    public function upsert_user(string $guard, string $username, string $id, string $secret_hash, array $roles): bool
    {
        $data = $this->read();
        $data['guards'][$guard]['users'][$username] = [
            'id'          => $id,
            'username'    => $username,
            'secret_hash' => $secret_hash,
            'roles'       => array_values($roles),
        ];

        return $this->write($data);
    }

    public function reset_secret(string $guard, string $username, string $secret_hash): bool
    {
        $data = $this->read();
        if (!isset($data['guards'][$guard]['users'][$username])) {
            return false;
        }

        $data['guards'][$guard]['users'][$username]['secret_hash'] = $secret_hash;
        return $this->write($data);
    }

    /** @param array{guards: array<string, mixed>} $data */
    private function write(array $data): bool
    {
        $path = $this->path();
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }

        $content = "<?php\n";
        $content .= "declare(strict_types=1);\n\n";
        $content .= "if (!defined('ATOMIC_START')) exit;\n\n";
        $content .= 'return ' . var_export(['guards' => $this->normalize_guards($data['guards'] ?? [])], true) . ";\n";

        return @file_put_contents($path, $content) !== false;
    }

    /** @return array<string, array{users: array<string, array<string, mixed>>}> */
    private function normalize_guards(array $guards): array
    {
        $normalized = [];
        foreach ($guards as $guard => $guard_config) {
            if (!is_string($guard) || !is_array($guard_config)) {
                continue;
            }

            $users = [];
            foreach ((array)($guard_config['users'] ?? []) as $username => $user_config) {
                if (!is_string($username) || !is_array($user_config)) {
                    continue;
                }

                $roles = $user_config['roles'] ?? [];
                $users[$username] = [
                    'id'          => (string)($user_config['id'] ?? ''),
                    'username'    => (string)($user_config['username'] ?? $username),
                    'secret_hash' => (string)($user_config['secret_hash'] ?? ''),
                    'roles'       => is_array($roles) ? array_values(array_map('strval', $roles)) : [],
                ];
            }

            $normalized[$guard] = ['users' => $users];
        }

        return $normalized;
    }
}
