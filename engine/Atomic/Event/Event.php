<?php
declare(strict_types=1);
namespace Engine\Atomic\Event;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Core\Traits\Singleton;

class Event {
    use Singleton;

    private static array $listeners = [];

    protected string $ekey;

    private function __construct(?object $obj = null) {
        if ($obj)
            $this->ekey = 'EVENTS_local.' . spl_object_id($obj) . '.';
        else
            $this->ekey = 'EVENTS.';
    }

    private function get(string $key): mixed {
        $parts = explode('.', $key);
        $current = self::$listeners;
        foreach ($parts as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) {
                return null;
            }
            $current = $current[$part];
        }
        return $current;
    }

    private function set(string $key, mixed $value): void {
        $parts = explode('.', $key);
        $current = &self::$listeners;
        foreach ($parts as $i => $part) {
            if ($i === count($parts) - 1) {
                $current[$part] = $value;
                return;
            }
            if (!isset($current[$part]) || !is_array($current[$part])) {
                $current[$part] = [];
            }
            $current = &$current[$part];
        }
    }

    private function exists(string $key): bool {
        return $this->get($key) !== null;
    }

    private function clear(string $key): void {
        $parts = explode('.', $key);
        $last = array_pop($parts);
        $current = &self::$listeners;
        foreach ($parts as $part) {
            if (!is_array($current) || !array_key_exists($part, $current)) {
                return;
            }
            $current = &$current[$part];
        }
        if (is_array($current)) {
            unset($current[$last]);
        }
    }

    public function on(string|\UnitEnum $key, callable|array $func, int $priority = 10, array $options = []): void {
        $full = $this->ekey . $this->normalize_key($key);
        $call = $options ? [$func, $options] : $func;
        $e = $this->exists($full) ? $this->get($full) : [];
        $e[(int)$priority][] = $call;
        ksort($e);
        $this->set($full, $e);
    }

    public function off(string|\UnitEnum $key, ?callable $func = null, ?int $priority = null): void {
        $full = $this->ekey . $this->normalize_key($key);

        if ($func === null && $priority === null) {
            $this->clear($full);
            return;
        }

        if (!$this->exists($full)) return;
        $e = $this->get($full);
        if (!is_array($e)) return;

        $target_id = $func !== null ? $this->callback_id($func) : null;

        foreach ($e as $prio => &$listeners) {
            if ($priority !== null && (int)$prio !== $priority) continue;

            if ($target_id !== null) {
                $listeners = array_values(array_filter($listeners, function ($call) use ($target_id) {
                    $cb = $this->extract_callable($call);
                    return $cb === null || $this->callback_id($cb) !== $target_id;
                }));
            } else {
                $listeners = [];
            }

            if (empty($listeners)) {
                unset($e[$prio]);
            }
        }

        if (empty($e)) {
            $this->clear($full);
        } else {
            $this->set($full, $e);
        }
    }

    private function extract_callable(mixed $call): ?callable
    {
        if (is_callable($call)) return $call;
        if (is_array($call) && isset($call[0]) && is_callable($call[0])) return $call[0];
        return null;
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

    public function has(string|\UnitEnum $key): bool {
        return (bool)$this->exists($this->ekey . $this->normalize_key($key));
    }

    public function broadcast(string|\UnitEnum $key, mixed $args = null, array &$context = [], bool $hold = true): mixed {
        $key = $this->normalize_key($key);
        $full = $this->ekey . $key;
        if (!$this->exists($full)) return $args;

        $e = $this->get($full);
        if (!is_array($e)) return $args;

        $descendants = [];
        foreach ($e as $nkey => $nval)
            if (is_string($nkey)) $descendants[] = $nkey;

        foreach ($descendants as $dkey) {
            $sub = $this->ekey . $key . '.' . $dkey;
            if ($this->exists($sub)) {
                $se = $this->get($sub);
                $listeners = [];
                if (is_array($se)) {
                    foreach ($se as $nkey => $nval)
                        if (is_numeric($nkey))
                            $listeners = array_merge($listeners, array_values($se[$nkey]));
                }
                foreach ($listeners as $func) {
                    if (!is_array($func) || is_callable($func))
                        $func = [$func, []];
                    $ev = ['name' => $key . '.' . $dkey, 'key' => $dkey, 'options' => $func[1]];
                    $out = call_user_func_array($func[0], [$args, &$context, $ev]);
                    if ($hold && $out === FALSE) break;
                    if ($out !== null) $args = $out;
                }
            }
            $args = $this->broadcast($key . '.' . $dkey, $args, $context, $hold);
        }
        return $args;
    }

    public function emit(string|\UnitEnum $key, mixed $args = null, array &$context = [], bool $hold = true): mixed {
        $key = $this->normalize_key($key);
        $nodes = explode('.', $key);
        foreach ($nodes as $i => $slot) {
            $key = implode('.', $nodes);
            array_pop($nodes);

            $full = $this->ekey . $key;
            if ($this->exists($full)) {
                $e = $this->get($full);
                if (is_array($e) && !empty($e)) {
                    $listeners = [];
                    foreach ($e as $nkey => $nval)
                        if (is_numeric($nkey))
                            $listeners = array_merge($listeners, array_values($e[$nkey]));
                    foreach ($listeners as $func) {
                        if (!is_array($func) || is_callable($func))
                            $func = [$func, []];
                        $ev = ['name' => $key, 'key' => substr($key, strrpos($key, '.') + 1), 'options' => $func[1]];
                        $out = call_user_func_array($func[0], [$args, &$context, $ev]);
                        if ($hold && $out === FALSE) return $args;
                        if ($out !== null) $args = $out;
                    }
                }
            }
            if ($i == 0) $args = $this->broadcast($key, $args, $context, $hold);
        }
        return $args;
    }

    public function watch(object $obj): static {
        return new static($obj);
    }

    public function unwatch(object $obj): void {
        $prefix = 'EVENTS_local.' . spl_object_id($obj);
        if (isset(self::$listeners[$prefix])) {
            unset(self::$listeners[$prefix]);
        }
    }

    public function get_registered_events(): array {
        $events = [];
        $prefix = $this->ekey;
        $this->collect_events(self::$listeners, $prefix, '', $events);
        return $events;
    }

    private function collect_events(array $node, string $root, string $path, array &$events): void {
        foreach ($node as $key => $value) {
            if (is_string($key)) {
                $full = $path === '' ? $key : $path . '.' . $key;
                if (is_array($value)) {
                    $has_listeners = false;
                    foreach ($value as $pk => $pv) {
                        if (is_numeric($pk)) {
                            $has_listeners = true;
                            break;
                        }
                    }
                    if ($has_listeners && str_starts_with($root . $full, $root)) {
                        $events[] = $full;
                    }
                    $this->collect_events($value, $root, $full, $events);
                }
            }
        }
    }

    protected function normalize_key(string|\UnitEnum $key): string {
        if (is_string($key)) {
            return $key;
        }

        return $key instanceof \BackedEnum ? (string)$key->value : $key->name;
    }
}
