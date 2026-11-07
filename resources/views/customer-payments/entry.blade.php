@if($paymentInvoice && auth()->user()?->can('view', $paymentInvoice))
<div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;padding:12px 16px;margin-bottom:12px;border:1px solid #dbe3ef;border-radius:14px;background:#fff;">
    <span style="color:#475569;font-size:13px;">Online payment · Invoice {{ $paymentInvoice->invoice_number }}</span>
    <a href="{{ route('customer-payments.invoice', $paymentInvoice->id) }}" style="display:inline-flex;align-items:center;padding:9px 14px;border-radius:10px;background:#2657c8;color:#fff;font-size:13px;font-weight:700;text-decoration:none;">{{ auth()->user()->can('createForInvoice', [\App\Models\Payment::class, $paymentInvoice]) ? 'Payment link' : 'Payment link status' }}</a>
</div>
@include('customer-payments.watch')
@once
<script src="{{ asset('js/phos-customer-payments.js') }}?v={{ hash_file('sha256', public_path('js/phos-customer-payments.js')) }}" defer></script>
@endonce
@endif
