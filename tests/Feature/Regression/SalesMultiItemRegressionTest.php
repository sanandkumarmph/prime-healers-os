<?php

namespace Tests\Feature\Regression;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Rental;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class SalesMultiItemRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_sale_can_be_created_with_multiple_product_lines_and_invoice_syncs_all_lines(): void
    {
        [$organization, $customer, $productA, $productB] = $this->saleContext();
        $this->actingAs(TestData::user($organization));

        $this->post(route('sales.store'), [
            'customer_id' => $customer->id,
            'sale_date' => '2026-05-13',
            'payment_status' => 'pending',
            'notes' => 'Multi product sale',
            'shipping_charges' => 50,
            'sale_items' => [
                [
                    'product_id' => $productA->id,
                    'quantity' => 1,
                    'unit_price' => 500,
                    'discount_amount' => 0,
                    'tax_percentage' => 0,
                    'tax_calculation_mode' => 'exclusive',
                ],
                [
                    'product_id' => $productB->id,
                    'quantity' => 2,
                    'unit_price' => 700,
                    'discount_amount' => 100,
                    'tax_percentage' => 0,
                    'tax_calculation_mode' => 'exclusive',
                ],
            ],
        ])->assertRedirect(route('sales.index'));

        $sale = Sale::query()
            ->where('organization_id', $organization->id)
            ->with(['saleItems.product'])
            ->firstOrFail();
        $invoice = Invoice::query()
            ->where('organization_id', $organization->id)
            ->with('items')
            ->firstOrFail();

        $this->assertCount(2, $sale->saleItems);
        $this->assertSame($productA->id, (int) $sale->product_id);
        $this->assertSame(1850.0, (float) $sale->sale_amount);
        $this->assertSame(50.0, (float) $sale->shipping_charges);
        $this->assertSame(0.0, (float) $sale->saleItems->sum('shipping_charges'));
        $this->assertSame(1850.0, (float) $invoice->total_amount);
        $this->assertSame(50.0, (float) $invoice->shipping_charges);
        $this->assertCount(2, $invoice->items->where('source_type', 'sale'));
        $this->assertTrue($invoice->items->contains(fn ($item) => str_contains((string) $item->description, $productA->name)));
        $this->assertTrue($invoice->items->contains(fn ($item) => str_contains((string) $item->description, $productB->name)));
    }

    public function test_sale_line_tax_type_defaults_from_customer_state_and_persists_override_into_invoice(): void
    {
        [$organization, $customer, $productA] = $this->saleContext();
        $this->actingAs(TestData::user($organization));

        $interstateCustomer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Interstate Customer',
            'phone' => '9876500002',
            'state' => 'Tamil Nadu',
        ]);

        $response = $this->post(route('sales.store'), [
            'customer_id' => $interstateCustomer->id,
            'sale_date' => '2026-05-13',
            'payment_status' => 'pending',
            'shipping_charges' => 0,
            'sale_items' => [
                [
                    'product_id' => $productA->id,
                    'quantity' => 1,
                    'unit_price' => 1000,
                    'discount_amount' => 0,
                    'tax_percentage' => 18,
                    'tax_calculation_mode' => 'exclusive',
                ],
                [
                    'product_id' => $productA->id,
                    'quantity' => 1,
                    'unit_price' => 1000,
                    'discount_amount' => 0,
                    'tax_percentage' => 18,
                    'tax_calculation_mode' => 'exclusive',
                    'tax_type' => 'cgst_sgst',
                ],
            ],
        ]);

        $response->assertRedirect(route('sales.index'));

        $sale = Sale::query()->where('organization_id', $organization->id)->with('saleItems')->firstOrFail();
        $invoice = Invoice::query()->where('organization_id', $organization->id)->with('items')->firstOrFail();

        $this->assertSame('igst', $sale->saleItems[0]->tax_type);
        $this->assertSame('cgst_sgst', $sale->saleItems[1]->tax_type);
        $this->assertSame('igst', $invoice->items[0]->tax_type);
        $this->assertSame(18.0, (float) $invoice->items[0]->igst_rate);
        $this->assertSame(180.0, (float) $invoice->items[0]->igst_amount);
        $this->assertSame('cgst_sgst', $invoice->items[1]->tax_type);
        $this->assertSame(9.0, (float) $invoice->items[1]->cgst_rate);
        $this->assertSame(9.0, (float) $invoice->items[1]->sgst_rate);
        $this->assertSame(90.0, (float) $invoice->items[1]->cgst_amount);
        $this->assertSame(90.0, (float) $invoice->items[1]->sgst_amount);
        $this->assertSame(360.0, (float) $invoice->total_tax_amount);
    }

    public function test_sale_edit_replaces_existing_line_items_without_duplication(): void
    {
        [$organization, $customer, $productA, $productB] = $this->saleContext();
        $this->actingAs(TestData::user($organization));

        $this->post(route('sales.store'), [
            'customer_id' => $customer->id,
            'sale_date' => '2026-05-13',
            'payment_status' => 'pending',
            'shipping_charges' => 0,
            'sale_items' => [
                [
                    'product_id' => $productA->id,
                    'quantity' => 1,
                    'unit_price' => 500,
                    'discount_amount' => 0,
                    'tax_percentage' => 0,
                    'tax_calculation_mode' => 'exclusive',
                ],
            ],
        ])->assertRedirect(route('sales.index'));

        $sale = Sale::query()->where('organization_id', $organization->id)->firstOrFail();

        $this->put(route('sales.update', $sale), [
            'customer_id' => $customer->id,
            'sale_date' => '2026-05-14',
            'payment_status' => 'pending',
            'shipping_charges' => 25,
            'sale_items' => [
                [
                    'product_id' => $productB->id,
                    'quantity' => 3,
                    'unit_price' => 450,
                    'discount_amount' => 50,
                    'tax_percentage' => 0,
                    'tax_calculation_mode' => 'exclusive',
                ],
            ],
        ])->assertRedirect(route('sales.index'));

        $sale->refresh()->load('saleItems.product');
        $invoice = Invoice::query()->where('organization_id', $organization->id)->with('items')->firstOrFail();

        $this->assertCount(1, $sale->saleItems);
        $this->assertSame($productB->id, (int) $sale->saleItems->first()->product_id);
        $this->assertSame(1325.0, (float) $sale->sale_amount);
        $this->assertSame(25.0, (float) $sale->shipping_charges);
        $this->assertSame(25.0, (float) $invoice->shipping_charges);
        $this->assertCount(1, $invoice->items->where('source_type', 'sale'));
        $this->assertTrue($invoice->items->contains(fn ($item) => str_contains((string) $item->description, $productB->name)));
        $this->assertFalse($invoice->items->contains(fn ($item) => str_contains((string) $item->description, $productA->name)));
    }

    public function test_rental_linked_sale_keeps_all_product_lines_on_the_sale_invoice(): void
    {
        [$organization, $customer, $productA, $productB] = $this->saleContext();
        $this->actingAs(TestData::user($organization));

        $rental = Rental::create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'phone' => $customer->phone,
            'product_id' => $productA->id,
            'quantity' => 1,
            'rental_amount' => 1000,
            'deposit_amount' => 0,
            'transport_amount' => 0,
            'other_amount' => 0,
            'start_date' => '2026-05-10',
            'end_date' => '2026-05-15',
            'payment_status' => 'pending',
            'delivery_status' => 'pending',
        ]);

        $this->post(route('sales.store'), [
            'customer_id' => $customer->id,
            'rental_id' => $rental->id,
            'sale_date' => '2026-05-13',
            'payment_status' => 'pending',
            'shipping_charges' => 0,
            'sale_items' => [
                [
                    'product_id' => $productA->id,
                    'quantity' => 1,
                    'unit_price' => 300,
                    'discount_amount' => 0,
                    'tax_percentage' => 0,
                    'tax_calculation_mode' => 'exclusive',
                ],
                [
                    'product_id' => $productB->id,
                    'quantity' => 1,
                    'unit_price' => 450,
                    'discount_amount' => 0,
                    'tax_percentage' => 0,
                    'tax_calculation_mode' => 'exclusive',
                ],
            ],
        ])->assertRedirect(route('sales.index'));

        $sale = Sale::query()->where('organization_id', $organization->id)->latest('id')->firstOrFail();
        $invoice = Invoice::query()->where('organization_id', $organization->id)->latest('id')->with('items')->firstOrFail();

        $this->assertSame($rental->id, (int) $sale->rental_id);
        $this->assertCount(2, $sale->saleItems()->get());
        $this->assertCount(2, $invoice->items->where('source_type', 'sale'));
        $this->assertSame(750.0, (float) $invoice->total_amount);
    }

    public function test_editing_legacy_sale_item_shipping_keeps_one_sale_level_shipping_charge(): void
    {
        [$organization, $customer, $productA, $productB] = $this->saleContext();
        $this->actingAs(TestData::user($organization));

        $sale = Sale::create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'product_id' => $productA->id,
            'quantity' => 1,
            'unit_price' => 500,
            'discount_amount' => 0,
            'shipping_charges' => 0,
            'tax_percentage' => 0,
            'tax_calculation_mode' => 'exclusive',
            'sale_date' => '2026-05-13',
            'sale_amount' => 1850,
            'payment_status' => 'pending',
        ]);

        $sale->saleItems()->createMany([
            [
                'organization_id' => $organization->id,
                'product_id' => $productA->id,
                'quantity' => 1,
                'unit_price' => 500,
                'discount_amount' => 0,
                'shipping_charges' => 50,
                'tax_percentage' => 0,
                'tax_calculation_mode' => 'exclusive',
                'taxable_amount' => 500,
                'total_tax_amount' => 0,
                'line_total' => 550,
                'sort_order' => 0,
            ],
            [
                'organization_id' => $organization->id,
                'product_id' => $productB->id,
                'quantity' => 2,
                'unit_price' => 700,
                'discount_amount' => 100,
                'shipping_charges' => 0,
                'tax_percentage' => 0,
                'tax_calculation_mode' => 'exclusive',
                'taxable_amount' => 1300,
                'total_tax_amount' => 0,
                'line_total' => 1300,
                'sort_order' => 1,
            ],
        ]);

        $this->post(route('sales.invoice', $sale))
            ->assertRedirect();

        $this->put(route('sales.update', $sale), [
            'customer_id' => $customer->id,
            'sale_date' => '2026-05-14',
            'payment_status' => 'pending',
            'shipping_charges' => 50,
            'sale_items' => [
                [
                    'product_id' => $productA->id,
                    'quantity' => 1,
                    'unit_price' => 500,
                    'discount_amount' => 0,
                    'tax_percentage' => 0,
                    'tax_calculation_mode' => 'exclusive',
                ],
                [
                    'product_id' => $productB->id,
                    'quantity' => 2,
                    'unit_price' => 700,
                    'discount_amount' => 100,
                    'tax_percentage' => 0,
                    'tax_calculation_mode' => 'exclusive',
                ],
            ],
        ])->assertRedirect(route('sales.index'));

        $sale->refresh()->load('saleItems');
        $invoice = Invoice::query()->where('organization_id', $organization->id)->with('items')->firstOrFail();

        $this->assertSame(50.0, (float) $sale->shipping_charges);
        $this->assertSame(0.0, (float) $sale->saleItems->sum('shipping_charges'));
        $this->assertSame(1850.0, (float) $sale->sale_amount);
        $this->assertSame(50.0, (float) $invoice->shipping_charges);
        $this->assertSame(1850.0, (float) $invoice->total_amount);
    }

    private function saleContext(): array
    {
        $organization = TestData::organization(['state' => 'Karnataka']);

        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Multi Sale Customer',
            'phone' => '9876500001',
            'state' => 'Karnataka',
        ]);

        $productA = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Hospital Bed Sheet',
            'product_type' => Product::TYPE_SELLABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'sale_price' => 500,
            'rental_price' => 0,
            'price_per_day' => 0,
            'available_quantity' => 25,
            'total_quantity' => 25,
        ]);

        $productB = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Adult Diapers Pack',
            'product_type' => Product::TYPE_SELLABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'sale_price' => 700,
            'rental_price' => 0,
            'price_per_day' => 0,
            'available_quantity' => 25,
            'total_quantity' => 25,
        ]);

        return [$organization, $customer, $productA, $productB];
    }
}
