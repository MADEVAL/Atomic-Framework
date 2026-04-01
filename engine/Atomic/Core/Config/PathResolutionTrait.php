<?php
declare(strict_types=1);
namespace Engine\Atomic\Core\Config;

if (!defined( 'ATOMIC_START' ) ) exit; 

trait PathResolutionTrait
{
    protected function fix_path(string $relative_path, bool $must_exist = false): string
    {
        $normalized    = $this->normalize_separators($relative_path);
        $wants_trailing = $this->has_trailing_slash($normalized);

        $absolute = $this->is_absolute_path($normalized)
            ? $normalized
            : $this->build_absolute_path($normalized);

        $absolute = $this->resolve_path($absolute, $must_exist);
        $absolute = $this->ensure_trailing_slash($absolute, $wants_trailing);

        return $absolute;
    }

    private function normalize_separators(string $path): string
    {
        $path          = str_replace('\\', '/', $path);
        $leading_slash = str_starts_with($path, '/') ? '/' : '';

        return $leading_slash . preg_replace('#/+#', '/', ltrim($path, '/'));
    }

    private function has_trailing_slash(string $path): bool
    {
        return $path !== '' && str_ends_with($path, '/');
    }

    protected function is_absolute_path(string $path): bool
    {
        return (bool) preg_match(
            '#^(?:[A-Za-z]:[\\\\/]|/|[a-z][a-z0-9+\-.]*://)#i',
            $path
        );
    }

    protected function resolve_base_path(string $path): string
    {
        return $this->do_resolve_base_path(
            ltrim($this->normalize_separators($path), '/')
        );
    }

    private function do_resolve_base_path(string $normalized): string
    {
        $prefix_map = [
            'engine/Atomic/' => 'ATOMIC_FRAMEWORK',
            'Atomic/'        => 'ATOMIC_ENGINE',
        ];

        foreach ($prefix_map as $prefix => $constant) {
            if (str_starts_with($normalized, $prefix)) {
                if (!defined($constant)) {
                    throw new \InvalidArgumentException(
                        "Path prefix '{$prefix}' matched but constant '{$constant}' is not defined. "
                        . "Check your framework bootstrap."
                    );
                }
                return rtrim(constant($constant), '/');
            }
        }

        if (!defined('ATOMIC_DIR')) {
            throw new \InvalidArgumentException(
                "Cannot resolve base path: ATOMIC_DIR is not defined."
            );
        }

        return rtrim(ATOMIC_DIR, '/');
    }

    private function build_absolute_path(string $relative_path): string
    {
        $base = $this->do_resolve_base_path(ltrim($relative_path, '/'));
        return $base . '/' . ltrim($relative_path, '/');
    }

    private function resolve_path(string $absolute_path, bool $must_exist): string
    {
        $resolved = realpath($absolute_path);

        if ($resolved === false) {
            if ($must_exist) {
                throw new \RuntimeException(
                    "Path does not exist or is not accessible: '{$absolute_path}'"
                );
            }
            return $absolute_path;
        }

        return $resolved;
    }

    private function ensure_trailing_slash(string $path, bool $wants_trailing): string
    {
        if (str_ends_with($path, '/')) {
            return $path;
        }

        if ($wants_trailing || is_dir($path)) {
            return $path . '/';
        }

        return $path;
    }
}