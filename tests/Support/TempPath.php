<?php
declare(strict_types=1);

namespace Tests\Support;

final class TempPath
{
    public static function make_dir(string $prefix): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefix . uniqid() . DIRECTORY_SEPARATOR;
        mkdir($dir, 0755, true);
        return $dir;
    }

    public static function remove(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }

        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }

        foreach (scandir($path) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            self::remove($path . DIRECTORY_SEPARATOR . $item);
        }

        @rmdir($path);
    }
}
