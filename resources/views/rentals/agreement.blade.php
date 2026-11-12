@if($agreement)
    @php
        $currentAgreement = $agreements->matches($rental, $agreement);
    @endphp
    <style>
        #rental-agreement .rcc-agreement-button { display:inline-flex; align-items:center; min-height:44px; padding:8px 14px; border:0; border-radius:8px; background:var(--rental-accent,#1d4ed8); color:white; font-weight:700; margin:8px 0; }
        #rental-agreement .agreement-clause { margin:12px 0; overflow-wrap:anywhere; }
    </style>
    <section class="rcc-card" id="rental-agreement" data-rental-anchor style="padding:16px;margin-bottom:16px">
        <h2>Rental agreement</h2>
        @if($agreement->signed_at)
            <p><strong>{{ $currentAgreement ? 'Signed' : 'Previous version signed' }}</strong>
                · {{ $agreement->signed_at->timezone('Asia/Kolkata')->format('d M Y, h:i A') }} IST</p>
            @can('update', $rental)
                @if($currentAgreement)
                    <a class="rcc-agreement-button" href="{{ $agreements->whatsapp($agreement, $rental->billingContactPhone(), 'Rental #'.$rental->id) }}" target="_blank" rel="noopener noreferrer" data-phos-whatsapp>Send signed copy on WhatsApp</a>
                    <button type="button" class="rcc-agreement-button" data-copy-whatsapp>Copy WhatsApp message</button>
                @endif
                <a class="rcc-agreement-button" href="{{ $agreements->pdfLink($agreement) }}">Download signed PDF</a>
                @include('sales-workspace.rental-agreement-record')
            @endcan
        @endif
        @if($agreementPending)
            <p><strong>Awaiting signature.</strong> The customer must sign before dispatch.</p>
            @can('update', $rental)
                @if($currentAgreement && !$agreement->quotation_id)
                    <a class="rcc-agreement-button" href="{{ $agreements->whatsapp($agreement, $rental->billingContactPhone(), 'Rental #'.$rental->id) }}" target="_blank" rel="noopener noreferrer" data-phos-whatsapp>Share on WhatsApp</a>
                    <button type="button" class="rcc-agreement-button" data-copy-whatsapp>Copy WhatsApp message</button>
                @elseif(!$currentAgreement)
                    <form action="{{ route('rentals.agreement.reissue', $rental) }}" method="post">
                        @csrf
                        <p>The rental details changed. Send an updated agreement for signing.</p>
                        <button class="rcc-agreement-button" type="submit">Prepare updated agreement</button>
                    </form>
                @endif
            @endcan
        @endif
        @include('sales-workspace.signature-exception', ['exceptionQuote'=>null,'exceptionRental'=>$rental])
        @can('update', $rental)
            @include('sales-workspace.terms-preview', ['previewReference' => 'Rental #'.$rental->id, 'allowTermsMessage' => $currentAgreement && ($agreement->signed_at || ($rental->status === 'active' && !$agreement->quotation_id))])
        @endcan
    </section>
@endif
