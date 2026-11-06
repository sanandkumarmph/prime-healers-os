@if(!$isEdit && app(\App\Services\Deliveries\OrderCollectionSetup::class)->allowed(auth()->user(), $orderKind === 'sale' ? 'sales' : 'rentals'))
    <div class="rental-col-12 sales-col-12 order-collection-setup" data-order-collection="{{ $orderKind }}" data-manual-amount="{{ old('collection_amount') !== null ? '1' : '0' }}">
        <input type="hidden" name="collection_submission_token" value="{{ old('collection_submission_token', (string) \Illuminate\Support\Str::uuid()) }}">
        <label class="order-collection-toggle">
            <input type="checkbox" id="collection_enabled" name="collection_enabled" value="1" @checked(old('collection_enabled'))>
            Collect payment on delivery
        </label>
        <p>Ask the assigned Delivery operator to collect the unpaid amount at delivery.</p>
        <fieldset data-order-collection-fields @if(!old('collection_enabled')) hidden disabled @endif>
            @if($orderKind === 'sale')
                <label for="delivery_operator_id">Delivery operator</label>
                <select id="delivery_operator_id" name="delivery_operator_id">
                    <option value="">Select internal Delivery staff</option>
                    @foreach($collectionOperators as $operator)
                        <option value="{{ $operator->id }}" @selected((int)old('delivery_operator_id') === (int)$operator->id)>{{ $operator->name }}</option>
                    @endforeach
                </select>
            @endif
            <label for="collection_amount">Amount to collect</label>
            <input type="number" id="collection_amount" name="collection_amount" min="0.01" max="99999999.99" step="0.01" inputmode="decimal" value="{{ old('collection_amount') }}" aria-describedby="orderCollectionSuggestion">
            <p id="orderCollectionSuggestion" data-collection-suggestion></p>
            <label for="collection_methods">Allowed methods</label>
            <select id="collection_methods" name="collection_methods">
                @foreach(\App\Services\Deliveries\OrderCollectionSetup::METHODS as $value => $label)
                    <option value="{{ $value }}" @selected(old('collection_methods', 'cash') === $value)>{{ $label }}</option>
                @endforeach
            </select>
            <label for="collection_instructions">Short instructions (optional)</label>
            <textarea id="collection_instructions" name="collection_instructions" maxlength="1000" rows="2">{{ old('collection_instructions') }}</textarea>
        </fieldset>
        <p class="field-error" role="alert" data-order-collection-error hidden></p>
        @foreach(['collection_enabled', 'collection_amount', 'collection_methods', 'collection_instructions', 'delivery_operator_id', 'collection_submission_token'] as $field)
            @error($field)<p class="field-error" role="alert">{{ $message }}</p>@enderror
        @endforeach
    </div>
    @once
        <style>
            .order-collection-setup { min-width:0; padding:14px; border:1px solid #cbd5e1; border-radius:12px; background:#f8fafc; }
            .order-collection-setup .order-collection-toggle { display:flex; align-items:center; gap:10px; min-height:44px; font-weight:700; }
            .order-collection-toggle input { width:22px; height:22px; flex:none; }
            .order-collection-setup fieldset { display:grid; gap:8px; border:0; padding:0; margin:12px 0 0; min-width:0; }
            .order-collection-setup [hidden] { display:none !important; }
            .order-collection-setup input:not([type=checkbox]):not([type=hidden]), .order-collection-setup select, .order-collection-setup textarea { width:100%; min-height:44px; padding:10px; box-sizing:border-box; font-size:16px; }
            .order-collection-setup p { margin:4px 0; }
        </style>
        @push('scripts')
            <script src="{{ asset('js/phos-order-collection.js') }}?v={{ hash_file('sha256', public_path('js/phos-order-collection.js')) }}" defer></script>
        @endpush
    @endonce
@endif
