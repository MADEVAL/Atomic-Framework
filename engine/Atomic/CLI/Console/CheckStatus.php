<?php
declare(strict_types=1);
namespace Engine\Atomic\CLI\Console;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\CLI\Style;

enum CheckStatus: string
{
    case OK = 'ok';
    case WARN = 'warn';
    case FAIL = 'fail';
    case SKIP = 'skip';

    public function label(): string
    {
        return match ($this) {
            self::OK => Style::green('[OK]', true),
            self::WARN => Style::yellow('[WARN]', true),
            self::FAIL => Style::red('[FAIL]', true),
            self::SKIP => Style::cyan('[SKIP]', true),
        };
    }
}
