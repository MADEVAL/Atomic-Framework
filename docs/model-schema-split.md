# Model definitions and live database structure

Migration DDL is the single source of truth for domain model storage. Models own
validation rules and casts. Both array-returning and class-format migrations run
through the migration executor and may be mixed in one migration history. Domain
models inspect the resulting database, independently of migration file format.
See [class-format migrations](migrations.md).

## Runtime flow

`LiveSchemaSource` reads the effective table (including `DB_CONFIG.prefix`).
Cortex's `\DB\Cortex\Schema\ColumnType` converts raw SQL type families into Cortex tokens and precise storage
metadata. `StructurePolicyMerger` combines that structure with `domain_schema()`
policy into Cortex `fieldConf`. Model construction and validation consume this
configuration. See [column descriptors](column-type-descriptors.md) for exact
numeric bounds, character and byte capacities, native enum and temporal semantics.

```php
protected static function domain_schema(DomainSchema $schema): DomainDefinition
{
    return $schema->define([
        'name' => $schema->field()->required()->str_min(2),
        'payload' => $schema->field()->json(),
    ]);
}
```

Import `DomainSchema` and `DomainDefinition` from
`Engine\Atomic\App\Schema\Domain`. The table must already exist before construction
or static configuration resolution. Defaults and column definitions belong in DDL.

## Compatibility

The MySQL integration suite exercises save/load and validation for every cell,
with `DB_CONFIG.prefix` empty and set:

| Model declaration | Array migration | Class migration |
| --- | --- | --- |
| Legacy `$fieldConf` | Supported | Supported |
| Domain `domain_schema(DomainSchema)` | Supported | Supported |

Array create followed by class alter, and class create followed by array alter,
are supported. After schema changes, reset model definitions before constructing
models again; legacy declarations retain their configuration while domain models
pick up added columns. Without an explicit reset or swap, normal schema cache TTLs apply to external DDL changes.

`ModelDefinitionManager` selects one strategy per declaring class, traverses parents
first, and uses recursive array replacement. Child keys win; omitted parent keys
survive (including rule arguments). A domain contribution includes the whole live
table structure, so its types replace inherited legacy types. A legacy child can
replace individual keys while retaining domain metadata. Rule arrays also merge
by numeric index rather than being appended. Review inherited rules deliberately.
Legacy parent/domain child and domain parent/legacy child are covered by
integration tests. Two styles declared on the same class throw
`LogicException`.

Cortex static configuration is memoized per domain model class on the active
manager. `ModelDefinitionManager::reset()` and `swap()` invalidate the active
configuration lifecycle and clear Cortex's shared column cache. A swapped-in manager discards its previously collected definitions and resolved configurations.

## Rules, casts and limits

- `required()` requires a non-null database column; it provides a validation error
  before storage. `unique()` is an optional early validation check; migrations must
  create unique indexes for concurrency guarantees. Structure does not add a
  uniqueness rule automatically.
- `unique_by(['store'])` is scoped uniqueness, the domain form of legacy
  `'unique' => true, 'unique_by' => ['store']`: the value must be unique only
  among rows with the same scope values (e.g. email per store). It compiles to
  the same fieldConf and runs the same validator check: same `UNIQUE` error code,
  the current record is excluded on update, and the prefixed table is queried.
  A NULL scope value is matched with `IS NULL`, so NULL-scoped rows share one
  bucket in this pre-check (a MySQL composite UNIQUE index would allow those
  duplicates). Scope fields must be columns of the live table and not the field
  itself; an empty list throws immediately, a missing column throws at merge
  time and is reported by `Model::lint()`. Like `unique()`, it is an early check
  only; the migration must still create the composite index, e.g.
  `UNIQUE KEY (store, email)`, for concurrency. `unique(false)` clears the scope.
- Domain rules must suit the live column kind. Impossible string limits, incompatible
  casts, invalid regexes and invalid native-enum subsets throw configuration errors.
  `Model::lint()` reports configuration problems as strings.
- `json()` and `serialized()` require text/blob storage; bounded char/varchar is
  rejected. Native MySQL JSON automatically selects Cortex JSON. MariaDB can expose
  its JSON alias as LONGTEXT, requiring an explicit `json()` cast. Cortex deprecates
  SERIALIZED: current writes use JSON, while legacy serialized reads remain supported.
- Relations remain legacy fieldConf declarations, including declarations inherited
  by domain models from a separate parent. There is no domain relation builder.
  Cross-style belongs-to-one and has-many resolution is supported through Cortex's
  static configuration API. A single class cannot declare legacy relations and its
  own domain schema together.
- Live schema requires an active SQL connection. This is runtime introspection, not an offline
  migration interpreter or DDL generator. Charset, collation, SQL mode and timezone
  coercions are not reproduced by validation. Legacy token-only validation retains
  its historical behavior; domain descriptors enforce exact database limits.

Integration coverage: `tests/Integration/Database/ModelMigrationCompatibilityTest.php`.

Non-null columns with database defaults carry only `has_db_default => true`
(no literal `default`). Untouched values pass pre-save validation so SQL applies
its default, including CURRENT_TIMESTAMP. Explicit `required()` still rejects null.
Assigning null to a non-nullable column never throws; the check runs on save.
Model validation answers first (`required()` returns `false` with an error code),
otherwise Cortex throws on save and stores nothing. Omit the assignment to use
the column's default.

Unrecognized raw column types do not prevent domain model construction. Without
a domain rule the column is omitted from `fieldConf`: Cortex can still read/write
it without casts or pre-save validation. A warning names the model, table, column
and raw type and says pre-save validation is skipped, once per model class and
column per process (also across manager resets). `Model::lint()` reports these
skipped columns.

With `required()`, `enum_values()`, `regex()`, `callback()` or `unique()`, an unknown
column uses the internal `DOMAIN_UNTYPED` token with `column_kind => unknown`;
these explicit rules run without inferred type checks or casts. Regex checks its
input itself and needs no storage-type assumption. String/character limits,
numeric limits, `json()`, `serialized()`, `email()`, `url()`, `uuid_v4()` and
`password_entropy()` throw a configuration LogicException on unknown storage:
they depend on a known numeric/string category or compatible cast storage.
SQLite alone uses declared-type affinity for otherwise arbitrary names. Other drivers keep unrecognized types untyped. Legacy configurations retain
their existing validation behavior.

After DDL, reset() or swap() evicts the exact F3 DSN/table schema entries read by the live source, with CACHE enabled or disabled, and clears Cortex's schema cache. Unrelated F3 cache entries are retained.

Domain instances use their current table assigned before parent construction, prefixing once. Collected definitions are memoized by class and effective table. Static resolveConfiguration() and lint() use the class default table because they have no instance table.

Explicit policies on non-auto-increment primary keys are included in fieldConf and run through normal pre-save validation; Cortex retains its primary-key mapper behavior. Primary keys without policy remain excluded. Policies on auto-increment primary keys throw a clear LogicException rather than validating an identifier generated only after insert.

Legacy lint accepts JSON and SERIALIZED tokens and relation declarations (has-many, has-one, belongs-to-one, belongs-to-many). It checks relation targets for an empty or invalid shape without requiring a scalar column type. Unknown live-column warnings remain part of lint output.
