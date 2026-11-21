<?php
declare(strict_types=1);
namespace Tests\Engine\App;
if (!defined('ATOMIC_START')) exit;
use DB\Cortex\Schema\ColumnType;
use Engine\Atomic\Validator\Validator;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
/** Validator checks against Cortex ColumnType descriptors; the type mapping itself is tested in Cortex-Atomic on every engine. */
final class ColumnTypeTest extends TestCase
{
    public static function limits(): array
    {
        return [
            ['varchar(64)', str_repeat('é',64), true], ['varchar(64)', str_repeat('a',65), false],
            ['varchar(256)', str_repeat('a',256), true], ['char(36)', str_repeat('a',37), false],
            ['mediumint', 8388608, false], ['mediumint unsigned', 16777215, true],
            ['int unsigned', 4294967295, true], ['int unsigned', -1, false],
            ['bigint unsigned', '18446744073709551615', true], ['bigint unsigned', '18446744073709551616', false],
            ['decimal(5,2)', '999.99', true], ['decimal(5,2)', '1000', false], ['decimal(5,2)', '1.001', false],
            ['decimal(5,2) unsigned', '-0.01', false], ['decimal(5,2)', '1e2', true],
            ['tinytext', str_repeat('é',128), false], ['mediumblob', str_repeat('x',65536), true],
            ['time', '-838:59:59', true], ['time', '839:00:00', false], ['time', '12:60:00', false],
            ['year', 1901, true], ['year', 1900, false], ['year', 0, true],
            ['json', '{"x":1}', true], ['json', '{bad}', false], ['json', ['x'=>1], true],
            ["enum('a','b')", 'b', true], ["enum('a','b')", 'c', false],
            ['varchar(1024)', str_repeat('é',1024), true], ['varchar(1024)', str_repeat('a',1025), false],
            ['smallint unsigned', 65536, false], ['tinyint unsigned', 255, true],
            ['bigint', '-9223372036854775808', true], ['bigint', '9223372036854775808', false],
            ['decimal(5,2)', '1e9999999999999999999999', false],
            ['decimal(5,2)', '1e-9999999999999999999999', false],
            ['time(3)', '12:34:56.123', true], ['time(3)', '12:34:56.1234', false],
            ['datetime(6)', '2026-01-01 12:00:00.123456', true],
            ['float unsigned', -1.2, false], ['year', 2156, false],
            ['json', INF, false], ['json', 'null', true],
        ];
    }
    #[DataProvider('limits')]
    public function test_real_limits_are_validated(string $raw, mixed $value, bool $valid): void
    {
        $model = (new \ReflectionClass(\DB\Cortex::class))->newInstanceWithoutConstructor();
        $this->assertSame($valid, Validator::validate_model(ColumnType::describe($raw, 'items', 'value', 'mysql'), $value, 'value', $model)[0]);
    }
    public function test_legacy_tokens_keep_results_and_error_codes(): void
    {
        $model = (new \ReflectionClass(\DB\Cortex::class))->newInstanceWithoutConstructor();
        foreach (['BOOLEAN'=>true,'INT1'=>127,'INT2'=>32767,'INT4'=>2147483647,'INT8'=>PHP_INT_MAX,'FLOAT'=>1.5,'DOUBLE'=>1.5,'DECIMAL'=>1.5,'VARCHAR128'=>'a','VARCHAR256'=>'a','VARCHAR512'=>'a','TEXT'=>'a','LONGTEXT'=>'a','DATE'=>'2026-01-01','DATETIME'=>'2026-01-01 12:00:00','TIMESTAMP'=>1,'BLOB'=>'a'] as $type=>$value) {
            $this->assertSame([true], Validator::validate_model(['type'=>$type], $value, 'value', $model));
        }
        $this->assertSame([false, \Engine\Atomic\Codes\Code::SERVER_ERROR, ['max'=>255, 'unit'=>'chars']], Validator::validate_model(['type'=>'VARCHAR256'], str_repeat('a',256), 'value', $model));
    }
}
