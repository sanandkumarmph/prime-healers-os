@if($conversionQuote = request()->attributes->get('conversion_quote'))
    @include('sales-workspace.style')
    <div class="sw" style="max-width:none;padding:0">
        @include('sales-workspace.workflow-navigation', [
            'workflowEnquiry' => $conversionQuote->enquiry, 'workflowStep' => 'order',
            'workflowQuotationUrl' => route('quotations.show', $conversionQuote),
            'workflowOrderUrl' => route($conversionQuote->kind.'s.create', ['quotation_id' => $conversionQuote->id]),
        ])
    </div>
<div class="alert alert-info" role="status" style="margin:16px 0;padding:16px;border:1px solid #b9d1ed;border-radius:10px;background:#eff6ff">
    <strong>Creating an order from {{ $conversionQuote->number() }}</strong>
    <p>Prices, products and rental dates must match the accepted quotation. Complete the stock and delivery details below. Stock is checked when you save.</p>
    @if(!$conversionQuote->snapshot['customer_id'])
    <p>The enquiry has been accepted. Select an existing customer or use Add customer below to register the confirmed customer and complete their billing / delivery address.</p>
    <label style="display:flex;gap:8px;align-items:flex-start"><input type="checkbox" name="quotation_recipient_confirmed" value="1" @checked(old('quotation_recipient_confirmed')) required> I confirm the selected customer is the billing recipient for this accepted quotation.</label>
    @error('quotation_recipient_confirmed')<p role="alert" class="field-error">{{ $message }}</p>@enderror
    @else<p>The customer must match the accepted quotation.</p>@endif
    <a href="{{ route('quotations.show', $conversionQuote) }}">Back to quotation</a>
</div>
<input type="hidden" name="quotation_id" value="{{ $conversionQuote->id }}">
<input type="hidden" name="quotation_version" value="{{ old('quotation_version', $conversionQuote->lock_version) }}">
@endif
@error('quotation_id')<p role="alert" class="field-error">{{ $message }}</p>@enderror
