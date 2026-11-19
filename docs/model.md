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

- `get_last_err_code()` — `null` when the last save passed validation (reset on every save)
- `get_last_err_vars()`
- `get_last_err_data()`

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
