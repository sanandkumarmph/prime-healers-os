<?php

namespace App\Console\Commands;

use App\Support\PdfBrowsershotConfigurator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Spatie\Browsershot\Browsershot;

class TestPdfRender extends Command
{
    protected $signature = 'rentnexis:test-pdf-render';

    protected $description = 'Render a simple PDF with Browsershot using the configured runtime and store it under storage/app/pdf-runtime/test.pdf.';

    public function handle(PdfBrowsershotConfigurator $configurator): int
    {
        $outputPath = storage_path('app/pdf-runtime/test.pdf');
        File::ensureDirectoryExists(dirname($outputPath));

        $html = <<<'HTML'
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Rentnexis PDF Runtime Test</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 32px; color: #1f2937; }
        h1 { font-size: 22px; margin-bottom: 12px; }
        p { font-size: 14px; line-height: 1.5; }
        .meta { margin-top: 20px; font-size: 12px; color: #6b7280; }
    </style>
</head>
<body>
    <h1>Rentnexis PDF Runtime Test</h1>
    <p>This is a dummy PDF render used to verify Chromium + Browsershot on the current environment.</p>
    <p>If this file renders successfully, the underlying Linux PDF runtime is working.</p>
    <div class="meta">Generated at: __GENERATED_AT__</div>
</body>
</html>
HTML;

        $html = str_replace('__GENERATED_AT__', now()->toDateTimeString(), $html);

        try {
            $browsershot = $configurator->configure(
                Browsershot::html($html)
                    ->format('A4')
                    ->margins(12, 12, 12, 12, 'mm')
                    ->showBackground()
                    ->hideBrowserHeaderAndFooter()
                    ->emulateMedia('print')
                    ->timeout(90)
            );

            $browsershot->savePdf($outputPath);
        } catch (\Throwable $exception) {
            $this->error('PDF render failed: ' . $exception::class);
            $this->line($exception->getMessage());

            if ($exception->getPrevious()) {
                $this->newLine();
                $this->warn('Previous exception: ' . $exception->getPrevious()::class);
                $this->line($exception->getPrevious()->getMessage());
            }

            $this->newLine();
            $this->line('Trace:');
            $this->line($exception->getTraceAsString());

            return self::FAILURE;
        }

        if (! File::exists($outputPath)) {
            $this->error('PDF render failed: output file was not created.');

            return self::FAILURE;
        }

        $size = (int) File::size($outputPath);
        if ($size <= 0) {
            $this->error('PDF render failed: output file is empty.');

            return self::FAILURE;
        }

        $this->info('PDF render succeeded.');
        $this->line('Output: ' . $outputPath);
        $this->line('Size: ' . $size . ' bytes');

        return self::SUCCESS;
    }
}
