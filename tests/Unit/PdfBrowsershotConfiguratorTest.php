<?php

namespace Tests\Unit;

use App\Support\PdfBrowsershotConfigurator;
use Illuminate\Support\Facades\File;
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
}
