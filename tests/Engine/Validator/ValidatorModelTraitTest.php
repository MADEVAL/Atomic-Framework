<?php
declare(strict_types=1);

namespace Tests\Engine\Validator;

use Engine\Atomic\Validator\Validator;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class ValidatorModelTraitTest extends TestCase
{
    // ────────────────────────────────────────
    //  Boolean
    // ────────────────────────────────────────

    #[DataProvider('booleanProvider')]
    public function test_validate_boolean(mixed $value, bool $expected): void
    {
        $this->assertSame($expected, Validator::validate_boolean($value));
    }

    public static function booleanProvider(): array
    {
        return [
            'true'       => [true, true],
            'false'      => [false, true],
            'int 1'      => [1, true],
            'int 0'      => [0, true],
            'string 1'   => ['1', true],
            'string 0'   => ['0', true],
            'string yes' => ['yes', false],
            'int 2'      => [2, false],
            'null'       => [null, false],
            'empty str'  => ['', false],
        ];
    }

    // ────────────────────────────────────────
    //  Integer
    // ────────────────────────────────────────

    #[DataProvider('integerProvider')]
    public function test_validate_integer(mixed $val, int $min, int $max, bool $expected): void
    {
        $this->assertSame($expected, Validator::validate_integer($val, $min, $max));
    }

    public static function integerProvider(): array
    {
        return [
            'min boundary'      => [-128, -128, 127, true],
            'max boundary'      => [127, -128, 127, true],
            'over max'          => [128, -128, 127, false],
            'under min'         => [-129, -128, 127, false],
            'zero'              => [0, -128, 127, true],
            'string int'        => ['42', 0, 100, true],
            'negative string'   => ['-42', -100, 0, true],
            'float'             => [1.5, 0, 100, false],
            'float int-valued'  => [1.0, 0, 100, false],
            'string non-int'    => ['abc', 0, 100, false],
            'string whitespace' => [' 42', 0, 100, true], // filter_var trims whitespace by design
            'null'              => [null, 0, 100, false],
        ];
    }

    // ────────────────────────────────────────
    //  Float / Double
    // ────────────────────────────────────────

    public function test_validate_float_valid(): void
    {
        $this->assertTrue(Validator::validate_float(3.14));
        $this->assertTrue(Validator::validate_float('2.71'));
        $this->assertTrue(Validator::validate_float(0));
        $this->assertTrue(Validator::validate_float(-1.5));
    }

    public function test_validate_float_invalid(): void
    {
        $this->assertFalse(Validator::validate_float('abc'));
        $this->assertFalse(Validator::validate_float(INF));
        $this->assertFalse(Validator::validate_float(-INF));
        $this->assertFalse(Validator::validate_float(NAN));
        $this->assertFalse(Validator::validate_float(3.402823467E+38)); // just over MySQL FLOAT max
    }

    public function test_validate_double_valid(): void
    {
        $this->assertTrue(Validator::validate_double(1.7976931348623E+100));
        $this->assertTrue(Validator::validate_double('99.99'));
    }

    public function test_validate_double_invalid(): void
    {
        $this->assertFalse(Validator::validate_double('not_a_number'));
        $this->assertFalse(Validator::validate_double(INF));
    }

    // ────────────────────────────────────────
    //  Varchar / Text / Blob
    // ────────────────────────────────────────

    public function test_validate_varchar(): void
    {
        $this->assertTrue(Validator::validate_varchar('hello', 128));
        $this->assertTrue(Validator::validate_varchar(str_repeat('a', 128), 128));
        $this->assertFalse(Validator::validate_varchar(str_repeat('a', 129), 128));
        $this->assertFalse(Validator::validate_varchar(123, 128));
        // varchar uses char count (mb_strlen), not byte count
        $this->assertTrue(Validator::validate_varchar(str_repeat('т', 128), 128));  // 128 Cyrillic chars = 256 bytes
        $this->assertFalse(Validator::validate_varchar(str_repeat('т', 129), 128)); // 129 chars over limit
    }

    public function test_validate_text(): void
    {
        $this->assertTrue(Validator::validate_text('short text', 65535));
        $this->assertFalse(Validator::validate_text(42, 65535));
    }

    public function test_validate_blob(): void
    {
        $this->assertTrue(Validator::validate_blob("\x00\x01\x02", 65535));
        $this->assertFalse(Validator::validate_blob(999, 65535));
    }

    // ────────────────────────────────────────
    //  Date / DateTime / Timestamp
    // ────────────────────────────────────────

    public function test_validate_date(): void
    {
        $this->assertTrue(Validator::validate_date('2025-01-15'));
        $this->assertTrue(Validator::validate_date('1000-01-01'));
        $this->assertTrue(Validator::validate_date('9999-12-31'));
        $this->assertFalse(Validator::validate_date('2025-13-01')); // invalid month
        $this->assertFalse(Validator::validate_date('2025-02-30')); // invalid calendar date
        $this->assertFalse(Validator::validate_date('0999-12-31')); // year below 1000
        $this->assertFalse(Validator::validate_date('2025-1-5'));   // non-zero-padded
        $this->assertFalse(Validator::validate_date('not-a-date'));
        $this->assertFalse(Validator::validate_date(12345));
    }

    public function test_validate_datetime(): void
    {
        $this->assertTrue(Validator::validate_datetime('2025-06-15 14:30:00'));
        $this->assertTrue(Validator::validate_datetime('2025-01-01 00:00:00'));
        $this->assertFalse(Validator::validate_datetime('2025-06-15'));           // date only
        $this->assertFalse(Validator::validate_datetime('2025-13-01 00:00:00')); // invalid month
        $this->assertFalse(Validator::validate_datetime('2025-01-01 25:00:00')); // invalid hour
        $this->assertFalse(Validator::validate_datetime('not a datetime'));
    }

    public function test_validate_timestamp(): void
    {
        $this->assertTrue(Validator::validate_timestamp(1000000));
        $this->assertTrue(Validator::validate_timestamp('1000000'));
        $this->assertTrue(Validator::validate_timestamp('2025-06-15 00:00:00'));
        $this->assertTrue(Validator::validate_timestamp(253402300799));  // max valid (9999-12-31 23:59:59 UTC)
        $this->assertFalse(Validator::validate_timestamp(253402300800)); // one over max
        $this->assertFalse(Validator::validate_timestamp(0));
        $this->assertFalse(Validator::validate_timestamp(-1));
        $this->assertFalse(Validator::validate_timestamp('0'));           // digit string but below min
        $this->assertFalse(Validator::validate_timestamp('never'));
    }

    // ────────────────────────────────────────
    //  Nullable / Required / Default
    // ────────────────────────────────────────

    public function test_nullable(): void
    {
        $this->assertTrue(Validator::nullable(['nullable' => true], null));
        $this->assertTrue(Validator::nullable(['nullable' => true], ''));
        $this->assertFalse(Validator::nullable(['nullable' => false], null));
        $this->assertFalse(Validator::nullable(['nullable' => true], 'value'));
        // PHP-falsy values are NOT considered nullable (only null and '' are)
        $this->assertFalse(Validator::nullable(['nullable' => true], '0'));
        $this->assertFalse(Validator::nullable(['nullable' => true], 0));
        $this->assertFalse(Validator::nullable(['nullable' => true], false));
        $this->assertFalse(Validator::nullable(['nullable' => true], []));
        // no nullable key
        $this->assertFalse(Validator::nullable([], null));
    }

    public function test_required(): void
    {
        $this->assertFalse(Validator::required(['required' => true], null));
        $this->assertFalse(Validator::required(['required' => true], ''));
        $this->assertFalse(Validator::required(['required' => true], []));
        $this->assertTrue(Validator::required(['required' => true], 'value'));
        $this->assertTrue(Validator::required(['required' => false], null));
        $this->assertTrue(Validator::required([], null));
        // 0 and false are valid non-empty values
        $this->assertTrue(Validator::required(['required' => true], 0));
        $this->assertTrue(Validator::required(['required' => true], false));
        $this->assertTrue(Validator::required(['required' => true], '0'));
    }

    public function test_default(): void
    {
        $this->assertTrue(Validator::default(['default' => 'foo'], null));
        $this->assertFalse(Validator::default(['default' => 'foo'], 'bar'));
        $this->assertFalse(Validator::default([], null));
    }

    // ────────────────────────────────────────
    //  String length (byte)
    // ────────────────────────────────────────

    public function test_str_min(): void
    {
        $this->assertTrue(Validator::str_min('abcd', 4));
        $this->assertTrue(Validator::str_min('abcde', 4));
        $this->assertFalse(Validator::str_min('abc', 4));
        $this->assertFalse(Validator::str_min(123, 1));
    }

    public function test_str_max(): void
    {
        $this->assertTrue(Validator::str_max('abc', 4));
        $this->assertTrue(Validator::str_max('abcd', 4));
        $this->assertFalse(Validator::str_max('abcde', 4));
        $this->assertFalse(Validator::str_max(123, 4));
    }

    // ────────────────────────────────────────
    //  Multibyte string length
    // ────────────────────────────────────────

    public function test_mb_min(): void
    {
        $this->assertTrue(Validator::mb_min('тест', 4));
        $this->assertTrue(Validator::mb_min('тесты', 4));
        $this->assertFalse(Validator::mb_min('те', 4));
        $this->assertFalse(Validator::mb_min(123, 1));
    }

    public function test_mb_max(): void
    {
        $this->assertTrue(Validator::mb_max('тест', 4));
        $this->assertFalse(Validator::mb_max('тесты', 4));
    }

    // ────────────────────────────────────────
    //  Numeric min / max
    // ────────────────────────────────────────

    public function test_num_min(): void
    {
        $this->assertTrue(Validator::num_min(10, 5));
        $this->assertTrue(Validator::num_min(5, 5));
        $this->assertFalse(Validator::num_min(4, 5));
        $this->assertFalse(Validator::num_min('abc', 5));
    }

    public function test_num_max(): void
    {
        $this->assertTrue(Validator::num_max(5, 10));
        $this->assertTrue(Validator::num_max(10, 10));
        $this->assertFalse(Validator::num_max(11, 10));
    }

    // ────────────────────────────────────────
    //  Email / URL (via F3 Audit)
    // ────────────────────────────────────────

    public function test_email(): void
    {
        $this->assertTrue(Validator::email('user@example.com'));
        $this->assertFalse(Validator::email('not-an-email'));
        $this->assertFalse(Validator::email(''));
        $this->assertFalse(Validator::email(123));
    }

    public function test_url(): void
    {
        $this->assertTrue(Validator::url('https://example.com'));
        $this->assertFalse(Validator::url('not a url'));
        $this->assertFalse(Validator::url(123));
    }

    // ────────────────────────────────────────
    //  Enum
    // ────────────────────────────────────────

    public function test_enum(): void
    {
        $this->assertTrue(Validator::enum('a', ['a', 'b', 'c']));
        $this->assertFalse(Validator::enum('d', ['a', 'b', 'c']));
        $this->assertTrue(Validator::enum(['a', 'b'], ['a', 'b', 'c']));
        $this->assertFalse(Validator::enum(['a', 'd'], ['a', 'b', 'c']));
    }

    // ────────────────────────────────────────
    //  Regex
    // ────────────────────────────────────────

    public function test_regex(): void
    {
        $this->assertTrue(Validator::regex('#FF0000', '/^#[0-9A-Fa-f]{6}$/'));
        $this->assertFalse(Validator::regex('red', '/^#[0-9A-Fa-f]{6}$/'));
        $this->assertFalse(Validator::regex(123, '/^\d+$/'));
    }

    // ────────────────────────────────────────
    //  UUID v4
    // ────────────────────────────────────────

    public function test_uuid_v4(): void
    {
        $this->assertTrue(Validator::uuid_v4('550e8400-e29b-41d4-a716-446655440000'));
        $this->assertFalse(Validator::uuid_v4('not-a-uuid'));
        $this->assertFalse(Validator::uuid_v4(123));
    }

    // ────────────────────────────────────────
    //  Callback
    // ────────────────────────────────────────

    public function test_callback(): void
    {
        $this->assertTrue(Validator::callback(10, fn($v) => $v > 5));
        $this->assertFalse(Validator::callback(3, fn($v) => $v > 5));
        $this->assertTrue(Validator::callback('hello', fn($v) => strlen($v) === 5));
        $this->assertFalse(Validator::callback('hi', fn($v) => strlen($v) === 5));
    }

    // ────────────────────────────────────────
    //  Generic min / max
    // ────────────────────────────────────────

    public function test_min(): void
    {
        $this->assertTrue(Validator::min(10, 5));
        $this->assertTrue(Validator::min(5, 5));
        $this->assertFalse(Validator::min(4, 5));
        // string: uses byte length
        $this->assertTrue(Validator::min('abcde', 5));
        $this->assertFalse(Validator::min('abc', 5));
        // non-numeric, non-string
        $this->assertFalse(Validator::min(null, 0));
    }

    public function test_max(): void
    {
        $this->assertTrue(Validator::max(5, 10));
        $this->assertTrue(Validator::max(10, 10));
        $this->assertFalse(Validator::max(11, 10));
        // string: uses byte length
        $this->assertTrue(Validator::max('abc', 5));
        $this->assertFalse(Validator::max('abcdef', 5));
        // non-numeric, non-string
        $this->assertFalse(Validator::max(null, 10));
    }

}
