<?php
declare(strict_types=1);
namespace App\Hook;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Traits\Singleton;

class Application
{
    use Singleton;

    protected App $atomic;

    private function __construct()
    {
        $this->atomic = App::instance();
    }

    public function init(): void
    {
        // Register application-level hooks and filters here
    }
}
