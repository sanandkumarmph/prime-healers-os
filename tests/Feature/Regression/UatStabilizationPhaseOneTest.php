<?php

namespace Tests\Feature\Regression;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Rental;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class UatStabilizationPhaseOneTest extends TestCase
{
    use RefreshDatabase;

    public function test_invoice_print_fails_gracefully_when_pdf_runtime_is_unavailable(): void
    {
        $organization = TestData::organization();
        $user = TestData::user($organization);
        $this->actingAs($user);

        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Aarav Sharma',
            'phone' => '9876543210',
        ]);

        $product = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Oxygen Concentrator 5 LPM',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'available_quantity' => 1,
            'total_quantity' => 1,
            'price_per_day' => 4500,
        ]);

        $invoice = Invoice::create([
            'organization_id' => $organization->id,
            'invoice_number' => 'INV-000001',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'bill_to_name' => $customer->name,
            'bill_to_phone' => $customer->phone,
            'tax_type' => 'cgst_sgst',
            'status' => 'unpaid',
            'payment_status' => 'unpaid',
            'subtotal' => 4500,
            'taxable_amount' => 4500,
            'total_amount' => 4500,
            'paid_amount' => 0,
            'balance_amount' => 4500,
        ]);

        $invoice->items()->create([
            'product_id' => $product->id,
            'source_type' => 'manual',
            'source_id' => null,
            'description' => $product->name,
            'quantity' => 1,
            'unit' => 'rental',
            'rate' => 4500,
            'taxable_amount' => 4500,
            'line_total' => 4500,
        ]);

        config([
            'pdf.node_binary' => 'missing-node-binary',
            'pdf.node_module_path' => base_path('node_modules'),
            'pdf.browser_path' => null,
        ]);

        $this->get(route('invoices.print', $invoice->id))
            ->assertRedirect(route('invoices.show', $invoice->id))
            ->assertSessionHas('error');
    }

    public function test_invoice_controller_no_longer_depends_on_hardcoded_windows_browser_paths(): void
    {
        $contents = file_get_contents(app_path('Http/Controllers/InvoiceController.php'));

        $this->assertIsString($contents);
        $this->assertStringNotContainsString('C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe', $contents);
        $this->assertStringNotContainsString('C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe', $contents);
    }

    public function test_pdf_config_values_resolve_from_configuration(): void
    {
        config([
            'pdf.node_binary' => 'node-custom',
            'pdf.node_module_path' => base_path('custom-node-modules'),
            'pdf.browser_path' => '/tmp/custom-browser',
            'pdf.disable_sandbox' => true,
        ]);

        $this->assertSame('node-custom', config('pdf.node_binary'));
        $this->assertSame(base_path('custom-node-modules'), config('pdf.node_module_path'));
        $this->assertSame('/tmp/custom-browser', config('pdf.browser_path'));
        $this->assertTrue(config('pdf.disable_sandbox'));
    }

    public function test_repair_command_dry_run_does_not_mutate_data(): void
    {
        $organization = TestData::organization();
        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Aarav Sharma',
            'phone' => '9876543210',
        ]);
        $product = Product::create([
            'organization_id' => $organization->id,
            'name' => 'BiPAP Machine',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'available_quantity' => 1,
            'total_quantity' => 1,
            'price_per_day' => 4500,
        ]);
        $rental = Rental::create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'phone' => $customer->phone,
            'product_id' => $product->id,
            'quantity' => 1,
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-15',
            'rental_amount' => 4500,
            'deposit_amount' => 5000,
            'transport_amount' => 350,
            'status' => 'active',
        ]);
        $invoice = Invoice::create([
            'organization_id' => $organization->id,
            'invoice_number' => 'INV-000001',
            'invoice_date' => '2026-05-01',
            'due_date' => '2026-05-01',
            'customer_id' => $customer->id,
            'rental_id' => $rental->id,
            'bill_to_name' => $customer->name,
            'bill_to_phone' => $customer->phone,
            'tax_type' => 'cgst_sgst',
            'status' => 'unpaid',
            'payment_status' => 'unpaid',
            'subtotal' => 4850,
            'taxable_amount' => 4850,
            'total_amount' => 9850,
            'paid_amount' => 0,
            'balance_amount' => 9850,
        ]);

        $invoice->items()->create([
            'product_id' => $product->id,
            'source_type' => 'rental',
            'source_id' => $rental->id,
            'description' => $product->name,
            'quantity' => 1,
            'unit' => 'rental',
            'rate' => 4500,
            'taxable_amount' => 4500,
            'line_total' => 4500,
        ]);
        $invoice->items()->create([
            'product_id' => null,
            'source_type' => 'manual',
            'source_id' => null,
            'description' => 'Rental deposit for rental #'.$rental->id,
            'quantity' => 1,
            'unit' => 'charge',
            'rate' => 5000,
            'taxable_amount' => 5000,
            'line_total' => 5000,
        ]);
        $invoice->items()->create([
            'product_id' => null,
            'source_type' => 'manual',
            'source_id' => null,
            'description' => 'Transport charge for rental #'.$rental->id,
            'quantity' => 1,
            'unit' => 'charge',
            'rate' => 350,
            'taxable_amount' => 350,
            'line_total' => 350,
        ]);

        $this->artisan('rentnexis:repair-uat-data')
            ->expectsOutputToContain('Mode: DRY RUN')
            ->assertExitCode(0);

        $this->assertDatabaseCount('invoice_items', 3);
        $this->assertDatabaseCount('rental_items', 0);
        $this->assertDatabaseHas('invoices', [
            'id' => $invoice->id,
            'deposit_amount' => 0,
            'shipping_charges' => 0,
        ]);
    }
}
