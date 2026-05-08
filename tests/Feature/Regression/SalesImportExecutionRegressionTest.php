<?php

namespace Tests\Feature\Regression;

use App\Models\Asset;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Warehouse;
use App\Services\ImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Support\TestData;
use Tests\TestCase;

class SalesImportExecutionRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_imported_paid_sale_creates_paid_invoice_and_payment_record(): void
    {
        [$organization, $warehouse, $product] = $this->bootSalesImportContext();

        $this->uploadSalesCsv([
            'Customer Phone,Customer Name,Product Name,Brand,Model,Quantity,Sale Amount,Tax Type,Payment Status,Paid Amount,Invoice Status,Delivery Status,Delivery Date,Sale Date,Warehouse,Notes',
            '9876543210,Aarav Sharma,Oxygen Concentrator 5 LPM,Philips,SimplyGo,1,45000,exclusive,paid,45000,paid,not_assigned,,2026-05-01,Main Warehouse,Paid import',
        ]);

        $this->post(route('imports.sales.execute'))
            ->assertRedirect(route('imports.sales.preview.page'));

        $sale = Sale::firstOrFail();
        $invoice = Invoice::firstOrFail();

        $this->assertSame('paid', $sale->payment_status);
        $this->assertSame('paid', $invoice->payment_status);
        $this->assertSame($sale->id, $invoice->sale_id);
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(0.0, (float) $invoice->balance_amount);
        $this->assertSame('sold', Asset::first()->fresh()->asset_status);
        $this->assertDatabaseHas('customers', [
            'organization_id' => $organization->id,
            'phone' => '9876543210',
            'name' => 'Aarav Sharma',
        ]);
    }

    public function test_imported_pending_sale_remains_pending_without_payment_record(): void
    {
        [$organization, $warehouse, $product] = $this->bootSalesImportContext();
        $saleDate = now()->addDay()->toDateString();

        $this->uploadSalesCsv([
            'Customer Phone,Customer Name,Product Name,Brand,Model,Quantity,Sale Amount,Tax Type,Payment Status,Paid Amount,Invoice Status,Delivery Status,Delivery Date,Sale Date,Warehouse,Notes',
            '9876543210,Aarav Sharma,Oxygen Concentrator 5 LPM,Philips,SimplyGo,1,45000,exclusive,pending,,generated,not_assigned,,' . $saleDate . ',Main Warehouse,Pending import',
        ]);

        $this->post(route('imports.sales.execute'))
            ->assertRedirect(route('imports.sales.preview.page'));

        $sale = Sale::firstOrFail();
        $invoice = Invoice::firstOrFail();

        $this->assertSame('pending', $sale->payment_status);
        $this->assertSame('unpaid', $invoice->payment_status);
        $this->assertSame($sale->id, $invoice->sale_id);
        $this->assertSame(0.0, (float) $invoice->paid_amount);
        $this->assertGreaterThan(0, (float) $invoice->balance_amount);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_imported_pending_sale_creates_invoice_by_default_when_invoice_status_is_blank(): void
    {
        [$organization, $warehouse, $product] = $this->bootSalesImportContext();
        $saleDate = now()->addDay()->toDateString();

        $this->uploadSalesCsv([
            'Customer Phone,Customer Name,Product Name,Brand,Model,Quantity,Sale Amount,Tax Type,Payment Status,Paid Amount,Invoice Status,Delivery Status,Delivery Date,Sale Date,Warehouse,Notes',
            '9876543210,Aarav Sharma,Oxygen Concentrator 5 LPM,Philips,SimplyGo,1,45000,exclusive,pending,,,not_assigned,,' . $saleDate . ',Main Warehouse,Default invoice import',
        ]);

        $this->post(route('imports.sales.execute'))
            ->assertRedirect(route('imports.sales.preview.page'));

        $invoice = Invoice::firstOrFail();
        $this->assertSame(Sale::firstOrFail()->id, $invoice->sale_id);
        $this->assertSame('unpaid', $invoice->payment_status);
    }

    public function test_imported_sale_skips_invoice_only_when_invoice_status_is_not_generated(): void
    {
        [$organization, $warehouse, $product] = $this->bootSalesImportContext();

        $this->uploadSalesCsv([
            'Customer Phone,Customer Name,Product Name,Brand,Model,Quantity,Sale Amount,Tax Type,Payment Status,Paid Amount,Invoice Status,Delivery Status,Delivery Date,Sale Date,Warehouse,Notes',
            '9876543210,Aarav Sharma,Oxygen Concentrator 5 LPM,Philips,SimplyGo,1,45000,exclusive,pending,,not_generated,not_assigned,,2026-05-01,Main Warehouse,No invoice import',
        ]);

        $this->post(route('imports.sales.execute'))
            ->assertRedirect(route('imports.sales.preview.page'));

        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_imported_completed_delivery_creates_completed_delivery_task(): void
    {
        [$organization, $warehouse, $product] = $this->bootSalesImportContext();

        $this->uploadSalesCsv([
            'Customer Phone,Customer Name,Product Name,Brand,Model,Quantity,Sale Amount,Tax Type,Payment Status,Paid Amount,Invoice Status,Delivery Status,Delivery Date,Sale Date,Warehouse,Notes',
            '9876543210,Aarav Sharma,Oxygen Concentrator 5 LPM,Philips,SimplyGo,1,45000,exclusive,pending,,generated,completed,2026-05-03,2026-05-01,Main Warehouse,Delivered import',
        ]);

        $this->post(route('imports.sales.execute'))
            ->assertRedirect(route('imports.sales.preview.page'));

        $delivery = Delivery::firstOrFail();
        $this->assertSame('delivery', $delivery->type);
        $this->assertSame('completed', $delivery->status);
        $this->assertNotNull($delivery->completed_at);
        $this->assertSame('2026-05-03', optional($delivery->completed_at)->toDateString());
    }

    public function test_invalid_status_values_are_rejected_in_preview(): void
    {
        [$organization, $warehouse, $product] = $this->bootSalesImportContext();

        $response = $this->uploadSalesCsv([
            'Customer Phone,Customer Name,Product Name,Brand,Model,Quantity,Sale Amount,Tax Type,Payment Status,Paid Amount,Invoice Status,Delivery Status,Delivery Date,Sale Date,Warehouse,Notes',
            '9876543210,Aarav Sharma,Oxygen Concentrator 5 LPM,Philips,SimplyGo,1,45000,exclusive,done,,issued,delivered-now,,2026-05-01,Main Warehouse,Bad statuses',
        ]);

        $response->assertRedirect(route('imports.sales.preview.page'));

        $preview = app(ImportService::class)->loadSnapshot(session('imports.sales.preview_custom'));
        $this->assertSame(0, $preview['valid_count']);
        $this->assertNotEmpty($preview['invalid_rows']);
        $errorText = implode(' | ', $preview['invalid_rows'][0]['errors'] ?? []);
        $this->assertStringContainsString('Payment Status must be paid, partial, pending, or unpaid.', $errorText);
        $this->assertStringContainsString('Invoice Status must be generated, not_generated, paid, unpaid, partial, or pending.', $errorText);
        $this->assertStringContainsString('Delivery Status must be not_assigned, assigned, pending, or completed.', $errorText);
    }

    public function test_duplicate_import_click_does_not_create_duplicate_sales(): void
    {
        [$organization, $warehouse, $product] = $this->bootSalesImportContext();

        $this->uploadSalesCsv([
            'Customer Phone,Customer Name,Product Name,Brand,Model,Quantity,Sale Amount,Tax Type,Payment Status,Paid Amount,Invoice Status,Delivery Status,Delivery Date,Sale Date,Warehouse,Notes',
            '9876543210,Aarav Sharma,Oxygen Concentrator 5 LPM,Philips,SimplyGo,1,45000,exclusive,pending,,generated,not_assigned,,2026-05-01,Main Warehouse,Duplicate guard',
        ]);

        $this->post(route('imports.sales.execute'))
            ->assertRedirect(route('imports.sales.preview.page'));
        $this->post(route('imports.sales.execute'))
            ->assertRedirect(route('imports.sales.preview.page'));

        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_importing_same_sale_row_twice_updates_existing_sale_instead_of_creating_duplicate(): void
    {
        [$organization, $warehouse, $product] = $this->bootSalesImportContext();

        $this->uploadSalesCsv([
            'Customer Phone,Customer Name,Product Name,Brand,Model,Quantity,Sale Amount,Tax Type,Payment Status,Paid Amount,Invoice Status,Delivery Status,Delivery Date,Sale Date,Warehouse,Notes',
            '9876543210,Aarav Sharma,Oxygen Concentrator 5 LPM,Philips,SimplyGo,1,45000,exclusive,pending,,generated,not_assigned,,2026-05-01,Main Warehouse,First import',
        ]);
        $this->post(route('imports.sales.execute'))->assertRedirect(route('imports.sales.preview.page'));

        $this->uploadSalesCsv([
            'Customer Phone,Customer Name,Product Name,Brand,Model,Quantity,Sale Amount,Tax Type,Payment Status,Paid Amount,Invoice Status,Delivery Status,Delivery Date,Sale Date,Warehouse,Notes',
            '9876543210,Aarav Sharma,Oxygen Concentrator 5 LPM,Philips,SimplyGo,1,45000,exclusive,pending,,generated,not_assigned,,2026-05-01,Main Warehouse,Second import',
        ]);
        $this->post(route('imports.sales.execute'))->assertRedirect(route('imports.sales.preview.page'));

        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_changing_sale_invoice_status_only_updates_existing_invoice(): void
    {
        [$organization, $warehouse, $product] = $this->bootSalesImportContext();

        $this->uploadSalesCsv([
            'Customer Phone,Customer Name,Product Name,Brand,Model,Quantity,Sale Amount,Tax Type,Payment Status,Paid Amount,Invoice Status,Delivery Status,Delivery Date,Sale Date,Warehouse,Notes',
            '9876543210,Aarav Sharma,Oxygen Concentrator 5 LPM,Philips,SimplyGo,1,45000,exclusive,pending,,not_generated,not_assigned,,2026-05-01,Main Warehouse,No invoice first',
        ]);
        $this->post(route('imports.sales.execute'))->assertRedirect(route('imports.sales.preview.page'));
        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('invoices', 0);

        $this->uploadSalesCsv([
            'Customer Phone,Customer Name,Product Name,Brand,Model,Quantity,Sale Amount,Tax Type,Payment Status,Paid Amount,Invoice Status,Delivery Status,Delivery Date,Sale Date,Warehouse,Notes',
            '9876543210,Aarav Sharma,Oxygen Concentrator 5 LPM,Philips,SimplyGo,1,45000,exclusive,pending,,generated,not_assigned,,2026-05-01,Main Warehouse,Generate invoice now',
        ]);
        $this->post(route('imports.sales.execute'))->assertRedirect(route('imports.sales.preview.page'));

        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertSame(Sale::firstOrFail()->id, Invoice::firstOrFail()->sale_id);
    }

    public function test_changing_sale_payment_status_to_paid_updates_existing_invoice_without_duplicate_payments(): void
    {
        [$organization, $warehouse, $product] = $this->bootSalesImportContext();

        $this->uploadSalesCsv([
            'Customer Phone,Customer Name,Product Name,Brand,Model,Quantity,Sale Amount,Tax Type,Payment Status,Paid Amount,Invoice Status,Delivery Status,Delivery Date,Sale Date,Warehouse,Notes',
            '9876543210,Aarav Sharma,Oxygen Concentrator 5 LPM,Philips,SimplyGo,1,45000,exclusive,pending,,generated,not_assigned,,2026-05-01,Main Warehouse,Pending first',
        ]);
        $this->post(route('imports.sales.execute'))->assertRedirect(route('imports.sales.preview.page'));

        $this->uploadSalesCsv([
            'Customer Phone,Customer Name,Product Name,Brand,Model,Quantity,Sale Amount,Tax Type,Payment Status,Paid Amount,Invoice Status,Delivery Status,Delivery Date,Sale Date,Warehouse,Notes',
            '9876543210,Aarav Sharma,Oxygen Concentrator 5 LPM,Philips,SimplyGo,1,45000,exclusive,paid,45000,generated,not_assigned,,2026-05-01,Main Warehouse,Paid second',
        ]);
        $this->post(route('imports.sales.execute'))->assertRedirect(route('imports.sales.preview.page'));

        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame('paid', Invoice::firstOrFail()->fresh()->payment_status);
        $this->assertSame(Sale::firstOrFail()->id, Invoice::firstOrFail()->fresh()->sale_id);
    }

    public function test_changing_sale_paid_amount_updates_existing_payment_without_duplicate_rows(): void
    {
        [$organization, $warehouse, $product] = $this->bootSalesImportContext();

        $this->uploadSalesCsv([
            'Customer Phone,Customer Name,Product Name,Brand,Model,Quantity,Sale Amount,Tax Type,Payment Status,Paid Amount,Invoice Status,Delivery Status,Delivery Date,Sale Date,Warehouse,Notes',
            '9876543210,Aarav Sharma,Oxygen Concentrator 5 LPM,Philips,SimplyGo,1,45000,exclusive,partial,20000,generated,not_assigned,,2026-05-01,Main Warehouse,Partial first',
        ]);
        $this->post(route('imports.sales.execute'))->assertRedirect(route('imports.sales.preview.page'));

        $this->uploadSalesCsv([
            'Customer Phone,Customer Name,Product Name,Brand,Model,Quantity,Sale Amount,Tax Type,Payment Status,Paid Amount,Invoice Status,Delivery Status,Delivery Date,Sale Date,Warehouse,Notes',
            '9876543210,Aarav Sharma,Oxygen Concentrator 5 LPM,Philips,SimplyGo,1,45000,exclusive,partial,30000,generated,not_assigned,,2026-05-01,Main Warehouse,Partial second',
        ]);
        $this->post(route('imports.sales.execute'))->assertRedirect(route('imports.sales.preview.page'));

        $this->assertDatabaseCount('sales', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(30000.0, (float) Payment::firstOrFail()->amount);
        $this->assertSame('partial', Invoice::firstOrFail()->fresh()->payment_status);
        $this->assertSame(Sale::firstOrFail()->id, Invoice::firstOrFail()->fresh()->sale_id);
    }

    public function test_changing_sale_delivery_status_updates_existing_delivery_without_duplicate_sale(): void
    {
        [$organization, $warehouse, $product] = $this->bootSalesImportContext();

        $this->uploadSalesCsv([
            'Customer Phone,Customer Name,Product Name,Brand,Model,Quantity,Sale Amount,Tax Type,Payment Status,Paid Amount,Invoice Status,Delivery Status,Delivery Date,Sale Date,Warehouse,Notes',
            '9876543210,Aarav Sharma,Oxygen Concentrator 5 LPM,Philips,SimplyGo,1,45000,exclusive,pending,,generated,assigned,2026-05-01,2026-05-01,Main Warehouse,Assigned first',
        ]);
        $this->post(route('imports.sales.execute'))->assertRedirect(route('imports.sales.preview.page'));

        $this->uploadSalesCsv([
            'Customer Phone,Customer Name,Product Name,Brand,Model,Quantity,Sale Amount,Tax Type,Payment Status,Paid Amount,Invoice Status,Delivery Status,Delivery Date,Sale Date,Warehouse,Notes',
            '9876543210,Aarav Sharma,Oxygen Concentrator 5 LPM,Philips,SimplyGo,1,45000,exclusive,pending,,generated,completed,2026-05-02,2026-05-01,Main Warehouse,Completed second',
        ]);
        $this->post(route('imports.sales.execute'))->assertRedirect(route('imports.sales.preview.page'));

        $this->assertDatabaseCount('sales', 1);
        $this->assertSame(1, Delivery::where('type', 'delivery')->count());
        $this->assertSame('completed', Delivery::where('type', 'delivery')->firstOrFail()->status);
    }

    private function bootSalesImportContext(): array
    {
        $organization = TestData::organization();
        $user = TestData::user($organization);
        $this->actingAs($user);

        $warehouse = Warehouse::create([
            'organization_id' => $organization->id,
            'name' => 'Main Warehouse',
            'code' => 'MAIN',
            'is_active' => true,
        ]);

        $product = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Oxygen Concentrator 5 LPM',
            'brand' => 'Philips',
            'model_name' => 'SimplyGo',
            'product_type' => Product::TYPE_SELLABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_SALE,
            'sale_price' => 45000,
            'rental_price' => 0,
            'price_per_day' => 0,
            'available_quantity' => 0,
            'total_quantity' => 0,
        ]);

        Asset::create([
            'organization_id' => $organization->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Sale Unit 1',
            'serial_number' => 'SALE-OC-001',
            'asset_stage' => Asset::STAGE_NEW_STOCK,
            'condition_status' => 'good',
            'asset_status' => Asset::STATUS_AVAILABLE_FOR_SALE,
        ]);

        return [$organization, $warehouse, $product];
    }

    private function uploadSalesCsv(array $lines)
    {
        $csv = implode("\n", $lines);
        $upload = UploadedFile::fake()->createWithContent('sales-import.csv', $csv);

        return $this->post(route('imports.sales.preview'), [
            'import_file' => $upload,
        ]);
    }
}
