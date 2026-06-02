<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->invoice_number }}</title>
    <link rel="icon" type="image/png" href="{{ asset('images/prime-healers-favicon.png') }}">
    <link rel="shortcut icon" href="{{ asset('favicon.ico') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/prime-healers-favicon.png') }}">
    <meta name="application-name" content="Prime Healers OS">
    <style>
        @include('invoices.partials.invoice-document-styles', [
            'invoiceBodyFontStack' => "'Inter', 'Segoe UI', Roboto, Arial, sans-serif",
            'invoiceHeadingFontStack' => "'Manrope', 'Inter', 'Segoe UI', sans-serif",
        ])
    </style>
</head>
<body>
@php
    $pdfAssets = app(\App\Support\InvoicePdfAssetResolver::class);
@endphp
@include('invoices.partials.invoice-document', [
    'invoice' => $invoice,
    'amountInWords' => $amountInWords ?? null,
    'pdfCurrencySymbol' => $pdfCurrencySymbol ?? null,
    'pdfCurrencyFallback' => $pdfCurrencyFallback ?? null,
    'documentRootClass' => 'invoice-page',
    'showPaymentsTable' => true,
    'tenantLogo' => $pdfAssets->logoDataUri(),
    'tenantQr' => $pdfAssets->qrDataUri($invoice->organization?->payment_qr_code),
    'tenantSignature' => $pdfAssets->signatureDataUri($invoice->organization?->digital_signature),
])
</body>
</html>
