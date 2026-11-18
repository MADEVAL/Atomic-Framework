# Idea: model rules in the telemetry Database tab

Status: idea, not scheduled. Written 2026-11-18.

## Problem

The Database tab shows what the database enforces: column types, nullability,
defaults, indexes and foreign keys. Much of the real validation lives in model
code instead and runs before save or update (required, unique, enum, min/max,
email, regex, callbacks). Today there is no single place that shows which
checks happen in the model and which in the database, or where the two
disagree.

## What it would show

- Per column, the database definition next to the model rules for that field.
- Mismatch warnings, for example:
  - enum or length limit checked only in the model;
  - `unique` in the model with no unique index in the database (race-condition
    duplicates are possible);
  - field required by the model while the column allows NULL.
- Code-level relations (`belongs-to-one`, `has-many`, ...) drawn as dashed
  lines in the per-table diagram. The framework's own tables have no database
  foreign keys, so these are the only relations they have.
- Tables with no registered model.
- Registered entries that are broken (class missing, not a `Model`).

Callback rules and custom pre-save code can only be shown as "custom check";
what they check is not knowable without running them.

## Design: explicit registration, no guessing

No filesystem or class scanning. Models are listed explicitly, the same way
plugins are (plugins are never auto-discovered either):

- **App models:** a `models` key in `config/providers.php`, entries written as
  `User::class`.
- **Framework models:** listed by the framework itself (Session, MutexLock,
  Meta, Options, ...).
- **Plugin models:** a `models(): array` method on `Plugin`, empty by default.

`::class` references follow IDE refactors, and a class that no longer exists is
reported as an error in the tab, so a rename never fails silently.

Trade-off: a model nobody registered does not appear. The "tables with no
registered model" list makes that visible instead of hiding it.

## Data sources (already exist)

- `ModelDefinitionManager::collect($class)` returns the merged field rules for
  both definition styles (legacy `fieldConf` and `domain_schema()`).
- `DomainStrategy::table_of($class)` resolves the table name, prefix included.

Neither creates a model instance or reads row data. The tab stays read-only.

## Testing

- Fixture models in `tests/` with known rules and relations.
- Assert the reporter output exactly: rules per field, table mapping, each
  mismatch type, missing-class and not-a-model errors, tables with no model.
- Two models mapped to one table must both be listed.

## Open questions

- Should `models` live in `config/providers.php` or in its own config file?
- Should a CLI command (e.g. `models/check`) reuse the same reporter, so CI can
  fail on mismatches?
