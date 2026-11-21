## Model ##

`Engine\Atomic\App\Model` is Atomic's base model on top of F3 Cortex.

```php
use Engine\Atomic\App\Model;

final class Post extends Model
{
    protected $table = 'posts';

    protected $fieldConf = [
        'title' => [
            'type' => 'VARCHAR256',
            'nullable' => false,
        ],
    ];
}
```

### What the base model adds

- automatic DB prefixing from `DB_CONFIG.ATOMIC_DB_PREFIX`
- validation on `save()` through `Engine\Atomic\Validator\Validator`
- nullable support in validation when values are already `null`
- optional `before_validate()` hook before validation

### Validation errors

```php
$post->title = '';

if (!$post->save()) {
    [$code, $vars] = $post->get_last_err_data();
}
```

Helpers:

- `get_last_validation_error()` — `?ValidationError` (`model`, `field`, `rule`, `vars`); `null` when the last save passed, or failed for a configuration reason (then `get_last_err_code()` is `Code::SERVER_ERROR`)
- `get_last_err_code()` — `null` when the last save passed validation (reset on every save)
- `get_last_err_vars()`
- `get_last_err_data()` — deprecated, use `get_last_validation_error()`

The framework resolves codes only against `Engine\Atomic\Codes\Code`, so app
field codes need the typed error:

```php
if (!$product->save()) {
    $error = $product->get_last_validation_error();   // Product, price, MIN, ['min' => 0]
    $code = $error?->code_in(\App\Codes\Code::class) ?? Code::SERVER_ERROR; // PRODUCT_PRICE_MIN or SERVER_ERROR
}
```

`vars` by failure:

- explicit `str_min`/`str_max`/`mb_min`/`mb_max`/`num_min`/`num_max` — `['min' => …]` or `['max' => …]` from the field's `min`/`max`
- column-type `MAX` (value too long for the column) — `['max' => …, 'unit' => 'chars'|'bytes']`, the limit the check enforced:
  - live `max_length` or `VARCHAR128`/`VARCHAR256`/`VARCHAR512` (128/255/512) — `chars`
  - live `max_bytes` or `TEXT`/`BLOB` (65535), `LONGTEXT` (4294967295) — `bytes`; multibyte text hits it sooner than the number suggests
- other failures (`REQUIRED`, `FORMAT`, `ENUM`, `INVALID`, `UNIQUE`) — `[]`

Use `{max}` and `{unit}` in the translated message, or pick wording per `unit`.

### Code messages (`CodeCatalog`)

`Engine\Atomic\Codes\CodeCatalog` wraps any class holding code constants
(constants from its traits count) and maps a value to its translation key by
convention, `errors.codes.` + lowercase constant name, so no hand-written
`match` is needed. The codes class stays plain constants; inject the catalog:

```php
$codes = new CodeCatalog(\App\Codes\Code::class);           // default: Engine\Atomic\Codes\Code
$codes = new CodeCatalog(Code::class, prefix: 'api.codes.'); // custom prefix
$codes = new CodeCatalog(Code::class, i18n: $i18n);          // custom I18n (default I18n::instance())
```

- `code_name($code)` — constant name, e.g. `'1571'` → `PRODUCT_PRICE_MIN`; `null` when unknown. Only string/int constants count; on duplicate values the first declared wins.
- `duplicate_codes()` — `[value => [NAMES...]]` for values shared by several constants; assert it is empty in an app test.
- `translation_key($code)` — `errors.codes.product_price_min`; unknown values give `errors.codes.server_error`.
- `message($code, $vars = [], $lang = null)` — `I18n::t()` of that key; `null` lang = current language.

`ValidationError::message(?CodeCatalog $catalog = null, ?string $lang = null)`
resolves `code_in($catalog->codes_class)` and translates it with the rule vars;
no catalog = framework `Code`.

```php
$codes = new CodeCatalog(Code::class);

if (!$product->save()) {
    $error = $product->get_last_validation_error();
    // object
    $error->field; $error->rule; $error->vars; $error->code_name();
    $code = $error->code_in(Code::class);
    $codes->translation_key($code);
    // message
    $error->message($codes);              // current language
    $error->message($codes, lang: 'ru');
}
$codes->message(Code::FORBIDDEN);
$codes->message('1571', ['min' => 0], lang: 'ru');
```

Validation failures are logged as warnings; rule configuration errors as errors.

### Scoped uniqueness

Legacy `'unique' => true, 'unique_by' => ['store']` and domain
`$schema->field()->unique_by(['store'])` both reject a value already used by
another row with the same `store`. This is a pre-save check only; add a
composite UNIQUE index in the migration. See `model-schema-split.md`.

### Bulk property update

```php
$post = new Post();
$post->update_property(['status = ?', 'draft'], 'status', 'published');
```

`update_property()` loads matching records and updates the selected field on each row.
