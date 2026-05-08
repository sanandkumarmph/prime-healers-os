<?php

namespace App\Support;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf as DomPdf;
use RuntimeException;
use Spatie\Browsershot\Browsershot;
use Throwable;

class InvoicePdfRenderer
{
    public function __construct(
        private readonly PdfBrowsershotConfigurator $browsershotConfigurator,
    ) {
    }

    public function render(Invoice $invoice, array $viewData): string
    {
        return match ($this->engine()) {
            'dompdf' => $this->renderWithDompdf($viewData),
            'auto' => $this->renderWithAutoFallback($viewData),
            default => $this->renderWithBrowsershot($viewData),
        };
    }

    public function engine(): string
    {
        $engine = strtolower(trim((string) config('pdf.engine', 'browsershot')));

        return in_array($engine, ['browsershot', 'dompdf', 'auto'], true)
            ? $engine
            : 'browsershot';
    }

    public function currencySymbol(): string
    {
        $symbol = trim((string) config('pdf.currency_symbol', "\u{20B9}"));

        if ($symbol !== '') {
            return $symbol;
        }

        return $this->currencyFallback();
    }

    public function currencyFallback(): string
    {
        $fallback = trim((string) config('pdf.currency_fallback', 'Rs.'));

        return $fallback !== '' ? $fallback : 'Rs.';
    }

    private function renderWithAutoFallback(array $viewData): string
    {
        try {
            return $this->renderWithBrowsershot($viewData);
        } catch (Throwable $browsershotException) {
            try {
                return $this->renderWithDompdf($viewData);
            } catch (Throwable $dompdfException) {
                throw new RuntimeException(
                    'Invoice PDF could not be generated with Browsershot or DOMPDF. Check PDF_ENGINE and server PDF runtime configuration.',
                    0,
                    $dompdfException
                );
            }
        }
    }

    private function renderWithBrowsershot(array $viewData): string
    {
        try {
            $html = view((string) config('pdf.browsershot_view', 'invoices.print'), $viewData)->render();

            $browsershot = $this->browsershotConfigurator->configure(
                Browsershot::html($html)
                    ->format('A4')
                    ->margins(12, 12, 12, 12, 'mm')
                    ->emulateMedia('print')
                    ->showBackground()
                    ->hideBrowserHeaderAndFooter()
                    ->deviceScaleFactor(2)
                    ->waitUntilNetworkIdle(false)
                    ->timeout(90)
            );

            return $browsershot->pdf();
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Invoice PDF could not be generated with Browsershot. Check PDF_BROWSER_PATH, PDF_NODE_BINARY, PDF_DISABLE_SANDBOX, and Chromium runtime configuration.',
                0,
                $exception
            );
        }
    }

    private function renderWithDompdf(array $viewData): string
    {
        try {
            $pdf = DomPdf::loadView((string) config('pdf.dompdf_view', 'invoices.pdf-dompdf'), $viewData)
                ->setPaper('a4', 'portrait');

            $pdf->setOption([
                'defaultFont' => 'DejaVu Sans',
                'dpi' => 96,
                'isRemoteEnabled' => false,
                'isHtml5ParserEnabled' => true,
                'isFontSubsettingEnabled' => false,
            ]);

            return $pdf->output(['compress' => 0]);
        } catch (Throwable $exception) {
            throw new RuntimeException(
                'Invoice PDF could not be generated with DOMPDF. Check PDF_ENGINE, PDF_CURRENCY_SYMBOL, PDF_CURRENCY_FALLBACK, and DOMPDF font/runtime configuration.',
                0,
                $exception
            );
        }
    }
}
