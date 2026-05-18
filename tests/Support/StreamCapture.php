<?php
declare(strict_types=1);

namespace Tests\Support;

use Engine\Atomic\CLI\Console\Output;

final class StreamCapture
{
    /** @return resource */
    public static function memory(string $mode = 'r+'): mixed
    {
        return fopen('php://memory', $mode);
    }

    public static function read(mixed $stream, bool $plain = false): string
    {
        rewind($stream);
        $contents = stream_get_contents($stream);
        if ($contents === false) {
            return '';
        }

        return $plain ? Output::plain($contents) : $contents;
    }

    /** @return array{0: Output, 1: resource} */
    public static function output_with_stderr(): array
    {
        $stream = self::memory();
        return [new Output(null, $stream), $stream];
    }
}
