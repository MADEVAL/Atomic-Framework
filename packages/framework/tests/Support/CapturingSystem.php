<?php
declare(strict_types=1);

namespace Tests\Support;

use Engine\Atomic\App\System;
use Engine\Atomic\CLI\Console\Output;

final class CapturingSystem extends System
{
    private Output $captured_output;

    public function __construct(Output $output)
    {
        parent::__construct();
        $this->captured_output = $output;
    }

    /** @return array{0:self,1:resource} */
    public static function make(): array
    {
        $stream = StreamCapture::memory();
        return [new self(new Output($stream, $stream)), $stream];
    }

    protected function output(): Output
    {
        return $this->captured_output;
    }
}
