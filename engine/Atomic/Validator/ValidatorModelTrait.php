<?php
declare(strict_types=1);
namespace Engine\Atomic\Validator;

if (!defined( 'ATOMIC_START' ) ) exit;

use Audit;
use DB\Cortex;
use Engine\Atomic\Codes\Code;
use Engine\Atomic\Core\ID;
use Engine\Atomic\Core\Log;
use Engine\Atomic\Enums\Rule;

trait ValidatorModelTrait
{
	public static function validate_model(
		array $field_conf, 
		mixed $field_val,
		string $field_name,
		Cortex $model,
	): array {
		$ref = new \ReflectionClass($model);
		$class_short = $ref->getShortName();
		$const_prefix = Code::class . '::' . strtoupper($class_short) . '_' . strtoupper($field_name) . '_';

		$error_handler = function(string $suffix, array $vars = []) use ($const_prefix): array {
			$is_valid = false;
			$const_name = $const_prefix . $suffix;
			$code = defined($const_name) ? constant($const_name) : Code::SERVER_ERROR;
			Log::error('Validation failed: ' . $const_name . ' Code ' . $code);
			return [$is_valid, $code, $vars];
		};

		if (self::nullable($field_conf, $field_val)) {
			$is_valid = true;
			return [$is_valid];
		}

		if (self::default($field_conf, $field_val)) {
			$is_valid = true;
			return [$is_valid];
		}

		if (self::required($field_conf, $field_val) === false) {
			return $error_handler('REQUIRED');
		}

		if (isset($field_conf['rules'])) {
			foreach ($field_conf['rules'] as $rule) {
				if (!($rule instanceof Rule)) {
					Log::error("Validation rule must be an instance of Rule.");
					return [false, Code::SERVER_ERROR];
				}
				if (!method_exists(self::class, $rule->value)) {
					Log::error("Validation rule '{$rule->value}' is not supported.");
					return [false, Code::SERVER_ERROR];
				}

				$result = match ($rule->value) {
					'enum' => !empty($field_conf['enum']) 
						? self::enum($field_val, $field_conf['enum']) 
						: (Log::error("Validation enum is empty for field '{$field_name}' in class '{$class_short}'.") ?? false),
					'regex' => !empty($field_conf['pattern'])
						? self::regex($field_val, $field_conf['pattern'])
						: (Log::error("Validation regex pattern is empty for field '{$field_name}' in class '{$class_short}'.") ?? false),
					'callback' => is_callable($field_conf['callback'] ?? null) 
						? self::callback($field_val, $field_conf['callback']) 
						: (Log::error("Validation callback is not callable for field '{$field_name}' in class '{$class_short}'.") ?? false),
					'num_min' => ($field_conf['min'] ?? null) !== null 
						? self::num_min($field_val, $field_conf['min']) 
						: false,
					'num_max' => ($field_conf['max'] ?? null) !== null 
						? self::num_max($field_val, $field_conf['max']) 
						: false,
					'str_min' => ($field_conf['min'] ?? null) !== null 
						? self::str_min($field_val, $field_conf['min']) 
						: false,
					'str_max' => ($field_conf['max'] ?? null) !== null 
						? self::str_max($field_val, $field_conf['max']) 
						: false,
					'mb_min' => ($field_conf['min'] ?? null) !== null 
						? self::mb_min($field_val, $field_conf['min']) 
						: false,
					'mb_max' => ($field_conf['max'] ?? null) !== null 
						? self::mb_max($field_val, $field_conf['max']) 
						: false,
					'password_entropy' => ($field_conf['min_entropy'] ?? null) !== null
						? self::password_entropy($field_val, (float) $field_conf['min_entropy'])
						: self::password_entropy($field_val),
					default => self::{$rule->value}($field_val),
				};

				if (!$result) {
					$suffix = match ($rule->value) {
						'regex' => 'FORMAT',
						'enum' => 'ENUM',
						'num_min', 'str_min', 'mb_min' => 'MIN',
						'num_max', 'str_max', 'mb_max' => 'MAX',
						'password_entropy' => 'WEAK',
						default => 'INVALID',
					};
					$vars = [];
					if (in_array($rule->value, ['num_min', 'str_min', 'mb_min', 'num_max', 'str_max', 'mb_max'], true)) {
						if (str_contains($rule->value, 'min')) {
							$vars['min'] = $field_conf['min'];
						} else {
							$vars['max'] = $field_conf['max'];
						}
					}
					return $error_handler($suffix, $vars);
				}
			}
		}

		$type = strtoupper($field_conf['type']);
		$is_valid = match ($type) {
			default => null,
			'BOOLEAN' => self::validate_boolean($field_val),
			'INT1' => self::validate_integer($field_val, -128, 127),
			'INT2' => self::validate_integer($field_val, -32768, 32767),
			'INT4' => self::validate_integer($field_val, -2147483648, 2147483647),
			'INT8' => self::validate_integer($field_val, -9223372036854775807 - 1, 9223372036854775807),
			'FLOAT' => self::validate_float($field_val),
			'DOUBLE', 'DECIMAL' => self::validate_double($field_val),
			'VARCHAR128' => self::validate_varchar($field_val, 128),
			'VARCHAR256' => self::validate_varchar($field_val, 255),
			'VARCHAR512' => self::validate_varchar($field_val, 512),
			'TEXT' => self::validate_text($field_val, 65535),
			'LONGTEXT' => self::validate_text($field_val, 4294967295),
			'DATE' => self::validate_date($field_val),
			'DATETIME' => self::validate_datetime($field_val),
			'TIMESTAMP' => self::validate_timestamp($field_val),
			'BLOB' => self::validate_blob($field_val, 65535),
		};

		if ($is_valid === null) {
			Log::error("Validation type '$type' is not supported.");
			return [false, Code::SERVER_ERROR];
		}

		if ($is_valid !== true) {
			$suffix = match ($type) {
				'VARCHAR128', 'VARCHAR256', 'VARCHAR512', 'TEXT', 'LONGTEXT', 'BLOB' => 'MAX',
				'DATE', 'DATETIME', 'TIMESTAMP' => 'FORMAT',
				default => 'INVALID',
			};
			return $error_handler($suffix);
		}

		if (isset($field_conf['unique']) && $field_conf['unique'] === true) {
			if (!self::unique($model, $field_val, $field_name)) {
				return $error_handler('UNIQUE');
			}
		}

		return [$is_valid];
	}	

	public static function validate_boolean(mixed $value): bool {
		return is_bool($value) || $value === 0 || $value === 1 || $value === '0' || $value === '1';
	}

	public static function validate_integer(mixed $val, int $min, int $max): bool {
		if (is_float($val)) return false;
		$filtered = filter_var($val, FILTER_VALIDATE_INT);
		if ($filtered === false) {
			return false;
		}
		return $filtered >= $min && $filtered <= $max;
	}

	public static function validate_float(mixed $value): bool {
		if (!is_numeric($value)) return false;

		$float_val = (float)$value;
		if (!is_finite($float_val)) {
			return false;
		}
		return abs($float_val) <= 3.402823466E+38;
	}

	public static function validate_double(mixed $value): bool {
		if (!is_numeric($value)) return false;

		$float_val = (float)$value;
		if (!is_finite($float_val)) {
			return false;
		}
		return true;
	}

	public static function validate_varchar(mixed $value, int $max_length): bool {
		if (!is_string($value)) return false;
		return mb_strlen($value, 'UTF-8') <= $max_length;
	}

	public static function validate_text(mixed $value, int $max_bytes): bool {
		if (!is_string($value)) return false;
		return strlen($value) <= $max_bytes;
	}

	public static function validate_blob(mixed $value, int $max_bytes): bool {
		if (!is_string($value)) return false;
		return strlen($value) <= $max_bytes;
	}

	public static function validate_byte_len(string $value, int $size): bool {
		return strlen($value) <= $size;
	}

	public static function validate_date(mixed $value): bool {
		if (!is_string($value)) return false;

		$d = \DateTime::createFromFormat('Y-m-d', $value);
		if (!$d || $d->format('Y-m-d') !== $value) {
			return false;
		}
		$year = (int)$d->format('Y');
		return $year >= 1000 && $year <= 9999;
	}

	public static function validate_datetime(mixed $value): bool {
		if (!is_string($value)) return false;

		$d = \DateTime::createFromFormat('Y-m-d H:i:s', $value);
		if (!$d || $d->format('Y-m-d H:i:s') !== $value) {
			return false;
		}
		$year = (int)$d->format('Y');
		return $year >= 1000 && $year <= 9999;
	}

	public static function validate_timestamp(mixed $value): bool {
		$min = 1;
		$max = 253402300799;

		if (is_int($value)) {
			return $value >= $min && $value <= $max;
		}

		if (is_string($value) && ctype_digit($value)) {
			$timestamp = (int)$value;
			if ($timestamp < $min || $timestamp > $max) return false;
			return true;
		}

		// DATETIME

		if (!is_string($value)) return false;

		$tz = new \DateTimeZone('UTC');
		$d = \DateTime::createFromFormat('Y-m-d H:i:s', $value, $tz);
		if (!$d || $d->format('Y-m-d H:i:s') !== $value) return false;

		$timestamp = $d->getTimestamp();
		return $timestamp >= $min && $timestamp <= $max;
	}

	public static function nullable(array $field_conf, mixed $field_val): bool {
		if (isset($field_conf['nullable']) && $field_conf['nullable'] && ($field_val === null || $field_val === '')) {
			return true;
		}
		return false;
	}

	public static function default(array $field_conf, mixed $field_val): bool {
		if (isset($field_conf['default']) && $field_val === null) {
			return true;
		}
		return false;
	}

	public static function required(array $field_conf, mixed $field_val): bool {
		if (isset($field_conf['required']) && $field_conf['required']) {
			if ($field_val === null || $field_val === '' || (is_array($field_val) && count($field_val) === 0)) {
				return false;
			}
		}
		return true;
	}

	public static function uuid_v4(mixed $val): bool {
		if (!is_string($val)) return false;
		return ID::is_valid_uuid_v4($val);
	}

	public static function url(mixed $val): bool {
		if (!is_string($val)) return false;
		return Audit::instance()->url($val);
	}

	public static function email(mixed $val): bool {
		if (!is_string($val)) return false;
		return Audit::instance()->email($val);
	}

	public static function enum(mixed $val, array $enum): bool {
		if (is_array($val)) return empty(array_diff($val, $enum));
		return in_array($val, $enum, true);
	}

	public static function regex(mixed $val, string $pattern): bool {
		if (!is_string($val)) return false;
		return preg_match($pattern, $val) === 1;
	}

	public static function callback(mixed $val, callable $callback): bool {
		return $callback($val);
	}

	public static function min(mixed $val, int|float $min): bool {
		if (is_numeric($val)) return (float)$val >= (float)$min;
		if (is_string($val)) return strlen($val) >= $min;
		return false;
	}

	public static function max(mixed $val, int|float $max): bool {
		if (is_numeric($val)) return (float)$val <= (float)$max;
		if (is_string($val)) return strlen($val) <= $max;
		return false;
	}

	public static function mb_min(mixed $val, int $min): bool {
		if (!is_string($val)) return false;
		return mb_strlen($val, 'UTF-8') >= $min;
	}

	public static function mb_max(mixed $val, int $max): bool {
		if (!is_string($val)) return false;
		return mb_strlen($val, 'UTF-8') <= $max;
	}

	public static function num_min(mixed $val, int|float $min): bool {
		if (!is_numeric($val)) return false;
		return (float)$val >= (float)$min;
	}

	public static function num_max(mixed $val, int|float $max): bool {
		if (!is_numeric($val)) return false;
		return (float)$val <= (float)$max;
	}

	public static function str_min(mixed $val, int $min): bool {
		if (!is_string($val)) return false;
		return strlen($val) >= $min;
	}

	public static function str_max(mixed $val, int $max): bool {
		if (!is_string($val)) return false;
		return strlen($val) <= $max;
	}

	/**
	 * Validate password strength using F3 Audit entropy.
	 * Default minimum entropy: 18.0 (equivalent to "password" complexity).
	 */
	public static function password_entropy(mixed $val, float $min_entropy = 18.0): bool {
		if (!is_string($val) || $val === '') return false;
		if (strlen($val) < 8) return false;
		return (float) Audit::instance()->entropy($val) >= $min_entropy;
	}

	public static function unique(Cortex $model, mixed $val, string $field): bool
	{
		$valid = true;
		if (empty($val)) return $valid;

		$params = [];
		$expr_parts = [];
		$expr_parts[] = $field . ' = ?';
		$params[] = $val;

		$field_config = $model->getFieldConfiguration()[$field] ?? [];
		if (!empty($field_config['unique_by']) && is_array($field_config['unique_by'])) {
			foreach ($field_config['unique_by'] as $unique_by_field) {
				$expr_parts[] = $unique_by_field . ' = ?';
				$unique_val = $model->getRaw($unique_by_field);
				if ($unique_val === null && isset($model->{$unique_by_field})) {
					$unique_val = $model->{$unique_by_field};
				}
				if (is_object($unique_val) && isset($unique_val->_id)) {
					$unique_val = $unique_val->_id;
				}
				$params[] = $unique_val;
			}
		}

		if (!$model->dry()) {
			$expr_parts[] = '_id != ?';
			$params[] = $model->_id;
		}

		$filter = array_merge([implode(' and ', $expr_parts)], $params);

		if ($model->findone($filter)) {
			$valid = false;
		}

		return $valid;
	}
}