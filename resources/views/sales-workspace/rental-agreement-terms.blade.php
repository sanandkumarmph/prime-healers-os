<div class="agreement-terms">
    @foreach(\App\Services\Sales\RentalAgreementTerms::sections($rentalTerms) as $section)
        <section class="agreement-clause">
            <h3>{{ $section['heading'] }}</h3>
            @foreach($section['lines'] as $line)
                @if($line === \App\Services\Sales\RentalAgreementTerms::POLICY_URL)
                    <p><a href="{{ $line }}" target="_blank" rel="noopener noreferrer">Read the full rental policy</a></p>
                @else
                    <p>{{ $line }}</p>
                @endif
            @endforeach
        </section>
    @endforeach
</div>
