<?php

namespace Tests\Unit;

use App\Support\InvoicePdfAssetResolver;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class InvoicePdfAssetResolverTest extends TestCase
{
    public function test_it_prefers_the_optimized_invoice_logo_asset(): void
    {
        $resolver = app(InvoicePdfAssetResolver::class);

        $this->assertSame('images/invoice-logo.png', $resolver->logoRelativePath());
        $this->assertStringContainsString('invoice-logo.png', $resolver->logoBrowserUrl());
        $this->assertStringStartsWith('data:image/', (string) $resolver->logoDataUri());
    }

    public function test_bulk_qr_can_be_disabled_without_affecting_individual_invoice_qr(): void
    {
        config([
            'pdf.optimize_images' => true,
            'pdf.show_qr' => true,
            'pdf.show_qr_in_bulk' => false,
            'pdf.max_image_kb' => 100,
        ]);

        $relativePath = 'tests/pdf-qr-small.png';
        $absolutePath = public_path('storage/' . $relativePath);

        File::ensureDirectoryExists(dirname($absolutePath));
        File::put($absolutePath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9pR7sQAAAABJRU5ErkJggg=='));

        $resolver = app(InvoicePdfAssetResolver::class);

        $this->assertNotNull($resolver->qrDataUri($relativePath));
        $this->assertNull($resolver->qrDataUri($relativePath, true));
    }

    public function test_oversized_storage_assets_are_skipped_and_logged(): void
    {
        Log::spy();

        config([
            'pdf.optimize_images' => true,
            'pdf.max_image_kb' => 1,
        ]);

        $relativePath = 'tests/pdf-qr-large.png';
        $absolutePath = public_path('storage/' . $relativePath);

        File::ensureDirectoryExists(dirname($absolutePath));
        File::put($absolutePath, str_repeat('A', 3 * 1024));

        $resolver = app(InvoicePdfAssetResolver::class);

        $this->assertNull($resolver->qrDataUri($relativePath));

        Log::shouldHaveReceived('warning')
            ->withArgs(function ($message, array $context) use ($absolutePath) {
                return $message === 'invoice_pdf_asset_skipped'
                    && ($context['kind'] ?? null) === 'qr'
                    && ($context['path'] ?? null) === $absolutePath;
            })
            ->once();
    }
}
