<?php
declare(strict_types=1);
namespace Engine\Atomic\Hook;

if (!defined( 'ATOMIC_START' ) ) exit;

use Engine\Atomic\Event\Event;

class Hook {
    protected static ?self $instance = null;
    protected Event $event;

    public static function instance(): self {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }
    protected function __construct() { $this->event = Event::instance(); }

    public function do_action(string|\UnitEnum $tag, mixed ...$args): void {
        $ctx = [];
        $this->event->emit($tag, $args, $ctx, true);
    }
    
    public function add_action(string|\UnitEnum $tag, callable $callback, int $priority = 10, int $accepted_args = 1): bool {
        $wrapper = function($payload, &$_ctx=null, $_ev=null) use ($callback, $accepted_args) {
            $args = is_array($payload) ? $payload : [$payload];
            if ($accepted_args >= 0) $args = array_slice($args, 0, $accepted_args);
            $callback(...$args);
            return null; 
        };
        $this->event->on($tag, $wrapper, $priority);
        return true;
    }

    public function remove_action(string|\UnitEnum $tag, ?callable $function_to_remove = null, int $priority = 10): bool {
        if ($this->has_action($tag)) { $this->event->off($tag); return true; }
        return false;
    }

    public function has_action(string|\UnitEnum $tag, ?callable $function_to_check = null): bool {
        return $this->event->has($tag);
    }

    public function apply_filters(string|\UnitEnum $tag, mixed $value, mixed ...$extra): mixed {
        $ctx = ['extra' => $extra];
        return $this->event->emit($tag, $value, $ctx, false);
    }

    public function add_filter(string|\UnitEnum $tag, callable $callback, int $priority = 10, int $accepted_args = 1): bool {
        $wrapper = function($value, &$_ctx=null, $_ev=null) use ($callback, $accepted_args) {
            $extra = (is_array($_ctx) && array_key_exists('extra', $_ctx)) ? (array)$_ctx['extra'] : [];
            $args = array_merge([$value], $extra);
            if ($accepted_args >= 0) $args = array_slice($args, 0, $accepted_args);
            return $callback(...$args); 
        };
        $this->event->on($tag, $wrapper, $priority);
        return true;
    }

    public function remove_filter(string|\UnitEnum $tag, ?callable $function_to_remove = null, int $priority = 10): bool {
        return $this->remove_action($tag, $function_to_remove, $priority);
    }

    public function has_filter(string|\UnitEnum $tag, ?callable $function_to_check = null): bool {
        return $this->has_action($tag, $function_to_check);
    }
}