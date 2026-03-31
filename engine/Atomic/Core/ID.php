<?php
declare(strict_types=1);
namespace Engine\Atomic\Core;

if (!defined( 'ATOMIC_START' ) ) exit;

class ID
{
    /**
     * Generate RFC4122 v4 UUID
     * @return string
     */
    public static function uuid_v4(): string
    {
        $data = random_bytes(16);
        $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
        $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    public static function is_valid_uuid_v4(string $uuid): bool {
        return preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid) === 1;
    }

    public static function uuid_v4_to_bytes(string $uuid): ?string
    {
        if (!self::is_valid_uuid_v4($uuid)) {
            return null;
        }
        $hex = str_replace('-', '', $uuid);
        return hex2bin($hex);
    }

    public static function uuid_v4_to_bytes_json(string $uuid): ?string
    {
        $bytes = self::uuid_v4_to_bytes($uuid);
        if ($bytes === null) {
            return null;
        }
        return base64_encode($bytes);
    }

    public static function bytes_to_uuid_v4(string $bytes): ?string
    {
        if (strlen($bytes) !== 16) return null;
        $hex = bin2hex($bytes);
        return sprintf('%08s-%04s-%04s-%04s-%012s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }

    public static function bytes_to_uuid_v4_json(string $bytes): ?string
    {
        $decoded = base64_decode($bytes, true);
        if ($decoded === null) return null;
        return self::bytes_to_uuid_v4($decoded);
    }

    public static function generate_unique_id(int $length = 16): string
    {
        $bytes = random_bytes($length);
        return bin2hex($bytes);
    }

    public static function generate_unique_id_json(int $length = 16): string
    {
        $bytes = random_bytes($length);
        return base64_encode($bytes);
    }

    public static function generate_access_token(int|string $id, string $uuid, int $length = 12): string
    {
        $app = App::instance();
        $secretKey = $app->get('APP_KEY');
        if (empty($secretKey) || $secretKey === 'default-key') {
            throw new \RuntimeException('APP_KEY is not configured. Cannot generate access tokens without a secure key.');
        }
        $data = $id . ':' . $uuid;
        $signature = hash_hmac('sha256', $data, $secretKey, true);
        $encoded = self::base64_url_encode(substr($signature, 0, max(4, (int)ceil($length * 0.75))));
        return $id . '.' . substr($encoded, 0, $length);
    }

    public static function verify_access_token(string $token, string $uuid): bool
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return false;
        }
        
        [$id, $signature] = $parts;
        $expectedToken = self::generate_access_token($id, $uuid, strlen($signature));
        
        return hash_equals($token, $expectedToken);
    }

    public static function extract_id_from_token(string $token): ?string
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return null;
        }
        return $parts[0];
    }

    public static function base64_url_encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    public static function base64_url_decode(string $data): string|false
    {
        $padded = str_pad(strtr($data, '-_', '+/'), (int)ceil(strlen($data) / 4) * 4, '=', STR_PAD_RIGHT);
        return base64_decode($padded);
    }
}
