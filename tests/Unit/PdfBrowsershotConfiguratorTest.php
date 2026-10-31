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
            $this->assertTrue($command['options']['pipe']);
            $this->assertSame('https://example.test/invoice', $command['url']);
            $this->assertFalse($command['options']['acceptInsecureCerts'] ?? false);
            $this->assertFalse($command['options']['ignoreHTTPSErrors'] ?? false);
            $this->assertStringNotContainsString('ignore-certificate-errors', json_encode($command['options'], JSON_THROW_ON_ERROR));
            $this->assertSame($disableSandbox, in_array('--no-sandbox', $command['options']['args'] ?? [], true));
        }
    }

    public function test_enabling_sandbox_removes_incompatible_legacy_flags_from_the_actual_pdf_command(): void
    {
        config([
            'pdf.browser_path' => null,
            'pdf.fallback_browser_paths.windows' => [],
            'pdf.fallback_browser_paths.linux' => [],
            'pdf.fallback_browser_paths.macos' => [],
            'pdf.chromium_arguments.common' => ['no-sandbox', 'disable-setuid-sandbox',
                'single-process', 'no-zygote', 'disable-seccomp-filter-sandbox',
                '--disable-namespace-sandbox=true', 'disable-dev-shm-usage'],
            'pdf.chromium_arguments.windows' => ['disable-gpu'],
            'pdf.chromium_arguments.linux' => ['disable-gpu'],
            'pdf.chromium_arguments.macos' => ['disable-gpu'],
            'pdf.disable_sandbox' => false,
        ]);
        $command = app(PdfBrowsershotConfigurator::class)
            ->configure(Browsershot::html('<p>Synthetic sandbox configuration check.</p>'))
            ->createPdfCommand();
        $arguments = $command['options']['args'] ?? [];
        $this->assertContains('--disable-dev-shm-usage', $arguments);
        $this->assertContains('--disable-gpu', $arguments);
        foreach ($arguments as $argument) {
            $this->assertStringNotContainsString('sandbox', strtolower($argument));
            $this->assertNotContains($argument, ['--single-process', '--no-zygote']);
        }

        config(['pdf.disable_sandbox' => true]);
        $legacy = app(PdfBrowsershotConfigurator::class)
            ->configure(Browsershot::html('<p>Synthetic existing configuration check.</p>'))
            ->createPdfCommand();
        foreach (['--single-process', '--no-zygote', '--no-sandbox', '--disable-setuid-sandbox'] as $argument) {
            $this->assertContains($argument, $legacy['options']['args']);
        }
    }
}
