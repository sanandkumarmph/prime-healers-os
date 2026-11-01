@extends('layouts.app')
@section('content')
@include('sales-workspace.style')
@php
    $canEdit = auth()->user()->canAccessModule('quotations','update') && auth()->user()->canAccessModule('enquiries','update');
    $shareText = $quote->snapshot['issuer']['name'].' — quotation '.$quote->number().': '.$shareUrl;
    $email = filter_var($quote->snapshot['recipient']['email'] ?? '', FILTER_VALIDATE_EMAIL) ?: '';
@endphp
<div class="sw"><a href="{{ route('enquiries.show',$enquiry) }}">← Back to enquiry</a><header class="sw-head" style="margin-top:20px"><div><div class="sw-eyebrow">Quotation workspace</div><h1>{{ $quote->number() }}</h1><p class="sw-muted">{{ $enquiry->subject }}</p></div><div class="sw-actions"><a class="sw-btn" href="{{ route('quotations.pdf',$quote) }}">Download PDF</a>
@if($canEdit && !$enquiry->closed())@if($quote->status==='draft')<a class="sw-btn" href="{{ route('quotations.edit',$quote) }}">Edit draft</a><form method="post" action="{{ route('quotations.action',$quote) }}">@csrf<input type="hidden" name="lock_version" value="{{ $quote->lock_version }}"><input type="hidden" name="action" value="send"><button class="sw-btn sw-primary">Finalise & share</button></form>@elseif($quote->status==='accepted' && !$quote->expired() && auth()->user()->canAccessModule($quote->kind.'s','create'))<a class="sw-btn sw-primary" href="{{ route($quote->kind.'s.create',['quotation_id'=>$quote->id]) }}">Create {{ $quote->kind }} order</a>@endif @endif
@if($quote->status==='converted')@php($orderClass=$quote->order_type==='rental' ? \App\Models\Rental::class : \App\Models\Sale::class)@php($linkedOrder=$orderClass::where('organization_id',$quote->organization_id)->find($quote->order_id))@if($linkedOrder && auth()->user()->can('view',$linkedOrder))<a class="sw-btn sw-primary" href="{{ route($quote->order_type.'s.show',$quote->order_id) }}">View {{ $quote->order_type }} #{{ $quote->order_id }}</a>@endif @endif</div></header>
@include('sales-workspace.alerts')
@if($quote->status==='draft')<div class="sw-alert">Review the customer, prices and terms before finalising. Finalised quotations are protected; changes require a new revision.</div>@elseif($quote->status==='superseded')<div class="sw-alert">This is a previous revision. <a href="{{ route('quotations.show',$enquiry->quotations()->latest('revision')->first()) }}">Open the latest quotation</a>.</div>@elseif($quote->expired())<div class="sw-alert">This quotation has expired. Create a revision with a new validity date before continuing.</div>@endif
<div class="{{ $canEdit && !$enquiry->closed() && $quote->status!=='superseded' ? 'sw-grid sw-share-workspace' : 'sw-document-only' }}"><div>@include('sales-workspace.quote-document')</div><aside>
@if($shareUrl && $canEdit && !$enquiry->closed())

<section class="sw-panel"><h2>{{ $quote->shared_at ? 'Shared with customer' : ($quote->status==='sent' ? 'Ready to share' : 'Share quotation') }}</h2>
    <p class="sw-muted">Open a message, check the recipient, then send it from your app.</p>
    <div class="sw-actions">
        <a class="sw-btn sw-primary" href="https://wa.me/?text={{ rawurlencode($shareText) }}" target="_blank" rel="noopener noreferrer" data-share-channel="whatsapp">WhatsApp</a>
        <a class="sw-btn" href="mailto:{{ $email }}?subject={{ rawurlencode('Quotation '.$quote->number()) }}&amp;body={{ rawurlencode($shareText) }}" data-share-channel="email">Email</a>
        <button class="sw-btn" type="button" id="copy-customer-link">Copy link</button>
    </div>
    <div class="sw-field" style="margin-top:12px"><label for="customer-link">Private customer link</label><input id="customer-link" value="{{ $shareUrl }}" readonly></div>
    <p id="copy-status" class="sw-meta" role="status"></p>
    @if(!$quote->shared_at && $quote->status==='sent')
    <form method="post" action="{{ route('quotations.action',$quote) }}" class="sw-share-confirm">@csrf
        <input type="hidden" name="lock_version" value="{{ $quote->lock_version }}"><input type="hidden" name="action" value="mark_shared">
        <div class="sw-field"><label for="shared-channel">Sent using</label><select id="shared-channel" name="shared_channel"><option value="whatsapp">WhatsApp</option><option value="email">Email</option><option value="other">Other / PDF</option></select></div>
        <button class="sw-btn" type="submit">I have sent this quotation</button>
        <p class="sw-meta">Opening a message or copying the link does not mark it as sent.</p>
    </form>
    @elseif($quote->shared_at)<p class="sw-meta">Marked as sent via {{ $quote->shared_channel }} on {{ $quote->shared_at->timezone(config('app.timezone'))->format('d M, H:i') }}.</p>@endif
    <details><summary>Link details</summary><p class="sw-meta">Expires {{ $quote->expiresAt()->timezone(config('app.timezone'))->format('d M Y, h:i A') }} IST. Anyone with the link can view and respond.</p><p class="sw-meta">{{ $quote->viewed_at ? 'Link first opened: '.$quote->viewed_at->timezone(config('app.timezone'))->format('d M, H:i') : 'Customer link not opened yet' }}. Opening a link is not proof of customer identity.</p></details>
</section>
@if($quote->status==='sent')<section class="sw-panel"><h2>Follow-up <span class="sw-meta">Optional</span></h2>
@php($nextFollowUp=$enquiry->followUps()->whereNull('completed_at')->orderBy('due_at')->first())
@if($nextFollowUp)<p class="sw-meta">Next: {{ $nextFollowUp->subject }} · {{ $nextFollowUp->due_at->timezone(config('app.timezone'))->format('d M, H:i') }} <a href="{{ route('enquiries.show',$enquiry) }}">Manage</a></p>
@else @include('sales-workspace.follow-up-form') @endif
</section>@endif
@endif
@if($canEdit && $quote->status==='sent' && !$quote->expired())<section class="sw-panel"><details @if($errors->has('contact_name') || $errors->has('note')) open @endif><summary>Record a response</summary><p class="sw-muted">Use this when the customer confirms by phone, email or in person. Their response through the link is recorded automatically.</p><form method="post" action="{{ route('quotations.action',$quote) }}">@csrf<input type="hidden" name="lock_version" value="{{ $quote->lock_version }}"><div class="sw-field"><label for="response">Response</label><select id="response" name="action"><option value="accepted">Accepted</option><option value="declined">Declined</option></select></div><div class="sw-field" style="margin-top:12px"><label for="contact-name">Customer contact name *</label><input name="contact_name" id="contact-name" required maxlength="150" value="{{ old('contact_name') }}"></div><div class="sw-field" style="margin-top:12px"><label for="response-note">How was this confirmed? *</label><textarea name="note" id="response-note" required rows="3" maxlength="3000" placeholder="e.g. Confirmed by email on 24 October">{{ old('note') }}</textarea></div><button class="sw-btn sw-primary" style="margin-top:14px">Record response</button></form></details></section>@endif
@if($canEdit && !$enquiry->closed() && !in_array($quote->status,['draft','superseded','converted']))<section class="sw-panel"><details><summary>Revise or withdraw</summary>@if(auth()->user()->canAccessModule('quotations','create'))<p class="sw-muted">Create a revision to update products, prices, dates or terms. The current link and any acceptance will stop being valid.</p><form method="post" action="{{ route('quotations.action',$quote) }}" onsubmit="return confirm('Create a new draft revision? The current customer link and acceptance will no longer be valid.')">@csrf<input type="hidden" name="lock_version" value="{{ $quote->lock_version }}"><input type="hidden" name="action" value="revise"><button class="sw-btn">Create revision</button></form>@endif
@if(in_array($quote->status,['sent','accepted']))<details><summary>Withdraw quotation</summary><form method="post" action="{{ route('quotations.action',$quote) }}">@csrf<input type="hidden" name="lock_version" value="{{ $quote->lock_version }}"><input type="hidden" name="action" value="withdraw"><div class="sw-field"><label for="withdraw-note">Reason *</label><textarea name="note" id="withdraw-note" required maxlength="3000" rows="2"></textarea></div><button class="sw-btn sw-danger" style="margin-top:12px">Withdraw & disable link</button></form></details>@endif</details></section>@endif
</aside></div></div>
@endsection
@push('scripts')<script>document.getElementById('copy-customer-link')?.addEventListener('click',async()=>{const input=document.getElementById('customer-link');try{await navigator.clipboard.writeText(input.value);document.getElementById('copy-status').textContent='Link copied. Send it to the customer, then confirm below.';}catch(error){input.focus();input.select();document.getElementById('copy-status').textContent='Select and copy the link above.';}});document.querySelectorAll('[data-share-channel]').forEach(link=>link.addEventListener('click',()=>{const select=document.getElementById('shared-channel');if(select)select.value=link.dataset.shareChannel;}));</script>@endpush
