<?php
declare(strict_types=1);
namespace Tests\Engine\App;
if (!defined('ATOMIC_START')) exit;
use DB\Cortex\Schema\ColumnType;
use Engine\Atomic\Validator\Validator;
use PHPUnit\Framework\TestCase;
final class ColumnFamilyTest extends TestCase
{
    public function test_new_metadata_validates_values(): void
    {
        $model = (new \ReflectionClass(\DB\Cortex::class))->newInstanceWithoutConstructor();
        foreach ([['uuid','pgsql','550e8400-e29b-41d4-a716-446655440000',true],['uuid','pgsql','bad',false],['binary(16)','mysql',str_repeat('x',17),false],['float(7,4)','mysql','123.4567',true],['float(7,4)','mysql','1234.5678',false],["set('a','b')",'mysql','a,b',true],["set('a','b')",'mysql','c',false],['time(7)','sqlsrv','24:00:00',false],['time(6) with time zone','pgsql','12:30:00+02:00',true],['numeric(10,8)','pgsql','1.23456789',true],['money','sqlsrv','922337203685477.5808',false],['money','sqlsrv','-922337203685477.5808',true],['smallmoney','sqlsrv','214748.3648',false],["enum('a[]')",'mysql','a[]',true]] as [$raw,$driver,$value,$valid]) {
            $this->assertSame($valid, Validator::validate_model(ColumnType::describe($raw,'t','v',$driver),$value,'v',$model)[0], $raw);
        }
    }
}
