<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <meta name="referrer" content="no-referrer">
    <title>Rental {{ $rental->id }} · Prime Healers</title>
    @include('sales-workspace.style')
</head>
<body class="sw-public"><main class="sw">
    @include('sales-workspace.alerts', ['showSuccess' => true])
    <header class="sw-head"><div>
        <div class="sw-eyebrow">Rental #{{ $rental->id }}</div>
        <h1>Prime Healers rental agreement</h1>
        <p>{{ $rental->billingContactName() }}</p>
    </div></header>
    @if($agreement->signed_at)
        <section class="sw-panel"><h2>Rental terms signed</h2><p>Thank you. Prime Healers has received your signature.</p></section>
    @else
        @include('sales-workspace.rental-agreement-sign', ['rentalTerms' => $agreement->terms])
    @endif
</main></body>
</html>
