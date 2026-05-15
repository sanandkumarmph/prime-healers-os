<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->invoice_number }}</title>
    <link rel="icon" type="image/png" href="{{ asset('images/prime-healers-favicon.png') }}">
    <link rel="shortcut icon" href="{{ asset('favicon.ico') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/prime-healers-favicon.png') }}">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800&family=manrope:600,700,800&display=swap" rel="stylesheet" />
    <meta name="application-name" content="Prime Healers OS">
    <style>
        @include('invoices.partials.invoice-document-styles')
    </style>
</head>
<body class="pdf-document">
@if(config('pdf.debug_runtime'))
<div style="font-size:6px; color:#d7dbe1; line-height:1; margin:0 0 1mm 0;">{{ config('pdf.debug_runtime_marker') }}</div>
@endif
<div class="pdf-page-shell">
    @include('invoices.partials.invoice-document', [
        'invoice' => $invoice,
        'amountInWords' => $amountInWords ?? null,
        'pdfCurrencySymbol' => $pdfCurrencySymbol ?? null,
        'pdfCurrencyFallback' => $pdfCurrencyFallback ?? null,
        'documentImageMode' => 'browser',
    ])
</div>
</body>
</html>
