# Common Rentnexis features adapted for PHOS

Source reviewed: Rentnexis UAT `ed5c4db6b603cadfe6148158706f7e5cca2eea37`.
PHOS baseline: `f9e6de32620ca8ecaf7293e0ee15149cd8c78f22` (accepted UAT PR #7).

## Included batches

| Area | Result | Existing behavior retained |
| --- | --- | --- |
| Sales workspace | Enquiries, owners, follow-ups, activity history, guided quotations, review, revisions, expiring customer links, PDF and accepted quotation conversion | Native customer, rental and sale workflows; no automatic messages or stock reservations |
| Business overview | Optional compact dashboard with action queues and existing scoped figures | Original default dashboard, widget settings, finance permissions and detailed reports |
| Availability | Read-only date forecast distinguishing ready, expected return, later reservation, overdue and unavailable stock | Allocation, warehouse/city boundaries and return inspection |
| Delivery evidence | Durable form retries, retained camera/gallery selections, private proof cleanup on failure, real signature validation | PHOS proof table, original task progression, booking dates and legacy collection reports |
| Collections and cash | Separate receipt ledger, online report verification, partial cash handovers, actual receipt confirmation and audited corrections | Canonical PHOS payments and invoice synchronization; no historical auto-posting |
| Walkthroughs | Permission-aware workflow guides linked from navigation | Existing Knowledge Hub |
| Invoices | Download action and lighter shared document styling | Existing print action, permissions, reconciliation and invoice calculations |

Negotiated primary and additional rental prices, including zero, survive form initialization.
Explicit exclusive GST remains exclusive even when a product defaults to inclusive.
The original rental form startup error that prevented additional-line rendering is fixed.

## Safety and access

The new receipt and handover services use PHOS's organization transaction. Delivery
mutations join the same boundary, with fresh route models before reads. Exact
retries return saved work; changed replay payloads fail. Failed saves roll back
payments, stock progress, submission records and audit entries. Only newly written
proof files belonging to that failed request are cleaned up.

Cash collection credits the customer once. A handover reserves cash until Accounts
records actual receipt; confirmation does not credit the customer again. A short
receipt requires a reason and leaves the remainder outstanding. Collectors cannot
confirm their own handovers. UPI/card/bank-transfer entries remain reports until
Accounts verifies them; this is manual verification, not gateway integration.

Standard Finance/Operations receivers still need payment-create permission with
company scope. A custom role needs explicit `cash.receive` as well as those payment
permissions; payment-create alone does not grant company cash visibility. Custom
roles receive no automatic new grants. System Sales/Operations/Admin templates
gain only enquiry and quotation read/create/update access.

History blocks deletion of linked tasks, invoices, converted orders and relevant
customers/products. A task with collection history cannot be moved to a different
order or workflow type. Existing PHOS cancellation and correction rules still apply.
Recorded cash corrections preserve original values, actor, reason and timestamps;
they do not record a refund or settle a deposit.

Quotation links grant access to the named quotation until expiry. Revisions revoke
the earlier version. Pages and PDFs disable caching and referrer disclosure and
request search-engine exclusion. Sharing opens the user's composer or copies a
link; the application sends no message automatically.

## Decisions deliberately pending

1. Whether rental billing starts at delivery instead of the booked dates, including
   the effect on existing rentals, renewals, partial delivery and invoice revisions.
2. Deposit holding, deductions, settlement and customer refunds.
3. Gateway/provider verification, reconciliation and automated online payments.
4. Partner quotations: bill-to party versus actual client, acceptance authority,
   referral arrangements and commercial terms.
5. SaaS onboarding, tenant subscriptions and platform billing.
6. Any replacement of the original dashboard or existing proof/history workflows.

These policies are not enabled by this backport. The original dashboard remains
the default; the new overview is an additional route. Real-device camera/GPS and
staff acceptance on UAT remain release checks.

## Upgrade and recovery

Deploy application/templates/assets together after a verified database and private
proof-storage backup. The four new migrations create seven additive tables and
extend existing system role grants. Do not import Rentnexis's incompatible
`delivery_proofs` migrations. No existing invoice, payment, proof or collection
report is migrated or reposted.

Apply migrations before exposing the routes and forms; build frontend assets and
refresh route/view caches. Refresh already-open task, payment and order forms.
Quotations are advisory and do not reserve equipment. Ambiguous task/invoice links
must be reconciled before collecting money.

The new migrations refuse routine down migrations once their history is in use.
For code rollback, retain the new tables, records and proof files and use a
coordinated recovery plan. Never reset a business database to roll back this code.

## Reproducible checks

```text
npm ci
npm run build
php artisan view:cache
vendor/bin/pest --do-not-cache-result
```

Synthetic HTML/PDF fixtures can be exported by setting
`GETTING_STARTED_PREVIEW_DIR` while running the new regression tests. Then run
`node tests/Browser/PhosCommonFeatures.cjs` with the same directory. The browser
server serves fixtures and public assets on loopback and blocks external requests.
Its checks cover three viewport widths, quotation form values, retained photos
and overlapping image preparation. They do not establish actual device behavior.

For native database checks, provision an isolated loopback MariaDB 10.11.16
instance with a dedicated `phos-mariadb-common-*` data directory and a private
instance JSON containing `host`, `port` and `dataDirectory`:

```text
node tests/Concurrency/CommonWorkflows.cjs <private-instance.json>
node tests/Concurrency/PaymentSubmissionConcurrency.cjs <private-instance.json>
node tests/Concurrency/PaymentCorrectionConcurrency.cjs <private-instance.json>
```

Each creates a new randomly named validation database, checks actual server/data
directory identity, and refuses the standard database port. Do not point these
scripts at a shared, UAT or production instance. Evidence/configuration files stay
under ignored `storage/framework/testing`; generated keys are never committed.

`CommonMigrationPreservation.php` accepts a private disposable database config and
`baseline` then `upgrade` modes to rehearse the original schema followed by exactly
the four additive migrations. `RunMariaDbTests.php` accepts a separate fresh config
followed by Pest arguments for native regression checks. Database configs require
the guarded validation name, expected data directory, generated key and credentials.

Recorded results are in `docs/validation/common-features-2026-10-28.json`.
Local synthetic testing is separate from hosted CI and UAT acceptance. This change
does not merge or deploy itself.


## Review corrections — 29 October 2026

Quotation tax option values use two decimal places, matching product-selection
JavaScript. Available rates include each product's PHOS GST default, including
saved custom rates. Browser checks select products at 0%, 5%, 12%, 18%, 28% and
7.5%, verify the preview and submitted value, and check a newly added line.

Follow-up due dates retain the existing UTC storage format. An explicit immutable
UTC cast reads those timestamps correctly; overdue queries compare with UTC now,
and the enquiry list interprets its raw next-follow-up aggregate as UTC. The UI
converts to the application timezone for display. No migration or historical date
rewrite is needed. Checks cover a 17:00 Asia/Kolkata reminder, before/after overdue
boundaries, local midnight, today queues, and duplicate completion protection.
