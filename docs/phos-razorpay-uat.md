# PHOS Razorpay payment links (UAT)

This backport reuses the Rentnexis link, signature-verification, receipt, reconciliation and status-update flow. It uses PHOS's existing organization financial lock and payment ledger. No SaaS subscription, new SDK or external messaging service is required.

## Connect

1. Sign in to PHOS UAT as Super Admin or Admin. Open Company Settings → Online Payments.
2. Enter the Razorpay **Test Mode** key ID and secret, plus your current PHOS password. Never send these in chat. They are encrypted using PHOS's existing application key and excluded from flashed form input and model serialization.
3. Copy the displayed webhook URL and generated webhook secret into Razorpay's Test Mode webhook settings. Select `payment_link.paid`, `payment_link.cancelled`, and `payment_link.expired`.
4. Open a test rental, sale or invoice → Payment link → Create test link. Share on WhatsApp opens a prepared message for staff to review and send; the application does not automatically send it. Gateway email reminders are off unless explicitly selected.
5. Complete the link using Razorpay's test payment flow. Confirm one receipt and one payment, matching invoice/order balances, and an IST payment date. Repeat the callback or refresh: no duplicate payment is created.

Links collect the full unpaid invoice balance (including any invoiced deposit/transport), expire after seven days and disallow partial payment. This does not alter incentive eligibility rules. Sales may collect where their existing payment permissions allow; Finance remains read-only. Only Super Admin/Admin with settings-update permission may configure the gateway.

## Reconciliation and safety

Webhooks verify HMAC-SHA256 over the original request bytes and match link reference, amount, currency, order and captured payment. Browser visits/redirects do not mark invoices paid. A minute scheduler checks delayed callbacks using authenticated GET requests. Status polling is read-only, pauses in hidden tabs and protects unsaved forms.

Creation timeouts retain their reference; refresh status rather than creating another link. Cancel/reconcile an open link before changing financial details or recording another payment. Changed balances, refunds and contradictory gateway results are retained for Accounts review. Verified receipts are protected from ordinary deletion/reversal. Gateway payments are company collections, never vendor cash.

Live credentials and live gateway requests are rejected in this UAT release. Test posting additionally requires `APP_ENV=uat`, the exact UAT URL and the connected encrypted MariaDB database/socket/server identity. Elsewhere test receipts do not affect the ledger. Preserve the application key and the encrypted backups.

## Verification

Run `php artisan test --filter=PhosCustomerPaymentLinksTest`. Setting `PHOS_PAYMENT_PREVIEW_DIR` exports synthetic browser pages for `node --test tests/Browser/CustomerPaymentLinksTest.cjs`. Tests fake every Razorpay API request and send no customer messages. A real Razorpay Test Mode checkout and dashboard webhook configuration still require the merchant's private test keys.

Official API contracts: [Create Payment Links](https://razorpay.com/docs/api/payments/payment-links/create-standard), [Verify webhooks](https://razorpay.com/docs/webhooks/validate-test), [Payment Link events](https://razorpay.com/docs/webhooks/payment-links).
