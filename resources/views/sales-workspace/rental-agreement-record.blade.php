@php
    $proof = $agreement->proof;
@endphp
<details>
    <summary>View signed agreement</summary>
    <p><strong>Signed by</strong> {{ $proof['name'] }}</p>
    <img src="data:image/png;base64,{{ $agreements->signatureOnWhite($proof['signature']) }}" alt="Customer signature" style="max-width:min(100%,380px);background:#fff">
    @include('sales-workspace.rental-agreement-terms', ['rentalTerms' => $agreement->terms])
</details>
