# TODO: replace validation error codes with typed errors

Status: todo, not scheduled. Written 2026-11-21.

## Problem

Model validation reports failures as numeric code strings, and the lookup that
produces them is broken.

`ValidatorModelTrait::validate_model()` builds a constant name from the model's
short class name, the field and the rule suffix, for example
`ORDER_ITEMS_REQUIRED`, and reads it from `Engine\Atomic\Codes\Code`. That class
only defines generic HTTP and OAuth codes. Applications define their field codes
in their own class (Atomic app: `App\Codes\Code`), which the framework never
reads. So the lookup always misses, falls back to `Code::SERVER_ERROR`, and
every model validation failure reaches the user as "server error".

The design is wrong even if the lookup worked:

- Names are derived by convention (class + field + rule). A typo or rename
  silently changes behaviour instead of failing.
- The fallback to `SERVER_ERROR` hides missing definitions.
- Numbers add nothing. Applications end up with a round trip, name → number →
  name: the Atomic app keeps about 156 constants split over traits by number
  range, plus a hand-written `match` that maps each number back to an i18n key.
  122 of those constants are never used.
- The error carries no structure (field, rule, arguments), so clients cannot
  highlight the failing input.

## Target design

1. **Typed result.** The validator returns a `ValidationError` value object:
   model class, field, `Rule` enum case, and rule arguments (`min`, `max`, ...).
   No code strings, no translation inside the validator.
2. **Generic rule messages.** The framework ships one message per rule, e.g.
   `validation.rule.str_min` → "{field} must be at least {min} characters".
   The field label comes from the model's i18n group.
3. **Explicit overrides.** Custom wording is declared in `domain_schema()`,
   e.g. `->required()->message('orders.form.items_required')`. Nothing is
   derived from names.
4. **No guessing.** A missing message key or label throws a `LogicException`
   naming the model, field and rule. No fallback to `SERVER_ERROR`.
5. **Response shape.** JSON errors expose `field`, `rule` and `message`.
6. **Remove the code lookup.** Drop the convention-based constant lookup from
   `ValidatorModelTrait`. `Engine\Atomic\Codes\Code` keeps only codes that
   callers send directly (HTTP, nonce, OAuth), or is replaced by direct
   `I18n::t()` keys at call sites.

## App follow-up (Atomic)

After the framework change, delete the field-code traits and the
`translation_key()` match in `App\Codes\Code`. Switch controllers from
`Code::message(...$model->get_last_err_data())` to the new error object.

## Related

- `domain_schema()` relations work (branch `feat/domain-schema-relations`)
  touches the same builder; land that first and add `->message()` on top.
