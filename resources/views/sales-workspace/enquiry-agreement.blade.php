@php($canShareAgreement = $canUpdateQuote)
@if(\App\Services\Sales\RentalAgreements::enabled() && ($quote ? $quote->kind === 'rental' : $enquiry->intent !== 'sale'))
    <details class="sw-panel sw-guided-section" id="rental-agreement" data-sales-section="rental-agreement" @if(($salesNextAction['section'] ?? null)==='rental-agreement' || $errors->has('exception_reason') || $errors->has('alternative_acceptance_confirmed') || $errors->has('exception_context')) open @endif>
        <summary>Rental terms <span class="sw-meta" data-live-terms-label>{{ $agreement?->signed_at ? 'Signed' : ($quote && $agreement && $agreements->exceptionForQuote($quote,$agreement) ? 'Exception approved' : ($agreement ? 'Signature pending' : 'Available after quotation')) }}</span></summary>
        @if($agreement?->signed_at)
            <p data-live-agreement-summary><strong>Signed</strong> · {{ $agreement->signed_at->timezone('Asia/Kolkata')->format('d M Y, h:i A') }} IST</p>
            @if($canShareAgreement)
                <div class="sw-actions" data-live-agreement-actions data-live-signed="{{ $agreement?->signed_at ? '1' : '0' }}">
                    <a class="sw-btn" href="{{ $agreements->whatsapp($agreement, $quote->snapshot['recipient']['phone'] ?? null, 'Quotation '.$quote->number()) }}" target="_blank" rel="noopener noreferrer" data-phos-whatsapp>Send signed copy on WhatsApp</a>
                    <button type="button" class="sw-btn" data-copy-whatsapp>Copy WhatsApp message</button>
                    <a class="sw-btn" href="{{ $agreements->pdfLink($agreement) }}">Download signed PDF</a>
                </div>
                @include('sales-workspace.rental-agreement-record')
            @endif
        @elseif($agreement && $agreements->exceptionForQuote($quote, $agreement))
            <p class="sw-meta" data-live-agreement-summary>A recorded signature exception allows this quotation to continue after customer acceptance.</p>
            @if($canShareAgreement)<div class="sw-actions" data-live-agreement-actions data-live-signed="0"></div>@endif
        @elseif($agreement)
            <p data-live-agreement-summary><strong>Awaiting signature.</strong> The customer can review and sign on their phone.</p>
            @if($canShareAgreement && $agreements->canIssueForQuote($quote))
                <div class="sw-actions" data-live-agreement-actions data-live-signed="{{ $agreement?->signed_at ? '1' : '0' }}"><a class="sw-btn" href="{{ $agreements->whatsapp($agreement, $quote->snapshot['recipient']['phone'] ?? null, 'Quotation '.$quote->number()) }}" target="_blank" rel="noopener noreferrer" data-phos-whatsapp data-guided-execute="terms_pending">Send rental terms on WhatsApp</a>
                <button type="button" class="sw-btn" data-copy-whatsapp>Copy WhatsApp message</button>
                </div>
            @endif
        @elseif($quote && $canShareAgreement && $agreements->canIssueForQuote($quote))
            <form method="post" action="{{ route('quotations.rental-agreement.issue', $quote) }}" target="_blank" rel="noopener noreferrer" data-phos-whatsapp-prepare>
                @csrf
                <input type="hidden" name="destination" value="whatsapp">
                <button class="sw-btn" type="submit" data-guided-execute="terms_pending">Share Rental Terms</button>
            </form>
        @else
            <p class="sw-meta">Review or copy the terms now. A customer signing link becomes available after the rental quotation is finalised.</p>
        @endif
        @include('sales-workspace.signature-exception', ['exceptionQuote'=>$quote,'exceptionRental'=>null])
        @include('sales-workspace.terms-preview', ['previewReference' => $quote ? 'Quotation '.$quote->number() : 'Rental agreement', 'allowTermsMessage' => $canShareAgreement && $agreement && ($agreement->signed_at || ($quote && $agreements->canIssueForQuote($quote)))])
    </details>
@endif
