<?php
declare(strict_types=1);
namespace Engine\Atomic\Hook;

if (!defined( 'ATOMIC_START' ) ) exit;

use Engine\Atomic\Core\Traits\Singleton;
use Engine\Atomic\Event\Event;

class Hook {
    use Singleton;

    protected Event $event;
    protected array $listener_map = [];

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
        $this->register_listener($tag, $callback, $wrapper, $priority);
        return true;
    }

    public function remove_action(string|\UnitEnum $tag, ?callable $function_to_remove = null, int $priority = 10): bool {
        $norm_tag = $this->normalize_key($tag);
        
        if ($function_to_remove !== null) {
            $cb_id = $this->callback_id($function_to_remove);
            if (!isset($this->listener_map[$norm_tag][$cb_id])) {
                return false;
            }
            $entry = $this->listener_map[$norm_tag][$cb_id];
            if ($entry['priority'] !== $priority) {
                return false;
            }
            $this->event->off($tag, $entry['wrapper'], $priority);
            unset($this->listener_map[$norm_tag][$cb_id]);
            if (empty($this->listener_map[$norm_tag])) {
                unset($this->listener_map[$norm_tag]);
            }
            return true;
        }

        if ($this->has_action($tag)) {
            $this->event->off($tag);
            unset($this->listener_map[$norm_tag]);
            return true;
        }
        return false;
    }

    public function has_action(string|\UnitEnum $tag, ?callable $function_to_check = null): bool {
        $norm_tag = $this->normalize_key($tag);
        
        if ($function_to_check !== null) {
            $cb_id = $this->callback_id($function_to_check);
            return isset($this->listener_map[$norm_tag][$cb_id]);
        }
        
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
        $this->register_listener($tag, $callback, $wrapper, $priority);
        return true;
    }

    public function remove_filter(string|\UnitEnum $tag, ?callable $function_to_remove = null, int $priority = 10): bool {
        return $this->remove_action($tag, $function_to_remove, $priority);
    }

    public function has_filter(string|\UnitEnum $tag, ?callable $function_to_check = null): bool {
        return $this->has_action($tag, $function_to_check);
    }

    private function register_listener(string|\UnitEnum $tag, callable $callback, callable $wrapper, int $priority): void
    {
        $norm_tag = $this->normalize_key($tag);
        $cb_id = $this->callback_id($callback);
        $this->listener_map[$norm_tag][$cb_id] = [
            'wrapper' => $wrapper,
            'priority' => $priority,
        ];
    }

    private function callback_id(callable $cb): string
    {
        if (is_string($cb)) return 'str:' . $cb;
        if (is_array($cb)) {
            $class = is_object($cb[0]) ? get_class($cb[0]) : (string)$cb[0];
            return 'arr:' . $class . '::' . $cb[1];
        }
        if ($cb instanceof \Closure) return 'closure:' . spl_object_id($cb);
        return 'inv:' . get_class($cb);
    }

    private function normalize_key(string|\UnitEnum $tag): string
    {
        if (is_string($tag)) return $tag;
        return $tag instanceof \BackedEnum ? (string)$tag->value : $tag->name;
    }
}
