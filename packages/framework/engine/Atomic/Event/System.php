<?php
declare(strict_types=1);
namespace Engine\Atomic\Event;

if (!defined( 'ATOMIC_START' ) ) exit;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Traits\Singleton;

class System {
    use Singleton;

    protected App $atomic;

    private function __construct()
    {
        $this->atomic = App::instance();
    }

    public function init(): void
    {

    }
}