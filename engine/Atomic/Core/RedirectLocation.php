<?php
declare(strict_types=1);
namespace Engine\Atomic\Core;

if (!defined('ATOMIC_START')) exit;

final class RedirectLocation
{
    /** Resolves a Location header against the current URL (RFC 3986 section 5.2). */
    public static function resolve(string $base, string $reference): ?string
    {
        $reference = (string) preg_replace('/#.*\z/s', '', $reference);
        if (preg_match('/\A[a-z][a-z0-9+.\-]*:/i', $reference) === 1) {
            return $reference;
        }

        $parts = parse_url($base);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return null;
        }
        if (str_starts_with($reference, '//')) {
            return $parts['scheme'] . ':' . $reference;
        }

        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        $basePath = $parts['path'] ?? '/';
        if ($reference === '' || str_starts_with($reference, '?')) {
            $query = $reference !== '' ? $reference : (isset($parts['query']) ? '?' . $parts['query'] : '');
            return $origin . $basePath . $query;
        }

        $query = '';
        if (($position = strpos($reference, '?')) !== false) {
            $query = substr($reference, $position);
            $reference = substr($reference, 0, $position);
        }
        $path = str_starts_with($reference, '/')
            ? $reference
            : substr($basePath, 0, (int) strrpos($basePath, '/') + 1) . $reference;

        $input = explode('/', $path);
        $segments = [];
        foreach ($input as $index => $segment) {
            $last = $index === count($input) - 1;
            if ($segment === '..' || $segment === '.') {
                if ($segment === '..' && count($segments) > 1) {
                    array_pop($segments);
                }
                if ($last) {
                    $segments[] = '';
                }
                continue;
            }
            $segments[] = $segment;
        }

        return $origin . implode('/', $segments) . $query;
    }
}
