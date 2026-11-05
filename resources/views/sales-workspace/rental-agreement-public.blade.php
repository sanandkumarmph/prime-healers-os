<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <meta name="referrer" content="no-referrer">
    <title>Rental agreement · Prime Healers</title>
    @include('sales-workspace.style')
    <style>
        .sw .agreement-terms { display:grid; gap:14px; margin:16px 0 24px; }
        .sw .agreement-clause { border-left:3px solid #b9d6df; padding:8px 14px; background:#f5f9fa; border-radius:0 8px 8px 0; }
        .sw .agreement-clause p { margin:3px 0; overflow-wrap:anywhere; }
    </style>
</head>
<body class="sw-public"><main class="sw">
    @include('sales-workspace.alerts', ['showSuccess' => true])
    <header class="sw-head"><div>
        <div class="sw-eyebrow">{{ $reference }}</div>
        <h1>Prime Healers rental agreement</h1>
    </div></header>
    @if($pdfUrl)
        <section class="sw-panel">
            <h2>Rental terms signed</h2>
            <p>Thank you. Download a copy with your signature for your records.</p>
            <a class="sw-btn sw-primary" href="{{ $pdfUrl }}">Download signed agreement (PDF)</a>
        </section>
    @else
        @include('sales-workspace.rental-agreement-sign')
    @endif
</main></body>
</html>
