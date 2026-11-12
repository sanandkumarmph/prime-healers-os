@extends('layouts.app')
@section('content')
@include('sales-workspace.style')
@php
    $canCollect = auth()->user()->can('createForInvoice',[\App\Models\Payment::class,$invoice]);
    $uatPosting = app(\App\Services\CustomerPayments\UatPaymentPosting::class)->enabled();
@endphp
<div class="sw">
    <a href="{{ route('invoices.show',$invoice->id) }}">← Back to invoice</a>
    <header class="sw-head"><div><div class="sw-eyebrow">{{ $invoice->invoice_number }}</div><h1>Customer payment link</h1><p class="sw-muted">{{ $invoice->bill_to_name }} · Current balance {{ 'INR' }} {{ number_format($invoice->balance_amount,2) }}</p></div></header>
    @include('sales-workspace.alerts',['showSuccess'=>true])
    <p class="sw-alert">Razorpay Test Mode. No real money is collected. @if($uatPosting)Successful test payments update this UAT invoice and its linked order.@endif</p>
    @include('customer-payments.watch', ['paymentInvoice' => $invoice])
    @if(!$account)
        <section class="sw-panel"><h2>Connect your payment account</h2><p>Ask your company administrator to connect Razorpay before collecting online payments.</p>@if(auth()->user()->isSuperAdmin() || auth()->user()->isAdminOperations())<a class="sw-btn" href="{{ route('customer-payments.settings') }}">Set up online payments</a>@endif</section>
    @elseif($canCollect && (float) $invoice->balance_amount >= 1 && !in_array($invoice->status, ['cancelled','void'], true) && !$links->contains(fn($link)=>in_array($link->status,\App\Models\CustomerPaymentLink::OPEN,true)))
        <section class="sw-panel"><h2>{{ $account->mode === 'test' ? 'Create a test payment link' : 'Collect the outstanding balance' }}</h2>
            @if($account->mode === 'test')<p class="sw-alert">{{ $uatPosting ? 'UAT test mode: a successful payment updates this test invoice and its linked rental or sale automatically. No real money is collected.' : 'Test mode: no real payment is collected and the invoice balance will stay unchanged.' }}</p>@endif
            <p>One link for the full outstanding amount, valid for seven days. Partial payments are disabled.</p>
            <form method="post" action="{{ route('customer-payments.create',$invoice->id) }}">@csrf
                <label style="display:flex;gap:10px;align-items:start"><input type="checkbox" name="reminders" value="1"><span>Send the link and automatic email reminders to <strong>{{ $invoice->bill_to_email ?: 'the billing email (add it first)' }}</strong>.<br><span class="sw-meta">Razorpay uses the reminder schedule configured in your gateway dashboard. Leave unchecked to share the link yourself.</span></span></label>
                <div class="sw-actions" style="margin-top:16px"><button class="sw-btn sw-primary">{{ $account->mode === 'test' ? 'Create test link' : 'Create payment link' }}</button></div>
            </form>
        </section>
    @endif
    @foreach($links as $link)
    <section class="sw-panel"><div class="sw-head"><div><h2>{{ $link->status === 'test_paid' ? 'Test payment completed' : ucfirst(str_replace('_',' ',$link->status)) }} @if($link->account->mode === 'test' && $link->status !== 'test_paid')· Test @endif</h2><p class="sw-meta">INR {{ number_format($link->amount_minor/100,2) }} @if(!in_array($link->status,['paid','test_paid']))· Expires {{ $link->expires_at->setTimezone('Asia/Kolkata')->format('d M Y, h:i A') . ' IST' }}@endif · Email reminders {{ $link->reminders ? 'enabled' : 'off' }}</p></div></div>
        @if($link->receipts->contains('status', 'recorded_test'))<p class="sw-alert">Recorded in UAT. The invoice and linked order balances include this simulated payment.</p>@elseif($link->status === 'paid')<p class="sw-alert">Payment recorded. The invoice and linked order balances have been updated.</p>@endif
        @if($link->status === 'active' && $link->short_url)
        <div class="sw-field"><label for="payment-link-{{ $link->id }}">Copy this link to share</label><input id="payment-link-{{ $link->id }}" readonly value="{{ $link->short_url }}" onclick="this.select()"></div>
        @php
            $number = \App\Support\WhatsAppHelper::normalizeNumber($invoice->bill_to_phone ?: $invoice->customer?->phone);
            $message = "Prime Healers - Payment request\nInvoice: ".$invoice->invoice_number."\nAmount: INR ".number_format($link->amount_minor/100, 2)."\n\nSecure payment link: ".$link->short_url."\n\nUAT test link - no real money is collected.\n\nRegards, ".auth()->user()->name;
            $whatsapp = \App\Support\WhatsAppHelper::chatUrl($number, $message);
        @endphp
        @if($whatsapp && $canCollect)<a href="{{ $whatsapp }}" target="_blank" rel="noopener noreferrer" class="sw-btn sw-primary" style="margin-top:10px">Share on WhatsApp</a>@endif
        <button type="button" class="sw-btn" style="margin-top:10px" data-copy-field="payment-link-{{ $link->id }}">Copy payment link</button><p data-copy-status class="sw-meta" role="status"></p>
        <p class="sw-meta">Cancel this link before changing invoice amounts or recording a payment received elsewhere. Opening the link does not mark the invoice paid.</p>
        @elseif(in_array($link->status,['unknown','creating']))<p class="sw-alert">The request is awaiting confirmation. Refresh status to recover it without creating a duplicate.</p>
        @elseif($link->status === 'needs_review')<p class="sw-alert">A gateway result needs reconciliation. Refresh status, then check Razorpay and the payment history before recording or refunding any amount.</p>@endif
        @foreach($link->receipts as $receipt)<p class="sw-meta">{{ $receipt->provider_payment_id }} · {{ ucfirst(str_replace('_',' ',$receipt->status)) }}{{ $receipt->payment_id ? ' · Payment #'.$receipt->payment_id : '' }}{{ $receipt->review_reason ? ' · '.$receipt->review_reason : '' }}</p>@endforeach
        @if($canCollect)<form method="post" action="{{ route('customer-payments.action',[$invoice->id,$link->id]) }}" class="sw-actions">@csrf<button class="sw-btn" name="action" value="refresh">Refresh status</button>@if(in_array($link->status,\App\Models\CustomerPaymentLink::OPEN,true))<button class="sw-btn" name="action" value="cancel">Cancel link</button>@endif</form>@endif
    </section>
    @endforeach
</div>
<script src="{{ asset('js/phos-customer-payments.js') }}?v={{ hash_file('sha256', public_path('js/phos-customer-payments.js')) }}" defer></script>
@endsection
