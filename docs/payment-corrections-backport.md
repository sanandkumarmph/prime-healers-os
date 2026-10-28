# Audited payment entry corrections

Backports the standalone correction workflow from Rentnexis PR36, adapted to PHOS. An incorrect receipt is retained unchanged and a uniquely linked, equal negative entry is added. A reason, explicit confirmation, current receipt version, and the existing payment-delete permission are required. The audit stores the actor, reason, original attributes, and reversal ID in the same transaction. This records a bookkeeping correction, not a customer refund. To change an amount, reverse the whole receipt and record a replacement through the existing payment form.

Opening balances, automatic paid-sale receipts, renewal-linked receipts, and import-managed receipts are refused here because their owning workflows reconcile those entries. PHOS does not yet have Rentnexis's delivery-collection ledger; the service also refuses linked collection receipts if that ledger is subsequently present.

Payment DELETE now redirects browsers to the correction screen and returns validation errors for JSON clients without removing anything. Invoice deletion refuses payment, renewal, or audit history, including fully reversed receipts. Bulk deletion validates the complete permitted selection before deleting any row. Existing cancelled-invoice zero balances and receipt-based collection totals remain in force; corrections use their posting date in collection totals.

## PHOS transaction adaptation

PHOS's existing payment submissions lock the organization row. The new transaction service and web middleware share that lock for Invoice, Payment, Rental, and Sale controllers. This includes their GET reconciliation paths, which can create legacy opening or sale receipts. Bound organization models are refreshed after the lock; failures roll back database effects. This is coarse serialization per organization and can delay other finance requests during a long import or PDF render. It is an interim PHOS adaptation, not a backport of Rentnexis's complete financial-lock coordinator or stock-integrity work. Direct CLI or background writers must explicitly use the transaction service; this change does not claim to coordinate arbitrary external database writes.

## Database and rollback

The additive migration adds a nullable unique `payments.reversal_of_payment_id` with a restrictive self-reference. Existing receipts are untouched. Apply only this migration after a verified database backup and before reopening the updated application. No asset build or dependency installation is required. Never drop correction links after a reversal exists; the migration's rollback refuses that. Application rollback retains the additive schema and all financial records. Once the feature has been used, prefer a reviewed forward fix to returning to an application that still offers receipt deletion.

## Validation

- Focused payment, correction, finance-service, invoice-presentation, and cancelled-balance checks: 67 tests, 476 assertions passed using isolated SQLite.
- Five HTTP-kernel concurrency scenarios passed on disposable loopback MariaDB 10.4.32: same-receipt double submission, correction versus a new payment, correction versus invoice deletion, failed correction then retry, and independent organizations. Each correction committed one reversal and one audit; rollback left no partial entries.
- MariaDB migration created the unique link and self-reference. UAT uses a different MariaDB version, so its deployment must verify the actual schema.
- Full-suite CI is not claimed green: the existing unquoted display name in the example environment file previously stops setup before tests. Manual UAT acceptance remains pending deployment.

## Manual UAT after deployment

Preserve all previously accepted synthetic records. Create a NEW clearly labelled customer/invoice with no real contact details, no messages or actual money transfer, total 100, and opening paid amount zero.

1. Record a normal 100 receipt through Mark Paid. Verify paid 100, due zero, one receipt. Open its correction form in two tabs.
2. Try an empty/short reason and omit confirmation. Expect validation and no new entry.
3. Enter a clear test reason and confirm in the first tab. Expect original +100 and correction -100, paid zero, due 100. Verify the reason and actor in activity history.
4. Submit the second tab and refresh. Expect already-reversed rejection and still exactly two entries, with one reversal and one audit.
5. Record a NEW replacement receipt of 40. Expect three entries, net paid 40, due 60, and partial status. Reload invoice and rental/sale views where applicable; the original and correction remain.
6. Check collection totals for the posting dates and CSV/ledger entries. The correction reduces the net amount rather than disappearing from history.
7. Attempt deletion only on this NEW synthetic invoice. Expect refusal, preserving the invoice, receipts, and audit. Do not try this on operational data.

Permissions, stale changed receipts, managed-entry refusals, cancellation preservation, audit-failure rollback, and replay after correction are covered by focused local tests. User-facing UAT checks should be recorded separately from these local results.
