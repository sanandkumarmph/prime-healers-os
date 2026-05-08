<?php

namespace Tests\Feature\Regression;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class ControlledPdfEngineRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_dompdf_engine_returns_pdf_response_with_invoice_details(): void
    {
        [$organization, $user, $invoice] = $this->invoiceContext();

        $this->actingAs($user);

        config([
            'pdf.engine' => 'dompdf',
            'pdf.currency_symbol' => '',
            'pdf.currency_fallback' => 'Rs.',
        ]);

        $response = $this->get(route('invoices.print', $invoice->id));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');

        $content = $response->getContent();

        $this->assertStringStartsWith('%PDF', $content);
        $this->assertPdfContainsText($content, $invoice->invoice_number);
        $this->assertPdfContainsText($content, 'Invoice Customer');
        $this->assertPdfContainsText($content, 'Oxygen Concentrator 5 LPM');
        $this->assertPdfContainsText($content, 'Rs.');
        $this->assertPdfContainsText($content, number_format((float) $invoice->total_amount, 2));
        $this->assertPdfContainsText($content, number_format((float) $invoice->balance_amount, 2));
    }

    public function test_auto_engine_falls_back_to_dompdf_when_browsershot_runtime_is_unavailable(): void
    {
        [$organization, $user, $invoice] = $this->invoiceContext();

        $this->actingAs($user);

        config([
            'pdf.engine' => 'auto',
            'pdf.node_binary' => 'missing-node-binary',
            'pdf.currency_symbol' => '',
            'pdf.currency_fallback' => 'Rs.',
        ]);

        $response = $this->get(route('invoices.print', $invoice->id));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_browsershot_engine_fails_gracefully_when_runtime_is_unavailable(): void
    {
        [$organization, $user, $invoice] = $this->invoiceContext();

        $this->actingAs($user);

        config([
            'pdf.engine' => 'browsershot',
            'pdf.node_binary' => 'missing-node-binary',
            'pdf.node_module_path' => base_path('node_modules'),
            'pdf.browser_path' => null,
        ]);

        $this->get(route('invoices.print', $invoice->id))
            ->assertRedirect(route('invoices.show', $invoice->id))
            ->assertSessionHas('error', 'Invoice PDF could not be generated with Browsershot. Check PDF_BROWSER_PATH, PDF_NODE_BINARY, PDF_DISABLE_SANDBOX, and Chromium runtime configuration.');
    }

    public function test_invoice_print_permission_boundary_is_unchanged_for_dompdf_engine(): void
    {
        [$organization, $user, $invoice] = $this->invoiceContext();

        $readerOnlyRole = Role::create([
            'organization_id' => $organization->id,
            'name' => 'Invoice Read Only',
            'slug' => 'invoice_read_only',
            'description' => 'Invoice Read Only',
            'permissions' => Role::normalizePermissions([
                'invoices' => ['read'],
            ]),
            'is_system' => false,
            'is_active' => true,
        ]);

        $readerOnlyUser = TestData::user($organization, [
            'role' => 'staff',
            'role_id' => $readerOnlyRole->id,
        ]);

        config(['pdf.engine' => 'dompdf']);

        $this->actingAs($readerOnlyUser)
            ->get(route('invoices.print', $invoice->id))
            ->assertRedirect($readerOnlyUser->defaultRedirectPath())
            ->assertSessionHas('error', 'You are not authorized to access this section.');
    }

    private function invoiceContext(): array
    {
        $organization = TestData::organization([
            'name' => 'Rentnexis Oxygen Care',
            'phone' => '9876500000',
            'email' => 'accounts@rentnexis.test',
            'gst_number' => '07ABCDE1234F1Z5',
            'default_terms' => "Payment due within 7 days.\nGoods once sold/rented will be billed as per agreement.",
        ]);
        $user = TestData::user($organization);

        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Invoice Customer',
            'phone' => '9876543210',
            'email' => 'customer@example.test',
            'city' => 'New Delhi',
            'customer_type' => 'Individual',
        ]);

        $product = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Oxygen Concentrator 5 LPM',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'available_quantity' => 5,
            'total_quantity' => 5,
            'price_per_day' => 4500,
        ]);

        $invoice = Invoice::create([
            'organization_id' => $organization->id,
            'invoice_number' => 'INV-ENGINE-001',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(7)->toDateString(),
            'customer_id' => $customer->id,
            'bill_to_name' => $customer->name,
            'bill_to_phone' => $customer->phone,
            'bill_to_email' => $customer->email,
            'bill_to_city' => 'New Delhi',
            'bill_to_state' => 'Delhi',
            'bill_to_pincode' => '110001',
            'tax_type' => 'cgst_sgst',
            'tax_calculation_mode' => 'exclusive',
            'status' => 'partial',
            'payment_status' => 'partial',
            'subtotal' => 4500,
            'discount_amount' => 250,
            'deposit_amount' => 1000,
            'shipping_charges' => 150,
            'taxable_amount' => 4250,
            'cgst_amount' => 382.50,
            'sgst_amount' => 382.50,
            'total_tax_amount' => 765,
            'total_amount' => 6165,
            'paid_amount' => 2000,
            'balance_amount' => 4165,
            'notes' => 'Please return accessories with the equipment.',
            'terms_conditions' => "Payment due within 7 days.\nLate return charges may apply.",
        ]);

        $invoice->items()->create([
            'product_id' => $product->id,
            'source_type' => 'manual',
            'source_id' => null,
            'description' => $product->name,
            'quantity' => 1,
            'unit' => 'rental',
            'rate' => 4500,
            'discount_amount' => 250,
            'taxable_amount' => 4250,
            'tax_percentage' => 18,
            'cgst_rate' => 9,
            'sgst_rate' => 9,
            'cgst_amount' => 382.50,
            'sgst_amount' => 382.50,
            'line_total' => 5015,
        ]);

        $invoice->payments()->create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'invoice_id' => $invoice->id,
            'payment_date' => now()->toDateString(),
            'amount' => 2000,
            'payment_method' => 'bank_transfer',
            'notes' => 'Advance received',
        ]);

        return [$organization, $user, $invoice];
    }

    private function assertPdfContainsText(string $content, string $needle): void
    {
        $normalizedContent = str_replace("\0", '', $content);

        if (str_contains($normalizedContent, $needle)) {
            $this->assertTrue(true);

            return;
        }

        $utf16Needle = mb_convert_encoding($needle, 'UTF-16BE', 'UTF-8');

        $this->assertTrue(
            str_contains($content, $utf16Needle),
            sprintf('Failed asserting that PDF output contains "%s".', $needle)
        );
    }
}
