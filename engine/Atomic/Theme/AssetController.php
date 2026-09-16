<?php
declare(strict_types=1);

namespace Engine\Atomic\Theme;

if (!defined('ATOMIC_START')) exit;

final class AssetController
{
    private const ALLOWED_EXTENSIONS = [
        'avif', 'css', 'gif', 'ico', 'jpeg', 'jpg', 'js', 'map', 'mjs',
        'otf', 'png', 'svg', 'ttf', 'webmanifest', 'webp', 'woff', 'woff2',
    ];

    public function serve(\Base $atomic, array $args = []): void
    {
        $theme = (string)($atomic->get('PARAMS.theme') ?? '');
        $relative = (string)($atomic->get('PARAMS')['*'] ?? ($args[0] ?? ''));
        $relative = ltrim(rawurldecode($relative), '/\\');

        if (!preg_match('/^[A-Za-z0-9_-]+$/', $theme)
            || $relative === ''
            || !str_starts_with($relative, 'assets/')
            || str_contains($relative, "\0")
            || preg_match('#(^|[\\/])\.\.?([\\/]|$)#', $relative)) {
            $this->not_found();
            return;
        }

        $base = Theme::builtin_theme_dir($theme);
        if ($base === null) {
            $this->not_found();
            return;
        }

        $file = realpath($base . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative));
        $realBase = realpath($base);
        $extension = $file === false ? '' : strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if ($file === false || $realBase === false
            || !str_starts_with($file, $realBase . DIRECTORY_SEPARATOR)
            || !is_file($file) || !is_readable($file)
            || !in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
            $this->not_found();
            return;
        }

        $mime = $this->mime_type($file);
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string)filesize($file));
        readfile($file);
    }

    private function mime_type(string $file): string
    {
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $known = match ($extension) {
            'css' => 'text/css; charset=UTF-8',
            'js', 'mjs' => 'application/javascript; charset=UTF-8',
            'svg' => 'image/svg+xml',
            'json', 'map' => 'application/json; charset=UTF-8',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
            default => null,
        };

        if ($known !== null) return $known;

        $detected = function_exists('mime_content_type') ? mime_content_type($file) : false;
        return is_string($detected) && $detected !== '' ? $detected : 'application/octet-stream';
    }

    private function not_found(): void
    {
        http_response_code(404);
        header('Content-Type: text/plain; charset=UTF-8');
        echo 'Not Found';
    }
}
