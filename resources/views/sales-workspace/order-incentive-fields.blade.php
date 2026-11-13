@php
    $credit = app(\App\Services\Sales\OrderIncentiveCredit::class);
    $salesOwners = $credit->salesStaff(auth()->user());
    $handoffOwner = $credit->handoffOwner(request(), $salesOwners);
    $selectedOwner = (int) old('incentive_owner_id', $credit->defaultOwner(request(), $salesOwners));
    $orderQuote = request()->attributes->get('conversion_quote');
@endphp
<fieldset style="border:1px solid #dbe4ee;border-radius:10px;padding:12px;margin:12px 0;min-width:0">
    <legend style="font-weight:700">Order ownership</legend>
    <div class="{{ $fieldClass }}">
        <label for="incentive_owner_id">Order owner</label>
        @if($handoffOwner !== null)
            <input id="incentive_owner_id" value="{{ $salesOwners->get($handoffOwner)->name }}" readonly>
        @else
            <select name="incentive_owner_id" id="incentive_owner_id">
                <option value="">Choose order owner</option>
                @if(auth()->user()->canViewConsolidatedFinance())
                    <option value="{{ auth()->id() }}" @selected($selectedOwner === (int) auth()->id())>Myself</option>
                @endif
                @foreach($salesOwners as $id => $owner)
                    <option value="{{ $id }}" @selected($selectedOwner === (int) $id)>{{ (int) auth()->id() === (int) $id ? 'Myself' : $owner->name }}</option>
                @endforeach
            </select>
        @endif
        @error('incentive_owner_id')<span role="alert">{{ $message }}</span>@enderror
    </div>
    @if($handoffOwner === null)
        <p>Choose the salesperson who first worked with the customer.</p>
    @endif
    @if(auth()->user()->canViewConsolidatedFinance())
        <p>Choose Myself for an admin-owned order.</p>
    @endif
    @if($orderQuote)
        <p>Enquiry source · {{ $orderQuote->enquiry->source_label }}</p>
    @else
        <div class="{{ $fieldClass }}">
            <label for="incentive_source">Enquiry source</label>
            <select name="incentive_source" id="incentive_source">
                <option value="">Choose the original source</option>
                @foreach(\App\Models\Enquiry::SOURCES as $key => $label)
                    <option value="{{ $key }}" @selected(old('incentive_source') === $key)>{{ $label }}</option>
                @endforeach
            </select>
            <small>Required when assigning a direct booking to a salesperson.</small>
            @error('incentive_source')<span role="alert">{{ $message }}</span>@enderror
        </div>
    @endif
</fieldset>
