<?php
declare(strict_types=1);
namespace Engine\Atomic\Validator;

if (!defined( 'ATOMIC_START' ) ) exit;

use Engine\Atomic\Codes\Code;

/**
 * A failed model field validation: which model, field and rule, with the
 * rule arguments (e.g. ['min' => 0]).
 */
final class ValidationError
{
	public function __construct(
		public readonly string $model,
		public readonly string $field,
		public readonly string $rule,
		public readonly array $vars = [],
	) {}

	/** Convention-based code constant name, e.g. PRODUCT_PRICE_MIN. */
	public function code_name(): string {
		return strtoupper($this->model) . '_' . strtoupper($this->field) . '_' . $this->rule;
	}

	/** Value of $codes_class::<code_name>, or Code::SERVER_ERROR when undefined. */
	public function code_in(string $codes_class): string {
		$const = $codes_class . '::' . $this->code_name();
		return defined($const) ? (string) constant($const) : Code::SERVER_ERROR;
	}
}
