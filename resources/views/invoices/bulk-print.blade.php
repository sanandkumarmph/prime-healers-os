<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Bulk Invoices</title>
    <link rel="icon" type="image/png" href="{{ asset('images/prime-healers-favicon.png') }}">
    <link rel="shortcut icon" href="{{ asset('favicon.ico') }}">
    <link rel="apple-touch-icon" href="{{ asset('images/prime-healers-favicon.png') }}">
    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800&family=manrope:600,700,800&display=swap" rel="stylesheet" />
    <meta name="application-name" content="Prime Healers OS">
    <style>
        @include('invoices.partials.invoice-document-styles')

        body {
            background: #eef3f8;
        }

        .bulk-toolbar {
            position: sticky;
            top: 0;
            z-index: 20;
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            padding: 14px 22px;
            background: rgba(255, 255, 255, 0.96);
            border-bottom: 1px solid #d7e1ec;
            backdrop-filter: blur(10px);
        }

        .bulk-toolbar h1 {
            margin: 0;
            color: #12263F;
            font-size: 18px;
            font-weight: 800;
            line-height: 1.2;
            font-family: 'Manrope', 'Inter', 'Segoe UI', sans-serif;
        }

        .bulk-toolbar p {
            margin: 4px 0 0;
            color: #5B6E84;
            font-size: 12px;
        }

        .bulk-toolbar-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .bulk-toolbar button,
        .bulk-toolbar a {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-height: 40px;
            padding: 10px 16px;
            border: 1px solid #c2d0de;
            border-radius: 999px;
            background: #ffffff;
            color: #24384f;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            cursor: pointer;
        }

        .bulk-toolbar .primary {
            border-color: #2A7EC5;
            background: #2A7EC5;
            color: #ffffff;
        }

        .bulk-shell {
            display: grid;
            gap: 24px;
            padding: 24px;
        }

        .invoice-sheet {
            width: 100%;
            max-width: 980px;
            margin: 0 auto;
            background: #ffffff;
            border: 1px solid #d7e1ec;
            border-radius: 20px;
            box-shadow: 0 20px 48px rgba(15, 23, 42, 0.08);
        }

        .invoice-sheet.page-break-after {
            page-break-after: always;
        }

        @media print {
            body {
                background: #ffffff;
            }

            .bulk-toolbar {
                display: none;
            }

            .bulk-shell {
                padding: 0;
                gap: 0;
            }

            .invoice-sheet {
                max-width: none;
                border: 0;
                border-radius: 0;
                box-shadow: none;
                margin: 0;
            }
        }

        @media (max-width: 900px) {
            .bulk-toolbar {
                align-items: flex-start;
                flex-direction: column;
            }

            .bulk-toolbar-actions {
                justify-content: flex-start;
            }

            .bulk-shell {
                padding: 16px;
            }
        }
    </style>
</head>
<body>
    <div class="bulk-toolbar">
        <div>
            <h1>{{ $invoices->count() }} selected invoice{{ $invoices->count() === 1 ? '' : 's' }}</h1>
            <p>Bulk print uses the same invoice document layout as the individual invoice print/PDF view.</p>
        </div>
        <div class="bulk-toolbar-actions">
            <a href="{{ route('invoices.index') }}">Back to Invoices</a>
            <button type="button" class="primary" onclick="window.print()">Download / Print PDF</button>
        </div>
    </div>

    <main class="bulk-shell">
        @foreach($invoices as $invoice)
            <section class="invoice-sheet{{ !$loop->last ? ' page-break-after' : '' }}">
                @include('invoices.partials.invoice-document', [
                    'invoice' => $invoice,
                    'amountInWords' => $amountInWordsByInvoiceId[$invoice->id] ?? null,
                    'pdfCurrencySymbol' => $pdfCurrencySymbol ?? null,
                    'pdfCurrencyFallback' => $pdfCurrencyFallback ?? null,
                    'documentImageMode' => 'browser',
                ])
            </section>
        @endforeach
    </main>
</body>
</html>
