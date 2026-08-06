<?php
declare(strict_types=1);
namespace Engine\Atomic\Queue\Exceptions;

if (!defined( 'ATOMIC_START' ) ) exit;

class JobCancelledException extends \RuntimeException {}
