<?php

namespace Tests\Feature\Regression;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Rental;
use App\Models\Sale;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class InvoiceLinkageRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_manual_rental_creation_generates_invoice_linked_by_rental_id(): void
    {
        [$organization, $customer, $product] = $this->bootRentalContext();

        $response = $this->post(route('rentals.store'), [
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'phone' => $customer->phone,
            'product_id' => $product->id,
            'quantity' => 1,
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-10',
            'rental_amount' => 4500,
            'deposit_amount' => 500,
            'transport_amount' => 0,
            'other_amount' => 0,
        ]);

        $response->assertRedirect('/rentals');

        $rental = Rental::query()->where('organization_id', $organization->id)->firstOrFail();
        $invoice = Invoice::query()->where('organization_id', $organization->id)->firstOrFail();

        $this->assertSame($rental->id, $invoice->rental_id);
        $this->assertNull($invoice->sale_id);
        $this->assertSame($invoice->id, $rental->invoice()->value('id'));
        $this->assertSame('unpaid', $invoice->payment_status);
        $this->assertCount(1, $invoice->items);
        $this->assertSame('rental', $invoice->items->first()->source_type);
        $this->assertSame(4500.0, (float) $invoice->subtotal);
        $this->assertSame(500.0, (float) $invoice->deposit_amount);
        $this->assertSame(0.0, (float) $invoice->shipping_charges);
        $this->assertSame(5000.0, (float) $invoice->total_amount);
        $this->assertStringNotContainsString('Rental deposit for rental #', $invoice->items->first()->description);
        $this->assertStringNotContainsString('Transport charge for rental #', $invoice->items->first()->description);
    }

    public function test_manual_sale_creation_generates_invoice_linked_by_sale_id(): void
    {
        [$organization, $customer, $product] = $this->bootSaleContext();
        $saleDate = now()->addDay()->toDateString();

        $response = $this->post(route('sales.store'), [
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 1500,
            'discount_amount' => 0,
            'shipping_charges' => 0,
            'tax_percentage' => 0,
            'tax_calculation_mode' => 'exclusive',
            'sale_date' => $saleDate,
            'sale_amount' => 1500,
            'payment_status' => 'pending',
            'notes' => 'Invoice linkage regression',
        ]);

        $response->assertRedirect(route('sales.index'));

        $sale = Sale::query()->where('organization_id', $organization->id)->firstOrFail();
        $invoice = Invoice::query()->where('organization_id', $organization->id)->firstOrFail();

        $this->assertSame($sale->id, $invoice->sale_id);
        $this->assertNull($invoice->rental_id);
        $this->assertSame($invoice->id, $sale->invoice()->value('id'));
        $this->assertSame('unpaid', $invoice->payment_status);
    }

    public function test_rental_list_bulk_invoice_detection_uses_direct_invoice_link(): void
    {
        [$organization, $customer, $product] = $this->bootRentalContext();

        $this->post(route('rentals.store'), [
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'phone' => $customer->phone,
            'product_id' => $product->id,
            'quantity' => 1,
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-10',
            'rental_amount' => 4500,
            'deposit_amount' => 500,
            'transport_amount' => 0,
            'other_amount' => 0,
        ])->assertRedirect('/rentals');

        $invoice = Invoice::query()->where('organization_id', $organization->id)->firstOrFail();

        $this->get(route('rentals.index'))
            ->assertOk()
            ->assertSee('data-invoice-id="' . $invoice->id . '"', false);
    }

    private function bootRentalContext(): array
    {
        $organization = TestData::organization();
        $user = TestData::user($organization);
        $this->actingAs($user);

        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Rental Customer',
            'phone' => '9876543210',
        ]);

        $product = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Rental Product',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'sale_price' => 0,
            'rental_price' => 4500,
            'price_per_day' => 4500,
            'available_quantity' => 5,
            'total_quantity' => 5,
        ]);

        return [$organization, $customer, $product];
    }

    private function bootSaleContext(): array
    {
        $organization = TestData::organization();
        $user = TestData::user($organization);
        $this->actingAs($user);

        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Sale Customer',
            'phone' => '9876543211',
        ]);

        $warehouse = Warehouse::create([
            'organization_id' => $organization->id,
            'name' => 'Sale Warehouse',
            'code' => 'SAL',
            'is_active' => true,
        ]);

        $product = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Sale Product',
            'product_type' => Product::TYPE_SELLABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'sale_price' => 1500,
            'rental_price' => 0,
            'price_per_day' => 0,
            'available_quantity' => 5,
            'total_quantity' => 5,
        ]);

        return [$organization, $customer, $product, $warehouse];
    }
}
