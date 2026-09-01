<?php

declare(strict_types=1);

namespace Tests\Engine\App;

use Engine\Atomic\App\Model;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

class ModelFieldConfParent extends Model
{
    protected $fieldConf = [
        'email' => [
            'type' => 'VARCHAR255',
            'nullable' => false,
            'validation' => [
                'required' => true,
                'maxLength' => 100,
            ],
        ],
        'profile' => [
            'type' => 'JSON',
            'cast' => [
                'format' => 'json',
                'nullable' => false,
            ],
        ],
    ];
}

class ModelFieldConfChild extends ModelFieldConfParent
{
    protected $fieldConf = [
        'name' => [
            'type' => 'VARCHAR255',
        ],
        'email' => [
            'nullable' => true,
            'validation' => [
                'maxLength' => 120,
            ],
        ],
        'profile' => [
            'cast' => [
                'format' => 'serialized',
            ],
        ],
    ];
}

class ModelFieldConfTest extends TestCase
{
    public function test_parent_and_child_class_field_conf_are_merged_base_first(): void
    {
        $this->assertSame([
            'email' => [
                'type' => 'VARCHAR255',
                'nullable' => true,
                'validation' => [
                    'required' => true,
                    'maxLength' => 120,
                ],
            ],
            'profile' => [
                'type' => 'JSON',
                'cast' => [
                    'format' => 'serialized',
                    'nullable' => false,
                ],
            ],
            'name' => [
                'type' => 'VARCHAR255',
            ],
        ], $this->collectFieldConf(ModelFieldConfChild::class));
    }

    public function test_child_class_field_conf_overrides_only_declared_nested_values(): void
    {
        $configuration = $this->collectFieldConf(ModelFieldConfChild::class);

        $this->assertTrue($configuration['email']['nullable']);
        $this->assertSame(120, $configuration['email']['validation']['maxLength']);
        $this->assertTrue($configuration['email']['validation']['required']);
        $this->assertSame('serialized', $configuration['profile']['cast']['format']);
        $this->assertFalse($configuration['profile']['cast']['nullable']);
    }

    public function test_initialize_field_conf_applies_merged_class_configuration(): void
    {
        $model = (new ReflectionClass(ModelFieldConfChild::class))->newInstanceWithoutConstructor();
        $initialize = (new ReflectionClass(Model::class))->getMethod('initialize_field_conf');
        $initialize->invoke($model);

        $fieldConf = (new ReflectionClass(ModelFieldConfChild::class))
            ->getProperty('fieldConf')
            ->getValue($model);

        $this->assertSame($this->collectFieldConf(ModelFieldConfChild::class), $fieldConf);
    }

    private function collectFieldConf(string $modelClass): array
    {
        $model = (new ReflectionClass($modelClass))->newInstanceWithoutConstructor();
        $collect = (new ReflectionClass(Model::class))->getMethod('collect_inherited_field_conf');

        return $collect->invoke($model);
    }
}
