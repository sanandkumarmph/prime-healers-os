<?php

namespace Tests\Feature\Regression;

use App\Models\Organization;
use App\Support\PdfImageDataUri;
use Barryvdh\DomPDF\Facade\Pdf as DomPdf;
use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class LedgerPdfImageRenderingRegressionTest extends TestCase
{
    public function test_pdf_image_helper_converts_local_readable_images_to_data_uris(): void
    {
        $path = storage_path('app/public/ledger-tests/logo.png');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, base64_decode($this->tinyPngBase64()));

        $dataUri = app(PdfImageDataUri::class)->fromLocalPath($path);

        $this->assertIsString($dataUri);
        $this->assertStringStartsWith('data:image/png;base64,', $dataUri);
        $this->assertStringNotContainsString(storage_path(), $dataUri);
        $this->assertNull(app(PdfImageDataUri::class)->fromLocalPath(storage_path('app/public/ledger-tests/missing.png')));

        File::deleteDirectory(storage_path('app/public/ledger-tests'));
    }

    public function test_ledger_pdf_view_renders_logo_and_signature_data_uris_without_filesystem_paths(): void
    {
        $logoDataUri = 'data:image/png;base64,' . $this->tinyPngBase64();
        $signatureDataUri = 'data:image/png;base64,' . $this->tinyPngBase64();

        $html = view('ledger.pdf', [
            'statement' => $this->statement(),
            'organization' => $this->organization(),
            'pdfImagesEnabled' => true,
            'logoDataUri' => $logoDataUri,
            'signatureDataUri' => $signatureDataUri,
        ])->render();

        $this->assertStringContainsString('src="' . $logoDataUri . '"', $html);
        $this->assertStringContainsString('src="' . $signatureDataUri . '"', $html);
        $this->assertSame(1, substr_count($html, 'Authorized Signatory'));
        $this->assertStringNotContainsString(storage_path(), $html);
        $this->assertStringNotContainsString(public_path(), $html);
        $this->assertStringContainsString('Rs. 7,600.00', $html);
    }

    public function test_missing_logo_and_signature_do_not_render_broken_image_placeholders(): void
    {
        $html = view('ledger.pdf', [
            'statement' => $this->statement(),
            'organization' => $this->organization(),
            'pdfImagesEnabled' => false,
            'logoDataUri' => null,
            'signatureDataUri' => null,
        ])->render();

        $this->assertStringNotContainsString('<img class="brand-mark"', $html);
        $this->assertStringNotContainsString('<img class="signature-image"', $html);
        $this->assertStringNotContainsString('Authorized Signatory', $html);
        $this->assertStringContainsString('Balance Due', $html);
        $this->assertStringContainsString('Rs. 7,600.00', $html);
    }

    public function test_signature_is_rendered_only_once_for_multi_page_statements(): void
    {
        $statement = $this->statement(80);

        $html = view('ledger.pdf', [
            'statement' => $statement,
            'organization' => $this->organization(),
            'pdfImagesEnabled' => true,
            'logoDataUri' => 'data:image/png;base64,' . $this->tinyPngBase64(),
            'signatureDataUri' => 'data:image/png;base64,' . $this->tinyPngBase64(),
        ])->render();

        $this->assertSame(1, substr_count($html, 'Authorized Signatory'));
    }

    public function test_ledger_pdf_still_generates_without_image_capability(): void
    {
        $pdf = DomPdf::loadView('ledger.pdf', [
            'statement' => $this->statement(),
            'organization' => $this->organization(),
            'pdfImagesEnabled' => false,
            'logoDataUri' => null,
            'signatureDataUri' => null,
        ])->setPaper('a4', 'portrait');

        $output = $pdf->output();

        $this->assertIsString($output);
        $this->assertStringStartsWith('%PDF', $output);
    }

    private function organization(): Organization
    {
        $organization = new Organization();
        $organization->forceFill([
            'name' => 'Prime Healers',
            'legal_name' => 'Prime Healers Healthcare Pvt. Ltd.',
            'address' => '#36 & 37, 14th Cross',
            'city' => 'Bengaluru',
            'state' => 'Karnataka',
            'pincode' => '560091',
            'gst_number' => '29AASCA2828H1ZA',
            'phone' => '+91 98765 03036',
            'email' => 'admin@primehealers.com',
        ]);

        return $organization;
    }

    private function statement(int $entries = 1): array
    {
        $rows = [];

        for ($i = 1; $i <= $entries; $i++) {
            $rows[] = [
                'entry_date' => Carbon::parse('2026-07-10')->addDays($i - 1),
                'source_label' => 'Invoice',
                'reference' => 'INV-' . str_pad((string) $i, 6, '0', STR_PAD_LEFT),
                'particulars' => 'Invoice generated',
                'due_date' => null,
                'debit' => $i === 1 ? 7600 : 0,
                'credit' => 0,
                'running_balance' => 7600,
            ];
        }

        return [
            'summary' => [
                'opening_balance' => 0,
                'total_debit' => 7600,
                'total_credit' => 0,
                'closing_balance' => 7600,
            ],
            'recipient' => [
                'name' => 'Aarav Sharma',
                'lines' => ['12, Residency Road', 'Bengaluru, Karnataka 560025'],
                'gstin' => '29ABCDE1234F1Z8',
                'phone' => '+91 90000 00000',
                'email' => 'aarav@example.com',
            ],
            'context_label' => 'Aarav Sharma',
            'date_label' => '01 Jul 2026 - 11 Jul 2026',
            'filters' => [
                'from_date' => Carbon::parse('2026-07-01'),
            ],
            'entries' => $rows,
        ];
    }

    private function tinyPngBase64(): string
    {
        return 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9pR7sQAAAABJRU5ErkJggg==';
    }
}