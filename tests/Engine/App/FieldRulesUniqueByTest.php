<?php
declare(strict_types=1);
namespace Tests\Engine\App;
if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\App\Schema\Definition\StructurePolicyMerger;
use Engine\Atomic\App\Schema\Domain\DomainSchema;
use Engine\Atomic\App\Schema\Domain\FieldRules;
use Engine\Atomic\App\Schema\Structure\SchemaStructure;
use PHPUnit\Framework\TestCase;

final class FieldRulesUniqueByTest extends TestCase
{
    private function merge(FieldRules $rules): array
    {
        $structure = SchemaStructure::from_columns('probe', [
            'id' => ['type' => 'int', 'nullable' => false, 'primary_key' => true, 'auto_increment' => true],
            'store' => ['type' => 'int', 'nullable' => true],
            'email' => ['type' => 'varchar(128)', 'nullable' => false],
        ], 'mysql');
        return StructurePolicyMerger::merge($structure, (new DomainSchema())->define(['email' => $rules]), self::class);
    }

    public function test_unique_by_exports_unique_and_scope_policy(): void
    {
        $this->assertSame(
            ['unique' => true, 'unique_by' => ['store', 'site']],
            (new FieldRules())->unique_by(['store', 'site', 'store'])->to_policy('email')
        );
    }

    public function test_unique_false_clears_scope(): void
    {
        $this->assertSame([], (new FieldRules())->unique_by(['store'])->unique(false)->to_policy('email'));
    }

    public function test_plain_unique_has_no_scope(): void
    {
        $this->assertSame(['unique' => true], (new FieldRules())->unique()->to_policy('email'));
    }

    public function test_empty_scope_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unique_by() scope fields must not be empty.');
        (new FieldRules())->unique_by([]);
    }

    public function test_non_string_scope_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new FieldRules())->unique_by(['']);
    }

    public function test_merged_conf_matches_legacy_key_order(): void
    {
        $conf = $this->merge((new FieldRules())->required()->unique_by(['store']))['email'];
        $this->assertSame(true, $conf['unique']);
        $this->assertSame(['store'], $conf['unique_by']);
        $keys = array_keys($conf);
        $this->assertSame(array_search('unique', $keys, true) + 1, array_search('unique_by', $keys, true));
        $this->assertSame(true, $conf['required']);
    }

    public function test_unknown_scope_column_is_rejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('unique_by() scope field "tenant" for field "email" in '.self::class.' has no matching column in table "probe".');
        $this->merge((new FieldRules())->unique_by(['tenant']));
    }

    public function test_self_scope_is_rejected(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('cannot be the field itself');
        $this->merge((new FieldRules())->unique_by(['email']));
    }
}
