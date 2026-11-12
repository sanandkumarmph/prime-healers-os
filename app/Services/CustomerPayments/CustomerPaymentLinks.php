<?php

namespace App\Services\CustomerPayments;

use App\Models\CustomerPaymentAccount;
use App\Models\CustomerPaymentLink;
use App\Models\CustomerPaymentReceipt;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\User;
use App\Support\ActivityLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CustomerPaymentLinks
{
    public function __construct(private RazorpayClient $gateway, private PaymentLinkLedger $finance, private PaymentLinkGuard $guard) {}

    public function create(Invoice $invoice, User $user, bool $reminders): CustomerPaymentLink
    {
        abort_unless((int) $invoice->organization_id === (int) $user->organization_id, 404);
        $recipient = [];
        $link = $this->finance->transaction((int) $invoice->organization_id, function () use ($invoice, $user, $reminders, &$recipient) {
            $locked = $this->finance->lockInvoice((int) $invoice->organization_id, $invoice->id);
            $this->finance->assertPayable($locked);
            $account = CustomerPaymentAccount::where('organization_id', $invoice->organization_id)->where('is_active', true)->lockForUpdate()->first();
            if (! $account || $account->mode !== 'test') {
                $this->fail('Connect Razorpay Test Mode first. Live payments are disabled for this UAT integration.');
            }
            if (CustomerPaymentLink::where('organization_id', $invoice->organization_id)->where('invoice_id', $invoice->id)->open()->lockForUpdate()->exists()) {
                $this->fail('A payment link already exists. Refresh its status or cancel it first.');
            }
            $balance = self::minor($locked->total_amount) - self::minor($this->finance->currentPaymentTotal($locked));
            if ($balance < 100) {
                $this->fail('The outstanding balance must be at least INR 1.00.');
            }
            if ($reminders && ! filter_var($locked->bill_to_email, FILTER_VALIDATE_EMAIL)) {
                $this->fail('Add a valid billing email to the invoice before enabling email reminders.');
            }
            $recipient = ['name' => mb_substr($locked->bill_to_name ?: 'Customer', 0, 40), 'email' => $locked->bill_to_email];

            return CustomerPaymentLink::create(['organization_id' => $invoice->organization_id, 'invoice_id' => $invoice->id,
                'account_id' => $account->id, 'created_by' => $user->id, 'reference' => (string) Str::uuid(), 'amount_minor' => $balance,
                'currency' => 'INR', 'reminders' => $reminders, 'expires_at' => now()->addDays(7), 'status' => 'creating']);
        });
        $data = ['amount' => $link->amount_minor, 'currency' => 'INR', 'accept_partial' => false,
            'reference_id' => $link->reference, 'expire_by' => $link->expires_at->timestamp,
            'description' => 'Invoice '.mb_substr($invoice->invoice_number, 0, 80),
            'notify' => ['email' => $reminders, 'sms' => false], 'reminder_enable' => $reminders];
        // Only send a recipient address when the operator explicitly enables notifications.
        if ($reminders) {
            $data['customer'] = $recipient;
        }
        try {
            $entity = $this->gateway->request($link->account, 'POST', 'payment_links', $data);
            $this->adopt($link, $entity);
        } catch (GatewayFailure $e) {
            CustomerPaymentLink::whereKey($link->id)->where('status', 'creating')->update(['status' => $e->definitelyRejected ? 'failed' : 'unknown']);
            throw $e;
        } catch (ValidationException $e) {
            CustomerPaymentLink::whereKey($link->id)->where('status', 'creating')->update(['status' => 'unknown']);
            throw $e;
        }
        ActivityLogger::log('payment_link.created', $invoice, ['link_id' => $link->id, 'mode' => $link->account->mode, 'reminders' => $reminders], 'Customer payment link created.');

        return $link->fresh();
    }

    public function refresh(CustomerPaymentLink $link): CustomerPaymentLink
    {
        $account = $link->account;
        if (! $link->provider_id) {
            $result = $this->gateway->request($account, 'GET', 'payment_links', ['reference_id' => $link->reference]);
            $matches = collect($result['payment_links'] ?? $result['items'] ?? [])->filter(fn ($e) => ($e['reference_id'] ?? '') === $link->reference);
            if ($matches->count() !== 1) {
                $this->fail('The gateway has not returned one matching link yet. Keep this request for reconciliation; do not create a duplicate link.');
            }
            $entity = $matches->first();
        } else {
            $entity = $this->gateway->request($account, 'GET', 'payment_links/'.$link->provider_id);
        }
        $this->adopt($link, $entity);
        if (($entity['status'] ?? '') === 'paid') {
            foreach ($entity['payments'] ?? [] as $item) {
                $paymentId = $item['payment_id'] ?? null;
                if (! is_string($paymentId) || ! preg_match('/^pay_[A-Za-z0-9]+$/D', $paymentId)) {
                    continue;
                }
                $payment = $this->gateway->request($account, 'GET', 'payments/'.$paymentId);
                $this->receive($link->fresh(), $entity, $payment, null);
            }
            if (! $link->receipts()->exists()) {
                $link->update(['status' => 'needs_review']);
            }
        }

        return $link->fresh();
    }

    public function cancel(CustomerPaymentLink $link): void
    {
        $link = $this->refresh($link);
        if (in_array($link->status, ['paid', 'test_paid', 'cancelled', 'expired', 'failed'], true)) {
            return;
        }
        $entity = $this->gateway->request($link->account, 'POST', 'payment_links/'.$link->provider_id.'/cancel');
        $this->adopt($link, $entity);
        if (! in_array($link->fresh()->status, ['cancelled', 'expired'], true)) {
            $this->fail('The gateway has not confirmed cancellation. Refresh its status before making another payment.');
        }
        ActivityLogger::log('payment_link.cancelled', $link->invoice, ['link_id' => $link->id], 'Payment link cancelled at the gateway.');
    }

    public function adopt(CustomerPaymentLink $link, array $entity): void
    {
        $this->validateLink($link, $entity);
        $url = $entity['short_url'] ?? null;
        if ($url && (parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_HOST) !== 'rzp.io'
            || parse_url($url, PHP_URL_USER) || !in_array(parse_url($url, PHP_URL_PORT), [null, 443], true))) {
            $this->fail('The gateway returned an unexpected payment URL.');
        }
        DB::transaction(function () use ($link, $entity, $url) {
            $locked = CustomerPaymentLink::whereKey($link->id)->lockForUpdate()->firstOrFail();
            $status = match ($entity['status'] ?? '') {
                'created' => 'active', 'cancelled' => 'cancelled', 'expired' => 'expired',
                default => 'needs_review',
            };
            // Out-of-order callbacks must never reopen a completed link.
            if (in_array($locked->status, ['paid', 'test_paid'], true)) {
                $status = $locked->status;
            } elseif (in_array($locked->status, ['cancelled', 'expired'], true) && $status === 'active') {
                $status = $locked->status;
            }
            if ($locked->receipts()->where('status', 'needs_review')->lockForUpdate()->first()) {
                $status = 'needs_review';
            }
            $locked->update(['provider_id' => $entity['id'], 'short_url' => $url ?: $locked->short_url, 'status' => $status, 'checked_at' => now()]);
        });
    }

    public function validateLink(CustomerPaymentLink $link, array $entity): void
    {
        if ($link->account->mode !== 'test'
            || ! is_string($entity['id'] ?? null) || ! preg_match('/^plink_[A-Za-z0-9]+$/D', $entity['id'])
            || ($link->provider_id && $link->provider_id !== $entity['id'])
            || ($entity['reference_id'] ?? '') !== $link->reference
            || ($entity['currency'] ?? '') !== $link->currency
            || ! is_int($entity['amount'] ?? null) || $entity['amount'] !== $link->amount_minor
            || ($entity['accept_partial'] ?? null) !== false) {
            $this->fail('Payment link identity or amount does not match the invoice request.');
        }
    }

    /** Only call after HMAC verification or authenticated gateway retrieval. */
    public function receive(CustomerPaymentLink $link, array $entity, array $payment, ?string $eventId): void
    {
        $this->validateLink($link, $entity);
        if (($entity['status'] ?? '') !== 'paid' || ($entity['amount_paid'] ?? null) !== $link->amount_minor
            || ($payment['status'] ?? '') !== 'captured' || ($payment['captured'] ?? null) !== true
            || ($payment['currency'] ?? '') !== $link->currency || ($payment['amount'] ?? null) !== $link->amount_minor
            || ! is_string($payment['id'] ?? null) || ! preg_match('/^pay_[A-Za-z0-9]+$/D', $payment['id'])
            || empty($payment['order_id']) || $payment['order_id'] !== ($entity['order_id'] ?? null)
            || ! is_int($payment['created_at'] ?? null) || $payment['created_at'] < 1) {
            $this->fail('Captured payment identity, amount or currency did not match.');
        }
        $this->finance->transaction((int) $link->organization_id, function () use ($link, $payment, $eventId) {
            $invoice = $this->finance->lockInvoice((int) $link->organization_id, $link->invoice_id);
            $locked = CustomerPaymentLink::whereKey($link->id)->lockForUpdate()->firstOrFail();
            $receipt = CustomerPaymentReceipt::where('account_id', $locked->account_id)->where('provider_payment_id', $payment['id'])->lockForUpdate()->first();
            $receipt ??= CustomerPaymentReceipt::create(['account_id' => $locked->account_id, 'provider_payment_id' => $payment['id'],
                'link_id' => $locked->id, 'event_id' => $eventId, 'amount_minor' => $payment['amount'], 'currency' => $payment['currency'],
                'method' => Payment::normalizeMethod($payment['method'] ?? 'other'), 'paid_at' => CarbonImmutable::createFromTimestampUTC($payment['created_at']), 'status' => 'received']);
            if ((int) $receipt->link_id !== (int) $locked->id) {
                $this->fail('This payment is already associated with another request.');
            }
            $isTest = $locked->account->mode === 'test';
            $postTest = $isTest && app(UatPaymentPosting::class)->enabled();
            if (($payment['amount_refunded'] ?? 0) > 0 && (! $isTest || $postTest)) {
                $receipt->update(['status' => 'needs_review', 'review_reason' => 'Razorpay reports a refund. Reconcile the refund with Accounts; the payment history is retained.']);
                $locked->update(['status' => 'needs_review', 'checked_at' => now()]);

                return;
            }
            // An existing verified test receipt can be reconciled once UAT posting
            // is enabled. Re-fetching/signature verification still precedes this.
            if ($receipt->payment_id || ! in_array($receipt->status, $postTest ? ['received', 'test'] : ['received'], true)) {
                return;
            }
            if ($isTest && ! $postTest) {
                $receipt->update(['status' => 'test']);
                $locked->update(['status' => 'test_paid']);

                return;
            }
            try {
                $balance = self::minor($invoice->total_amount) - self::minor($this->finance->currentPaymentTotal($invoice));
                if ($balance !== $receipt->amount_minor) {
                    $this->fail('The invoice balance changed. Review this receipt manually.');
                }
                $recorded = $this->guard->receiving($invoice->id, fn () => $this->finance->createInvoicePayment(
                    (int) $locked->organization_id, $invoice,
                    ['payment_date' => $receipt->paid_at->setTimezone('Asia/Kolkata')->toDateString(),
                        'amount' => $receipt->amount_minor / 100, 'payment_method' => $receipt->method,
                        'notes' => ($isTest ? '[TEST PAYMENT - UAT] ' : '').'Razorpay '.$receipt->provider_payment_id],
                    $invoice->linkedRentalId(), true));
                $receipt->update(['status' => $isTest ? 'recorded_test' : 'recorded', 'payment_id' => $recorded->id]);
                $locked->update(['status' => $isTest ? 'test_paid' : 'paid', 'checked_at' => now()]);
                ActivityLogger::log('payment_link.received', $invoice, ['link_id' => $locked->id, 'payment_id' => $recorded->id, 'mode' => $locked->account->mode], $isTest ? 'Simulated payment recorded in UAT.' : 'Verified gateway payment recorded.');
            } catch (ValidationException $e) {
                $receipt->update(['status' => 'needs_review', 'review_reason' => 'Invoice changed, a settlement exists, or the gateway reports a refund. Reconcile before recording or refunding.']);
                $locked->update(['status' => 'needs_review', 'checked_at' => now()]);
            }
        });
    }

    public static function minor(string|float|int $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['payment_link' => $message]);
    }
}
