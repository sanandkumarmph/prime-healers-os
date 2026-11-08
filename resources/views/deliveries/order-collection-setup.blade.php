@php
    $deliveryCollectionAllowed = !$isEdit && app(\App\Services\Deliveries\OrderCollectionSetup::class)->allowed(auth()->user(), $orderKind === 'sale' ? 'sales' : 'rentals');
    $salesCollectionAvailable = !$isEdit && ($collectionAssignees ?? collect())->isNotEmpty();
    $collectionOwner = old('collection_owner', old('collection_enabled') ? 'delivery' : ((old('collection_mode') || old('collection_assignee_id')) ? 'sales' : 'none'));
@endphp
@if($deliveryCollectionAllowed || $salesCollectionAvailable)
    <section class="rental-col-12 sales-col-12 order-collection-setup" data-order-collection="{{ $orderKind }}" data-manual-amount="{{ old('collection_amount') !== null ? '1' : '0' }}">
        <h3>Payment collection</h3>
        <div class="order-collection-field">
            <label for="collection_owner">Who will collect the unpaid amount?</label>
            <select id="collection_owner" name="collection_owner" data-collection-owner>
                <option value="none" @selected($collectionOwner === 'none')>No collection assignment</option>
                @if($deliveryCollectionAllowed)<option value="delivery" data-delivery-collection-option @selected($collectionOwner === 'delivery')>Collect payment on delivery · Internal Delivery team</option>@endif
                @if($salesCollectionAvailable)<option value="sales" @selected($collectionOwner === 'sales')>Assign collection to Sales</option>@endif
            </select>
        </div>
        <p class="order-collection-help">This assigns a collection task. Record payment after receiving the money.</p>
        @if($deliveryCollectionAllowed)
        <fieldset data-order-collection-fields @if($collectionOwner !== 'delivery') hidden disabled @endif>
            <input type="checkbox" id="collection_enabled" name="collection_enabled" value="1" @checked($collectionOwner === 'delivery') hidden>
            <input type="hidden" name="collection_submission_token" value="{{ old('collection_submission_token', (string) \Illuminate\Support\Str::uuid()) }}">
            @if($orderKind === 'sale')
                <div class="order-collection-field"><label for="delivery_operator_id">Delivery operator</label>
                <select id="delivery_operator_id" name="delivery_operator_id"><option value="">Select internal Delivery staff</option>
                    @foreach($collectionOperators as $operator)<option value="{{ $operator->id }}" @selected((int)old('delivery_operator_id') === (int)$operator->id)>{{ $operator->name }}</option>@endforeach
                </select></div>
            @else
                <p class="order-collection-help">The internal Delivery operator selected above will collect it.</p>
            @endif
            <div class="order-collection-field"><label for="collection_amount">Amount to collect</label>
            <input type="number" id="collection_amount" name="collection_amount" min="0.01" max="99999999.99" step="0.01" inputmode="decimal" value="{{ old('collection_amount') }}" aria-describedby="orderCollectionSuggestion"></div>
            <p id="orderCollectionSuggestion" data-collection-suggestion></p>
            <div class="order-collection-field"><label for="collection_methods">Allowed methods</label>
            <select id="collection_methods" name="collection_methods">@foreach(\App\Services\Deliveries\OrderCollectionSetup::METHODS as $value => $label)<option value="{{ $value }}" @selected(old('collection_methods', 'cash') === $value)>{{ $label }}</option>@endforeach</select></div>
            <div class="order-collection-field order-collection-full"><label for="collection_instructions">Short instructions (optional)</label><textarea id="collection_instructions" name="collection_instructions" maxlength="1000" rows="2">{{ old('collection_instructions') }}</textarea></div>
        </fieldset>
        @endif
        @if($salesCollectionAvailable)
        <fieldset class="order-collection-sales" data-sales-collection-fields @if($collectionOwner !== 'sales') hidden disabled @endif>
            @include('components.order-collection-assignment', ['fieldClass'=>'order-collection-field','errorClass'=>'field-error'])
            <p class="order-collection-help order-collection-full">The selected salesperson will receive a follow-up for the outstanding balance.</p>
        </fieldset>
        @endif
        <p class="field-error" role="alert" data-order-collection-error hidden></p>
        @foreach(['collection_owner','collection_enabled','collection_amount','collection_methods','collection_instructions','delivery_operator_id','collection_submission_token'] as $field)
            @error($field)<p class="field-error" role="alert">{{ $message }}</p>@enderror
        @endforeach
    </section>
    @once
        <style>
            .order-collection-setup{min-width:0;padding:14px;border:1px solid #dce4ef;border-radius:12px;background:#f8fafc}
            .order-collection-setup h3{font-size:15px;margin:0 0 10px}.order-collection-setup .order-collection-field{min-width:0;display:grid;gap:5px}
            .order-collection-setup label{font-size:12px;font-weight:650}.order-collection-setup fieldset{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;border:0;padding:0;margin:12px 0 0;min-width:0}
            .order-collection-setup [hidden]{display:none!important}.order-collection-setup fieldset>*{min-width:0}
            .order-collection-setup input:not([type=checkbox]):not([type=hidden]),.order-collection-setup select,.order-collection-setup textarea{width:100%;min-height:42px;padding:8px 10px;border:1px solid #cbd5e1;border-radius:8px;background:#fff;color:#172b4d;box-sizing:border-box;font-size:14px}
            .order-collection-setup p{margin:4px 0;font-size:12px}.order-collection-help{color:#64748b}.order-collection-full,.order-collection-setup [data-collection-suggestion]{grid-column:1/-1}
            @media(max-width:600px){.order-collection-setup fieldset{grid-template-columns:1fr}.order-collection-setup input:not([type=checkbox]):not([type=hidden]),.order-collection-setup select,.order-collection-setup textarea{font-size:16px}}
        </style>
        @push('scripts')<script src="{{ asset('js/phos-order-collection.js') }}?v={{ hash_file('sha256', public_path('js/phos-order-collection.js')) }}" defer></script>@endpush
    @endonce
@endif
