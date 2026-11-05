<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Prime Healers signed rental agreement</title>
    <style>
        @page { margin: 40pt 46pt; }
        body { font: 10pt/1.45 "DejaVu Sans", sans-serif; color: #172b4d; }
        h1 { font-size: 22pt; margin: 4pt 0; }
        h2 { font-size: 13pt; margin: 14pt 0 8pt; }
        .brand { color: #145466; font-size: 11pt; font-weight: bold; }
        .reference { color: #465b6c; }
        .receipt { border: 1pt solid #cedee4; background: #f4f8fa; padding: 10pt 13pt; margin: 14pt 0 16pt; page-break-inside: avoid; }
        .receipt h2 { margin: 0 0 6pt; }
        .receipt p { margin: 3pt 0; word-wrap: break-word; }
        .receipt img { display: block; width: 168pt; max-height: 82pt; margin: 6pt 0; }
        .agreement-clause { margin-bottom: 9pt; page-break-inside: avoid; }
        .agreement-clause h3 { margin: 0 0 3pt; font-size: 11pt; color: #145466; }
        .agreement-clause p { margin: 0 0 2pt; }
        a { color: #145466; }
    </style>
</head>
<body>
    <header>
        <div class="brand">Prime Healers</div>
        <h1>Signed rental agreement</h1>
        <div class="reference">{{ $reference }} &nbsp;|&nbsp; Agreement #{{ $agreement->id }}</div>
    </header>
    <section class="receipt">
        <h2>Customer acceptance</h2>
        <p><strong>Signed by</strong> {{ $proof['name'] }}</p>
        <p><strong>Signed on</strong> {{ $agreement->signed_at->timezone('Asia/Kolkata')->format('d M Y, h:i A') }} IST</p>
        <img src="data:image/png;base64,{{ $proof['signature'] }}" alt="Customer signature">
        <p>The signer agreed to the rental terms reproduced below.</p>
    </section>
    <h2>Rental terms and conditions</h2>
    @include('sales-workspace.rental-agreement-terms')
</body>
</html>
