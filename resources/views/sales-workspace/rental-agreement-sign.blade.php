<section class="sw-panel" id="sign-rental-terms">
    <h2>Review &amp; sign rental terms</h2>
    @php
        $policyUrl = 'https://www.primehealers.com/rental-terms-and-conditions';
        $termsParts = explode($policyUrl, $rentalTerms, 2);
    @endphp
    <p class="sw-note">{{ $termsParts[0] }}@if(count($termsParts) === 2)<a href="{{ $policyUrl }}" target="_blank" rel="noopener noreferrer">Full rental policy</a>{{ $termsParts[1] }}@endif</p>

    <form method="post" action="{{ request()->fullUrl() }}" data-rental-signature>
        @csrf
        <input type="hidden" name="rental_agreement_version" value="{{ hash('sha256', $rentalTerms) }}">
        @if(isset($quote))
            <input type="hidden" name="decision" value="accepted">
            <input type="hidden" name="acknowledge" value="1">
        @endif
        <div class="sw-fields">
            <div class="sw-field">
                <label for="rental-signer-name">Your full name *</label>
                <input id="rental-signer-name" name="contact_name" maxlength="150" autocomplete="name" required value="{{ old('contact_name') }}">
            </div>
            <div class="sw-field">
                <label for="rental-signer-id">Last four digits of an ID (optional)</label>
                <input id="rental-signer-id" name="rental_agreement_id_reference" inputmode="numeric" maxlength="4" pattern="[0-9]{4}" autocomplete="off">
            </div>
            <div class="sw-field sw-full">
                <label id="rental-signature-label">Signature *</label>
                <p class="sw-meta">Draw your signature in the box with a finger, stylus or mouse.</p>
                <canvas width="760" height="280" aria-labelledby="rental-signature-label"
                    style="width:100%;height:150px;display:block;background:#fff;border:1px solid #cbd6e3;border-radius:7px;touch-action:none;cursor:crosshair"></canvas>
                <input type="hidden" name="signature_data">
                <p class="sw-meta" role="alert" data-signature-error hidden>Draw your signature before continuing.</p>
                <div><button class="sw-btn sw-small" type="button" data-clear-signature>Clear signature</button></div>
            </div>
        </div>
        <p style="margin-top:18px">
            <label><input type="checkbox" name="rental_agreement_acknowledged" value="1" required>
                I have read and agree to the rental terms{{ isset($quote) ? ' and accept this quotation' : '' }}.
            </label>
        </p>
        <button class="sw-btn sw-primary" type="submit">Agree &amp; sign</button>
    </form>
</section>
<script src="{{ asset('js/phos-rental-agreement.js') }}" defer></script>
