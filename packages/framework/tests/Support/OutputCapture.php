<?php
declare(strict_types=1);

namespace Tests\Support;

final class OutputCapture
{
    public static function capture(callable $callback): string
    {
        ob_start();
        try {
            $callback();
            return (string)ob_get_clean();
        } catch (\Throwable $e) {
            ob_end_clean();
            throw $e;
        }
    }
}
