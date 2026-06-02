<?php

namespace Tests\Feature\Regression;

use App\Models\Customer;
use App\Models\Delivery;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Rental;
use App\Models\Sale;
use App\Models\Warehouse;
use Illuminate\Support\Facades\Artisan;
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

    public function test_updating_delivered_rental_resyncs_linked_invoice_amounts(): void
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

        $rental = Rental::query()->where('organization_id', $organization->id)->firstOrFail();
        $invoice = Invoice::query()->where('organization_id', $organization->id)->firstOrFail();

        Delivery::create([
            'organization_id' => $organization->id,
            'rental_id' => $rental->id,
            'type' => 'delivery',
            'scheduled_at' => now(),
            'status' => 'completed',
            'completed_at' => now(),
            'notes' => 'Delivered before rental edit',
        ]);

        $this->put(route('rentals.update', $rental), [
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'phone' => $customer->phone,
            'product_id' => $product->id,
            'quantity' => 1,
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-12',
            'rental_amount' => 6000,
            'deposit_amount' => 750,
            'transport_amount' => 100,
            'other_amount' => 50,
        ])->assertRedirect(route('rentals.show', $rental));

        $invoice->refresh()->load('items');

        $this->assertSame(6000.0, (float) $invoice->subtotal);
        $this->assertSame(750.0, (float) $invoice->deposit_amount);
        $this->assertSame(100.0, (float) $invoice->shipping_charges);
        $this->assertSame(6900.0, (float) $invoice->total_amount);
        $this->assertSame(6900.0, (float) $invoice->balance_amount);
        $this->assertTrue(
            $invoice->items->contains(fn ($item) => $item->description === 'Other charge for rental #' . $rental->id && (float) $item->line_total === 50.0)
        );
    }

    public function test_rental_invoice_includes_new_products_sold_with_rental_in_grand_total(): void
    {
        [$organization, $customer, $product] = $this->bootRentalContext();

        $diaperXl = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Adult Diapers Pant Type XL - Svach',
            'product_type' => Product::TYPE_SELLABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'sale_price' => 750,
            'rental_price' => 0,
            'price_per_day' => 0,
            'available_quantity' => 25,
            'total_quantity' => 25,
        ]);

        $diaperL = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Adult Diapers Pant Type L - Svach',
            'product_type' => Product::TYPE_SELLABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'sale_price' => 700,
            'rental_price' => 0,
            'price_per_day' => 0,
            'available_quantity' => 25,
            'total_quantity' => 25,
        ]);

        $this->post(route('rentals.store'), [
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'phone' => $customer->phone,
            'product_id' => $product->id,
            'quantity' => 1,
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-10',
            'rental_amount' => 1000,
            'deposit_amount' => 2000,
            'transport_amount' => 800,
            'other_amount' => 0,
            'sale_items' => [
                [
                    'product_id' => $diaperXl->id,
                    'quantity' => 1,
                    'unit_price' => 750,
                ],
                [
                    'product_id' => $diaperL->id,
                    'quantity' => 1,
                    'unit_price' => 700,
                ],
            ],
        ])->assertRedirect('/rentals');

        $invoice = Invoice::query()
            ->where('organization_id', $organization->id)
            ->with('items')
            ->firstOrFail();

        $saleLines = $invoice->items->where('source_type', 'rental_sale')->values();

        $this->assertCount(3, $invoice->items);
        $this->assertCount(2, $saleLines);
        $this->assertTrue($saleLines->contains(fn ($item) => $item->description === 'Adult Diapers Pant Type XL - Svach' && (float) $item->line_total === 750.0));
        $this->assertTrue($saleLines->contains(fn ($item) => $item->description === 'Adult Diapers Pant Type L - Svach' && (float) $item->line_total === 700.0));
        $this->assertSame(2450.0, (float) $invoice->subtotal);
        $this->assertSame(2000.0, (float) $invoice->deposit_amount);
        $this->assertSame(800.0, (float) $invoice->shipping_charges);
        $this->assertSame(5250.0, (float) $invoice->total_amount);
        $this->assertSame(5250.0, (float) $invoice->balance_amount);
    }

    public function test_rental_invoice_view_shows_products_sold_with_rental_lines(): void
    {
        [$organization, $customer, $product] = $this->bootRentalContext();

        $saleAddon = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Adult Diapers Pant Type XL - Svach',
            'product_type' => Product::TYPE_SELLABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'sale_price' => 750,
            'rental_price' => 0,
            'price_per_day' => 0,
            'available_quantity' => 25,
            'total_quantity' => 25,
        ]);

        $this->post(route('rentals.store'), [
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'phone' => $customer->phone,
            'product_id' => $product->id,
            'quantity' => 1,
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-10',
            'rental_amount' => 1000,
            'deposit_amount' => 0,
            'transport_amount' => 0,
            'other_amount' => 0,
            'sale_items' => [
                [
                    'product_id' => $saleAddon->id,
                    'quantity' => 1,
                    'unit_price' => 750,
                ],
            ],
        ])->assertRedirect('/rentals');

        $invoice = Invoice::query()->where('organization_id', $organization->id)->firstOrFail();

        $this->get(route('invoices.show', $invoice))
            ->assertOk()
            ->assertSee('Adult Diapers Pant Type XL - Svach')
            ->assertSee('Products Sold With Rental')
            ->assertSee('Unit: sale');
    }

    public function test_rental_invoice_view_uses_inclusive_rental_day_display(): void
    {
        [$organization, $customer, $product] = $this->bootRentalContext();

        $this->post(route('rentals.store'), [
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'phone' => $customer->phone,
            'product_id' => $product->id,
            'quantity' => 1,
            'start_date' => '2026-06-02',
            'end_date' => '2026-07-01',
            'rental_amount' => 3000,
            'deposit_amount' => 0,
            'transport_amount' => 0,
            'other_amount' => 0,
        ])->assertRedirect('/rentals');

        $invoice = Invoice::query()->where('organization_id', $organization->id)->firstOrFail();

        $this->get(route('invoices.show', $invoice))
            ->assertOk()
            ->assertSee('Days: 30.00', false)
            ->assertSee('Duration: 30 days', false);
    }

    public function test_removing_rental_sale_items_removes_stale_invoice_lines_on_resync(): void
    {
        [$organization, $customer, $product] = $this->bootRentalContext();

        $saleAddon = Product::create([
            'organization_id' => $organization->id,
            'name' => 'BiPAP Disposable Filter',
            'product_type' => Product::TYPE_SELLABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'sale_price' => 180,
            'rental_price' => 0,
            'price_per_day' => 0,
            'available_quantity' => 25,
            'total_quantity' => 25,
        ]);

        $this->post(route('rentals.store'), [
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'phone' => $customer->phone,
            'product_id' => $product->id,
            'quantity' => 1,
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-10',
            'rental_amount' => 1000,
            'deposit_amount' => 0,
            'transport_amount' => 0,
            'other_amount' => 0,
            'sale_items' => [
                [
                    'product_id' => $saleAddon->id,
                    'quantity' => 1,
                    'unit_price' => 180,
                ],
            ],
        ])->assertRedirect('/rentals');

        $rental = Rental::query()->where('organization_id', $organization->id)->firstOrFail();
        $invoice = Invoice::query()->where('organization_id', $organization->id)->firstOrFail();

        $this->assertCount(1, $invoice->items()->where('source_type', 'rental_sale')->get());

        $this->put(route('rentals.update', $rental), [
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'phone' => $customer->phone,
            'product_id' => $product->id,
            'quantity' => 1,
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-12',
            'rental_amount' => 1200,
            'deposit_amount' => 100,
            'transport_amount' => 50,
            'other_amount' => 0,
            'sale_items' => [],
        ])->assertRedirect(route('rentals.show', $rental));

        $invoice->refresh()->load('items');

        $this->assertCount(0, $invoice->items->where('source_type', 'rental_sale'));
        $this->assertSame(1200.0, (float) $invoice->subtotal);
        $this->assertSame(1350.0, (float) $invoice->total_amount);
    }

    public function test_rental_invoice_pdf_renders_products_sold_with_rental_lines(): void
    {
        [$organization, $customer, $product] = $this->bootRentalContext();

        $saleAddon = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Adult Diapers Pant Type L - Svach',
            'product_type' => Product::TYPE_SELLABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'sale_price' => 700,
            'rental_price' => 0,
            'price_per_day' => 0,
            'available_quantity' => 25,
            'total_quantity' => 25,
        ]);

        $this->post(route('rentals.store'), [
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'phone' => $customer->phone,
            'product_id' => $product->id,
            'quantity' => 1,
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-10',
            'rental_amount' => 1000,
            'deposit_amount' => 0,
            'transport_amount' => 0,
            'other_amount' => 0,
            'sale_items' => [
                [
                    'product_id' => $saleAddon->id,
                    'quantity' => 1,
                    'unit_price' => 700,
                ],
            ],
        ])->assertRedirect('/rentals');

        config([
            'pdf.engine' => 'dompdf',
            'pdf.currency_symbol' => '',
            'pdf.currency_fallback' => 'Rs.',
        ]);

        $invoice = Invoice::query()->where('organization_id', $organization->id)->firstOrFail();

        $response = $this->get(route('invoices.print', $invoice));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');

        $content = str_replace("\0", '', $response->getContent());

        $this->assertStringContainsString('Adult Diapers Pant Type L - Svach', $content);
        $this->assertStringContainsString('Products Sold With Rental', $content);
    }

    public function test_updating_rental_resyncs_invoice_sale_lines_with_product_gst(): void
    {
        [$organization, $customer, $product] = $this->bootRentalContext();

        $saleAddon = Product::create([
            'organization_id' => $organization->id,
            'name' => 'BiPAP Disposable Filter',
            'product_type' => Product::TYPE_SELLABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'sale_price' => 118,
            'rental_price' => 0,
            'price_per_day' => 0,
            'gst_tax_type' => Product::GST_TAX_TYPE_CGST_SGST,
            'gst_calculation_mode' => 'inclusive',
            'cgst_rate' => 9,
            'sgst_rate' => 9,
            'available_quantity' => 25,
            'total_quantity' => 25,
        ]);

        $this->post(route('rentals.store'), [
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'phone' => $customer->phone,
            'product_id' => $product->id,
            'quantity' => 1,
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-10',
            'rental_amount' => 1000,
            'deposit_amount' => 0,
            'transport_amount' => 0,
            'other_amount' => 0,
        ])->assertRedirect('/rentals');

        $rental = Rental::query()->where('organization_id', $organization->id)->firstOrFail();
        $invoice = Invoice::query()->where('organization_id', $organization->id)->firstOrFail();

        $this->put(route('rentals.update', $rental), [
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'phone' => $customer->phone,
            'product_id' => $product->id,
            'quantity' => 1,
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-12',
            'rental_amount' => 1200,
            'deposit_amount' => 100,
            'transport_amount' => 50,
            'other_amount' => 0,
            'sale_items' => [
                [
                    'product_id' => $saleAddon->id,
                    'quantity' => 1,
                    'unit_price' => 118,
                ],
            ],
        ])->assertRedirect(route('rentals.show', $rental));

        $invoice->refresh()->load('items');

        $saleLine = $invoice->items
            ->first(fn ($item) => $item->source_type === 'rental_sale' && $item->product_id === $saleAddon->id);

        $this->assertNotNull($saleLine);
        $this->assertSame(1318.0, (float) $invoice->subtotal);
        $this->assertSame(1300.0, (float) $invoice->taxable_amount);
        $this->assertSame(9.0, (float) $invoice->cgst_amount);
        $this->assertSame(9.0, (float) $invoice->sgst_amount);
        $this->assertSame(18.0, (float) $invoice->total_tax_amount);
        $this->assertSame(1468.0, (float) $invoice->total_amount);
        $this->assertSame(1468.0, (float) $invoice->balance_amount);
        $this->assertSame(100.0, (float) $saleLine->taxable_amount);
        $this->assertSame(9.0, (float) $saleLine->cgst_amount);
        $this->assertSame(9.0, (float) $saleLine->sgst_amount);
        $this->assertSame(118.0, (float) $saleLine->line_total);
    }

    public function test_sync_rental_invoices_command_repairs_existing_stale_invoice_totals(): void
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

        $rental = Rental::query()->where('organization_id', $organization->id)->firstOrFail();
        $invoice = Invoice::query()->where('organization_id', $organization->id)->firstOrFail();

        $rental->update([
            'rental_amount' => 6250,
            'deposit_amount' => 900,
            'transport_amount' => 150,
            'other_amount' => 75,
        ]);

        $line = $rental->rentalItems()->first();
        $line?->update([
            'unit_rental_amount' => 6250,
            'line_total' => 6250,
        ]);

        Artisan::call('rentnexis:sync-rental-invoices', [
            '--organization' => $organization->id,
            '--rental' => [$rental->id],
            '--apply' => true,
        ]);

        $invoice->refresh()->load('items');

        $this->assertSame(6250.0, (float) $invoice->subtotal);
        $this->assertSame(900.0, (float) $invoice->deposit_amount);
        $this->assertSame(150.0, (float) $invoice->shipping_charges);
        $this->assertSame(7375.0, (float) $invoice->total_amount);
        $this->assertSame(7375.0, (float) $invoice->balance_amount);
        $this->assertTrue(
            $invoice->items->contains(fn ($item) => $item->description === 'Other charge for rental #' . $rental->id && (float) $item->line_total === 75.0)
        );
    }

    public function test_invoice_mark_paid_works_on_sqlite_for_non_rental_invoice(): void
    {
        [$organization, $customer] = $this->invoiceContext();

        $invoice = Invoice::create([
            'organization_id' => $organization->id,
            'invoice_number' => 'INV-MARK-PAID-001',
            'invoice_date' => '2026-05-01',
            'due_date' => '2026-05-01',
            'customer_id' => $customer->id,
            'bill_to_name' => $customer->name,
            'bill_to_phone' => $customer->phone,
            'tax_type' => 'cgst_sgst',
            'tax_calculation_mode' => 'exclusive',
            'status' => 'unpaid',
            'payment_status' => 'unpaid',
            'subtotal' => 1500,
            'discount_amount' => 0,
            'deposit_amount' => 0,
            'shipping_charges' => 0,
            'taxable_amount' => 1500,
            'cgst_amount' => 0,
            'sgst_amount' => 0,
            'igst_amount' => 0,
            'total_tax_amount' => 0,
            'total_amount' => 1500,
            'paid_amount' => 0,
            'balance_amount' => 1500,
        ]);

        $this->post(route('invoices.markPaid', $invoice->id))
            ->assertRedirect(route('invoices.show', $invoice->id));

        $payment = Payment::query()
            ->where('organization_id', $organization->id)
            ->where('invoice_id', $invoice->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($payment);
        $this->assertNull($payment->rental_id);
        $this->assertSame(1500.0, (float) $payment->amount);
        $this->assertSame('paid', $invoice->fresh()->payment_status);
        $this->assertSame(0.0, (float) $invoice->fresh()->balance_amount);
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

    private function invoiceContext(): array
    {
        $organization = TestData::organization();
        $user = TestData::user($organization);
        $this->actingAs($user);

        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Invoice Mark Paid Customer',
            'phone' => '9876543212',
        ]);

        return [$organization, $customer];
    }
}
