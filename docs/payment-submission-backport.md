# PHOS payment submission safety backport

Source: Rentnexis UAT `ed5c4db6b603cadfe6148158706f7e5cca2eea37`.
Target baseline: PHOS UAT `4171aa0c3e058a9190af05a48180051e62c4fee2`.

This first increment adapts the durable payment-form token pattern without importing
Rentnexis delivery-collection models, SaaS provisioning or its complete financial
lock context.

## Behavior

Every interactive payment endpoint requires a UUID from the submitted form:
rental and sale Record Payment / Mark Paid, Add Payment, invoice payments,
invoice Mark Paid and bulk invoice Mark Paid. Bulk void retains its existing contract.
Desktop, mobile and list actions supply the hidden field without changing layout.

The organization/token claim and controller work share one transaction.
A replay is rejected even if the original receipt was later deleted. Distinct
forms can record equal instalments. Validation, permission and downstream failures
roll back both the claim and payment; the same form can be corrected and retried.
Cancelled invoices are rejected by the payment service before financial status
synchronization, including the automatic paid-sale entry point. Service calls
also check that the invoice belongs to the supplied organization.

PHOS interactive payment submissions lock their organization row before controller
balance reads. This deliberately serializes interactive payment forms within one
organization. It does not replace coordinated parent/invoice/stock locking across
all edit, import, renewal, deletion or background jobs. Those are separate increments.
Existing notifications and activity logging execute within the submission transaction.
No automatic transaction retry is added, avoiding accidental replay of side effects.

## Deployment

Apply `2026_10_27_000000_create_payment_submissions_table.php` before exposing
the updated forms/routes. Deploy templates and application files together, refresh
view/route caches and refresh pages opened before the release. There is no new
JavaScript, dependency or configuration flag. No existing receipt is rewritten.
Do not prune claims or delete the table while this guard is active: doing so
removes replay protection for historical form tokens.

Existing API clients posting to these interactive routes must supply a fresh UUID
in `payment_submission_token` per intended payment and reuse it for retries.
The token prevents duplicate recording; it is not a replacement for CSRF,
authorization, gateway verification or a bank transaction identifier.

Rollback requires a coordinated code/templates rollback. Retain the claims table
and its data; it is safe for the prior code to leave the additive table unused.
Never reset the transaction database to roll back this code change.

## Validation

Run:
```text
vendor/bin/phpunit --do-not-cache-result tests/Feature/Regression/PaymentSubmissionSafetyTest.php
vendor/bin/phpunit --do-not-cache-result tests/Feature/Regression/DeliveryBillingPermissionsRegressionTest.php tests/Feature/Regression/InvoiceLinkageRegressionTest.php tests/Feature/Regression/TimelineActivityRegressionTest.php
vendor/bin/pest tests/Feature tests/Unit
```

The focused suite covers all eight route entry points, duplicate and equal
instalment submissions, rollback after receipt creation, failed validation retries,
permission/organization boundaries, token case normalization, retained claims after
receipt deletion, bulk void compatibility and rendered hidden fields.

The first source snapshot was checked against all 654 tracked PHOS blobs.
Local tests use isolated application files, copied dependencies and in-memory SQLite.
A local runner loads the ordinary test environment directly because Windows PHP's
readability probe rejects files it can read; it does not alter application code or
test assertions. Record actual results in the PR. SQLite checks cannot establish
InnoDB contention behavior: rehearse competing form submissions against disposable
MariaDB before deployment. Do not target a live database with tests.

## Still to backport

Audited payment reversals; safe invoice deletion/void/restore and paid edits;
coordinated financial/stock locks and import replay protection; delivery collection
and deposit safeguards. This increment does not declare that wider batch complete.

## Recorded local results — 27 October 2026

- Focused payment-safety suite: **23 passed, 161 assertions**.
- Related suites plus focused checks: 72 tests, 490 assertions; one pickup-label
  failure reproduced on the unmodified baseline.
- Wider class-based regressions: proposed source 497 tests / 3,248 assertions,
  baseline 474 tests / 3,087 assertions. Both have the same 33 failures and five
  errors; the sets of failing test identities and exception types are identical.
  This is not a clean full-suite result, and the inherited failures remain open.
- The mixed Pest suite was not completed. MariaDB concurrency tests were not run
  because database sockets are unavailable in this session.
- PHP syntax and patch application checks passed. No hosted CI or deployment ran.
- GitHub publication was blocked because the write tool requires approval and
  this session's approval policy is never. Deliver the patch for review; do not
  treat this as a merged or deployed change.
