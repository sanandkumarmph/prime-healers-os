<?php

namespace Tests\Feature\Regression;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class PdfRuntimeReadinessCommandTest extends TestCase
{
    public function test_pdf_runtime_command_is_registered(): void
    {
        $this->assertArrayHasKey('rentnexis:check-pdf-runtime', Artisan::all());
    }

    public function test_pdf_runtime_command_handles_missing_browser_path_gracefully(): void
    {
        $basePath = storage_path('framework/testing/pdf-runtime-' . uniqid());
        $modulePath = $basePath . DIRECTORY_SEPARATOR . 'node_modules';

        File::ensureDirectoryExists($modulePath . DIRECTORY_SEPARATOR . 'puppeteer');
        File::ensureDirectoryExists($modulePath . DIRECTORY_SEPARATOR . 'puppeteer-core');

        config([
            'pdf.node_binary' => PHP_BINARY,
            'pdf.node_module_path' => $modulePath,
            'pdf.browser_path' => $basePath . DIRECTORY_SEPARATOR . 'missing-browser.exe',
            'pdf.disable_sandbox' => true,
        ]);

        $this->artisan('rentnexis:check-pdf-runtime')
            ->expectsOutputToContain('Rentnexis PDF runtime readiness')
            ->expectsOutputToContain('Configured PDF_BROWSER_PATH was not found')
            ->assertExitCode(1);
    }
}
