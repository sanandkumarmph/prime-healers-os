<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $invoice->invoice_number }}</title>
    <style>
        @include('invoices.partials.invoice-document-styles')

        body {
            font-family: DejaVu Sans, sans-serif;
        }

        table,
        td,
        th,
        div,
        span,
        p,
        strong {
            font-family: inherit;
        }
    </style>
</head>
<body class="pdf-document">
<div class="pdf-page-shell">
    @include('invoices.partials.invoice-document', [
        'invoice' => $invoice,
        'amountInWords' => $amountInWords ?? null,
        'pdfCurrencySymbol' => $pdfCurrencySymbol ?? null,
        'pdfCurrencyFallback' => $pdfCurrencyFallback ?? null,
        'documentImageMode' => 'dompdf',
        'compactPdfTable' => true,
    ])
</div>
</body>
</html>
