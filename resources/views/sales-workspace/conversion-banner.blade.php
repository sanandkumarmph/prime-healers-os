@if($conversionQuote = request()->attributes->get('conversion_quote'))
<div class="alert alert-info" role="status" style="margin:16px 0;padding:16px;border:1px solid #b9d1ed;border-radius:10px;background:#eff6ff">
    <strong>Creating an order from {{ $conversionQuote->number() }}</strong>
    <p>Prices, products, rental dates and customer must match the accepted quotation. Complete the stock and delivery details below. Stock is checked when you save.</p>
    <a href="{{ route('quotations.show', $conversionQuote) }}">Back to quotation</a>
</div>
<input type="hidden" name="quotation_id" value="{{ $conversionQuote->id }}">
<input type="hidden" name="quotation_version" value="{{ old('quotation_version', $conversionQuote->lock_version) }}">
@endif
@error('quotation_id')<p role="alert" class="field-error">{{ $message }}</p>@enderror
