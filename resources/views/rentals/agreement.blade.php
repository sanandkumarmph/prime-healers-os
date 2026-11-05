@php
    $agreements = app(\App\Services\Sales\RentalAgreements::class);
    $agreement = $agreements->current($rental);
@endphp
@if($agreement)
    @php
        $currentAgreement = $agreements->matches($rental, $agreement);
    @endphp
    <section class="rcc-card" id="rental-agreement" style="padding:16px;margin-bottom:16px;scroll-margin-top:20px">
        <h2>Rental agreement</h2>
        @if($agreement->signed_at)
            <p><strong>{{ $currentAgreement ? 'Signed' : 'Previous version signed' }}</strong>
                · {{ $agreement->signed_at->timezone('Asia/Kolkata')->format('d M Y, h:i A') }} IST</p>
            @can('update', $rental)
                @php
                    $proof = $agreement->proof;
                @endphp
                <details>
                    <summary>View signed terms and signature</summary>
                    <p>Signed by {{ $proof['name'] }}</p>
                    <img src="data:image/png;base64,{{ $proof['signature'] }}" alt="Customer signature" style="max-width:min(100%,380px)">
                    <p style="white-space:pre-wrap;overflow-wrap:anywhere">{{ $agreement->terms }}</p>
                </details>
            @endcan
        @endif
        @if($rental->status === 'active' && !$agreements->signedFor($rental, $agreement))
            <p><strong>Awaiting signature.</strong> The customer must sign before dispatch.</p>
            @can('update', $rental)
                @if($currentAgreement && !$agreement->quotation_id)
                    @php
                        $agreementLink = $agreements->link($agreement);
                        $phone = preg_replace('/\D/', '', \App\Support\PhoneNumber::normalize($rental->billingContactPhone()) ?? '');
                        $message = 'Please review and sign the Prime Healers agreement for rental #'.$rental->id.': '.$agreementLink;
                    @endphp
                    <div class="rcc-actions">
                        <a href="https://wa.me/{{ $phone }}?text={{ rawurlencode($message) }}" target="_blank" rel="noopener noreferrer">Share on WhatsApp</a>
                        <a href="{{ $agreementLink }}" target="_blank" rel="noopener noreferrer">Open signing link</a>
                    </div>
                    <label for="rental-agreement-link">Signing link</label>
                    <input id="rental-agreement-link" type="url" readonly value="{{ $agreementLink }}" style="width:100%;max-width:42rem">
                @elseif(!$currentAgreement)
                    <form action="{{ route('rentals.agreement.reissue', $rental) }}" method="post">
                        @csrf
                        <p>The rental details changed. Send an updated agreement for signing.</p>
                        <button type="submit">Prepare new signing link</button>
                    </form>
                @endif
            @endcan
        @endif
    </section>
@endif
