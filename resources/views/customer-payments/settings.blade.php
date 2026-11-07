@extends('layouts.app')
@section('content')
@include('sales-workspace.style')
<div class="sw">
    <a href="{{ route('organization.settings.edit') }}">Back to company settings</a>
    <h1>Online customer payments</h1>
    <div class="sw" style="max-width:none;margin:0;padding:0">
    <p class="sw-muted">Connect Razorpay Test Mode to test payment links. No real money is collected in UAT.</p>
    @include('sales-workspace.alerts',['showSuccess'=>true])
    @if($account)
    <section class="sw-panel"><h2>{{ $account->mode === 'test' ? 'Test account connected' : 'Live account configured' }}</h2><p class="sw-meta">{{ $account->mode === 'test' ? (app(\App\Services\CustomerPayments\UatPaymentPosting::class)->enabled() ? 'UAT testing is enabled. Verified test payments update invoices and linked rental or sale balances automatically. No real money is collected.' : 'Test payments verify the connection without changing invoice balances.') : 'Verified captured payments update the invoice and linked rental or sale automatically.' }}</p>
        <h3>Complete webhook setup in Razorpay</h3><p>Subscribe to <code>payment_link.paid</code>, <code>payment_link.cancelled</code> and <code>payment_link.expired</code>.</p>
        <div class="sw-field"><label for="webhook-url">Webhook URL</label><input id="webhook-url" readonly value="{{ route('customer-payments.webhook',$account->webhook_token) }}" onclick="this.select()"></div>
        <div class="sw-field" style="margin-top:12px"><label for="webhook-secret">Webhook secret · copy this into Razorpay</label><input id="webhook-secret" readonly type="password" value="{{ $account->webhook_secret }}" autocomplete="off" onclick="this.select()"></div>
        <div class="sw-actions" style="margin-top:12px"><button type="button" class="sw-btn" data-copy-field="webhook-url">Copy webhook URL</button><button type="button" class="sw-btn" data-copy-field="webhook-secret">Copy webhook secret</button></div><p data-copy-status class="sw-meta" role="status"></p>
        <p class="sw-meta">API secrets are encrypted. This webhook secret is available only to users who can manage company settings. Refresh status on an invoice if a callback is delayed.</p>
    </section>
    @endif
    <section class="sw-panel"><h2>{{ $account ? 'Replace account' : 'Connect Razorpay' }}</h2>
        <p class="sw-meta">Use your organisation’s own API keys. Payment links are issued in INR. Live-mode credentials are not accepted in UAT. @if($account)Resolve all open links first, then use newly generated API keys. Previous account details are retained to verify late callbacks.@endif</p>
        <form action="{{ route('customer-payments.settings.save') }}" method="post" autocomplete="off">@csrf
            <div class="sw-fields">
                <div class="sw-field"><label for="gateway-mode">Mode</label><select name="mode" id="gateway-mode"><option value="test">Test · no real money</option></select></div>
                <div class="sw-field"><label for="gateway-key">API key ID</label><input name="key_id" id="gateway-key" required maxlength="100" autocomplete="off"></div>
                <div class="sw-field"><label for="gateway-secret">API key secret</label><input name="key_secret" id="gateway-secret" type="password" required autocomplete="new-password"></div>
                <div class="sw-field"><label for="gateway-password">Your PHOS password</label><input name="current_password" id="gateway-password" type="password" required autocomplete="current-password"></div>
            </div>
            <div class="sw-actions" style="margin-top:16px"><button class="sw-btn sw-primary">Save payment account</button></div>
        </form>
    </section>
    </div>
</div>
<script src="{{ asset('js/phos-customer-payments.js') }}?v={{ hash_file('sha256', public_path('js/phos-customer-payments.js')) }}" defer></script>
@endsection
