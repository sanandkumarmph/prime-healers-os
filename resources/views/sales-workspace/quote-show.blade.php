@extends('layouts.app')
@section('content')
@include('sales-workspace.style')
<style>
.sw .sw-quote-share{padding:14px}.sw-quote-share h2{font-size:16px;margin-bottom:5px}.sw-share-step{padding:11px 0;border-bottom:1px solid #e0e7ef}.sw-share-step:last-child{border-bottom:0;padding-bottom:0}.sw-share-step h3{display:flex;gap:7px;align-items:center;font-size:13px;margin-bottom:5px}.sw-share-step h3>span{display:inline-grid;place-items:center;width:20px;height:20px;border-radius:6px;background:#eaf0ff;color:#2558c8;font-size:11px}.sw-share-step .sw-meta{font-size:11px;line-height:1.5;margin:4px 0 8px}.sw-share-step .sw-actions{gap:6px}.sw-share-step .sw-btn{font-size:11px;min-height:34px;padding:6px 9px}.sw-share-step details{margin-top:8px;padding-top:8px}.sw-share-step summary{font-size:11px;margin-bottom:8px}.sw-share-step .sw-share-confirm{margin-top:0;padding-top:0;border:0}.sw-share-step .sw-share-confirm button{margin-top:7px}.sw-share-step .sw-terms-preview .sw-terms-toolbar .sw-btn{font-size:11px}.sw-share-step .sw-terms-preview>summary{font-size:11px}.sw-share-step [data-whatsapp-status]{flex-basis:100%}
@media(max-width:600px){.sw-share-step .sw-btn{min-height:40px}.sw-share-step :is(input,select,textarea){font-size:16px}}
</style>
@php
    $canEdit = auth()->user()->canAccessModule('quotations','update') && auth()->user()->canAccessModule('enquiries','update');
    $shareText = $shareUrl ? app(\App\Services\Sales\QuotationSharing::class)->message($quote, $shareUrl) : '';
    $whatsappPhone = preg_replace('/\D/', '', \App\Support\PhoneNumber::normalize($quote->snapshot['recipient']['phone'] ?? null) ?? '');
    $email = filter_var($quote->snapshot['recipient']['email'] ?? '', FILTER_VALIDATE_EMAIL) ?: '';
    $agreements = app(\App\Services\Sales\RentalAgreements::class);
    $salesNextAction = app(\App\Services\Sales\SalesNextAction::class)->for(auth()->user(),$enquiry,$quote,$rentalAgreement);
    $requiresRentalSignature = $quote->kind === 'rental' && \App\Services\Sales\RentalAgreements::enabled();
@endphp
<div class="sw" data-quote-live="{{ $quote->id }}" data-live-page="quotation" data-live-status-url="{{ route('quotations.status',$quote) }}"><a href="{{ route('enquiries.show',$enquiry) }}">← Back to enquiry</a><header class="sw-head" style="margin-top:20px"><div><div class="sw-eyebrow">Quotation workspace</div><h1>{{ $quote->number() }}</h1><p class="sw-muted">{{ $enquiry->subject }}</p></div><div class="sw-actions"><a class="sw-btn" href="{{ route('quotations.pdf',$quote) }}">Download PDF</a>

@if($canEdit && !$enquiry->closed() && auth()->user()->canAccessModule('quotations','create') && !in_array($quote->status,['draft','superseded','converted']))<form method="post" action="{{ route('quotations.action',$quote) }}" id="quotation-revision" data-edit-quotation onsubmit="return confirm('Create an editable revision? The current acceptance and signing link will no longer apply. The customer must accept and sign the revised quotation again.')">@csrf<input type="hidden" name="lock_version" value="{{ $quote->lock_version }}"><input type="hidden" name="action" value="revise"><button class="sw-btn" type="submit" data-guided-execute="draft_quote">Edit quotation</button></form>@endif
</div></header>
@include('sales-workspace.workflow-navigation', [
    'workflowEnquiry' => $enquiry, 'workflowQuotationUrl' => route('quotations.show', $latestQuote),
    'workflowOrderUrl' => $orderAction['url'] ?? null, 'workflowStep' => 'quotation',
])
@include('sales-workspace.alerts')
@include('sales-workspace.live-status',['liveQuote'=>$quote,'liveAgreement'=>$rentalAgreement])
@include('sales-workspace.next-action')
@if($quote->status==='draft')<div class="sw-alert">Review the customer, prices and terms before finalising. Finalised quotations are protected; changes require a new revision.</div>@elseif($quote->status==='superseded')<div class="sw-alert">This is a previous revision. <a href="{{ route('quotations.show',$enquiry->quotations()->latest('revision')->first()) }}">Open the latest quotation</a>.</div>@elseif($quote->expired())<div class="sw-alert">This quotation has expired. Create a revision with a new validity date before continuing.</div>@endif
<div class="{{ $canEdit && !$enquiry->closed() && $quote->status!=='superseded' ? 'sw-grid sw-share-workspace' : 'sw-document-only' }}"><div>@include('sales-workspace.quote-document')</div><aside>
@if($shareUrl && $canEdit && !$enquiry->closed())

<section class="sw-panel sw-quote-share" data-quotation-sharing>
    <h2>{{ $quote->shared_at ? 'Shared with customer' : 'Ready to share' }}</h2>
    <details class="sw-share-step sw-guided-section" id="quotation-share" data-sales-section="quotation-share" data-share-step="quotation" @if($salesNextAction['section']==='quotation-share') open @endif>
        <summary>Send quotation <span class="sw-meta" data-live-share-label>{{ $quote->shared_at ? 'Shared' : 'Ready to share' }}</span></summary>
        <p class="sw-meta">Share the prices. Review the message, then send it to the customer.</p>
        <div class="sw-actions">
            <a class="sw-btn" href="https://wa.me/{{ $whatsappPhone }}?text={{ rawurlencode($shareText) }}" target="_blank" rel="noopener noreferrer" data-share-channel="whatsapp" data-phos-whatsapp data-guided-execute="share_quote">WhatsApp</a>
            <button class="sw-btn" type="button" data-copy-whatsapp>Copy quotation message</button>
        </div>
        <details class="sw-share-options"><summary>Preview / other ways to share</summary>
            <div class="sw-actions"><a class="sw-btn sw-small" href="mailto:{{ $email }}?subject={{ rawurlencode('Quotation '.$quote->number()) }}&amp;body={{ rawurlencode($shareText) }}" data-share-channel="email">Email</a><button class="sw-btn sw-small" type="button" id="copy-customer-link">Copy link</button></div>
            <div class="sw-field" id="customer-link-field" hidden><label for="customer-link">Quotation link</label><input id="customer-link" value="{{ $shareUrl }}" readonly></div>
            <div class="sw-field" style="margin-top:8px"><label for="quote-message">Quotation message and sender sign-off</label><textarea id="quote-message" rows="7" readonly>{{ $shareText }}</textarea></div>
            <button type="button" class="sw-btn sw-small" id="copy-quote-message">Copy quotation text</button><p class="sw-meta" id="message-copy-status" role="status"></p><p id="copy-status" class="sw-meta" role="status"></p>
        </details>
        @if(!$quote->shared_at && $quote->status==='sent')
            <details class="sw-share-options"><summary>Already sent? Mark it as sent</summary>
                <form method="post" action="{{ route('quotations.action',$quote) }}" class="sw-share-confirm">@csrf
                    <input type="hidden" name="lock_version" value="{{ $quote->lock_version }}"><input type="hidden" name="action" value="mark_shared">
                    <div class="sw-field"><label for="shared-channel">Sent using</label><select id="shared-channel" name="shared_channel"><option value="whatsapp">WhatsApp</option><option value="email">Email</option><option value="other">Other / PDF</option></select></div>
                    <button class="sw-btn sw-small" type="submit">I have sent this quotation</button>
                    <p class="sw-meta">Opening or copying a message does not mark it as sent.</p>
                </form>
            </details>
        @elseif($quote->shared_at)<p class="sw-meta">Sent via {{ $quote->shared_channel }} · {{ $quote->shared_at->timezone(config('app.timezone'))->format('d M, h:i A') }} IST</p>@endif
    </details>
    @if($requiresRentalSignature)
    <details class="sw-share-step sw-guided-section" id="rental-agreement" data-sales-section="rental-agreement" data-share-step="terms" @if($salesNextAction['section']==='rental-agreement' || $errors->has('exception_reason') || $errors->has('alternative_acceptance_confirmed') || $errors->has('exception_context')) open @endif>
        <summary>Rental terms <span class="sw-meta" data-live-terms-label>{{ $rentalAgreement?->signed_at ? 'Signed' : ($agreements->readyForQuote($quote,$rentalAgreement) ? 'Exception approved' : 'Signature pending') }}</span></summary>
        <p class="sw-meta" data-live-agreement-summary>{{ $rentalAgreement?->signed_at ? 'Signed. You can send the customer their signed copy.' : ($agreements->readyForQuote($quote,$rentalAgreement) ? 'Signature exception approved. Customer acceptance is still required.' : 'Send the terms separately. The customer signs on their phone.') }}</p>
        <div class="sw-actions" data-live-agreement-actions data-live-signed="{{ $rentalAgreement?->signed_at ? '1' : '0' }}">
            @if($rentalAgreement)
                <a class="sw-btn" href="{{ $agreements->whatsapp($rentalAgreement, $quote->snapshot['recipient']['phone'] ?? null, 'Quotation '.$quote->number()) }}" target="_blank" rel="noopener noreferrer" data-phos-whatsapp data-guided-execute="terms_pending">{{ $rentalAgreement->signed_at ? 'Send signed copy on WhatsApp' : 'Share Rental Terms' }}</a>
                <button class="sw-btn" type="button" data-copy-whatsapp>{{ $rentalAgreement->signed_at ? 'Copy signed-copy message' : 'Copy rental terms message' }}</button>
            @else
                <form method="post" action="{{ route('quotations.rental-agreement.issue', $quote) }}" target="_blank" rel="noopener noreferrer" data-phos-whatsapp-prepare>@csrf<input type="hidden" name="destination" value="whatsapp"><button class="sw-btn" type="submit" data-guided-execute="terms_pending">Share Rental Terms</button></form>
            @endif
        </div>
        @include('sales-workspace.signature-exception',['agreement'=>$rentalAgreement,'exceptionQuote'=>$quote,'exceptionRental'=>null,'agreements'=>$agreements])
        @include('sales-workspace.terms-preview', ['agreement'=>$rentalAgreement,'previewReference'=>'Quotation '.$quote->number(),'allowTermsMessage'=>$canEdit && $rentalAgreement && ($rentalAgreement->signed_at || $agreements->canIssueForQuote($quote))])
    </details>
    @endif
    <details class="sw-share-step sw-guided-section" id="enquiry-response" data-sales-section="enquiry-response" data-share-step="order" @if($salesNextAction['section']==='enquiry-response' || $errors->has('note') || $errors->has('contact_name')) open @endif>
        <summary>Confirm &amp; create order <span class="sw-meta" data-live-quote-label>{{ $quote->statusLabel() }}</span></summary>
        <div data-live-order-actions>
        @if($orderAction)
            <p class="sw-meta">The customer has accepted{{ $requiresRentalSignature ? ($rentalAgreement?->signed_at ? ' and signed the terms' : ' with a recorded signature exception') : '' }}. Continue with the order.</p><a class="sw-btn" href="{{ $orderAction['url'] }}">{{ $orderAction['label'] }}</a>
        @endif
        </div>
        <div data-live-response>
        @if(!$orderAction && $quote->status==='sent' && !$quote->expired())
            <p class="sw-meta">Record the customer's acceptance after they agree to the quotation.</p>@include('sales-workspace.quotation-response')
        @elseif(!$orderAction)
            <p class="sw-meta">{{ $requiresRentalSignature && !$agreements->readyForQuote($quote,$rentalAgreement) ? 'Waiting for the customer to sign the rental terms.' : 'A staff member with order access can continue once acceptance is complete.' }}</p>
        @endif
        </div>
    </details>
</section>
@if($quote->status==='sent')<details class="sw-panel sw-guided-section" id="enquiry-followups" data-sales-section="enquiry-followups" @if($salesNextAction['section']==='enquiry-followups') open @endif><summary>Follow-up <span class="sw-meta">Optional</span></summary>
@php($nextFollowUp=$enquiry->followUps()->whereNull('completed_at')->orderBy('due_at')->first())
@if($nextFollowUp)<p class="sw-meta">Next: {{ $nextFollowUp->subject }} · {{ $nextFollowUp->due_at->timezone(config('app.timezone'))->format('d M, H:i') }} <a href="{{ route('enquiries.show',$enquiry) }}">Manage</a></p>
@else @include('sales-workspace.follow-up-form') @endif
</details>@endif
@endif
@if($canEdit && !$enquiry->closed() && in_array($quote->status,['sent','accepted']))<section class="sw-panel"><details><summary>Withdraw quotation</summary><p class="sw-meta">Disable the current customer link without creating a replacement.</p><form method="post" action="{{ route('quotations.action',$quote) }}">@csrf<input type="hidden" name="lock_version" value="{{ $quote->lock_version }}"><input type="hidden" name="action" value="withdraw"><div class="sw-field"><label for="withdraw-note">Reason *</label><textarea name="note" id="withdraw-note" required maxlength="3000" rows="2"></textarea></div><button class="sw-btn sw-danger" style="margin-top:10px">Withdraw & disable link</button></form></details></section>@endif
</aside></div></div>

@include('partials.record-timestamps', ['timestampRecord' => $quote, 'timestampFields' => ['created_at' => 'Created', 'updated_at' => 'Last updated', 'sent_at' => 'Finalised', 'shared_at' => 'Shared', 'viewed_at' => 'Viewed', 'accepted_at' => 'Accepted']])
@endsection
@push('scripts')
@include('sales-workspace.share-message-script')
<script>
    document.getElementById('copy-customer-link')?.addEventListener('click', async () => {
        const input = document.getElementById('customer-link');
        const status = document.getElementById('copy-status');
        try {
            await navigator.clipboard.writeText(input.value);
            status.textContent = 'Link copied. Send it to the customer, then confirm below.';
        } catch (error) {
            document.getElementById('customer-link-field').hidden = false;
            input.focus();
            input.select();
            status.textContent = 'Select and copy the link above.';
        }
    });
    document.querySelectorAll('[data-share-channel]').forEach(link => link.addEventListener('click', () => {
        const select = document.getElementById('shared-channel');
        if (select) select.value = link.dataset.shareChannel;
    }));
</script>
@endpush
