<?php
declare(strict_types=1);

namespace Tests\Engine\App\Schema\RelationTranslatorTest;

if (!defined('ATOMIC_START')) exit;

use Engine\Atomic\App\Model;
use Engine\Atomic\App\Schema\Definition\StructurePolicyMerger;
use Engine\Atomic\App\Schema\Domain\DomainSchema;
use Engine\Atomic\App\Schema\Domain\FieldRules;
use Engine\Atomic\App\Schema\Structure\SchemaStructure;
use PHPUnit\Framework\TestCase;

class Target extends Model
{
    protected $table = 'rt_target';
}

class NotAModel
{
}

/** Database-free: the translator only sees a live-structure snapshot. */
final class RelationTranslatorTest extends TestCase
{
    private const COLUMNS = [
        'id' => ['type' => 'int(11)', 'nullable' => false, 'pkey' => true, 'auto_inc' => true],
        'owner' => ['type' => 'int(11)', 'nullable' => true],
        'strict_owner' => ['type' => 'int(11)', 'nullable' => false],
        'big_owner' => ['type' => 'bigint(20) unsigned', 'nullable' => true],
        'uuid_owner' => ['type' => 'varchar(64)', 'nullable' => true],
        'tags' => ['type' => 'text', 'nullable' => true],
        'native_tags' => ['type' => 'json', 'nullable' => true],
        'short_tags' => ['type' => 'varchar(128)', 'nullable' => true],
        'odd' => ['type' => 'geometry', 'nullable' => true],
        'code' => ['type' => 'int(11)', 'nullable' => true],
    ];

    /** @param array<string, FieldRules> $fields */
    private function merge(array $fields, array $columns = self::COLUMNS): array
    {
        $structure = SchemaStructure::from_columns('rt_source', $columns, 'mysql');
        return StructurePolicyMerger::merge($structure, (new DomainSchema())->define($fields), self::class);
    }

    private function field(): FieldRules
    {
        return (new DomainSchema())->field();
    }

    private function assertThrows(string $message, array $fields): void
    {
        try {
            $this->merge($fields);
            $this->fail('Expected LogicException: ' . $message);
        } catch (\LogicException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    // ─── shapes ───────────────────────────────────────────────────

    public function test_belongs_to_one_primary_key_form_keeps_live_type(): void
    {
        $conf = $this->merge(['owner' => $this->field()->belongs_to_one(Target::class)])['owner'];
        $this->assertSame(Target::class, $conf['belongs-to-one']);
        $this->assertSame('INT4', $conf['type']);
        $this->assertTrue($conf['nullable']);
        $this->assertArrayNotHasKey('onDelete', $conf);
    }

    public function test_belongs_to_one_field_form(): void
    {
        $conf = $this->merge(['uuid_owner' => $this->field()->belongs_to_one(Target::class, 'uuid')])['uuid_owner'];
        $this->assertSame([Target::class, 'uuid'], $conf['belongs-to-one']);
        $this->assertSame('VARCHAR128', $conf['type']);
    }

    public function test_belongs_to_one_on_bigint_keeps_int8(): void
    {
        $conf = $this->merge(['big_owner' => $this->field()->belongs_to_one(Target::class)])['big_owner'];
        $this->assertSame('INT8', $conf['type']);
    }

    public function test_belongs_to_one_with_policy(): void
    {
        $conf = $this->merge(['strict_owner' => $this->field()->required()->belongs_to_one(Target::class)])['strict_owner'];
        $this->assertSame(Target::class, $conf['belongs-to-one']);
        $this->assertTrue($conf['required']);
        $this->assertFalse($conf['nullable']);
    }

    public function test_belongs_to_many_on_text_and_native_json(): void
    {
        $conf = $this->merge([
            'tags' => $this->field()->belongs_to_many(Target::class),
            'native_tags' => $this->field()->belongs_to_many(Target::class, 'uuid'),
        ]);
        $this->assertSame(Target::class, $conf['tags']['belongs-to-many']);
        $this->assertSame('JSON', $conf['tags']['type']);
        $this->assertSame([Target::class, 'uuid'], $conf['native_tags']['belongs-to-many']);
        $this->assertSame('JSON', $conf['native_tags']['type']);
    }

    public function test_has_one_is_virtual(): void
    {
        $conf = $this->merge(['profile' => $this->field()->has_one(Target::class, 'author')]);
        $this->assertSame(['has-one' => [Target::class, 'author']], $conf['profile']);
    }

    public function test_has_many_inverse_is_virtual(): void
    {
        $conf = $this->merge(['posts' => $this->field()->has_many(Target::class, 'author')]);
        $this->assertSame(['has-many' => [Target::class, 'author']], $conf['posts']);
    }

    public function test_has_many_pivot_without_table_emits_no_table(): void
    {
        $conf = $this->merge(['groups' => $this->field()->has_many(Target::class, 'members')->pivot()]);
        $this->assertSame(['has-many' => [Target::class, 'members']], $conf['groups']);
    }

    public function test_has_many_pivot_with_every_option(): void
    {
        $conf = $this->merge(['groups' => $this->field()->has_many(Target::class, 'members')
            ->pivot('group_members')->rel_field('member_ref')->rel_pk('code')->local_key('code')]);
        $this->assertSame(['has-many' => [
            Target::class, 'members', 'group_members',
            'relField' => 'member_ref', 'relPK' => 'code', 'localKey' => 'code',
        ]], $conf['groups']);
    }

    public function test_local_key_may_be_the_primary_key(): void
    {
        $conf = $this->merge(['groups' => $this->field()->has_many(Target::class, 'members')->pivot()->local_key('id')]);
        $this->assertSame('id', $conf['groups']['has-many']['localKey']);
    }

    public function test_relations_are_reported_to_the_manager(): void
    {
        $this->merge([
            'owner' => $this->field()->belongs_to_one(Target::class),
            'groups' => $this->field()->has_many(Target::class, 'members')->pivot('p'),
            'code' => $this->field()->required(false),
        ]);
        $relations = StructurePolicyMerger::relations(self::class);
        $this->assertSame(['owner', 'groups'], array_keys($relations));
        $this->assertSame(['pivot' => 'p'], $relations['groups']['options']);
    }

    // ─── negatives: declaration ───────────────────────────────────

    public function test_two_relations_on_one_field_are_rejected(): void
    {
        $this->assertThrows('field "owner" declares more than one relation (belongs-to-one, belongs-to-many)',
            ['owner' => $this->field()->belongs_to_one(Target::class)->belongs_to_many(Target::class)]);
    }

    public function test_options_without_relation_are_rejected(): void
    {
        $this->assertThrows('field "owner" declares relation option(s) rel_field() without a relation',
            ['owner' => $this->field()->rel_field('x')]);
    }

    public function test_duplicate_option_is_rejected(): void
    {
        $this->assertThrows('declares relation option "pivot" twice',
            ['groups' => $this->field()->has_many(Target::class, 'members')->pivot()->pivot()]);
    }

    public function test_unknown_target_class_is_rejected(): void
    {
        $this->assertThrows('relation to unknown class "Nope\\Missing"',
            ['owner' => $this->field()->belongs_to_one('Nope\\Missing')]);
    }

    public function test_non_model_target_is_rejected(): void
    {
        $this->assertThrows('which is not a Cortex model',
            ['owner' => $this->field()->belongs_to_one(NotAModel::class)]);
    }

    public function test_empty_and_underscore_id_related_fields_are_rejected(): void
    {
        $this->assertThrows('with an empty related field', ['posts' => $this->field()->has_many(Target::class, ' ')]);
        $this->assertThrows('names "_id" as the related field', ['owner' => $this->field()->belongs_to_one(Target::class, '_id')]);
    }

    public function test_option_not_supported_by_kind_is_rejected(): void
    {
        $this->assertThrows('pivot() is not supported on a belongs-to-one relation',
            ['owner' => $this->field()->belongs_to_one(Target::class)->pivot()]);
        $this->assertThrows('local_key() is not supported on a has-one relation',
            ['profile' => $this->field()->has_one(Target::class, 'author')->local_key('id')]);
        $this->assertThrows('rel_field() is not supported on a belongs-to-many relation',
            ['tags' => $this->field()->belongs_to_many(Target::class)->rel_field('x')]);
    }

    public function test_pivot_options_require_pivot(): void
    {
        foreach (['rel_field', 'rel_pk', 'local_key'] as $method) {
            $this->assertThrows($method . '() only applies to a many-to-many has-many; declare pivot() as well',
                ['posts' => $this->field()->has_many(Target::class, 'author')->$method('code')]);
        }
    }

    public function test_empty_option_values_are_rejected(): void
    {
        $this->assertThrows('rel_field() needs a non-empty value',
            ['groups' => $this->field()->has_many(Target::class, 'members')->pivot()->rel_field('')]);
        $this->assertThrows('pivot() table name must be non-empty',
            ['groups' => $this->field()->has_many(Target::class, 'members')->pivot(' ')]);
    }

    // ─── negatives: structure ─────────────────────────────────────


    public function test_column_relation_without_column_is_rejected(): void
    {
        $this->assertThrows('field "author" declares a belongs-to-one relation, but table "rt_source" has no column "author"',
            ['author' => $this->field()->belongs_to_one(Target::class)]);
        $this->assertThrows('field "labels" declares a belongs-to-many relation, but table "rt_source" has no column "labels"',
            ['labels' => $this->field()->belongs_to_many(Target::class)]);
    }

    public function test_virtual_relation_shadowing_a_column_is_rejected(): void
    {
        $this->assertThrows('field "owner" declares a virtual has-many relation, but the table has a column with that name',
            ['owner' => $this->field()->has_many(Target::class, 'author')]);
        $this->assertThrows('field "owner" declares a virtual has-one relation',
            ['owner' => $this->field()->has_one(Target::class, 'author')]);
    }

    public function test_virtual_relation_with_policy_is_rejected(): void
    {
        $this->assertThrows('field "posts" combines a virtual has-many relation with validation or cast policy',
            ['posts' => $this->field()->required()->has_many(Target::class, 'author')]);
        $this->assertThrows('field "profile" combines a virtual has-one relation',
            ['profile' => $this->field()->json()->has_one(Target::class, 'author')]);
    }

    public function test_relation_on_primary_key_is_rejected(): void
    {
        $columns = self::COLUMNS;
        $columns['id']['auto_inc'] = false;
        try {
            $this->merge(['id' => $this->field()->belongs_to_one(Target::class)], $columns);
            $this->fail('Expected LogicException');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('field "id" declares a belongs-to-one relation on the primary key column', $e->getMessage());
        }
    }

    public function test_relation_on_untyped_column_is_rejected(): void
    {
        $this->assertThrows('field "odd" declares a belongs-to-one relation on a column with unrecognized type "geometry"',
            ['odd' => $this->field()->belongs_to_one(Target::class)]);
    }

    public function test_relation_with_cast_is_rejected(): void
    {
        $this->assertThrows('field "uuid_owner" combines a belongs-to-one relation with the json cast',
            ['uuid_owner' => $this->field()->json()->belongs_to_one(Target::class)]);
    }

    public function test_belongs_to_many_needs_json_capable_storage(): void
    {
        $this->assertThrows('field "short_tags" declares a belongs-to-many relation on a VARCHAR128 (string) column',
            ['short_tags' => $this->field()->belongs_to_many(Target::class)]);
        $this->assertThrows('field "owner" declares a belongs-to-many relation on a INT4 (integer) column',
            ['owner' => $this->field()->belongs_to_many(Target::class)]);
    }

    public function test_belongs_to_many_with_policy_is_rejected(): void
    {
        $this->assertThrows('field "tags" combines a belongs-to-many relation with validation or cast policy',
            ['tags' => $this->field()->required()->belongs_to_many(Target::class)]);
    }

    public function test_rel_pk_must_be_a_non_primary_column(): void
    {
        $this->assertThrows('rel_pk("id") must name a non-primary-key column of table "rt_source"',
            ['groups' => $this->field()->has_many(Target::class, 'members')->pivot()->rel_pk('id')]);
        $this->assertThrows('rel_pk("missing") must name a non-primary-key column',
            ['groups' => $this->field()->has_many(Target::class, 'members')->pivot()->rel_pk('missing')]);
    }

    public function test_local_key_must_be_a_column(): void
    {
        $this->assertThrows('local_key("missing") has no matching column in table "rt_source"',
            ['groups' => $this->field()->has_many(Target::class, 'members')->pivot()->local_key('missing')]);
    }

    public function test_missing_column_without_relation_still_rejected(): void
    {
        $this->assertThrows('Domain policy for field "ghost"', ['ghost' => $this->field()->required()]);
    }
}
