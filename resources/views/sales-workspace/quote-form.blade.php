@extends('layouts.app')
@section('content')
@include('sales-workspace.style')
@php
    $s = $quote?->snapshot ?? [];
    $lines = array_values(array_filter((array) old('lines',$s['lines'] ?? []),'is_array'));
    if (!$lines) $lines = [['product_id'=>'','quantity'=>1,'rate'=>0,'tax_rate'=>0,'tax_mode'=>'inclusive']];
    $originalKind = $quote?->kind ?? $enquiry->intent;
    $kind = old('kind',$originalKind ?: 'rental');
    $productData = $products->mapWithKeys(fn($p)=>[$p->id=>['name'=>$p->name,'rental'=>(float)($p->rental_price ?: $p->rental_price_30_days ?: 0),'sale'=>(float)$p->sale_price,'tax_rate'=>$p->defaultGstRate(),'tax_mode'=>$p->defaultGstMode(),'can_rent'=>$p->canRent(),'can_sell'=>$p->canSell()]]);
    $selectedRecipient = old('customer_id',$s['customer_id'] ?? $enquiry->customer_id);
@endphp
<div class="sw">
    <a href="{{ route('enquiries.show',$enquiry) }}">← Back to enquiry</a>
    <header class="sw-head"><div><div class="sw-eyebrow">{{ $quote?->number() ?? 'New quotation' }}</div><h1>Prepare quotation</h1><p class="sw-muted">{{ $enquiry->subject }} · Contact: {{ $enquiry->name }}</p></div>@if($quote)<a class="sw-btn" id="saved-draft-pdf" href="{{ route('quotations.pdf',$quote) }}">Saved draft PDF</a>@endif</header>
    @include('sales-workspace.alerts')
    <p class="sw-guide"><strong>Next:</strong> Confirm the recipient and prices, then review before sharing.</p>
    <form method="post" action="{{ $quote ? route('quotations.update',$quote) : route('quotations.store',$enquiry) }}" id="quotation-form" data-original-kind="{{ $originalKind }}">
        @csrf
        @if($quote)@method('PUT')<input type="hidden" name="lock_version" value="{{ old('lock_version',$quote->lock_version) }}">@endif
        <input type="hidden" name="enquiry_lock_version" value="{{ old('enquiry_lock_version',$enquiry->lock_version) }}">
        <input type="hidden" name="pricing_kind" value="{{ old('pricing_kind',$originalKind ?: $kind) }}">
        <input type="hidden" name="kind_confirmed" value="{{ old('kind_confirmed',0) }}">
        <div class="sw-grid sw-editor">
            <div>
                <section class="sw-panel">
                    <details class="sw-recipient" @if(!$selectedRecipient || $errors->has('customer_id')) open @endif>
                        <summary>Quotation addressed to <span class="sw-meta" id="recipient-label">{{ $customers->firstWhere('id',(int)$selectedRecipient)?->name ?: 'Select or add customer' }} · Change</span></summary>
                        <p class="sw-meta">Enquiry contact: {{ $enquiry->name }}{{ $enquiry->phone ? ' · '.$enquiry->phone : '' }}</p>
                        @include('sales-workspace.customer-picker')
                    </details>
                </section>
                <section class="sw-panel">
                    <div class="sw-fields">
                        <div class="sw-field"><label for="kind">Order type *</label><select id="kind" name="kind"><option value="rental" @selected($kind==='rental')>Rental</option><option value="sale" @selected($kind==='sale')>Sale</option></select>@error('kind')<span class="sw-overdue">{{ $message }}</span>@enderror</div>
                        <div class="sw-field"><label for="valid_until">Valid until *</label><input id="valid_until" name="valid_until" type="date" value="{{ old('valid_until',$quote?->valid_until->toDateString() ?? now(config('app.timezone'))->addDays(7)->toDateString()) }}" required></div>
                        <div class="sw-field" data-rental-dates><label for="start_date">Rental start *</label><input type="date" name="start_date" id="start_date" value="{{ old('start_date',$s['start_date'] ?? '') }}"></div>
                        <div class="sw-field" data-rental-dates><label for="end_date">Rental end *</label><input type="date" name="end_date" id="end_date" value="{{ old('end_date',$s['end_date'] ?? '') }}"></div>
                    </div>
                    <div id="price-review" class="sw-alert" hidden role="status">
                        <strong>Order type changed. Review the prices.</strong>
                        <p>Your entered prices are unchanged. Choose how to continue; unavailable products must be replaced.</p>
                        <div class="sw-actions"><button type="button" class="sw-btn" id="use-kind-prices">Use product prices</button><button type="button" class="sw-btn" id="keep-kind-prices">Keep entered prices</button></div>
                    </div>
                </section>
                <section class="sw-panel">
                    <h2>Products & pricing</h2>
                    <p class="sw-meta" id="rental-rate-hint">Price per unit for the entire rental period. Confirm the price when changing dates.</p>
                    <div id="quotation-lines">@foreach($lines as $i=>$line)@include('sales-workspace.quote-line',['i'=>$i,'line'=>$line])@endforeach</div>
                    <p id="line-status" role="status" class="sw-meta"></p>
                    <button class="sw-btn sw-small" type="button" id="add-quote-line">+ Add product</button>
                    <div data-rental-dates style="margin-top:16px">
                        <button type="button" class="sw-btn" data-quote-availability="{{ route('availability.check') }}">Check availability for these dates</button>
                        <a href="{{ route('availability.index') }}" target="_blank" rel="noopener" class="sw-meta">Check by warehouse ↗</a>
                        <div data-quote-availability-results role="status" style="margin-top:12px"></div>
                        <p class="sw-meta">Readiness is checked again when creating the order. Forecasts do not reserve stock.</p>
                    </div>
                </section>
                <section class="sw-panel"><details @if($errors->has('terms')) open @endif><summary>Terms & conditions <span class="sw-meta">Included in the quotation · Edit</span></summary><div class="sw-field"><label for="terms">Customer terms</label><textarea id="terms" name="terms" rows="4" maxlength="12000">{{ old('terms',$s['terms'] ?? $enquiry->organization->default_terms) }}</textarea></div></details></section>
            </div>
            <aside><section class="sw-panel sw-sticky-summary">
                <h2>Summary <span class="sw-meta">{{ $enquiry->organization->currency_code ?: 'INR' }}</span></h2>
                <p id="quote-recipient" class="sw-muted">{{ $customers->firstWhere('id',(int)$selectedRecipient)?->name ?: 'Choose a recipient' }}</p>
                <div class="sw-field"><label for="transport">Delivery / transport</label><input id="transport" type="number" name="transport" value="{{ old('transport',$s['transport'] ?? 0) }}" min="0" max="99999999.99" step="0.01" required></div>
                <div class="sw-field" data-rental-dates style="margin-top:10px"><label for="deposit">Refundable deposit</label><input id="deposit" type="number" name="deposit" value="{{ old('deposit',$s['deposit'] ?? 0) }}" min="0" max="99999999.99" step="0.01" required></div>
                <details><summary>Tax settings</summary><div class="sw-field"><label for="tax_type">Tax split</label><select id="tax_type" name="tax_type"><option value="cgst_sgst" @selected(old('tax_type',$s['tax_type'] ?? 'cgst_sgst')==='cgst_sgst')>CGST + SGST</option><option value="igst" @selected(old('tax_type',$s['tax_type'] ?? '')==='igst')>IGST / single tax</option></select></div></details>
                <div class="sw-totals"><div><span>Taxable amount</span><strong id="preview-subtotal">—</strong></div><div><span>Tax</span><strong id="preview-tax">—</strong></div><div class="sw-grand"><span>Total</span><span id="preview-total">—</span></div><div data-rental-dates><span>With deposit</span><strong id="preview-payable">—</strong></div></div>
                <p class="sw-meta">Stock and delivery are confirmed when creating the order.</p>
                <div class="sw-editor-actions"><button class="sw-btn sw-primary" name="next" value="review">Review & share</button><button class="sw-btn" name="next" value="save">Save draft</button></div>
                <p id="draft-status" class="sw-meta" role="status">{{ $quote ? 'Saved draft' : 'Not saved yet' }}</p>
            </section></aside>
        </div>
    </form>
    <template id="quote-line-template">@include('sales-workspace.quote-line',['i'=>'__INDEX__','line'=>['product_id'=>'','quantity'=>1,'rate'=>0,'tax_rate'=>0,'tax_mode'=>'inclusive']])</template>
    @if($quote && $quote->snapshot['customer_id'])
    <dialog id="quotation-review" class="sw-review" aria-labelledby="review-title" data-open="{{ session('review_ready') ? '1' : '0' }}">
        <header class="sw-review-head"><div><h2 id="review-title">Review quotation</h2><p class="sw-meta">Check the recipient, order type and total.</p></div><button type="button" class="sw-btn" data-close-review>Back to editing</button></header>
        @include('sales-workspace.quote-document')
        <footer class="sw-review-footer"><div><strong>Total: {{ $s['currency'] }} {{ number_format($s['total'],2) }}</strong>@if($quote->kind==='rental')<span class="sw-meta"> · With deposit {{ number_format($s['payable_with_deposit'],2) }}</span>@endif<p class="sw-meta">Creating the link locks this revision. Later changes need a new revision.</p></div><form method="post" action="{{ route('quotations.action',$quote) }}">@csrf<input type="hidden" name="lock_version" value="{{ $quote->lock_version }}"><input type="hidden" name="action" value="send"><button class="sw-btn sw-primary" id="finalise-quote">Create sharing link</button></form></footer>
    </dialog>
    @endif
</div>
@if(auth()->user()->canAccessModule('customers','create'))
@include('customers.partials.quick-create-modal', ['quickModalId'=>'salesQuickCustomerModal','quickFormId'=>'salesQuickCustomerForm','quickSelectTarget'=>'customer_id','quickPrefill'=>['name'=>$enquiry->name,'phone'=>$enquiry->phone,'email'=>$enquiry->email]])
@endif
@endsection
@push('scripts')
@include('sales-workspace.customer-script')
@include('sales-workspace.quotation-script')
@endpush
