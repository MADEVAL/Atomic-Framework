<?php
declare(strict_types=1);

namespace Tests\Engine\App;

use Engine\Atomic\App\Model;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * @covers \Engine\Atomic\App\Model
 */
class ModelTraitFieldConfTest extends TestCase
{
    public function test_trait_fieldConf_is_merged_for_leaf_class(): void
    {
        $merged = $this->callCollectInheritedFieldConf(ModelTraitTest_ModelWithTrait::class);
        $this->assertArrayHasKey('from_trait', $merged);
        $this->assertSame('VARCHAR8', $merged['from_trait']['type'] ?? null);
    }

    public function test_trait_on_parent_and_trait_on_child_both_merge(): void
    {
        $merged = $this->callCollectInheritedFieldConf(ModelTraitTest_ChildWithSecondTrait::class);
        $this->assertArrayHasKey('from_parent_trait', $merged);
        $this->assertArrayHasKey('from_child_trait', $merged);
    }

    public function test_class_body_fieldConf_overrides_merged_trait_fieldConf(): void
    {
        $merged = $this->callCollectInheritedFieldConf(ModelTraitTest_ChildWithOverride::class);
        $this->assertTrue($merged['from_trait']['nullable'] ?? false, 'redeclared class $fieldConf should win over trait for the same key');
    }

    public function test_fieldConf_from_inner_trait_merged_when_class_only_uses_wrapper_trait(): void
    {
        $merged = $this->callCollectInheritedFieldConf(ModelTraitTest_NestedWrapperModel::class);
        $this->assertArrayHasKey('from_inner', $merged);
    }

    public function test_initialize_field_conf_merges_trait_inheritance(): void
    {
        $ref = new ReflectionClass(ModelTraitTest_ChildWithSecondTrait::class);
        $model = $ref->newInstanceWithoutConstructor();
        $init = $ref->getMethod('initialize_field_conf');
        $init->invoke($model);
        $prop = $ref->getProperty('fieldConf');
        /** @var array $conf */
        $conf = $prop->getValue($model);
        $this->assertArrayHasKey('from_parent_trait', $conf);
        $this->assertArrayHasKey('from_child_trait', $conf);
    }

    /**
     * @return array<string, mixed>
     */
    private function callCollectInheritedFieldConf(string $modelClass): array
    {
        $ref = new ReflectionClass($modelClass);
        $model = $ref->newInstanceWithoutConstructor();
        $m = $ref->getMethod('collect_inherited_field_conf');
        $result = $m->invoke($model);
        $this->assertIsArray($result);
        return $result;
    }
}

trait ModelTraitTest_FieldsA {
    protected $fieldConf = [
        'from_trait' => [
            'type' => 'VARCHAR8',
            'nullable' => true,
        ],
    ];
}

trait ModelTraitTest_ParentFields {
    protected $fieldConf = [
        'from_parent_trait' => [
            'type' => 'INT4',
        ],
    ];
}

trait ModelTraitTest_ChildFields {
    protected $fieldConf = [
        'from_child_trait' => [
            'type' => 'VARCHAR32',
        ],
    ];
}

trait ModelTraitTest_Inner {
    protected $fieldConf = [
        'from_inner' => ['type' => 'VARCHAR1'],
    ];
}

trait ModelTraitTest_WrapperTrait {
    use ModelTraitTest_Inner;
}

class ModelTraitTest_TraitOnlyBase extends Model
{
    use ModelTraitTest_FieldsA;

    protected $table = 'm_trait_t_shared';
}

class ModelTraitTest_ModelWithTrait extends Model
{
    use ModelTraitTest_FieldsA;

    protected $table = 'm_trait_t1';
}

class ModelTraitTest_ParentWithTrait extends Model
{
    use ModelTraitTest_ParentFields;

    protected $table = 'm_trait_t2';
}

class ModelTraitTest_ChildWithSecondTrait extends ModelTraitTest_ParentWithTrait
{
    use ModelTraitTest_ChildFields;
}

class ModelTraitTest_ChildWithOverride extends ModelTraitTest_TraitOnlyBase
{
    protected $fieldConf = [
        'from_trait' => [
            'type' => 'VARCHAR8',
            'nullable' => true,
        ],
    ];
}

class ModelTraitTest_NestedWrapperModel extends Model
{
    use ModelTraitTest_WrapperTrait;

    protected $table = 'm_trait_t_nested';
}
