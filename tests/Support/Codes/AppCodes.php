<?php
declare(strict_types=1);

namespace Tests\Support\Codes;

final class AppCodes
{
    use ProductCodes;

    public const FORBIDDEN = '403';
    public const SERVER_ERROR = '500';
}
