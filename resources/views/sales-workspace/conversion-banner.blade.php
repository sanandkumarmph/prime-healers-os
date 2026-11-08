@if($conversionQuote = request()->attributes->get('conversion_quote'))
    @include('sales-workspace.style')
    <div class="sw" style="max-width:none;padding:0">
        @include('sales-workspace.workflow-navigation', [
            'workflowEnquiry' => $conversionQuote->enquiry, 'workflowStep' => 'order',
            'workflowQuotationUrl' => route('quotations.show', $conversionQuote),
            'workflowOrderUrl' => route($conversionQuote->kind.'s.create', ['quotation_id' => $conversionQuote->id]),
        ])
    </div>
<div class="alert alert-info" role="status" style="margin:10px 0;padding:10px 12px;border:1px solid #b9d1ed;border-radius:10px;background:#eff6ff">
    <strong>Creating an order from {{ $conversionQuote->number() }}</strong>
    <p style="margin:4px 0 8px">Products and prices are filled in. Confirm the customer, stock and delivery.</p>
    @if(!$conversionQuote->snapshot['customer_id'])
    <label style="display:flex;gap:8px;align-items:flex-start;font-size:12px"><input type="checkbox" name="quotation_recipient_confirmed" value="1" @checked(old('quotation_recipient_confirmed')) required> This customer is the billing recipient for the quotation.</label>
    @error('quotation_recipient_confirmed')<p role="alert" class="field-error">{{ $message }}</p>@enderror
    @endif
    <a href="{{ route('quotations.show', $conversionQuote) }}">Back to quotation</a>
</div>
<input type="hidden" name="quotation_id" value="{{ $conversionQuote->id }}">
<input type="hidden" name="quotation_version" value="{{ old('quotation_version', $conversionQuote->lock_version) }}">
@endif
@error('quotation_id')<p role="alert" class="field-error">{{ $message }}</p>@enderror
