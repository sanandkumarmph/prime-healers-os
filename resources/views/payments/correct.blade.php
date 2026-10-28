@extends('layouts.app')
@section('content')
<style>
.payment-correction{max-width:680px;margin:24px auto;padding:24px;background:white;border:1px solid #e2e8f0;border-radius:12px;color:#0f172a}
.payment-correction h1{font-size:22px;font-weight:700;margin-bottom:16px}
.payment-correction dl{display:grid;grid-template-columns:100px 1fr;gap:8px;margin:16px 0}
.payment-correction dt{color:#475569}.payment-correction dd{font-weight:600}
.payment-correction p{margin:12px 0}.payment-correction [role=alert]{color:#b91c1c}
.payment-correction form{margin-top:20px}.payment-correction textarea{display:block;width:100%;padding:10px;border:1px solid #94a3b8;border-radius:8px}
.payment-correction .confirm{display:block;margin:16px 0}.payment-correction button{padding:12px 18px;background:#b91c1c;color:white;border-radius:8px;font-weight:600}
.payment-correction .back{display:block;margin-top:20px;color:#1d4ed8}
@media(max-width:640px){.payment-correction{margin:12px;padding:16px}}
</style>
<div class="payment-correction">
    <h1 >Reverse payment #{{ $payment->id }}</h1>
    @if(session('success'))<p role="status">{{ session('success') }}</p>@endif
    @if(session('error'))<p role="alert">{{ session('error') }}</p>@endif
    @if($errors->any())
        <div role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>
    @endif
    <dl >
        <dt>Amount</dt>
        <dd>@if($canViewFinance)₹{{ number_format((float) $payment->amount, 2) }}@else Restricted @endif</dd>
        <dt>Method</dt><dd>{{ $payment->paymentMethodLabel() }}</dd>
        <dt>Date</dt><dd>{{ $payment->payment_date?->format('d M Y') }}</dd>
    </dl>
    <p>The original stays in the payment history. An equal negative entry corrects the balance. This does not refund money to the customer.</p>
    @if($reversal)
        <p role="status">Already reversed by payment #{{ $reversal->id }}. No further action is needed.</p>
    @elseif($payment->reversal_of_payment_id || (float) $payment->amount <= 0)
        <p>This is a correction entry and cannot be reversed here.</p>
    @else
        <form method="POST" action="{{ route('payments.reverse', $payment) }}">
            @csrf
            <input type="hidden" name="version" value="{{ $version }}">
            <label for="reason">Reason for correction</label>
            <textarea id="reason" name="reason" required minlength="5" maxlength="1000" rows="3">{{ old('reason') }}</textarea>
            <label class="confirm"><input type="checkbox" name="confirm" value="1" required> I confirm this payment entry is incorrect.</label>
            <button type="submit">Reverse payment</button>
        </form>
    @endif
    @if($backUrl)<a href="{{ $backUrl }}" class="back">Back to payment history</a>@endif
</div>
@endsection
