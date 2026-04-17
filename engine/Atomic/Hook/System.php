<?php
declare(strict_types=1);
namespace Engine\Atomic\Hook;

if (!defined( 'ATOMIC_START' ) ) exit;

use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Methods;
use Engine\Atomic\Core\Traits\Singleton;
use Engine\Atomic\Hook\Hook;

class System {
    use Singleton;

    protected App $atomic;

    private function __construct()
    {
        $this->atomic = App::instance();
    }

    public function init(): void
    {
        add_filter('powered_by', function($text){
            return $text . ' | Powered by Atomic';
        });

        add_filter('the_excerpt', function($text, $length = 150){
            $clean = strip_tags((string)$text);
            return mb_substr($clean, 0, $length) . '...';
        }, 10, 2);

        add_filter('body_class', function($classes){
            $device = Methods::instance()->get_user_device();
            $classes[] = $device;
            return $classes;
        });
    }

}