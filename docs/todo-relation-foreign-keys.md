# TODO: finish domain relations and check them against real foreign keys

Status: todo, not scheduled. Written 2026-11-21.

## Context

The model and the database each hold half of a relation:

- **Migration** owns the database rule: the column, its type and the foreign key
  constraint with its ON DELETE action. Raw SQL obeys it.
- **Model** (`domain_schema()`) owns navigation: which PHP class a row loads as
  (`belongs_to_one`, `belongs_to_many`) and the reverse views that have no column
  (`has_one`, `has_many`, `pivot()`).

Done so far:

- Branch `feat/domain-schema-relations` (not merged): relation builder on
  `FieldRules`, translated to the exact Cortex fieldConf keys; every contradiction
  throws. `on_delete()` was removed from the model on purpose, since it belongs to
  the migration.
- Cortex-Atomic `feat/column-foreign-keys`: migrations can declare a foreign key on
  the column, `->references('users')->onDelete('CASCADE')`, emitted inside
  CREATE TABLE (SQLite included) and named `fk_<table>_<column>` on every path.

## Remaining steps

1. **Land the Atomic branch.** Update `docs/model-schema-split.md` to say foreign
   keys are declared in migrations with `references()`. Bump the `cortex-atomic`
   dependency to the version with the helper, then merge
   `feat/domain-schema-relations`.
2. **Foreign-key reader in Cortex.** A schema method that lists a table's foreign
   keys with the referenced table and column, for MySQL, PostgreSQL and SQLite.
   Today Cortex only has the yes/no `hasForeignKey()`.
3. **Consistency check in Atomic.** In `RelationVerifier` (first instantiation and
   `lint()`), compare each relation with the real keys:
   - `belongs_to_one`: if the column has a foreign key, it must point at the
     related model's table and key, otherwise throw naming model and field.
   - inverse `has_many` / `has_one`: covered by the other side's check.
   - pivot `has_many`: foreign keys on the pivot table must point at both tables.
   - a missing foreign key is not an error; `lint()` reports it.

## Gotcha

Cortex creates the default `id` as INT. A `DT_BIGINT` column cannot reference it
on MySQL/MariaDB (errno 150); the column type must match the referenced key.
