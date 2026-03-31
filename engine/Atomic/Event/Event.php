<?php
declare(strict_types=1);
namespace Engine\Atomic\Event;

if (!defined( 'ATOMIC_START' ) ) exit;

use Engine\Atomic\Core\App;

class Event extends \Prefab {

	protected App $atomic;
	protected string $ekey;

	public function __construct(?object $obj=null) {
		$this->atomic = App::instance();
		if ($obj)
			$this->ekey = 'EVENTS_local.'.spl_object_id($obj).'.';
		else
			$this->ekey = 'EVENTS.';
	}

	public function __destruct() {
		$this->atomic->clear(rtrim($this->ekey,'.'));
	}

	public function on(string $key, callable|array $func, int $priority=10, array $options=[]): void {
		$full = $this->ekey.$key;
		$call = $options ? [$func,$options] : $func;
		$e = $this->atomic->exists($full) ? $this->atomic->get($full) : [];
		$e[(int)$priority][] = $call;
		ksort($e);
		$this->atomic->set($full, $e);
	}

	public function off(string $key): void {
		$this->atomic->clear($this->ekey.$key);
	}

	public function has(string $key): bool {
		return (bool)$this->atomic->exists($this->ekey.$key);
	}

	public function broadcast(string $key, mixed $args=null, array &$context=[], bool $hold=true): mixed {
		$full = $this->ekey.$key;
		if (!$this->atomic->exists($full)) return $args;

		$e = $this->atomic->get($full);
		if (!is_array($e)) return $args;

		$descendants=[];
		foreach($e as $nkey=>$nval)
			if (is_string($nkey)) $descendants[] = $nkey;

		foreach($descendants as $dkey) {
			$sub = $this->ekey.$key.'.'.$dkey;
			if ($this->atomic->exists($sub)) {
				$se = $this->atomic->get($sub);
				$listeners = [];
				if (is_array($se)) {
					foreach($se as $nkey=>$nval)
						if (is_numeric($nkey))
							$listeners = array_merge($listeners, array_values($se[$nkey]));
				}
				foreach ($listeners as $func) {
					if (!is_array($func) || is_callable($func))
						$func = [$func,[]];
					$ev=['name'=>$key.'.'.$dkey,'key'=>$dkey,'options'=>$func[1]];
					$out = call_user_func_array($func[0], [$args, &$context, $ev]);
					if ($hold && $out===FALSE) break;
					if ($out !== null) $args = $out;
				}
			}
			$args = $this->broadcast($key.'.'.$dkey,$args,$context,$hold);
		}
		return $args;
	}

	public function emit(string $key, mixed $args=null, array &$context=[], bool $hold=true): mixed {
		$nodes = explode('.',$key);
		foreach ($nodes as $i=>$slot) {
			$key = implode('.',$nodes);
			array_pop($nodes);

			$full = $this->ekey.$key;
			if ($this->atomic->exists($full)) {
				$e = $this->atomic->get($full);
				if (is_array($e) && !empty($e)) {
					$listeners=[];
					foreach ($e as $nkey=>$nval)
						if (is_numeric($nkey))
							$listeners = array_merge($listeners, array_values($e[$nkey]));
					foreach ($listeners as $func) {
						if (!is_array($func) || is_callable($func))
							$func = [$func,[]];
						$ev = ['name'=>$key,'key'=>substr($key, strrpos($key,'.')+1), 'options'=>$func[1]];
						$out = call_user_func_array($func[0], [$args, &$context, $ev]);
						if ($hold && $out===FALSE) return $args;
						if ($out !== null) $args = $out;
					}
				}
			}
			if ($i==0) $args = $this->broadcast($key,$args,$context,$hold);
		}
		return $args;
	}

	public function watch(object $obj): static {
		return new static($obj);
	}

	public function unwatch(object $obj): void {
		$this->atomic->clear('EVENTS_local.'.spl_object_id($obj));
	}

	public function getRegisteredEvents(): array {
		$events = [];
		$prefix = $this->ekey;
		$prefixLen = strlen($prefix);
		
		foreach ($this->atomic->hive() as $key => $value) {
			if (strpos($key, $prefix) === 0) {
				$events[] = substr($key, $prefixLen);
			}
		}
		
		return $events;
	}
}