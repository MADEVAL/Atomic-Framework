<?php
declare(strict_types=1);

namespace Tests\Engine\Validator;

use Engine\Atomic\Validator\Validator as V;
use PHPUnit\Framework\TestCase;

class ValidatorTraitTest extends TestCase
{
    // ── Boolean ──

    public function test_validate_boolean_true_values(): void
    {
        $this->assertTrue(V::validate_boolean(true));
        $this->assertTrue(V::validate_boolean(false));
        $this->assertTrue(V::validate_boolean(1));
        $this->assertTrue(V::validate_boolean(0));
        $this->assertTrue(V::validate_boolean('1'));
        $this->assertTrue(V::validate_boolean('0'));
    }

    public function test_validate_boolean_false_values(): void
    {
        $this->assertFalse(V::validate_boolean('yes'));
        $this->assertFalse(V::validate_boolean('true'));
        $this->assertFalse(V::validate_boolean(2));
        $this->assertFalse(V::validate_boolean(null));
    }

    // ── Integer ──

    public function test_validate_integer_in_range(): void
    {
        $this->assertTrue(V::validate_integer(0, -128, 127));
        $this->assertTrue(V::validate_integer(127, -128, 127));
        $this->assertTrue(V::validate_integer(-128, -128, 127));
        $this->assertTrue(V::validate_integer('42', -128, 127));
    }

    public function test_validate_integer_out_of_range(): void
    {
        $this->assertFalse(V::validate_integer(128, -128, 127));
        $this->assertFalse(V::validate_integer(-129, -128, 127));
    }

    public function test_validate_integer_rejects_float(): void
    {
        $this->assertFalse(V::validate_integer(1.5, -128, 127));
    }

    public function test_validate_integer_rejects_string(): void
    {
        $this->assertFalse(V::validate_integer('abc', -128, 127));
    }

    // ── Float / Double ──

    public function test_validate_float(): void
    {
        $this->assertTrue(V::validate_float(1.5));
        $this->assertTrue(V::validate_float(0));
        $this->assertTrue(V::validate_float('3.14'));
        $this->assertFalse(V::validate_float('abc'));
        $this->assertFalse(V::validate_float(INF));
    }

    public function test_validate_double(): void
    {
        $this->assertTrue(V::validate_double(1.5));
        $this->assertTrue(V::validate_double(PHP_FLOAT_MAX));
        $this->assertFalse(V::validate_double('text'));
        $this->assertFalse(V::validate_double(INF));
    }

    // ── Varchar / Text / Blob ──

    public function test_validate_varchar(): void
    {
        $this->assertTrue(V::validate_varchar('hello', 128));
        $this->assertTrue(V::validate_varchar(str_repeat('a', 128), 128));
        $this->assertFalse(V::validate_varchar(str_repeat('a', 129), 128));
        $this->assertFalse(V::validate_varchar(123, 128));
    }

    public function test_validate_varchar_unicode(): void
    {
        $this->assertTrue(V::validate_varchar('Привіт', 128));
        $val = str_repeat('я', 129);
        $this->assertFalse(V::validate_varchar($val, 128));
    }

    public function test_validate_text(): void
    {
        $this->assertTrue(V::validate_text('some text', 65535));
        $this->assertFalse(V::validate_text(str_repeat('x', 65536), 65535));
        $this->assertFalse(V::validate_text(123, 65535));
    }

    public function test_validate_blob(): void
    {
        $this->assertTrue(V::validate_blob("\x00\x01\x02", 65535));
        $this->assertFalse(V::validate_blob(123, 65535));
    }

    // ── Date / DateTime / Timestamp ──

    public function test_validate_date(): void
    {
        $this->assertTrue(V::validate_date('2024-01-15'));
        $this->assertTrue(V::validate_date('1000-01-01'));
        $this->assertFalse(V::validate_date('2024-13-01'));
        $this->assertFalse(V::validate_date('2024-02-30'));
        $this->assertFalse(V::validate_date('not-a-date'));
        $this->assertFalse(V::validate_date(123));
    }

    public function test_validate_datetime(): void
    {
        $this->assertTrue(V::validate_datetime('2024-01-15 10:30:00'));
        $this->assertFalse(V::validate_datetime('2024-01-15'));
        $this->assertFalse(V::validate_datetime('not-a-datetime'));
        $this->assertFalse(V::validate_datetime(123));
    }

    public function test_validate_timestamp(): void
    {
        $this->assertTrue(V::validate_timestamp(1000000));
        $this->assertTrue(V::validate_timestamp('1000000'));
        $this->assertTrue(V::validate_timestamp('2024-01-15 10:30:00'));
        $this->assertFalse(V::validate_timestamp(0));
        $this->assertFalse(V::validate_timestamp(-1));
        $this->assertFalse(V::validate_timestamp('not-valid'));
    }

    // ── Nullable / Default / Required ──

    public function test_nullable(): void
    {
        $this->assertTrue(V::nullable(['nullable' => true], null));
        $this->assertFalse(V::nullable(['nullable' => true], 'val'));
        $this->assertFalse(V::nullable(['nullable' => false], null));
        $this->assertFalse(V::nullable([], null));
    }

    public function test_default(): void
    {
        $this->assertTrue(V::default(['default' => 'foo'], null));
        $this->assertFalse(V::default(['default' => 'foo'], 'bar'));
        $this->assertFalse(V::default([], null));
    }

    public function test_required(): void
    {
        $this->assertTrue(V::required(['required' => true], 'val'));
        $this->assertFalse(V::required(['required' => true], null));
        $this->assertFalse(V::required(['required' => true], ''));
        $this->assertFalse(V::required(['required' => true], []));
        $this->assertTrue(V::required(['required' => false], null));
        $this->assertTrue(V::required([], null));
    }

    // ── UUID ──

    public function test_uuid_v4_validation(): void
    {
        $this->assertTrue(V::uuid_v4('550e8400-e29b-41d4-a716-446655440000'));
        $this->assertFalse(V::uuid_v4('invalid'));
        $this->assertFalse(V::uuid_v4(123));
    }

    // ── Enum ──

    public function test_enum(): void
    {
        $this->assertTrue(V::enum('a', ['a', 'b', 'c']));
        $this->assertFalse(V::enum('d', ['a', 'b', 'c']));
        $this->assertTrue(V::enum(['a', 'b'], ['a', 'b', 'c']));
        $this->assertFalse(V::enum(['a', 'd'], ['a', 'b', 'c']));
    }

    // ── Regex ──

    public function test_regex(): void
    {
        $this->assertTrue(V::regex('abc123', '/^[a-z0-9]+$/'));
        $this->assertFalse(V::regex('ABC!', '/^[a-z0-9]+$/'));
        $this->assertFalse(V::regex(123, '/^[a-z]+$/'));
    }

    // ── Callback ──

    public function test_callback_rule(): void
    {
        $this->assertTrue(V::callback(10, fn($v) => $v > 5));
        $this->assertFalse(V::callback(3, fn($v) => $v > 5));
    }

    // ── Numeric min/max ──

    public function test_num_min_max(): void
    {
        $this->assertTrue(V::num_min(10, 5));
        $this->assertFalse(V::num_min(3, 5));
        $this->assertTrue(V::num_max(5, 10));
        $this->assertFalse(V::num_max(15, 10));
        $this->assertFalse(V::num_min('abc', 0));
    }

    // ── String min/max ──

    public function test_str_min_max(): void
    {
        $this->assertTrue(V::str_min('hello', 3));
        $this->assertFalse(V::str_min('hi', 3));
        $this->assertTrue(V::str_max('hi', 5));
        $this->assertFalse(V::str_max('toolong', 3));
        $this->assertFalse(V::str_min(123, 1));
    }

    // ── MB min/max ──

    public function test_mb_min_max(): void
    {
        $this->assertTrue(V::mb_min('Привіт', 3));
        $this->assertFalse(V::mb_min('Пр', 3));
        $this->assertTrue(V::mb_max('Пр', 5));
        $this->assertFalse(V::mb_max('Привіт-мир', 5));
        $this->assertFalse(V::mb_min(123, 1));
    }

    // ── Password entropy ──

    public function test_password_entropy(): void
    {
        $this->assertTrue(V::password_entropy('Str0ng!Pass#2024', 18.0));
        $this->assertTrue(V::password_entropy('12345678', 18.0)); // 8 digits = ~26.6 bits, passes 18.0
        $this->assertFalse(V::password_entropy('short', 18.0));   // < 8 chars
        $this->assertFalse(V::password_entropy('', 18.0));
        $this->assertFalse(V::password_entropy(123, 18.0));
    }

    // ── Min / Max combined ──

    public function test_min_numeric(): void
    {
        $this->assertTrue(V::min(10, 5));
        $this->assertFalse(V::min(3, 5));
    }

    public function test_min_string_length(): void
    {
        $this->assertTrue(V::min('hello', 3));
        $this->assertFalse(V::min('hi', 3));
    }

    public function test_max_numeric(): void
    {
        $this->assertTrue(V::max(5, 10));
        $this->assertFalse(V::max(15, 10));
    }

    public function test_max_string_length(): void
    {
        $this->assertTrue(V::max('hi', 5));
        $this->assertFalse(V::max('toolong', 3));
    }
}
