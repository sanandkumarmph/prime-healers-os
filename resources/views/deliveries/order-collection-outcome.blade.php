@php
    $methods = ['cash' => 'Cash', 'upi' => 'UPI', 'bank_transfer' => 'Bank transfer', 'card' => 'Card'];
@endphp
<p class="sw-muted">₹{{ number_format($available, 2) }} available to collect now. Record only what you received.</p>
<form method="post" action="{{ route('delivery-collections.store', $task) }}" enctype="multipart/form-data" data-order-collection-outcome>
    @csrf
    <input type="hidden" name="submission_token" value="{{ old('submission_token', (string) \Illuminate\Support\Str::uuid()) }}">
    <input type="hidden" name="version" value="{{ $version }}">
    <div class="sw-field">
        <label for="collection-outcome">Outcome</label>
        <select id="collection-outcome" name="outcome" required>
            @if($available > 0)
                <option value="collected" @selected(old('outcome') === 'collected')>Collected in full</option>
                <option value="partial" @selected(old('outcome') === 'partial')>Collected part of the amount</option>
            @endif
            <option value="none" @selected(old('outcome') === 'none')>Nothing collected</option>
        </select>
    </div>
    <fieldset class="border-0 p-0" data-collection-payment-fields @if($available <= 0 || old('outcome') === 'none') hidden disabled @endif>
        <div class="sw-fields">
            <div class="sw-field"><label for="collect-amount">Amount received</label><input id="collect-amount" type="number" name="amount" min="0.01" max="{{ $available }}" step="0.01" inputmode="decimal" value="{{ old('amount', $available) }}"></div>
            <div class="sw-field"><label for="collect-method">Payment method</label>
                <select id="collect-method" name="method">
                    @foreach($task->allowedCollectionMethods() as $method)<option value="{{ $method }}" @selected(old('method') === $method)>{{ $methods[$method] }}</option>@endforeach
                    @if($task->collection_allowed_methods === 'both')<option value="split" @selected(old('method') === 'split')>Cash and online</option>@endif
                </select>
            </div>
        </div>
        <fieldset class="border-0 p-0" data-collection-split hidden disabled>
            <div class="sw-fields">
                <div class="sw-field"><label for="collect-cash">Cash portion</label><input id="collect-cash" type="number" name="cash_amount" min="0.01" step="0.01" inputmode="decimal" value="{{ old('cash_amount') }}"></div>
                <div class="sw-field"><label for="collect-online-method">Online method for the remainder</label>
                    <select id="collect-online-method" name="online_method">
                        @foreach(\Illuminate\Support\Arr::except($methods, 'cash') as $method => $label)<option value="{{ $method }}" @selected(old('online_method') === $method)>{{ $label }}</option>@endforeach
                    </select>
                </div>
            </div>
        </fieldset>
        <fieldset class="border-0 p-0" data-collection-online hidden disabled>
            <p class="sw-muted">Give Accounts a transaction reference or upload a payment screenshot to verify.</p>
            <div class="sw-fields">
                <div class="sw-field"><label for="collect-reference">Transaction reference</label><input id="collect-reference" name="reference" maxlength="255" value="{{ old('reference') }}"></div>
                <div class="sw-field"><label for="collect-screenshot">Private payment screenshot</label><input id="collect-screenshot" type="file" name="payment_screenshot" accept="image/jpeg,image/png,image/webp"></div>
            </div>
        </fieldset>
    </fieldset>
    <div class="sw-field"><label for="collect-note">Note <span data-collection-reason hidden>(required for partial or missed collection)</span></label><textarea id="collect-note" name="note" maxlength="1000" rows="2">{{ old('note') }}</textarea></div>
    <label style="display:block;margin:16px 0"><input type="checkbox" name="confirmed" value="1" required> I checked the amount received and the saved collection history.</label>
    <button class="sw-btn sw-primary">Save collection outcome</button>
</form>
@push('scripts')
    <script src="{{ asset('js/phos-order-collection.js') }}?v={{ hash_file('sha256', public_path('js/phos-order-collection.js')) }}" defer></script>
@endpush
