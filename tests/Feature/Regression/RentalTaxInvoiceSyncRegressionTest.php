<?php

namespace Tests\Feature\Regression;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Rental;
use App\Models\RentalSaleItem;
use App\Services\Finance\InvoiceSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TestData;
use Tests\TestCase;

class RentalTaxInvoiceSyncRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_rental_exclusive_cgst_sgst_generates_invoice_tax_split(): void
    {
        [$organization, $customer, $product] = $this->rentalTaxContext();
        $this->actingAs(TestData::user($organization));

        $this->post(route('rentals.store'), [
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'phone' => $customer->phone,
            'product_id' => $product->id,
            'quantity' => 1,
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-10',
            'rental_amount' => 1000,
            'gst_rate' => 18,
            'gst_mode' => 'exclusive',
            'tax_type' => Product::GST_TAX_TYPE_CGST_SGST,
            'deposit_amount' => 0,
            'transport_amount' => 0,
            'other_amount' => 0,
        ])->assertRedirect(route('rentals.index', ['sort_by' => 'latest']));

        $invoice = Invoice::query()->where('organization_id', $organization->id)->with('items')->firstOrFail();
        $rental = Rental::query()->where('organization_id', $organization->id)->with('rentalItems')->firstOrFail();
        $line = $invoice->items->firstWhere('source_type', 'rental');

        $this->assertSame(1000.0, (float) $invoice->subtotal);
        $this->assertSame(1000.0, (float) $invoice->taxable_amount);
        $this->assertSame(90.0, (float) $invoice->cgst_amount);
        $this->assertSame(90.0, (float) $invoice->sgst_amount);
        $this->assertSame(0.0, (float) $invoice->igst_amount);
        $this->assertSame(180.0, (float) $invoice->total_tax_amount);
        $this->assertSame(1180.0, (float) $invoice->total_amount);
        $this->assertSame(18.0, (float) $line->tax_percentage);
        $this->assertSame(90.0, (float) $line->cgst_amount);
        $this->assertSame(90.0, (float) $line->sgst_amount);
        $this->assertSame(1180.0, (float) $line->line_total);
        $this->assertSame(18.0, (float) $rental->rentalItems->first()->gst_rate);
    }

    public function test_rental_missing_tax_type_defaults_to_igst_for_interstate_customer(): void
    {
        [$organization, $customer, $product] = $this->rentalTaxContext([
            'customer_state' => 'Maharashtra',
        ]);
        $this->actingAs(TestData::user($organization));

        $this->post(route('rentals.store'), [
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'phone' => $customer->phone,
            'product_id' => $product->id,
            'quantity' => 1,
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-10',
            'rental_amount' => 1000,
            'gst_rate' => 18,
            'gst_mode' => 'exclusive',
            'deposit_amount' => 0,
            'transport_amount' => 0,
            'other_amount' => 0,
        ])->assertRedirect(route('rentals.index', ['sort_by' => 'latest']));

        $invoice = Invoice::query()->where('organization_id', $organization->id)->with('items')->firstOrFail();
        $line = $invoice->items->firstWhere('source_type', 'rental');

        $this->assertSame(Product::GST_TAX_TYPE_IGST, $invoice->tax_type);
        $this->assertSame(0.0, (float) $invoice->cgst_amount);
        $this->assertSame(0.0, (float) $invoice->sgst_amount);
        $this->assertSame(180.0, (float) $invoice->igst_amount);
        $this->assertSame(Product::GST_TAX_TYPE_IGST, $line->tax_type);
        $this->assertSame(18.0, (float) $line->igst_rate);
        $this->assertSame(180.0, (float) $line->igst_amount);
    }

    public function test_inclusive_product_tax_defaults_are_copied_and_survive_product_master_change(): void
    {
        [$organization, $customer, $product] = $this->rentalTaxContext([
            'product_gst_type' => Product::GST_TAX_TYPE_CGST_SGST,
            'product_gst_mode' => 'inclusive',
            'product_cgst_rate' => 9,
            'product_sgst_rate' => 9,
        ]);
        $this->actingAs(TestData::user($organization));

        $this->post(route('rentals.store'), [
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'phone' => $customer->phone,
            'product_id' => $product->id,
            'quantity' => 1,
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-10',
            'rental_amount' => 1180,
            'deposit_amount' => 0,
            'transport_amount' => 0,
            'other_amount' => 0,
        ])->assertRedirect(route('rentals.index', ['sort_by' => 'latest']));

        $rental = Rental::query()->where('organization_id', $organization->id)->with('rentalItems')->firstOrFail();
        $invoice = Invoice::query()->where('organization_id', $organization->id)->with('items')->firstOrFail();

        $this->assertSame(18.0, (float) $rental->rentalItems->first()->gst_rate);
        $this->assertSame('inclusive', $rental->rentalItems->first()->gst_mode);
        $this->assertSame(1000.0, (float) $invoice->taxable_amount);
        $this->assertSame(90.0, (float) $invoice->cgst_amount);
        $this->assertSame(90.0, (float) $invoice->sgst_amount);
        $this->assertSame(1180.0, (float) $invoice->total_amount);

        $product->update([
            'gst_tax_type' => Product::GST_TAX_TYPE_IGST,
            'gst_calculation_mode' => 'exclusive',
            'cgst_rate' => 0,
            'sgst_rate' => 0,
            'igst_rate' => 28,
        ]);

        app(InvoiceSyncService::class)->syncRentalInvoiceFromRental(
            $organization->id,
            $rental->fresh(['customer', 'product', 'rentalItems.product', 'saleItems.product']),
            $invoice->fresh(),
            $organization->fresh(),
            Schema::hasColumn('invoices', 'rental_id'),
            Rental::hasRentalItemsTable()
        );

        $invoice->refresh()->load('items');
        $line = $invoice->items->firstWhere('source_type', 'rental');

        $this->assertSame(Product::GST_TAX_TYPE_CGST_SGST, $line->tax_type);
        $this->assertSame(90.0, (float) $line->cgst_amount);
        $this->assertSame(90.0, (float) $line->sgst_amount);
        $this->assertSame(0.0, (float) $line->igst_amount);
        $this->assertSame(1180.0, (float) $invoice->total_amount);
    }

    public function test_new_products_alongside_rental_use_saved_tax_snapshot_in_invoice(): void
    {
        [$organization, $customer, $product] = $this->rentalTaxContext();
        $saleProduct = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Rental Add-on Mask',
            'product_type' => Product::TYPE_SELLABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'sale_price' => 118,
            'rental_price' => 0,
            'price_per_day' => 0,
            'available_quantity' => 20,
            'total_quantity' => 20,
            'gst_tax_type' => Product::GST_TAX_TYPE_CGST_SGST,
            'gst_calculation_mode' => 'inclusive',
            'cgst_rate' => 9,
            'sgst_rate' => 9,
        ]);

        $this->actingAs(TestData::user($organization));

        $this->post(route('rentals.store'), [
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'phone' => $customer->phone,
            'product_id' => $product->id,
            'quantity' => 1,
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-10',
            'rental_amount' => 1000,
            'gst_rate' => 0,
            'gst_mode' => 'exclusive',
            'tax_type' => Product::GST_TAX_TYPE_CGST_SGST,
            'deposit_amount' => 0,
            'transport_amount' => 0,
            'other_amount' => 0,
            'sale_items' => [[
                'product_id' => $saleProduct->id,
                'quantity' => 1,
                'unit_price' => 118,
                'gst_rate' => 18,
                'gst_mode' => 'inclusive',
                'tax_type' => Product::GST_TAX_TYPE_CGST_SGST,
            ]],
        ])->assertRedirect(route('rentals.index', ['sort_by' => 'latest']));

        $invoice = Invoice::query()->where('organization_id', $organization->id)->with('items')->firstOrFail();
        $saleLine = $invoice->items->firstWhere('source_type', 'rental_sale');
        $savedSaleLine = RentalSaleItem::query()->where('organization_id', $organization->id)->firstOrFail();

        $this->assertNotNull($saleLine);
        $this->assertSame(18.0, (float) $savedSaleLine->gst_rate);
        $this->assertSame('inclusive', $savedSaleLine->gst_mode);
        $this->assertSame(100.0, (float) $saleLine->taxable_amount);
        $this->assertSame(9.0, (float) $saleLine->cgst_amount);
        $this->assertSame(9.0, (float) $saleLine->sgst_amount);
        $this->assertSame(1118.0, (float) $invoice->total_amount);
    }

    private function rentalTaxContext(array $overrides = []): array
    {
        $organization = TestData::organization([
            'state' => $overrides['organization_state'] ?? 'Karnataka',
        ]);

        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Rental Tax Customer',
            'phone' => '9876500001',
            'state' => $overrides['customer_state'] ?? 'Karnataka',
        ]);

        $product = Product::create([
            'organization_id' => $organization->id,
            'name' => 'BiPAP Machine',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'price_per_day' => 1000,
            'rental_price' => 1000,
            'available_quantity' => 10,
            'total_quantity' => 10,
            'gst_tax_type' => $overrides['product_gst_type'] ?? Product::GST_TAX_TYPE_CGST_SGST,
            'gst_calculation_mode' => $overrides['product_gst_mode'] ?? 'exclusive',
            'cgst_rate' => $overrides['product_cgst_rate'] ?? 0,
            'sgst_rate' => $overrides['product_sgst_rate'] ?? 0,
            'igst_rate' => $overrides['product_igst_rate'] ?? 0,
        ]);

        return [$organization, $customer, $product];
    }
}

