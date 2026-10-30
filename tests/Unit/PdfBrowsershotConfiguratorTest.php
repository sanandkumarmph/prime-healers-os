<?php

namespace Tests\Unit;

use App\Support\PdfBrowsershotConfigurator;
use Illuminate\Support\Facades\File;
use Spatie\Browsershot\Browsershot;
use Tests\TestCase;

class PdfBrowsershotConfiguratorTest extends TestCase
{
    public function test_blank_runtime_paths_fall_back_to_storage_defaults(): void
    {
        config([
            'pdf.temp_path' => '',
            'pdf.user_data_dir' => '',
        ]);

        $configurator = app(PdfBrowsershotConfigurator::class);

        $tempPath = $configurator->tempPath();
        $userDataDir = $configurator->userDataDir();

        $this->assertSame(storage_path('app/pdf-runtime/tmp'), $tempPath);
        $this->assertSame(storage_path('app/pdf-runtime/profile'), $userDataDir);
        $this->assertTrue(File::isDirectory($tempPath));
        $this->assertTrue(File::isDirectory($userDataDir));
    }

    public function test_invoice_pdf_config_uses_stable_invoice_views(): void
    {
        $this->assertSame('invoices.print', config('pdf.browsershot_view'));
        $this->assertSame('invoices.pdf-dompdf', config('pdf.dompdf_view'));
    }

    public function test_pdf_command_keeps_tls_verification_with_existing_sandbox_configuration(): void
    {
        config([
            'pdf.browser_path' => null,
            'pdf.fallback_browser_paths.windows' => [],
            'pdf.fallback_browser_paths.linux' => [],
            'pdf.fallback_browser_paths.macos' => [],
            'pdf.chromium_arguments.common' => [],
        ]);

        foreach ([false, true] as $disableSandbox) {
            config(['pdf.disable_sandbox' => $disableSandbox]);
            $shot = app(PdfBrowsershotConfigurator::class)->configure(Browsershot::url('https://example.test/invoice'));
            $command = $shot->createPdfCommand();

            $this->assertSame('pdf', $command['action']);
            $this->assertSame('https://example.test/invoice', $command['url']);
            $this->assertFalse($command['options']['acceptInsecureCerts'] ?? false);
            $this->assertFalse($command['options']['ignoreHTTPSErrors'] ?? false);
            $this->assertStringNotContainsString('ignore-certificate-errors', json_encode($command['options'], JSON_THROW_ON_ERROR));
            $this->assertSame($disableSandbox, in_array('--no-sandbox', $command['options']['args'] ?? [], true));
        }
    }
}
