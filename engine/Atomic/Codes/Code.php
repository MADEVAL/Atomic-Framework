<?php
declare(strict_types=1);
namespace Engine\Atomic\Codes;

if (!defined( 'ATOMIC_START' ) ) exit;

class Code
{
    use OAuth;
    use Generic;
}