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

        if (extension_loaded('gd') || extension_loaded('imagick')) {
            $this->assertStringStartsWith('data:image/', (string) $resolver->logoDataUri());
        } else {
            $this->assertNull($resolver->logoDataUri());
        }
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
        $absolutePath = storage_path('app/public/' . $relativePath);

        File::ensureDirectoryExists(dirname($absolutePath));
        File::put($absolutePath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9pR7sQAAAABJRU5ErkJggg=='));

        $resolver = app(InvoicePdfAssetResolver::class);

        if (extension_loaded('gd') || extension_loaded('imagick')) {
            $this->assertNotNull($resolver->qrDataUri($relativePath));
        } else {
            $this->assertNull($resolver->qrDataUri($relativePath));
        }

        $this->assertNull($resolver->qrDataUri($relativePath, true));
    }

    public function test_oversized_storage_assets_are_skipped_and_logged(): void
    {
        Log::spy();

        config([
            'pdf.optimize_images' => true,
            'pdf.max_image_kb' => 1,
            'pdf.max_qr_image_kb' => 1,
            'pdf.show_qr' => true,
        ]);

        $relativePath = 'tests/pdf-qr-large.png';
        $absolutePath = storage_path('app/public/' . $relativePath);

        File::ensureDirectoryExists(dirname($absolutePath));
        File::put($absolutePath, str_repeat('A', 3 * 1024));

        $resolver = app(InvoicePdfAssetResolver::class);

        $this->assertNull($resolver->qrDataUri($relativePath));

        if (extension_loaded('gd') || extension_loaded('imagick')) {
            Log::shouldHaveReceived('warning')
                ->withArgs(function ($message, array $context) use ($absolutePath) {
                    return $message === 'invoice_pdf_asset_skipped'
                        && ($context['kind'] ?? null) === 'qr'
                        && ($context['path'] ?? null) === $absolutePath;
                })
                ->once();
        } else {
            Log::shouldNotHaveReceived('warning');
        }
    }
    public function test_it_resolves_uploaded_organization_logos_from_storage_path_variants(): void
    {
        $relativePath = 'tests/org-logo.png';
        $absolutePath = storage_path('app/public/' . $relativePath);

        File::ensureDirectoryExists(dirname($absolutePath));
        File::put($absolutePath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9pR7sQAAAABJRU5ErkJggg=='));

        $resolver = new class(app(\App\Support\PdfImageDataUri::class)) extends InvoicePdfAssetResolver {
            public function imagesEnabled(): bool
            {
                return true;
            }
        };

        $this->assertStringStartsWith('data:image/', (string) $resolver->logoDataUri($relativePath));
        $this->assertStringStartsWith('data:image/', (string) $resolver->logoDataUri('storage/' . $relativePath));
        $this->assertStringStartsWith('data:image/', (string) $resolver->logoDataUri('/storage/' . $relativePath));
    }

    public function test_it_resolves_uploaded_signatures_without_the_invoice_image_size_limit(): void
    {
        config([
            'pdf.optimize_images' => true,
            'pdf.max_image_kb' => 1,
        ]);

        $relativePath = 'tests/org-signature.png';
        $absolutePath = storage_path('app/public/' . $relativePath);

        File::ensureDirectoryExists(dirname($absolutePath));
        File::put($absolutePath, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9pR7sQAAAABJRU5ErkJggg=='));

        $resolver = new class(app(\App\Support\PdfImageDataUri::class)) extends InvoicePdfAssetResolver {
            public function imagesEnabled(): bool
            {
                return true;
            }
        };

        $this->assertStringStartsWith('data:image/', (string) $resolver->signatureDataUri($relativePath));
    }
}
