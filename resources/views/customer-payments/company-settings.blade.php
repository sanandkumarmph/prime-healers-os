<section class="settings-section" data-org-section="payments">
    <div class="settings-section-head"><div class="settings-section-title"><span class="settings-icon">₹</span><div><h2>Online Payments</h2><p>Connect Razorpay and configure payment updates.</p></div></div></div>
    <div class="settings-muted-card">
        <strong>UAT — Test Mode</strong>
        <span>Test payments collect no real money. Live mode is available only when the production payment integration is activated. Production settings are not changed here.</span>
    </div>
    <div class="settings-card">
        <div class="settings-card-head"><div><h3>Webhook setup</h3><small>@if($paymentAccount)Your test account is saved. Copy these details into Razorpay Test Mode → Webhooks.@else Save your test API keys below to generate your webhook URL and secret.@endif</small></div></div>
        @if($paymentAccount)
        <div class="settings-field"><label for="webhook-url">Webhook URL</label><input class="settings-input" id="webhook-url" type="url" readonly value="{{ route('customer-payments.webhook', $paymentAccount->webhook_token) }}" onclick="this.select()" form="customerPaymentAccountForm"></div>
        <div class="settings-field" style="margin-top:14px"><label for="webhook-secret">Webhook secret</label><input class="settings-input" id="webhook-secret" readonly type="password" value="{{ $paymentAccount->webhook_secret }}" autocomplete="off" form="customerPaymentAccountForm"></div>
        <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:12px">
            <button type="button" class="settings-btn" data-copy-field="webhook-url">Copy webhook URL</button>
            <button type="button" class="settings-btn" data-toggle-secret="webhook-secret" aria-controls="webhook-secret" aria-pressed="false">Show secret</button>
            <button type="button" class="settings-btn" data-copy-field="webhook-secret">Copy webhook secret</button>
        </div>
        <p data-copy-status class="settings-help" role="status" aria-live="polite"></p>
        <p class="settings-help" style="margin-top:12px">Select these events in Razorpay: <code>payment_link.paid</code>, <code>payment_link.cancelled</code> and <code>payment_link.expired</code>.</p>
        @else
        <p class="settings-help">Webhook details appear here after a successful save. You do not need to create a separate secret yourself.</p>
        @endif
    </div>
    <div class="settings-card">
        <div class="settings-card-head"><div><h3>{{ $paymentAccount ? 'Replace Razorpay account' : 'Connect Razorpay' }}</h3><small>Enter credentials privately. API secrets are never shown after saving.</small></div></div>
        @if($paymentAccount)<p class="settings-help" style="margin-bottom:12px">Resolve open payment links before replacing this account. Use newly generated keys; previous details are retained for delayed payment updates.</p>@endif
        <div class="settings-grid-2">
            <div class="settings-field"><label for="gateway-mode">Payment mode</label><select class="settings-input" name="mode" id="gateway-mode" form="customerPaymentAccountForm"><option value="test">Test — no real money</option><option value="live" disabled>Live — production only</option></select></div>
            <div class="settings-field"><label for="gateway-key">Test API key ID</label><input class="settings-input" name="key_id" id="gateway-key" required maxlength="100" autocomplete="off" form="customerPaymentAccountForm"></div>
            <div class="settings-field"><label for="gateway-secret">Test API key secret</label><input class="settings-input" name="key_secret" id="gateway-secret" type="password" required autocomplete="new-password" form="customerPaymentAccountForm"></div>
            <div class="settings-field"><label for="gateway-password">Your PHOS password</label><input class="settings-input" name="current_password" id="gateway-password" type="password" required autocomplete="current-password" form="customerPaymentAccountForm"></div>
        </div>
        <button type="submit" form="customerPaymentAccountForm" class="settings-btn settings-btn-primary" style="margin-top:16px">Save Razorpay settings</button>
    </div>
</section>
