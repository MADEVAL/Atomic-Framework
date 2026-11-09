# Live column type families

`\DB\Cortex\Schema\ColumnType` lives in Cortex-Atomic (2.0.31+), where it is
tested against live MySQL/MariaDB, SQLite, SQL Server and PostgreSQL runners.
Atomic keeps the validator checks that consume its descriptors.

`ColumnType::describe($raw, $table, $field, ?string $driver = null)` returns
Cortex-compatible `type` and validator `column_kind` metadata. The optional fourth
argument preserves existing three-argument callers. `LiveSchemaSource` reads
`$db->schema()`, passes it through `ColumnType::withDeclaredTypes()`, then hands
`$db->driver()` to `SchemaStructure::from_columns()`.

| Raw spellings | Cortex token | Metadata / validation |
| --- | --- | --- |
| varchar, char, character varying, character, nvarchar, nchar, varchar2 with (n) | VARCHAR128/256/512, LONGTEXT | exact max_length in characters |
| unbounded strings, varchar(max), nvarchar(max), text, clob, ntext | TEXT | string; no invented capacity when driver omits it |
| tinyint, smallint/int2/smallserial, mediumint, int/integer/int4/serial, bigint/int8/bigserial | INT1/2/4/8 | integer_bits=8/16/24/32/64, unsigned; display widths ignored |
| tinyint(1), bit(1), bool, boolean, bit | BOOLEAN | boolean values only |
| decimal/numeric(p,s), float(p,s) | DOUBLE or FLOAT | precision, scale, unsigned; reject rounding and overflow |
| decimal/numeric without size outside MySQL | DOUBLE | numeric syntax; no fabricated precision |
| money, smallmoney | DOUBLE | decimal precision 19/10, scale 4, exact signed scaled_bounds |
| float, float(p), double, double precision, real, float4, float8 | FLOAT/DOUBLE | finite numeric range; float(p) uses 24-bit mantissa boundary |
| tinytext/text/mediumtext/longtext | TEXT/LONGTEXT | MySQL max_bytes=255/65535/16777215/4294967295 |
| blob variants, binary(n), varbinary(n), bytea, image, varbinary(max) | BLOB | max_bytes when declared; binary strings |
| date | DATE | date format |
| time, time with/without time zone | VARCHAR128 | fractional_precision, clock_time, timezone |
| datetime, datetime2, smalldatetime, datetimeoffset | DATETIME | fractional_precision, clock_time, timezone |
| timestamp, timestamp with/without time zone, timestamptz | TIMESTAMP | fractional_precision, clock_time, timezone |
| year | INT2 | 0 or 1901..2155 |
| json, jsonb | JSON | JSON text or encodable PHP value |
| uuid, uniqueidentifier | VARCHAR128 | string kind, max_length=36, uuid=true; canonical hexadecimal UUID, any version |
| enum(...), set(...) | string bucket | native enum_values/set_values parsed separately; quoted case preserved |

String buckets round up at 128, 256 and 512. Metadata-free legacy fieldConf
retains its historical validation and error codes, including VARCHAR256's
255-character limit. No new Cortex tokens are introduced. JSON/SERIALIZED casts
on unbounded text/blob validate the encoded representation without an invented
65535-byte cap.

MySQL/MariaDB unsigned and zerofill preserve unsigned ranges, including BIGINT
bounds compared as decimal strings. TIME is a signed duration up to 838:59:59;
other known drivers use a 24-hour clock. Zoned types require Z or a numeric offset.
SQL Server tinyint is unsigned; timestamp/rowversion is eight-byte binary, not a
date. Domain MySQL BIT(1) mapper writes bind as integers because F3 otherwise
binds the ASCII string '1'. Legacy model mappers are unchanged.

F3's `schema()` reads INFORMATION_SCHEMA.DATA_TYPE on PostgreSQL/SQL Server,
which drops lengths, precision and temporal precision (`NVARCHAR(64)` comes back
as `nvarchar`). `ColumnType::withDeclaredTypes()` re-reads CHARACTER_MAXIMUM_LENGTH,
NUMERIC_PRECISION/SCALE and DATETIME_PRECISION and rebuilds the full type
(`nvarchar(64)`, `decimal(10,2)`, `datetime2(3)`). MySQL/MariaDB and SQLite already
report full types and are not queried again. Money uses exact signed
64-bit/32-bit fixed-point endpoints at scale four. Server-specific date ranges,
timezone names, collation, charset and coercion are not reproduced.

SQLite uses affinity in documented order: INT -> signed 64-bit integer;
CHAR/CLOB/TEXT -> text; BLOB or empty -> binary; REAL/FLOA/DOUB -> double;
BOOL/BOOLEAN -> boolean; DATE, DATETIME, TIMESTAMP -> their date/time kinds (Cortex
creates these on SQLite); otherwise numeric. Declared lengths and precision do not
impose SQLite limits. This validates the intended affinity family rather than
reproducing SQLite's permissive storage of values of other classes.

Arrays (`integer[]`, `_int4`), spatial types, multi-bit bit strings, user-defined
and other unsupported types follow the existing unknown path outside SQLite:
column omitted from fieldConf, warning once per model/column, lint diagnostic.
Explicit storage-independent policies use DOMAIN_UNTYPED; type-dependent rules
throw. SQLite affinity is never guessed for other drivers.
