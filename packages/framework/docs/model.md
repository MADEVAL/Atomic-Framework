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

- `get_last_err_code()`
- `get_last_err_vars()`
- `get_last_err_data()`

### Bulk property update

```php
$post = new Post();
$post->update_property(['status = ?', 'draft'], 'status', 'published');
```

`update_property()` loads matching records and updates the selected field on each row.
