<?php

namespace App\Support;

use App\Models\Invoice;
use Barryvdh\DomPDF\Facade\Pdf as DomPdf;
use Illuminate\Support\Facades\Log;
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
        $selectedEngine = $this->engine();

        $this->logRuntimeDebug($invoice, 'render.start', [
            'configured_engine' => (string) config('pdf.engine', 'browsershot'),
            'selected_engine_branch' => $selectedEngine,
            'config_cached' => app()->configurationIsCached(),
            'env_pdf_engine' => env('PDF_ENGINE'),
            'browsershot_view' => (string) config('pdf.browsershot_view', 'invoices.print'),
            'dompdf_view' => (string) config('pdf.dompdf_view', 'invoices.pdf-dompdf'),
        ]);

        return match ($selectedEngine) {
            'dompdf' => $this->renderWithDompdf($invoice, $viewData),
            'auto' => $this->renderWithAutoFallback($invoice, $viewData),
            default => $this->renderWithBrowsershot($invoice, $viewData),
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

    private function renderWithAutoFallback(Invoice $invoice, array $viewData): string
    {
        try {
            return $this->renderWithBrowsershot($invoice, $viewData);
        } catch (Throwable $browsershotException) {
            $this->logRuntimeDebug($invoice, 'render.auto_fallback.browsershot_failed', [
                'message' => $browsershotException->getMessage(),
                'exception' => $browsershotException::class,
            ]);

            try {
                return $this->renderWithDompdf($invoice, $viewData);
            } catch (Throwable $dompdfException) {
                $this->logRuntimeDebug($invoice, 'render.auto_fallback.dompdf_failed', [
                    'message' => $dompdfException->getMessage(),
                    'exception' => $dompdfException::class,
                ]);

                throw new RuntimeException(
                    'Invoice PDF could not be generated with Browsershot or DOMPDF. Check PDF_ENGINE and server PDF runtime configuration.',
                    0,
                    $dompdfException
                );
            }
        }
    }

    private function renderWithBrowsershot(Invoice $invoice, array $viewData): string
    {
        try {
            $viewName = (string) config('pdf.browsershot_view', 'invoices.print');
            $html = view($viewName, $viewData)->render();
            $marginMm = max((float) config('pdf.browsershot_margin_mm', 14), 0);
            $scale = (float) config('pdf.browsershot_scale', 0.9);
            $scale = $scale > 0 ? min(max($scale, 0.5), 1) : 0.9;

            $this->logRuntimeDebug($invoice, 'render.browsershot.html', [
                'view' => $viewName,
                'html_length' => strlen($html),
                'contains_pdf_document_body_class' => str_contains($html, 'body class="pdf-document"') || str_contains($html, "body class='pdf-document'"),
                'contains_page_margin' => str_contains($html, '@page') && str_contains($html, 'margin: 14mm'),
                'contains_invoice_document' => str_contains($html, 'invoice-document'),
                'contains_runtime_marker' => str_contains($html, (string) config('pdf.debug_runtime_marker', 'PDF_RUNTIME_MARKER_2026_05_15_MARGIN_FIX')),
                'paper_size' => 'A4',
                'orientation' => 'portrait',
                'margins_mm' => [
                    'top' => $marginMm,
                    'right' => $marginMm,
                    'bottom' => $marginMm,
                    'left' => $marginMm,
                ],
                'scale' => $scale,
                'prefer_css_page_size' => true,
                'print_background' => true,
            ]);

            $browsershot = $this->browsershotConfigurator->configure(
                Browsershot::html($html)
                    ->format('A4')
                    ->margins($marginMm, $marginMm, $marginMm, $marginMm, 'mm')
                    ->setOption('landscape', false)
                    ->setOption('preferCSSPageSize', true)
                    ->scale($scale)
                    ->emulateMedia('print')
                    ->showBackground()
                    ->hideBrowserHeaderAndFooter()
                    ->deviceScaleFactor(2)
                    ->waitUntilNetworkIdle(false)
                    ->timeout(90)
            );

            $pdf = $this->browsershotConfigurator->runInPdfWorkingDirectory(
                fn () => $browsershot->pdf()
            );

            $this->logRuntimeDebug($invoice, 'render.browsershot.output', [
                'pdf_byte_size' => strlen($pdf),
            ]);

            return $pdf;
        } catch (Throwable $exception) {
            $this->logRuntimeDebug($invoice, 'render.browsershot.failed', [
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            throw new RuntimeException(
                'Invoice PDF could not be generated with Browsershot. Check PDF_BROWSER_PATH, PDF_NODE_BINARY, PDF_DISABLE_SANDBOX, and Chromium runtime configuration.',
                0,
                $exception
            );
        }
    }

    private function renderWithDompdf(Invoice $invoice, array $viewData): string
    {
        try {
            $viewName = (string) config('pdf.dompdf_view', 'invoices.pdf-dompdf');
            $html = view($viewName, $viewData)->render();

            $this->logRuntimeDebug($invoice, 'render.dompdf.html', [
                'view' => $viewName,
                'html_length' => strlen($html),
                'contains_pdf_document_body_class' => str_contains($html, 'body class="pdf-document"') || str_contains($html, "body class='pdf-document'"),
                'contains_page_margin' => str_contains($html, '@page') && str_contains($html, 'margin: 14mm'),
                'contains_invoice_document' => str_contains($html, 'invoice-document'),
                'contains_runtime_marker' => str_contains($html, (string) config('pdf.debug_runtime_marker', 'PDF_RUNTIME_MARKER_2026_05_15_MARGIN_FIX')),
                'paper_size' => 'A4',
                'orientation' => 'portrait',
            ]);

            $pdf = DomPdf::loadHTML($html)
                ->setPaper('a4', 'portrait');

            $pdf->setOption([
                'defaultFont' => 'DejaVu Sans',
                'dpi' => 96,
                'isRemoteEnabled' => false,
                'isHtml5ParserEnabled' => true,
                'isFontSubsettingEnabled' => false,
            ]);

            $output = $pdf->output(['compress' => 0]);

            $this->logRuntimeDebug($invoice, 'render.dompdf.output', [
                'pdf_byte_size' => strlen($output),
            ]);

            return $output;
        } catch (Throwable $exception) {
            $this->logRuntimeDebug($invoice, 'render.dompdf.failed', [
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            throw new RuntimeException(
                'Invoice PDF could not be generated with DOMPDF. Check PDF_ENGINE, PDF_CURRENCY_SYMBOL, PDF_CURRENCY_FALLBACK, and DOMPDF font/runtime configuration.',
                0,
                $exception
            );
        }
    }

    private function logRuntimeDebug(Invoice $invoice, string $event, array $context): void
    {
        if (!config('pdf.debug_runtime', false)) {
            return;
        }

        Log::info('invoice_pdf_runtime_debug', array_merge([
            'event' => $event,
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->invoice_number,
        ], $context));
    }
}
