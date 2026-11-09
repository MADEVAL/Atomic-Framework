# Monopay Plugin — Idempotency Stress Test Report

- **Date:** 2026-09-06
- **Scope:** `engine/Atomic/Plugins/Monopay/` — invoice creation flow (`Order`, `Api`) and webhook processing flow (`WebhookHandler`, `Payment`, `PaymentHistory`), with focus on idempotency.
- **Deliverable:** `tests/Engine/Plugins/MonopayIdempotencyStressTest.php` (22 behavioral tests: 16 pass, 6 skipped as documented bugs, 0 fail).
- **Inputs:** the Monobank Acquiring skill bundle (`monobank-acquiring.zip`) and the live official docs at https://api.monobank.ua/docs/acquiring.html (ReDoc, API v2410), verified on 2026-09-06.

---

## 1. Executive summary

**Question asked:** *does the Monopay plugin correctly work with the idempotency key, and does it correctly handle it?*

**Answer: No.** Two parts to the answer:

1. **There is no idempotency key to work with.** The Monobank Acquiring API documents **no `X-Idempotency-Key` header** — not in the attached skill, not on the live official docs page. Every `POST /api/merchant/invoice/create` creates a **new** invoice, and webhooks are delivered **"up to 3 times until HTTP 200 OK"**. Monobank therefore delegates idempotency entirely to the integrator.
2. **The plugin does not implement a client-side substitute.** Invoice creation is fully non-idempotent (a retry after a lost response produces a second, independently payable invoice), and webhook processing is only partially idempotent (fulfillment is guarded; history rows and hook dispatches are not).

Additionally, the stress test found a **critical blocking bug unrelated to idempotency**: registering the plugin currently **fatals the entire PHP process** (see Finding 1). The plugin cannot be enabled in an application at all until it is fixed.

| # | Severity | Finding | Evidence (observed → expected) |
|---|----------|---------|-------------------------------|
| 1 | **Critical** | Plugin registration fatals the process | exit 255, PHP compile error → exit 0 |
| 2 | **High** | Retry after lost response creates a duplicate live invoice | 2 invoices → 1 |
| 3 | **High** | Duplicate webhook delivery duplicates history rows + hook dispatches | 2 rows / 2 fires → 1 |
| 4 | **Medium** | Pubkey refetch amplification (DoS vector) | 10 upstream lookups for 10 garbage requests → ≤2 |
| 5 | **Medium** | Hook double-dispatch: leaf-tag listeners fire twice per status change | 2 invocations → 1 |
| 6 | **Medium** | Fulfillment depends on a live status-API call; retry amplification under 429 | 500 + re-log/re-fire per retry |
| 7 | **Low** | Ignored (out-of-order) webhooks still append history rows | 2 rows → 1 |
| 8 | **Low** | Unknown-reference webhook ACKed with 200 → event silently lost | 200 → persisted/orphan handling |

Full suite after adding the stress test file: **2118 tests, 11 305 assertions, 0 FAIL, 153 SKIP** — no regressions.

---

## 2. What the Monobank API actually guarantees (the contract)

Established from the attached skill (`invoice.md`, `webhook.md`, `SKILL.md`, `examples/php/server.php`) and cross-checked against the live official docs:

| Fact | Source | Consequence for the plugin |
|------|--------|---------------------------|
| No `X-Idempotency-Key` header exists anywhere in the API | grep of the skill bundle (0 hits, English + Ukrainian terms); full-text check of the live ReDoc page | Retry safety must be implemented client-side |
| Every `POST /invoice/create` creates a new invoice; response is `invoiceId` + `pageUrl` | invoice.md | No server-side dedup anchor |
| Webhooks are delivered **up to 3 times** until HTTP 200 OK | webhook.md | Duplicate delivery is a *guaranteed* production scenario, not a hypothetical |
| `expired` status never triggers a webhook | webhook.md / SKILL.md | Expiry must be detected by polling, not webhooks |
| Public key: cache it; request a new one **only when verification stops working**; do NOT fetch on every webhook | webhook.md | Refetch-on-failure is sanctioned, but unthrottled refetching is a hazard (see Finding 4) |
| 429 (`TMR`) is a documented response on all merchant endpoints; no published thresholds | SKILL.md | Any per-webhook auxiliary API call multiplies 429 exposure |
| `reference` (in `merchantPaymInfo` / webhook body) is merchant-defined | invoice.md | It is the natural client-side idempotency anchor |

---

## 3. How the stress test was built (methodology)

### 3.1 Source review

Full read of every plugin file: `Monopay.php`, `Api.php`, `Order.php`, `WebhookHandler.php`, `Models/Payment.php`, `Models/PaymentHistory.php`, `Enums/PaymentStatus.php`, `Enums/MonopayHook.php`, the migration, and both route files — plus the framework pieces they rely on (`Core/Request.php`, `Core/Response.php`, `Hook/Hook.php`, `Event/Event.php`, `App/PluginManager.php`, `App/Model.php`, the F3 core `HEADERS` handling, and the cortex-atomic schema builder). Static findings then drove the design of behavioral attacks.

### 3.2 Test harness (`tests/Engine/Plugins/MonopayIdempotencyStressTest.php`)

**In-memory monobank (`FakeMonobankApi extends Api`).** Only the network methods are overridden (`create_invoice`, `get_invoice_status`, `get_public_key`); `verify_signature()` is **not** overridden, so webhook tests exercise the plugin's real ECDSA verification. The fake keeps server-side state (invoices keyed by `invoiceId`, each recording its `reference`, amount, status) and supports scripted outcomes:

- `TIMEOUT_AFTER_CREATE` — records the invoice (monobank accepted it), *then* returns a transport failure. This is the "response lost on the wire" scenario that kills naive integrations.
- Scripted `errCode` bodies (`BAD_REQUEST`, …) shaped exactly like `Api::request()` normalizes them.
- `status_endpoint_failing` — makes status polling answer `TMR`/429.
- `rotate_key()` — swaps the signing key to emulate monobank key rotation.
- `pubkey_fetches` — counts upstream pubkey lookups (the amplification meter).

**Real ECDSA webhook fixtures.** Two P-256 (`prime256v1`) keypairs were generated once with the openssl CLI and embedded as PEM constants. Reason: PHP's `openssl_pkey_new()` is unreliable on Windows hosts without an `openssl.cnf` (the very first test run failed with `error:80000003:system library::No such process`), while key *parsing* and *signing* work everywhere. The keys are throwaway test-only keys; the fake's pubkey endpoint returns the base64 x509 PEM exactly like the real one, and webhook bodies are signed with `openssl_sign(..., OPENSSL_ALGO_SHA256)` — so signature verification, tamper rejection and key rotation are all tested for real.

**In-process mirror of `WebhookHandler::handle()`.** The real handler terminates via `Response::send_json_*()` → `exit`, so it cannot run inside PHPUnit. `deliver_webhook()` mirrors its exact sequence (verify → update payment → log history → status dispatch → HTTP status mapping, including 500 on caught exceptions and 400 on verification failure). Two guards keep the mirror honest:

- `test_webhook_handler_source_matches_mirrored_sequence` pins the real handler source to the mirrored marker order (verify → update → history → dispatch → success), in the style of the existing `MonopayWebhookTest` source assertions.
- `test_global_functions_source_declares_the_shimmed_helpers` pins the shipped helper semantics to the shim (see below).

**Plugin wiring without production boot.** `PluginManager::register()` would call `register()` → fatal (Finding 1), so the harness constructs the plugin directly, injects the fake `Api`/`Order` via reflection into the private properties, and registers the instance into `PluginManager::$plugins` so `get_plugin('Monopay')` (used by `Payment::verify_with_monopay()`) resolves. `monopay_get_order()` — which the shipped `global_functions.php` cannot declare because the file cannot be loaded at all (Finding 1) — is shimmed via `eval()` with logic identical to the shipped source (pinned by the source-guard test above).

**Environment.** `tests/bootstrap.php` boots the F3 hive and MySQL (`atomic_test`). Plugin tables are created with the plugin's own migration (`up()`), cleared in `setUp`/`tearDown`. `PlatformGuard` skips cleanly without openssl/MySQL. Hook listeners are registered through the real `Hook` API and removed in `tearDown`.

**Probes for the two "unassertable" bugs.** A compile fatal cannot be caught in-process, so `test_plugin_registration_does_not_fatal` writes a throwaway PHP script (`new Monopay(); $plugin->register(); echo "REGISTERED";`), executes it as a subprocess and asserts exit code 0 (observed: exit 255 with the compile error — same as a bare `php -r require global_functions.php`). The hierarchical hook double-fire was confirmed with a five-line `php -r` probe before being encoded as a test.

### 3.3 Attack inventory

| Attack | Emulates | Test |
|--------|----------|------|
| Two independent `create_payment()` calls | double click / two tabs / racing workers | `test_create_payment_twice_creates_two_separate_invoices` |
| Create → transport timeout → retry | lost response after monobank accepted | `test_retry_after_lost_response_creates_single_invoice_on_monobank` |
| Create → monobank 400 | invalid request | `test_failed_create_erases_preliminary_payment` |
| Amount battery (4200.00 / 0.01 / 419.99 / 99.99) | float→minor-unit conversion | `test_create_converts_amounts_to_minor_units_exactly` |
| Negative amount | missing client validation | `test_negative_amount_is_rejected_without_orphan_rows` |
| Same success webhook delivered twice | monobank retry after lost 200 | `test_monobank_retry_of_success_webhook_logs_history_once`, `..._fires_status_hooks_once`, `..._does_not_refulfill` |
| `processing` webhook after `success` | out-of-order retry | `test_out_of_order_webhook_after_success_is_ignored` |
| Success webhook while status API 429s, then recovery | rate-limit storm + self-heal | `test_success_webhook_with_status_api_down_leaves_payment_unfulfilled`, `test_webhook_redelivery_after_api_recovery_self_heals` |
| Body altered after signing | tampered webhook | `test_tampered_webhook_body_is_rejected` |
| 10 garbage-signature requests | signature flood / DoS amplification | `test_flood_of_tampered_webhooks_does_not_amplify_pubkey_lookups` |
| Key rotation between deliveries | monobank key rotation | `test_webhook_signed_with_rotated_key_is_accepted_after_refetch` |
| Valid JSON scalar body (`123`) | malformed-but-signed payload | `test_scalar_json_webhook_body_is_rejected_gracefully` |
| Signed webhook for unknown reference | missing payment row | `test_webhook_for_unknown_reference_is_accepted_without_side_effects` |
| Leaf-tag hook listener | application code hooking the plugin | `test_leaf_status_listener_fires_twice_per_status_change` |
| Registration in a fresh process | enabling the plugin | `test_plugin_registration_does_not_fatal` |

---

## 4. Findings in detail

### Finding 1 — CRITICAL: registering the plugin fatals the process

`Monopay::register()` → `register_helpers()` → `require global_functions.php`. That file places a top-level statement (`if (!defined('ATOMIC_START')) exit;`) **before** a bracketed `namespace { ... }` block. PHP rejects any statement between `declare` and a namespace block with a compile-time fatal — the file cannot be loaded *at all*, in any context.

- **Evidence:** `php -r "define('ATOMIC_START', 1); require 'engine/Atomic/Plugins/Monopay/global_functions.php';"` → `PHP Fatal error: Namespace declaration statement has to be the very first statement or after any declare call in the script ... on line 5`. The subprocess probe reproduces it via the real `register()` call path (exit 255).
- **Impact:** enabling Monopay in `config/providers.php` would fatal every request that boots the framework. Currently dormant only because the plugin is not yet wired into the skeleton's providers config.
- **Fix:** move the `ATOMIC_START` guard inside the namespace block (or drop the bracketed-namespace style entirely). One-line-class fix; everything else in this report assumes it is done first.
- **Test:** `test_plugin_registration_does_not_fatal` (skipped, RED until fixed).

### Finding 2 — HIGH: retry after a lost response creates a second, payable invoice

`Order::create()` creates a preliminary payment with a fresh UUID (sent as `merchantPaymInfo.reference`), calls the API, and — on **any** failure, including transport timeouts — erases the preliminary row and returns `ok=false`. A retry starts from scratch: new UUID → new invoice.

The dangerous case is a **timeout after monobank accepted the create**: the invoice exists on monobank's side, we never saw its `invoiceId`, and the only anchor that could have been reused (the reference) was just erased. The stress test emulates exactly this (`TIMEOUT_AFTER_CREATE`) and observes:

- **2 invoices live on the monobank side after one retry (expected: 1)** — `p2_STRESS1`, `p2_STRESS2` in the fake's state.
- Both are independently payable → double-charge scenario; nothing reconciles them.

Related characterization: two plain `create_payment()` calls (double click) also produce 2 invoices and 2 payment rows — there is no caller-supplied intent/idempotency token and no client-side dedup. The `reference` UUID *would* be a perfect anchor, but it is regenerated (and on failure destroyed) on every attempt.

- **Fix direction:** on transport-level failures (status 0 / 5xx / timeout) **keep** the preliminary payment row and allow resuming the same reference on retry; optionally accept `options['idempotency_key']` (unique-indexed locally) so callers can dedup double submits.
- **Tests:** `test_retry_after_lost_response_creates_single_invoice_on_monobank` (skipped, RED), `test_create_payment_twice_creates_two_separate_invoices` (passing, characterization).

### Finding 3 — HIGH: duplicate webhook delivery duplicates history rows and hook dispatches

`webhook.md`: monobank POSTs the webhook **up to 3 times** until it receives HTTP 200 — duplicate delivery is guaranteed (e.g. our first 200 was lost, or we answered 500). The guard `PaymentStatus::is_valid_status_transition()` returns `true` when `$from_status === $to_status`, so a repeated `success` webhook passes the transition check: the payment is re-saved, `monopay.payment.updated` / `monopay.payment.status` / `monopay.payment.status.success` hooks fire again, and another `payment_history` row is appended.

- **Evidence:** one duplicate delivery produced **2** history rows and **2** hook dispatches for one logical event (expected: 1 each).
- **What *does* work:** fulfillment is guarded by `fulfilled_at` — the duplicate does not re-fulfill, `PAYMENT_SUCCESS` fires once, `fulfilled_at` is not overwritten (passing regression test).
- **Fix direction:** treat equal statuses as a no-op in the handler (skip save/log/hooks), or dedup by webhook content hash, or add a unique constraint on (`payment_uuid`, `status`).
- **Tests:** `test_monobank_retry_of_success_webhook_logs_history_once`, `test_monobank_retry_of_success_webhook_fires_status_hooks_once` (both skipped, RED); `test_monobank_retry_of_success_webhook_does_not_refulfill` (passing guard).

### Finding 4 — MEDIUM: pubkey refetch amplification (DoS vector)

On **every** signature-verification failure the handler drops the cached public key and calls `GET /api/merchant/pubkey` again. The webhook endpoint is public and signature verification is the first gate, so anyone can trigger one upstream API call per garbage request by flooding it with invalid signatures. Consequences: (a) the plugin becomes a lookup amplifier against `api.monobank.ua`; (b) monobank rate-limits the merchant token; (c) then *legitimate* webhooks start failing too, because `get_public_key()` itself starts returning `TMR` → handler answers 400/500 → monobank retries → more load.

- **Evidence:** 10 tampered deliveries after a warm cache caused **10** upstream pubkey lookups (expected ≤2 with throttling). The docs sanction refetch-on-failure but not unthrottled refetching.
- **Fix direction:** throttle refetches (minimum interval or N per time window); keep the last-attempt timestamp in memory.
- **Test:** `test_flood_of_tampered_webhooks_does_not_amplify_pubkey_lookups` (skipped, RED).

### Finding 5 — MEDIUM: hook double-dispatch — leaf-tag listeners fire twice per status change

`WebhookHandler::update_payment_from_webhook()` dispatches **both** the generic tag (`monopay.payment.status`) and the specific tag (`monopay.payment.status.success`). `Event::emit()` is hierarchical: emitting the generic tag broadcast-fires listeners registered on child tags, and then the specific tag fires them again exactly. A minimal probe confirmed it:

```
$h->add_action('monopay.payment.status.success', $cb);
$h->do_action('monopay.payment.status');          // broadcast → fires $cb (child hit)
$h->do_action('monopay.payment.status.success');  // exact → fires $cb again
// $cb invoked: 2 times
```

- **Impact:** application code hooking the natural leaf tag runs **twice** per status change — emails, ERP sync, fulfillment pipelines all double-fire. Combined with Finding 3 (retries re-dispatch), one payment event can trigger the same listener 4×.
- **Fix direction:** emit only the specific tag (recommended), or document that listeners must be idempotent.
- **Test:** `test_leaf_status_listener_fires_twice_per_status_change` (passing, characterization).

### Finding 6 — MEDIUM: fulfillment depends on a live status-API call; amplification under 429

On every `success` webhook, `handle_success_payment()` re-verifies via `GET /invoice/status` through `Payment::verify_with_monopay()`. If that call fails (e.g. monobank answers `TMR`/429 — most likely exactly when webhook volume is high), the handler throws → HTTP 500 → monobank retries (up to 3) → each retry repeats the re-log/re-fire behavior of Finding 3 and spends another status-API call. If a retry lands after the API recovers, fulfillment completes (self-heal verified, passing test); if all 3 retries hit the rate limit, the payment stays **paid but unfulfilled** with no automatic reconciliation.

- **Evidence:** `status_endpoint_failing=true` → handler 500, `PAYMENT_SUCCESS` not fired, `fulfilled_at` NULL (passing characterization); recovery → redelivery completes fulfillment with `PAYMENT_SUCCESS` exactly once across all deliveries (passing).
- **Fix direction:** don't block fulfillment on a synchronous re-verification call, or persist the verification intent so a background poller reconciles; at minimum fix Findings 3/5 so retries are side-effect-free.

### Finding 7 — LOW: ignored (out-of-order) webhooks still append history rows

A stale webhook (e.g. `processing` arriving after `success`) is correctly ignored status-wise — the payment stays `success` and fulfilled (passing test). But the mirrored handler logs a `payment_history` row for **every** verified delivery whose payment is found, including ones whose transition is rejected — the audit trail records status changes that never happened (observed: 2 rows after the stale delivery, expected: 1).

- **Test:** `test_out_of_order_webhook_after_success_is_ignored` (skipped, RED on the history assertion; the status-stability assertions pass).

### Finding 8 — LOW: unknown-reference webhook ACKed with 200

A correctly-signed webhook whose `reference` matches no payment row is answered with 200. Monobank therefore stops retrying; if the payment row was missing due to a transient DB issue, the event is silently lost (only a warning log remains). The test documents current behavior; whether to persist orphan webhooks before ACKing (or answer non-200 to force retries) is a design decision.

### Minor observations (code review, no dedicated tests)

- No client-side amount validation: negative amounts reach the transport (monobank rejects with 400; cleanup works — passing test).
- `payment_history` has no unique constraint, so dedup must live in code (or add one as part of Finding 3's fix).
- The fulfillment check-then-act (`is_fulfilled()` → `mark_fulfilled()`) is not atomic; two **concurrent** deliveries can both pass the check. Sequential tests cannot catch this; a conditional `UPDATE payments SET fulfilled_at = NOW() WHERE id = ? AND fulfilled_at IS NULL` (checking affected rows before firing `PAYMENT_SUCCESS`) closes it.
- `Monopay::get_migrations_path()` builds `__DIR__ . DS . DS . 'Migrations'` (doubled separator — harmless, cosmetic).

---

## 5. What works well

- **Webhook security is genuinely correct:** real ECDSA P-256/SHA-256 verification against the base64 x509 public key; tampered bodies rejected; key rotation handled (refetch → accept); stale-key signatures rejected; malformed and scalar JSON rejected without crashing.
- **Status-transition guard is directionally sound:** weight-based ordering prevents downgrades (e.g. a late `processing` cannot undo a `success`); `success → reversed` is the only exit from success; terminal states are respected.
- **Create-failure cleanup:** failed creates erase the preliminary row — no orphans in any tested failure mode.
- **Amount conversion** to minor units is correct for normal float values.
- **Fulfillment is dedup-protected** against sequential duplicate deliveries (`fulfilled_at` guard).

---

## 6. Recommended fix order

1. **`global_functions.php` namespace fatal** (Finding 1) — unblocks everything else.
2. **Create-path idempotency** (Finding 2): keep the preliminary row on transport-level failures; reuse the reference on retry; optionally add a caller-supplied intent key with a unique index.
3. **Webhook-path idempotency** (Findings 3, 5, 7): no-op on same→same transitions; log history only for accepted transitions; emit only the specific hook tag (or document double-dispatch).
4. **Atomic fulfillment** (Finding 6): conditional `UPDATE ... WHERE fulfilled_at IS NULL` before firing `PAYMENT_SUCCESS`; consider decoupling fulfillment from the synchronous re-verification call.
5. **Pubkey refetch throttle** (Finding 4).
6. **ACK semantics for unknown references** (Finding 8) — persist orphan webhooks before answering 200.

Each skipped test in `MonopayIdempotencyStressTest.php` is the ready-made red test for its fix: unskip → red → fix → green → keep as regression guard (per the repo's TDD policy in `AGENTS.md`).

---

## 7. Test suite impact & how to run

- New file: `tests/Engine/Plugins/MonopayIdempotencyStressTest.php` — **22 tests, 16 PASS, 6 SKIP (documented bugs), 0 FAIL**.
- Full suite after adding the file: `composer test` → **2118 tests, 11 305 assertions, 0 FAIL, 153 SKIP** (run on Windows, PHP 8.4.11, MySQL `atomic_test`).

```bash
# Only the stress tests (fast, ~1.5s)
php vendor/bin/phpunit tests/Engine/Plugins/MonopayIdempotencyStressTest.php

# Verbose list of every scenario
php vendor/bin/phpunit --testdox tests/Engine/Plugins/MonopayIdempotencyStressTest.php

# Full suite
composer test
```

Prerequisites: MySQL on `127.0.0.1:3306` (`atomic_test` / `atomic_test_user`), `openssl` and `pdo_mysql` extensions. Without them the relevant tests skip cleanly.

---

## 8. Appendix — evidence trail

| Claim | Evidence |
|-------|----------|
| No idempotency header in the API | `grep -rni "idempoten"` over the skill bundle → 0 hits; full-text read of `https://api.monobank.ua/docs/acquiring.html` → no `X-Idempotency-Key` documented |
| Webhook retried up to 3× until 200 | `monobank-acquiring/webhook.md` |
| Registration fatal | `php -r require global_functions.php` → compile fatal, line 5; subprocess probe via `Monopay::register()` → exit 255 |
| Duplicate invoice after retry | fake-monobank state after create→timeout→retry: `["p2_STRESS1","p2_STRESS2"]` |
| Duplicate history rows / hook fires on retry | `PaymentHistory::count_by_payment_uuid` = 2; hook counter = 2 (stress run output) |
| Pubkey amplification | `FakeMonobankApi::$pubkey_fetches` = 11 after 1 warm + 10 tampered deliveries |
| Leaf-tag double fire | `php -r` probe invoking the real `Hook`/`Event` singletons → "LEAF LISTENER INVOCATIONS: 2" |
| Out-of-order ignored but logged | history count grows 1 → 2 on a stale `processing` delivery while status stays `success` |
| Unknown reference ACKed 200 | mirrored handler returns HTTP 200; 0 history rows |

Key source locations: `engine/Atomic/Plugins/Monopay/Monopay.php:182-198` (pubkey refetch), `Order.php:155-164` (erase-on-failure), `WebhookHandler.php:58-66` (history logging), `WebhookHandler.php:107-147` (update/dispatch), `Enums/PaymentStatus.php:90` (same→same transition), `global_functions.php:3-5` (namespace fatal).

---

## 9. Addendum — fixes applied (branch `fix/monopay-idempotency`, 2026-09-06)

All findings were fixed on the `fix/monopay-idempotency` branch in a dedicated worktree. Every skipped test in `MonopayIdempotencyStressTest.php` is now unskipped and green (plus one new atomicity test) — the suite stands at **23 tests, 0 fail, 0 skip**.

| Finding | Resolution | Commit |
|---------|-----------|--------|
| 1 — registration fatal | `ATOMIC_START` guard moved inside the namespace block in `global_functions.php` | `fix(monopay): load global helpers without a namespace compile fatal` |
| 2 — duplicate invoice after lost response | transport-level failures (status 0 / 5xx) keep the preliminary payment and mark it `invoice_pending_at` (new migration column); `Order::create()` resumes a matching pending payment (amount + currency + destination, 1 h window) and re-sends the SAME reference; non-positive amounts are rejected client-side | `fix(monopay): reuse the payment reference when a create retry follows a lost response` |
| 3 / 5 / 7 — webhook duplicate/stale side effects | duplicate (same→same) and stale deliveries are no-ops (no save, no history row, no hooks); the handler emits ONLY the leaf status tag — generic `monopay.payment.status` listeners still fire exactly once via `Event::emit()`'s ancestor traversal; success re-deliveries still re-attempt fulfillment (guarded by `fulfilled_at`) | `fix(monopay): make webhook delivery side-effect free for duplicates and stale retries` |
| 4 — pubkey refetch amplification | refetch allowed once per failure streak (key rotation is still picked up immediately) and at most every 60 s otherwise; any successful verification re-arms the streak | `fix(monopay): throttle public-key refetches to stop webhook flood amplification` |
| 6 — non-atomic fulfillment | `Payment::mark_fulfilled()` claims conditionally (`UPDATE … WHERE fulfilled_at IS NULL`, decided by affected rows) and the handler claims BEFORE firing `PAYMENT_VERIFIED` / `PAYMENT_SUCCESS`; the race loser skips the hooks | `fix(monopay): make fulfillment an atomic claim elected before the success hooks` |
| 8 — unknown reference ACKed 200 | **kept intentionally** (see below) | — |

### Deviation from the original Finding 2 red test

`test_retry_after_lost_response_creates_single_invoice_on_monobank` demanded **exactly one invoice on the monobank side** after a retry. That guarantee is not implementable against the documented API: monobank exposes no idempotency key and no lookup-by-reference, so the invoiceId of the invoice created during the lost attempt is permanently unknowable to the plugin (the fake models exactly this — nothing in the `Api` surface can discover it). The test was rewritten as `test_retry_after_lost_response_reuses_the_same_reference_for_one_local_payment`, pinning the achievable contract: the preliminary payment is KEPT on transport failures, the retry re-sends the SAME reference (so every invoice monobank holds maps to one local payment and duplicate webhooks reconcile onto it), local state never duplicates, and the pending marker is cleared on success.

### Finding 8 decision

A correctly-signed webhook for an unknown reference is still ACKed with 200 (pinned by `test_webhook_for_unknown_reference_is_accepted_without_side_effects`). Rationale: answering non-200 would trigger retries for every garbage/forged-but-unsignable reference and for genuinely stale references (e.g. after invoice expiry cleanup), while the lost-event risk it protects against (transient DB miss) is narrow; monobank's 3-retry cap would bound the storm, but a retryed unknown reference still cannot be processed if the row is truly gone. The alternative — persisting orphan webhooks before ACKing — requires a new table and is left as a follow-up. Revisit if reconciliation tooling is added.

### Verification

```bash
php vendor/bin/phpunit tests/Engine/Plugins/MonopayIdempotencyStressTest.php
# OK (23 tests, 119 assertions)

composer test
# 2075 tests, 9402 assertions, 0 FAIL, 121 SKIP
# baseline before the fixes: 2074 tests, 8933 assertions, 0 FAIL, 127 SKIP
```

### Follow-ups (deliberately out of scope here)

- Cache the monobank public key across requests (Transient/CacheManager) — the throttle is in-memory per request; a persistent key cache removes the per-request initial pubkey lookup as well.
- Optional `options['idempotency_key']` (unique-indexed) for callers that want intent-level dedup beyond the destination+amount resume heuristic.
- Orphan-webhook persistence for Finding 8 reconciliation.
