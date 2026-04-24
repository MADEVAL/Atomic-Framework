<?php
declare(strict_types=1);
namespace Engine\Atomic\App;

if (!defined( 'ATOMIC_START' ) ) exit;

use DB\Cortex;
use Engine\Atomic\Codes\Code;
use Engine\Atomic\Core\App;
use Engine\Atomic\Core\Log;
use Engine\Atomic\Enums\Cast;
use Engine\Atomic\Validator\PreValidation\NullableEmptyToNullTrait;
use Engine\Atomic\Validator\Validator;

abstract class Model extends Cortex 
{
	use NullableEmptyToNullTrait;
	
	protected $table = null;
	protected $db = 'DB';
	protected $fieldConf = null;
	protected ?string $last_err_code = null;
	protected array $last_err_vars = [];
	private static array $inherited_field_conf_cache = [];

	protected static function extended_field_conf(): array {
		return [];
	}

	private function merge_field_conf_from_class_traits(\ReflectionClass $classRef, array &$merged): void {
		$seen = [];
		foreach ($classRef->getTraits() as $traitRef) {
			$this->merge_field_conf_from_trait($traitRef, $merged, $seen);
		}
	}

	private function merge_field_conf_from_trait(
		\ReflectionClass $traitRef,
		array &$merged,
		array &$seen,
	): void {
		$name = $traitRef->getName();
		if (isset($seen[$name])) {
			return;
		}
		$seen[$name] = true;
		foreach ($traitRef->getTraits() as $nested) {
			$this->merge_field_conf_from_trait($nested, $merged, $seen);
		}
		if ($traitRef->hasProperty('fieldConf')) {
			$prop = $traitRef->getProperty('fieldConf');
			if ($prop->getDeclaringClass()->getName() === $name) {
				$defaults = $traitRef->getDefaultProperties();
				$conf = $defaults['fieldConf'] ?? null;
				if (is_array($conf) && !empty($conf)) {
					$merged = array_replace_recursive($merged, $conf);
				}
			}
		}
	}

	private function collect_inherited_field_conf(): array {
		if (isset(self::$inherited_field_conf_cache[static::class])) {
			return self::$inherited_field_conf_cache[static::class];
		}

		$chain = array_reverse(class_parents(static::class));
		$chain[] = static::class;

		$merged = [];
		foreach ($chain as $class_name) {
			$ref = new \ReflectionClass($class_name);
			$this->merge_field_conf_from_class_traits($ref, $merged);
			if ($ref->hasProperty('fieldConf')) {
				$prop = $ref->getProperty('fieldConf');
				if ($prop->getDeclaringClass()->getName() === $class_name) {
					$defaults = $ref->getDefaultProperties();
					$conf = $defaults['fieldConf'] ?? null;
					if (is_array($conf) && !empty($conf)) {
						$merged = array_replace_recursive($merged, $conf);
					}
				}
			}
		}

		self::$inherited_field_conf_cache[static::class] = $merged;
		return $merged;
	}

	protected function initialize_field_conf(): void {
		$base = $this->collect_inherited_field_conf();
		$extra = static::extended_field_conf();
		if (!empty($base) || !empty($extra) || is_array($this->fieldConf)) {
			$this->fieldConf = array_replace_recursive($base, $extra);
		}
	}

	public function __construct() {
		$this->db = 'DB';
		$this->initialize_field_conf();
		if ($this->table !== null) {
			$prefix = (string) App::instance()->get('DB_CONFIG.prefix');
			if ($prefix !== '' && !str_starts_with((string) $this->table, $prefix)) {
				$this->table = $prefix . $this->table;
			}
		}
		parent::__construct();
		$saveHandler = function(self $self): bool {
			$self->before_validate();
			$valid = true;
			foreach ($self->getFieldConfiguration() as $field => $conf) {
				if (in_array($conf['relType'] ?? null, ['has-many', 'belongs-to-many'], true)) {
					continue;
				}
				$val = isset($conf['relType']) ? $self->getRaw($field) : $self->get($field);
				$res = Validator::validate_model($conf, $val, $field, $self);
				if (!$res[0]) {
					$valid = false;
					$self->last_err_code = $res[1];
					$self->last_err_vars = $res[2] ?? [];
					break;
				}
			}
			return $valid;
		};
		$this->beforesave($saveHandler);
	}

	protected function before_validate(): void {
		$this->pre_validate_nullable_empty_to_null();
	}

	public function get_field_configuration(): array {
		return $this->getFieldConfiguration();
	}

	public function get_last_err_code(): false|string {
		return $this->last_err_code;
	}

	public function get_last_err_vars(): array {
		return $this->last_err_vars;
	}

	public function get_last_err_data(): array {
		return [$this->last_err_code, $this->last_err_vars];
	}

	public function update_property(mixed $filter, string $key, mixed $value): bool
	{
		$this->load($filter);
		if ($this->dry()) {
			return false;
		}
		while (!$this->dry()) {
			$this->set($key, $value);
			$this->save();
			$this->next();
		}
		return true;
	}
}
