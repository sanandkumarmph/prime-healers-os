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

## Follow-up validation and confirmed UAT target

The public PHOS UAT URL is https://uat-admin.primehealers.com/.
The operator's read-only server inspection confirmed:
application root `/home/uat-admin.primehealers.com/public_html`,
checked-out baseline `4171aa0c3e058a9190af05a48180051e62c4fee2`,
connected database `uata_primehealersos`, PHP 8.2.30 and MariaDB 10.11.16.
The checked source fingerprints match the expected PHOS baseline; the new middleware
was absent before deployment. Do not use Rentnexis paths or its database.

After interactive approvals were enabled, a normal-permission mixed Pest run
produced a complete JUnit report: **581 tests, 3,663 assertions, 31 failures,
zero errors**. All 31 failing identities map to failures recorded on the earlier
unmodified PHOS baseline. The console formatter was interrupted after JUnit export
while printing the large failure diffs; this is not a green command-exit result.
The broader inherited UI/metric failures remain open.

The disposable MariaDB concurrency runner passed **five scenarios** using MariaDB
10.4.32: same-token serialization/rejection; competing overpayments; equal
instalments; rollback after receipt creation with a waiting same-token retry; and
independent organizations proceeding without a shared organization-row lock.
The test server was bound to loopback, used a fresh private data directory and
new random database, and was stopped afterward. UAT uses 10.11.16, so this is
InnoDB behavior validation on a different MariaDB minor release, not a claim that
the production-equivalent runtime was tested.

To repeat, initialize a new disposable MariaDB instance and pass a private JSON file
containing `dataDirectory` and `port`:
```text
node tests/Concurrency/PaymentSubmissionConcurrency.cjs /path/to/disposable-instance.json
```
On systems without the default Windows PHP location, set `PHOS_TEST_PHP` to the
PHP binary first. The runner expects a newly initialized local instance with the
default local root account and no password; it verifies the connected data directory
and refuses an existing generated database name before creating or migrating any
schema. Its output is synthetic test data only. Never point it at UAT.

The current evidence is recorded in
`docs/validation/payment-submission-concurrency-20261027.json`.
GitHub publication is now enabled. Server preflight, backup and a pinned deployment
remain separate operations; completing these tests does not deploy the PR.
