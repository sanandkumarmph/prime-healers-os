<?php

namespace Tests\Feature\Regression;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class InvoiceBulkOperationsRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_index_supports_allowed_per_page_values_and_caps_high_values(): void
    {
        [$organization, $customer] = $this->invoiceContext();

        $this->createInvoices($organization->id, $customer->id, 70);

        foreach ([20, 50, 100, 250, 500] as $perPage) {
            $response = $this->get(route('invoices.index', ['per_page' => $perPage]));

            $response->assertOk();
            $this->assertSame($perPage, $response->viewData('invoices')->perPage());
        }

        $capped = $this->get(route('invoices.index', ['per_page' => 999]));

        $capped->assertOk();
        $this->assertSame(500, $capped->viewData('invoices')->perPage());
    }

    public function test_invoice_index_preserves_filters_with_per_page_in_pagination_links(): void
    {
        [$organization, $customer] = $this->invoiceContext();

        $this->createInvoices($organization->id, $customer->id, 70, 'unpaid');

        $response = $this->get(route('invoices.index', [
            'status' => 'unpaid',
            'search' => 'INV-BULK-0',
            'per_page' => 20,
        ]));

        $response->assertOk();
        $response->assertSee('status=unpaid&amp;search=INV-BULK-0&amp;per_page=20&amp;page=2', false);
    }

    public function test_bulk_csv_export_supports_more_than_twenty_selected_invoices_and_ignores_other_organizations(): void
    {
        [$organization, $customer] = $this->invoiceContext();
        $invoiceIds = $this->createInvoices($organization->id, $customer->id, 25);

        $otherOrganization = TestData::organization(['name' => 'Other Org']);
        $otherCustomer = Customer::create([
            'organization_id' => $otherOrganization->id,
            'name' => 'Other Customer',
            'phone' => '9000009999',
        ]);
        $otherInvoice = $this->createInvoice($otherOrganization->id, $otherCustomer->id, 'INV-OTHER-001');

        $response = $this->post(route('invoices.bulk.export.csv'), [
            'invoice_ids' => array_merge($invoiceIds, [$otherInvoice->id]),
        ]);

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $content = $response->streamedContent();

        $this->assertStringContainsString('INV-BULK-001', $content);
        $this->assertStringContainsString('INV-BULK-025', $content);
        $this->assertStringNotContainsString('INV-OTHER-001', $content);
    }

    public function test_bulk_print_uses_shared_invoice_document_and_renders_tax_and_rental_sale_lines(): void
    {
        [$organization, $customer] = $this->invoiceContext();

        $invoice = $this->createInvoice($organization->id, $customer->id, 'INV-BULK-DOC-001', [
            'bill_to_name' => 'Bulk Print Customer',
            'bill_to_phone' => '9876543210',
            'place_of_supply_state' => 'Karnataka',
            'tax_type' => 'cgst_sgst',
            'tax_calculation_mode' => 'exclusive',
            'subtotal' => 2450,
            'taxable_amount' => 2450,
            'cgst_amount' => 65.25,
            'sgst_amount' => 65.25,
            'igst_amount' => 0,
            'total_tax_amount' => 130.50,
            'deposit_amount' => 2000,
            'shipping_charges' => 800,
            'total_amount' => 5380.50,
            'balance_amount' => 5380.50,
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'source_type' => 'rental',
            'description' => 'Hospital Bed Electric - Kraft 2 Function',
            'quantity' => 1,
            'unit' => 'rental',
            'days' => 10,
            'rate' => 1000,
            'discount_amount' => 0,
            'taxable_amount' => 1000,
            'tax_percentage' => 0,
            'tax_type' => 'cgst_sgst',
            'cgst_rate' => 0,
            'sgst_rate' => 0,
            'igst_rate' => 0,
            'cgst_amount' => 0,
            'sgst_amount' => 0,
            'igst_amount' => 0,
            'line_total' => 1000,
        ]);

        InvoiceItem::create([
            'invoice_id' => $invoice->id,
            'source_type' => 'rental_sale',
            'description' => 'Adult Diapers Pant Type XL - Svach',
            'quantity' => 1,
            'unit' => 'sale',
            'rate' => 1450,
            'discount_amount' => 0,
            'taxable_amount' => 1450,
            'tax_percentage' => 9,
            'tax_type' => 'cgst_sgst',
            'cgst_rate' => 4.5,
            'sgst_rate' => 4.5,
            'igst_rate' => 0,
            'cgst_amount' => 65.25,
            'sgst_amount' => 65.25,
            'igst_amount' => 0,
            'line_total' => 1580.50,
        ]);

        $otherOrganization = TestData::organization(['name' => 'Other Org']);
        $otherCustomer = Customer::create([
            'organization_id' => $otherOrganization->id,
            'name' => 'Other Customer',
            'phone' => '9000009998',
        ]);
        $otherInvoice = $this->createInvoice($otherOrganization->id, $otherCustomer->id, 'INV-BULK-DOC-OTHER');

        $response = $this->post(route('invoices.bulk.print'), [
            'invoice_ids' => [$invoice->id, $otherInvoice->id],
        ]);

        $response->assertOk();
        $response->assertSee('INV-BULK-DOC-001');
        $response->assertSee('Products Sold With Rental');
        $response->assertSee('Adult Diapers Pant Type XL - Svach');
        $response->assertSee('CGST + SGST');
        $response->assertDontSee('INV-BULK-DOC-OTHER');

        $this->assertStringContainsString(
            "invoices.partials.invoice-document",
            (string) file_get_contents(resource_path('views/invoices/bulk-print.blade.php'))
        );
        $this->assertStringContainsString(
            "invoices.partials.invoice-document",
            (string) file_get_contents(resource_path('views/invoices/print.blade.php'))
        );
        $this->assertStringContainsString(
            "invoices.partials.invoice-document",
            (string) file_get_contents(resource_path('views/invoices/pdf-dompdf.blade.php'))
        );
        $this->assertStringContainsString(
            "invoices.partials.invoice-document-styles",
            (string) file_get_contents(resource_path('views/invoices/bulk-print.blade.php'))
        );
        $this->assertStringContainsString(
            "invoices.partials.invoice-document-styles",
            (string) file_get_contents(resource_path('views/invoices/print.blade.php'))
        );
        $this->assertStringContainsString(
            "invoices.partials.invoice-document-styles",
            (string) file_get_contents(resource_path('views/invoices/pdf-dompdf.blade.php'))
        );
    }

    public function test_shared_invoice_styles_constrain_logo_and_use_between_invoice_page_breaks(): void
    {
        $styles = (string) file_get_contents(resource_path('views/invoices/partials/invoice-document-styles.blade.php'));
        $bulkTemplate = (string) file_get_contents(resource_path('views/invoices/bulk-print.blade.php'));
        $sharedPartial = (string) file_get_contents(resource_path('views/invoices/partials/invoice-document.blade.php'));
        $dompdfTemplate = (string) file_get_contents(resource_path('views/invoices/pdf-dompdf.blade.php'));
        $printTemplate = (string) file_get_contents(resource_path('views/invoices/print.blade.php'));
        $renderer = (string) file_get_contents(app_path('Support/InvoicePdfRenderer.php'));
        $pdfConfig = (string) file_get_contents(config_path('pdf.php'));

        $this->assertStringContainsString('max-width: 26mm;', $styles);
        $this->assertStringContainsString('max-height: 13mm;', $styles);
        $this->assertStringContainsString('width: auto !important;', $styles);
        $this->assertStringContainsString('height: auto !important;', $styles);
        $this->assertStringContainsString('.invoice-logo {', $styles);
        $this->assertStringContainsString('max-width: 95px !important;', $styles);
        $this->assertStringContainsString('max-height: 45px !important;', $styles);
        $this->assertStringContainsString('margin: 10mm;', $styles);
        $this->assertStringContainsString('body.pdf-document {', $styles);
        $this->assertStringContainsString('font-size: 9.5px;', $styles);
        $this->assertStringContainsString('.pdf-page-shell {', $styles);
        $this->assertStringContainsString('padding: 6mm;', $styles);
        $this->assertStringContainsString('.invoice-document {', $styles);
        $this->assertStringContainsString('box-sizing: border-box;', $styles);
        $this->assertStringContainsString('overflow: hidden;', $styles);
        $this->assertStringContainsString('overflow-wrap: anywhere;', $styles);
        $this->assertStringContainsString('max-width: 95px !important;', $styles);
        $this->assertStringContainsString('max-height: 45px !important;', $styles);
        $this->assertStringNotContainsString('100vw', $styles);
        $this->assertStringNotContainsString('margin-left: -', $styles);
        $this->assertStringContainsString("images/prime-healers-logo.png", $sharedPartial);
        $this->assertStringContainsString("invoice-page invoice-document", $sharedPartial);
        $this->assertStringContainsString("\$compactPdfTable = \$compactPdfTable ?? false;", $sharedPartial);
        $this->assertStringContainsString("'serial' => 5", $sharedPartial);
        $this->assertStringContainsString("'description' => 45", $sharedPartial);
        $this->assertStringContainsString("'qty' => 8", $sharedPartial);
        $this->assertStringContainsString("'rate' => 14", $sharedPartial);
        $this->assertStringContainsString("'tax' => 13", $sharedPartial);
        $this->assertStringContainsString("'amount' => 15", $sharedPartial);
        $this->assertStringContainsString("HSN/SAC: ", $sharedPartial);
        $this->assertStringContainsString("Discount: ", $sharedPartial);
        $this->assertStringContainsString("Tax Amt:", $sharedPartial);
        $this->assertStringContainsString("@if(\$compactPdfTable || \$showTaxColumns)", $sharedPartial);
        $this->assertStringNotContainsString('>HSN/SAC</th>', $sharedPartial);
        $this->assertStringNotContainsString('>Tax Amt</th>', $sharedPartial);
        $this->assertStringNotContainsString('$organization?->logo', $sharedPartial);
        $this->assertStringNotContainsString('rentnexis-logo', $sharedPartial);
        $this->assertStringNotContainsString('logo-rentnexis', $sharedPartial);
        $this->assertStringContainsString('.summary-totals-wrap {', $styles);
        $this->assertStringContainsString('display: block;', $styles);
        $this->assertStringContainsString('margin-left: auto;', $styles);
        $this->assertStringContainsString("'browsershot_view' => 'invoices.print'", $pdfConfig);
        $this->assertStringContainsString("'dompdf_view' => 'invoices.pdf-dompdf'", $pdfConfig);
        $this->assertStringContainsString("'browsershot_margin_mm' => (float) env('PDF_BROWSERSHOT_MARGIN_MM', 10),", $pdfConfig);
        $this->assertStringContainsString("'browsershot_scale' => (float) env('PDF_BROWSERSHOT_SCALE', 0.9),", $pdfConfig);
        $this->assertStringContainsString("'debug_runtime' => (bool) env('PDF_DEBUG_RUNTIME', false),", $pdfConfig);
        $this->assertStringContainsString("'debug_runtime_marker' => env('PDF_DEBUG_RUNTIME_MARKER', 'PDF_RUNTIME_MARKER_2026_05_15_MARGIN_FIX')", $pdfConfig);
        $this->assertStringContainsString("->margins(\$marginMm, \$marginMm, \$marginMm, \$marginMm, 'mm')", $renderer);
        $this->assertStringContainsString("->setOption('preferCSSPageSize', true)", $renderer);
        $this->assertStringContainsString("->setOption('landscape', false)", $renderer);
        $this->assertStringContainsString('->scale($scale)', $renderer);
        $this->assertStringContainsString("Log::info('invoice_pdf_runtime_debug'", $renderer);
        $this->assertStringContainsString("contains_pdf_document_body_class", $renderer);
        $this->assertStringContainsString("contains_page_margin", $renderer);
        $this->assertStringContainsString("contains_invoice_document", $renderer);
        $this->assertStringContainsString("contains_runtime_marker", $renderer);
        $this->assertStringNotContainsString('page-break-before', $bulkTemplate);
        $this->assertStringContainsString('.bulk-invoice-page:not(:last-child) {', $bulkTemplate);
        $this->assertStringContainsString('page-break-after: always;', $bulkTemplate);
        $this->assertStringNotContainsString('bulk-invoice-page', $printTemplate);
        $this->assertStringContainsString('<body class="pdf-document">', $printTemplate);
        $this->assertStringContainsString('<div class="pdf-page-shell">', $printTemplate);
        $this->assertStringContainsString("'compactPdfTable' => true", $printTemplate);
        $this->assertStringContainsString("@if(config('pdf.debug_runtime'))", $printTemplate);
        $this->assertStringContainsString("config('pdf.debug_runtime_marker')", $printTemplate);
        $this->assertStringNotContainsString('page-break-after', $dompdfTemplate);
        $this->assertStringNotContainsString('link rel="stylesheet"', $dompdfTemplate);
        $this->assertStringContainsString('<body class="pdf-document">', $dompdfTemplate);
        $this->assertStringContainsString('<div class="pdf-page-shell">', $dompdfTemplate);
        $this->assertStringContainsString("'compactPdfTable' => true", $dompdfTemplate);
        $this->assertStringContainsString('<div class="pdf-page-shell">', $bulkTemplate);
        $this->assertStringContainsString("'compactPdfTable' => true", $bulkTemplate);
    }

    private function invoiceContext(): array
    {
        $organization = TestData::organization();
        $user = TestData::user($organization);
        $this->actingAs($user);

        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Bulk Invoice Customer',
            'phone' => '9000000001',
        ]);

        return [$organization, $customer];
    }

    private function createInvoices(int $organizationId, int $customerId, int $count, string $paymentStatus = 'unpaid'): array
    {
        $ids = [];

        for ($index = 1; $index <= $count; $index++) {
            $ids[] = $this->createInvoice(
                $organizationId,
                $customerId,
                'INV-BULK-' . str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                [
                    'payment_status' => $paymentStatus,
                    'status' => $paymentStatus,
                    'subtotal' => 1000 + $index,
                    'taxable_amount' => 1000 + $index,
                    'total_amount' => 1000 + $index,
                    'balance_amount' => 1000 + $index,
                ]
            )->id;
        }

        return $ids;
    }

    private function createInvoice(int $organizationId, int $customerId, string $invoiceNumber, array $overrides = []): Invoice
    {
        return Invoice::create(array_merge([
            'organization_id' => $organizationId,
            'invoice_number' => $invoiceNumber,
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'customer_id' => $customerId,
            'bill_to_name' => 'Invoice Customer',
            'bill_to_phone' => '9000000001',
            'tax_type' => 'cgst_sgst',
            'tax_calculation_mode' => 'exclusive',
            'status' => 'unpaid',
            'payment_status' => 'unpaid',
            'subtotal' => 1000,
            'discount_amount' => 0,
            'deposit_amount' => 0,
            'shipping_charges' => 0,
            'taxable_amount' => 1000,
            'cgst_amount' => 0,
            'sgst_amount' => 0,
            'igst_amount' => 0,
            'total_tax_amount' => 0,
            'total_amount' => 1000,
            'paid_amount' => 0,
            'balance_amount' => 1000,
        ], $overrides));
    }
}
