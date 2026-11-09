# Monopay Idempotency Fix — Concurrency Race Report

- **Date:** 2026-10-16
- **Scope:** the staged Monopay idempotency fix (resumable pending payments, webhook duplicate/stale dedup, atomic fulfillment claim, pubkey refetch throttle)
- **Method:** the staged patch applied to a scratch git worktree, then three gate-synchronized multi-process (8-way) race probes against the real patched classes, on MySQL `atomic_test`
- **Status:** **2 races confirmed — both fixed (2026-11-06).** Parallel webhooks now elect one winner; create retries resume only by caller `idempotency_key`. Both races are covered by multi-process tests in `tests/Engine/Plugins/MonopayConcurrencyRaceTest.php`, and the plugin was verified live against monobank's test environment (success, decline, hold). See `docs/plugins/monopay.md` for the developer-facing behaviour.

## 1. Verdict summary

| # | Area | Probe | Result | Runs |
|---|------|-------|--------|------|
| 1 | `Payment::mark_fulfilled()` atomic claim | 8 gated processes claim the same payment | **PASS** — exactly 1 winner | 3 / 3 |
| 2 | Webhook duplicate dedup | 8 gated deliveries of the same status change | **FAIL** — 6–8 accepted, 6–8 history rows (expected 1) | 10 / 10 |
| 3 | Resume after a lost create response | 8 gated retries against one `invoice_pending_at` row | **FAIL** — 3–5 distinct local rows/references (expected 1) | 4 / 4 |

The staged stress test (`tests/Engine/Plugins/MonopayIdempotencyStressTest.php`) exercises every scenario **sequentially** and stays green: **23 tests, 119 assertions, OK**. Findings 2 and 3 below are invisible to it by construction.

## 2. How the probes work

- Scratch worktree: `.worktrees/idempotency-stress`, branch `test/monopay-idempotency-stress`, base commit `68a1223` + the staged patch applied via `git diff --cached --binary | git apply --index`.
- Probe sources: `.worktrees/idempotency-stress/tests/tmp/race/` (gitignored, throwaway): `common.php`, `probe_fulfillment_race.php`, `probe_webhook_transition_race.php`, `probe_resume_race.php`.
- Each probe spawns 8 real PHP processes; every child prints `READY`, then blocks on a gate file; the parent releases the gate only after all children are ready, so all of them enter the critical section within microseconds of each other.
- The probes call the real patched code (private methods via reflection where required). The resume probe uses the `FakeMonobankApi` from the staged stress test (network stubbed, real plugin logic).
- MySQL runs at its default isolation (REPEATABLE READ): gated children's plain `SELECT`s all read the pre-transition state, which is what makes the races deterministic.

## 3. Finding A (HIGH) — parallel duplicate webhooks are not deduplicated

### Observed

8 gated processes call the real `WebhookHandler::update_payment_from_webhook()` with the same success delivery, and log history exactly like `WebhookHandler::handle()` does. Across 10 runs: **6–8 of 8 deliveries were accepted**, producing **6–8 `payment_history` rows** for one logical event (expected: 1).

### Mechanism

`update_payment_from_webhook()` is check-then-act:

```php
$payment = $payment_model->get_by_uuid($reference);   // SELECT
$old_status = $payment->get('status');
$new_status = $data['status'] ?? $old_status;

if ($new_status === $old_status) { return null; }      // duplicate check
...
$payment->update_from_webhook($data);                  // UPDATE
Hook::instance()->do_action(MonopayHook::PAYMENT_UPDATED, ...);
Hook::instance()->do_action(MonopayHook::status($new_status), ...);
```

Under concurrency, all deliveries read `status = created` before any of them commits, so every delivery passes the duplicate check. Each accepted delivery then appends a history row and fires `monopay.payment.updated` plus the leaf `monopay.payment.status.success` hook.

The atomic fulfillment claim from the fix still works: `mark_fulfilled()` lets exactly one delivery fire `PAYMENT_VERIFIED` / `PAYMENT_SUCCESS` (probe 1). The leak is **history rows + update/leaf hooks**, once per accepted parallel delivery.

### Reachability

This is a production scenario, not a synthetic one:

- the acquiring docs state that delivery of status webhooks **is not guaranteed to be sequential** ("no guarantee that messages arrive one after another"; a `success` webhook can arrive before the `processing` one);
- `handle_success_payment()` makes a synchronous status-API call, so while the first delivery is still in flight, monobank's retry (up to 3 attempts until HTTP 200) can arrive and run in parallel.

### Fix direction

Elect a single transition winner with a conditional UPDATE — the same pattern as `mark_fulfilled()`:

```sql
UPDATE `atomic_payments` SET <fields>, status = :new
WHERE id = :id AND status = :old
```

Only the delivery whose UPDATE reports `affected = 1` may log history and fire hooks; the others treat the delivery as already handled. A unique index on (`payment_uuid`, `status`) is a possible backstop — verify it against the allowed-transition set before adding.

## 4. Finding B (HIGH) — concurrent retries break the "one local payment" promise

### Observed

The parent creates one payment whose create failed at transport level (row kept, `invoice_pending_at` set). 8 gated children then call `create_payment(4200.00, 'Race order')` — the documented retry path. Across 4 runs: **3–5 distinct payment rows and references** were created (expected: 1 row, 1 reference); all 8 children created an invoice.

### Mechanism

Two sub-races in the resume path:

1. **No claim on resume.** `Payment::find_resumable()` is a bare `SELECT`: N children can all find the same pending row and all proceed to `invoice/create`. Monobank creates N invoices; they share one reference (the staged report already acknowledges that "exactly one invoice on monobank's side" is not implementable without a server-side idempotency key), but the retries are not serialized either.
2. **Cleared marker cascades into new rows.** `Order::create()` performs its own `find_resumable()` call *after* the children were released. A child that arrives once an earlier retry has completed (invoice bound, `invoice_pending_at` cleared) finds nothing resumable and falls through to `Payment::create_preliminary()` — minting a **brand-new payment row and reference** for the same intent. In the instrumented runs, all 8 children had seen the pending row microseconds earlier; the run result (3–5 rows) proves that later children took the fresh-create path.

Net effect: after an ambiguous create failure, a double-click, two parallel workers, or a retry loop can produce **duplicate local payments and duplicate billable invoices**. The report's claim "local state never duplicates" holds only for strictly sequential retries.

### Fix direction

Claim the resume atomically:

```sql
UPDATE `atomic_payments` SET invoice_pending_at = NULL
WHERE id = :id AND invoice_id IS NULL AND invoice_pending_at IS NOT NULL
```

`affected = 1` owns the resume; losers must not create a new payment — they should poll briefly for the winner's `invoice_id` / `page_url`, or return a retryable "resume in progress" result. The structural answer for late arrivals after a completed retry is a caller-supplied idempotency key with a unique index (already listed as a follow-up in the stress report).

## 5. What passed

- **Fulfillment claim (3/3 runs):** 8 gated processes racing `mark_fulfilled()` → exactly 1 winner every run; a post-race claim returns false and `fulfilled_at` is never overwritten. The conditional `UPDATE ... WHERE fulfilled_at IS NULL` does its job under real concurrency.
- **Staged stress suite:** 23 tests, 119 assertions, OK.
- **Full suite in the worktree:** 2288 tests, 13912 assertions, 2 failures, 123 skipped. Both failures — `QueueDbDriverEdgeTest::test_monitor_adapter_loads_and_handles_active_jobs` and `QueueTelemetryDbTest::test_public_fetch_methods_report_db_job_states_and_pagination` — fail identically in the main repo, so they are pre-existing queue issues, unrelated to the Monopay changes.

## 6. Reproduction

```bash
# from the framework root
git worktree add .worktrees/idempotency-stress -b test/monopay-idempotency-stress
git diff --cached --binary > staged.patch
git -C .worktrees/idempotency-stress apply --index staged.patch
# mirror vendor/ and composer.lock into the worktree, then:

cd .worktrees/idempotency-stress
php tests/tmp/race/probe_fulfillment_race.php         # VERDICT=PASS (expected)
php tests/tmp/race/probe_webhook_transition_race.php  # VERDICT=RACE_DETECTED
php tests/tmp/race/probe_resume_race.php              # VERDICT=LOCAL_INVARIANT_VIOLATED
```

Requirements match the normal suite: MySQL (`atomic_test`) and the `openssl`/`pdo_mysql` extensions. The probes are throwaway (gitignored `tests/tmp/`); promote them into committed tests as part of the fixes.

## 7. Live-testing gap — we need a test API key to test this fully

The local probes prove the races exist **in our code**, but they cannot prove how monobank behaves in reality. The acquiring documentation (v2410) documents a **test environment**: it requires a test token from `https://api.monobank.ua/` (available to any monobank client, no terminal or acquiring-testing permission needed), and payments can be made with **any Luhn-valid fake card number**. *(Correction for the record: an earlier assumption in this effort that monobank offers no test mode was wrong — the test environment is documented in the acquiring API docs.)*

With a test API key/token we could additionally:

- prove there is **no server-side dedup**: two `invoice/create` calls with the same `merchantPaymInfo.reference` returning distinct `invoiceId`s;
- replay **Finding B** against the real API under real network latency (N parallel retries → count distinct real `invoiceId`s);
- validate the real pubkey / base64-x509 format against the plugin's `verify_signature()`;
- run a genuine **end-to-end webhook test** (requires a public tunnel and the skeleton app wired with the plugin): real signed webhooks, forcing monobank's documented up-to-3 retries by answering non-200, then re-checking Findings A and B.

Without a test token, the strongest claim we can make is "our logic is wrong in these two ways". With one, we can also state "and this is exactly what happens against monobank".

What no token can produce — and why the local adversarial probes remain mandatory — is the injection of the failure modes the fix exists for: lost responses, 5xx, forced key rotation, and deterministic overlapping retries.

## 8. Recommended next steps

1. Fix Finding A with the transition CAS in `update_payment_from_webhook()`; convert `probe_webhook_transition_race.php` into a committed regression test.
2. Fix Finding B with the resume claim in the `find_resumable()` → create path; convert `probe_resume_race.php` into a committed regression test.
3. Keep the idempotency-key follow-up (caller-supplied intent key + unique index) on the roadmap — it is the only mechanism that also covers duplicate submits *before* any failure occurs.
4. Run the live test-environment checks (Section 7) as soon as a test token is available.
