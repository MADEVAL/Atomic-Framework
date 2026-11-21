<?php
declare(strict_types=1);
namespace Tests\Engine\Validator;
if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\Codes\Code;
use Engine\Atomic\Validator\ValidationError;
use PHPUnit\Framework\TestCase;

final class ValidationErrorFixtureCodes
{
    public const PRODUCT_PRICE_MIN = '1571';
}

final class ValidationErrorTest extends TestCase
{
    public function test_code_name_is_uppercase_model_field_rule(): void
    {
        $error = new ValidationError('Product', 'price', 'MIN', ['min' => 0]);
        $this->assertSame('PRODUCT_PRICE_MIN', $error->code_name());
    }

    public function test_code_in_returns_constant_from_given_class(): void
    {
        $error = new ValidationError('Product', 'price', 'MIN', ['min' => 0]);
        $this->assertSame('1571', $error->code_in(ValidationErrorFixtureCodes::class));
    }

    public function test_code_in_falls_back_to_server_error_when_missing(): void
    {
        $error = new ValidationError('Product', 'price', 'MAX');
        $this->assertSame(Code::SERVER_ERROR, $error->code_in(ValidationErrorFixtureCodes::class));
        $this->assertSame(Code::SERVER_ERROR, $error->code_in(Code::class));
    }
}
