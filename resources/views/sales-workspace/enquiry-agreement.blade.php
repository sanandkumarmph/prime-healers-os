@php($canShareAgreement = $canUpdateQuote)
@if(\App\Services\Sales\RentalAgreements::enabled() && ($quote ? $quote->kind === 'rental' : $enquiry->intent !== 'sale'))
    <section class="sw-panel" id="rental-agreement" style="scroll-margin-top:20px">
        <h2>Rental agreement</h2>
        @if($agreement?->signed_at)
            <p><strong>Signed</strong> · {{ $agreement->signed_at->timezone('Asia/Kolkata')->format('d M Y, h:i A') }} IST</p>
            @if($canShareAgreement)
                <div class="sw-actions">
                    <a class="sw-btn sw-primary" href="{{ $agreements->whatsapp($agreement, $quote->snapshot['recipient']['phone'] ?? null, 'Quotation '.$quote->number()) }}" target="_blank" rel="noopener noreferrer" data-phos-whatsapp>Send signed copy on WhatsApp</a>
                    <button type="button" class="sw-btn" data-copy-whatsapp>Copy WhatsApp message</button>
                    <a class="sw-btn" href="{{ $agreements->pdfLink($agreement) }}">Download signed PDF</a>
                </div>
                @include('sales-workspace.rental-agreement-record')
            @endif
        @elseif($agreement)
            <p><strong>Awaiting signature.</strong> The customer can review and sign on their phone.</p>
            @if($canShareAgreement && $agreements->canIssueForQuote($quote))
                <a class="sw-btn sw-primary" href="{{ $agreements->whatsapp($agreement, $quote->snapshot['recipient']['phone'] ?? null, 'Quotation '.$quote->number()) }}" target="_blank" rel="noopener noreferrer" data-phos-whatsapp>Send rental terms on WhatsApp</a>
                <button type="button" class="sw-btn" data-copy-whatsapp>Copy WhatsApp message</button>
            @endif
        @elseif($quote && $canShareAgreement && $agreements->canIssueForQuote($quote))
            <form method="post" action="{{ route('quotations.rental-agreement.issue', $quote) }}" target="_blank" rel="noopener noreferrer" data-phos-whatsapp-prepare>
                @csrf
                <input type="hidden" name="destination" value="whatsapp">
                <button class="sw-btn sw-primary" type="submit">Share Rental Terms</button>
            </form>
        @else
            <p class="sw-meta">Review or copy the terms now. A customer signing link becomes available after the rental quotation is finalised.</p>
        @endif
        @include('sales-workspace.terms-preview', ['previewReference' => $quote ? 'Quotation '.$quote->number() : 'Rental agreement', 'allowTermsMessage' => $canShareAgreement && $agreement && ($agreement->signed_at || ($quote && $agreements->canIssueForQuote($quote)))])
    </section>
@endif
