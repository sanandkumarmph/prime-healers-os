<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->invoice_number }}</title>
    <style>
        @include('invoices.partials.invoice-document-styles', [
            'invoiceBodyFontStack' => "'DejaVu Sans', sans-serif",
            'invoiceHeadingFontStack' => "'DejaVu Sans', sans-serif",
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
