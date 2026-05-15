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

    public function test_bulk_csv_export_rejects_more_than_five_hundred_selected_rows(): void
    {
        [$organization, $customer] = $this->invoiceContext();
        $invoice = $this->createInvoice($organization->id, $customer->id, 'INV-BULK-LIMIT-001');

        $response = $this->from(route('invoices.index'))->post(route('invoices.bulk.export.csv'), [
            'invoice_ids' => array_fill(0, 501, $invoice->id),
        ]);

        $response->assertRedirect(route('invoices.index'));
        $response->assertSessionHas('error', 'You can export up to 500 invoices at a time.');
    }

    public function test_bulk_print_renders_selected_invoices_and_ignores_other_organizations(): void
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
        $response->assertSee('Adult Diapers Pant Type XL - Svach');
        $response->assertSee('Bulk print layout uses a separate compact template');
        $response->assertSee('bulk-invoice-page', false);
        $response->assertDontSee('INV-BULK-DOC-OTHER');
    }

    public function test_bulk_print_rejects_more_than_five_hundred_selected_rows(): void
    {
        [$organization, $customer] = $this->invoiceContext();
        $invoice = $this->createInvoice($organization->id, $customer->id, 'INV-BULK-PRINT-LIMIT-001');

        $response = $this->from(route('invoices.index'))->post(route('invoices.bulk.print'), [
            'invoice_ids' => array_fill(0, 501, $invoice->id),
        ]);

        $response->assertRedirect(route('invoices.index'));
        $response->assertSessionHas('error', 'You can print up to 500 invoices at a time.');
    }

    public function test_bulk_print_supports_multiple_selected_invoices_with_bulk_page_wrappers(): void
    {
        [$organization, $customer] = $this->invoiceContext();

        $first = $this->createInvoice($organization->id, $customer->id, 'INV-BULK-MULTI-001');
        $second = $this->createInvoice($organization->id, $customer->id, 'INV-BULK-MULTI-002');

        $response = $this->post(route('invoices.bulk.print'), [
            'invoice_ids' => [$first->id, $second->id],
        ]);

        $response->assertOk();
        $response->assertSee('INV-BULK-MULTI-001');
        $response->assertSee('INV-BULK-MULTI-002');
        $this->assertSame(2, substr_count($response->getContent(), 'class="bulk-invoice-page"'));
    }

    public function test_individual_invoice_pdf_templates_remain_untouched_and_bulk_uses_separate_wrapper(): void
    {
        $bulkTemplate = (string) file_get_contents(resource_path('views/invoices/bulk-print.blade.php'));
        $dompdfTemplate = (string) file_get_contents(resource_path('views/invoices/pdf-dompdf.blade.php'));
        $printTemplate = (string) file_get_contents(resource_path('views/invoices/print.blade.php'));
        $renderer = (string) file_get_contents(app_path('Support/InvoicePdfRenderer.php'));
        $pdfConfig = (string) file_get_contents(config_path('pdf.php'));

        $this->assertStringContainsString("'browsershot_view' => 'invoices.print'", $pdfConfig);
        $this->assertStringContainsString("'dompdf_view' => 'invoices.pdf-dompdf'", $pdfConfig);
        $this->assertStringNotContainsString('browsershot_margin_mm', $pdfConfig);
        $this->assertStringNotContainsString('browsershot_scale', $pdfConfig);
        $this->assertStringNotContainsString('debug_runtime', $pdfConfig);
        $this->assertStringContainsString("->margins(12, 12, 12, 12, 'mm')", $renderer);
        $this->assertStringNotContainsString("preferCSSPageSize", $renderer);
        $this->assertStringNotContainsString("->scale(", $renderer);
        $this->assertStringContainsString("invoice_pdf_size_warning", $renderer);
        $this->assertStringContainsString("InvoicePdfAssetResolver::class", $printTemplate);
        $this->assertStringContainsString("InvoicePdfAssetResolver::class", $dompdfTemplate);
        $this->assertStringContainsString("logoDataUri()", $printTemplate);
        $this->assertStringContainsString("logoDataUri()", $dompdfTemplate);
        $this->assertStringNotContainsString('$organization?->logo', $printTemplate);
        $this->assertStringNotContainsString('$organization?->logo', $dompdfTemplate);
        $this->assertStringNotContainsString('fonts.bunny.net', $printTemplate);
        $this->assertStringNotContainsString('rentnexis-logo.png', $printTemplate);
        $this->assertStringNotContainsString('logo-rentnexis.png', $printTemplate);
        $this->assertStringNotContainsString('rentnexis-logo.png', $dompdfTemplate);
        $this->assertStringNotContainsString('logo-rentnexis.png', $dompdfTemplate);
        $this->assertStringContainsString('Products Sold With Rental', $printTemplate);
        $this->assertStringContainsString('Products Sold With Rental', $dompdfTemplate);
        $this->assertStringNotContainsString("invoices.partials.invoice-document", $printTemplate);
        $this->assertStringNotContainsString("invoices.partials.invoice-document", $dompdfTemplate);
        $this->assertStringNotContainsString("invoices.partials.invoice-document-styles", $printTemplate);
        $this->assertStringNotContainsString("invoices.partials.invoice-document-styles", $dompdfTemplate);
        $this->assertStringContainsString('@page {', $printTemplate);
        $this->assertStringContainsString('@page {', $dompdfTemplate);
        $this->assertStringContainsString('.bulk-invoice-page:not(:last-child)', $bulkTemplate);
        $this->assertStringContainsString('page-break-after: always;', $bulkTemplate);
        $this->assertStringContainsString('.bulk-items {', $bulkTemplate);
        $this->assertStringContainsString('table-layout: fixed;', $bulkTemplate);
        $this->assertStringContainsString('Item &amp; Description', $bulkTemplate);
        $this->assertStringContainsString("InvoicePdfAssetResolver::class", $bulkTemplate);
        $this->assertStringContainsString("logoBrowserUrl()", $bulkTemplate);
        $this->assertStringNotContainsString('invoices.partials.invoice-document', $bulkTemplate);
        $this->assertStringNotContainsString('invoices.print', $bulkTemplate);
        $this->assertStringNotContainsString('pdf-page-shell', $printTemplate);
        $this->assertStringNotContainsString('pdf-document', $printTemplate);
        $this->assertStringNotContainsString("debug_runtime", $printTemplate);
        $this->assertStringNotContainsString('pdf-page-shell', $dompdfTemplate);
        $this->assertStringNotContainsString('pdf-document', $dompdfTemplate);
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
