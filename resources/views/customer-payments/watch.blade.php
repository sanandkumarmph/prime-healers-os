@if($paymentInvoice && auth()->user()?->can('view', $paymentInvoice)
    && \App\Models\CustomerPaymentLink::where('organization_id', $paymentInvoice->organization_id)->where('invoice_id', $paymentInvoice->id)->exists())
<div data-payment-watch data-status-url="{{ route('customer-payments.status', $paymentInvoice->id) }}"
    data-status-version="{{ app(\App\Services\CustomerPayments\PaymentStatusSnapshot::class)->version($paymentInvoice) }}"
    style="font-size:13px;color:#64748b;margin:8px 0;" role="status" aria-live="polite">
    <span data-payment-watch-message>Payment updates automatically.</span>
    <button type="button" data-payment-watch-reload hidden>Refresh page</button>
</div>
@endif
