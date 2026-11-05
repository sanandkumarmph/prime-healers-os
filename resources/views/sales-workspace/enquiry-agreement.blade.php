@php($canShareAgreement = $canUpdateQuote)
@if($quote && ($agreements->canIssueForQuote($quote) || $agreement?->signed_at))
    <section class="sw-panel" id="rental-agreement" style="scroll-margin-top:20px">
        <h2>Rental agreement</h2>
        <p class="sw-muted">Send this separately from quotation {{ $quote->number() }}.</p>
        @if($agreement?->signed_at)
            <p><strong>Signed</strong> · {{ $agreement->signed_at->timezone('Asia/Kolkata')->format('d M Y, h:i A') }} IST</p>
            @if($canShareAgreement)
                <div class="sw-actions">
                    <a class="sw-btn sw-primary" href="{{ $agreements->whatsapp($agreement, $quote->snapshot['recipient']['phone'] ?? null, 'Quotation '.$quote->number()) }}" target="_blank" rel="noopener noreferrer">Send signed copy on WhatsApp</a>
                    <a class="sw-btn" href="{{ $agreements->pdfLink($agreement) }}">Download signed PDF</a>
                </div>
                @include('sales-workspace.rental-agreement-record')
            @endif
        @elseif($agreement)
            <p><strong>Awaiting signature.</strong> The customer can review and sign on their phone.</p>
            @if($canShareAgreement)
                <a class="sw-btn sw-primary" href="{{ $agreements->whatsapp($agreement, $quote->snapshot['recipient']['phone'] ?? null, 'Quotation '.$quote->number()) }}" target="_blank" rel="noopener noreferrer">Send rental terms on WhatsApp</a>
            @endif
        @elseif($canShareAgreement)
            <form method="post" action="{{ route('quotations.rental-agreement.issue', $quote) }}">
                @csrf
                <button class="sw-btn sw-primary" type="submit">Prepare rental agreement</button>
            </form>
        @endif
    </section>
@endif
