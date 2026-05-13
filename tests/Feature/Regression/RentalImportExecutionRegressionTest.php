<?php

namespace Tests\Feature\Regression;

use App\Models\Asset;
use App\Models\Delivery;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Rental;
use App\Models\Warehouse;
use App\Services\ImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Support\TestData;
use Tests\TestCase;

class RentalImportExecutionRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_imported_paid_rental_creates_paid_invoice_and_payment_record(): void
    {
        [$organization, $warehouse, $product] = $this->bootRentalImportContext();

        [$service, $preview] = $this->buildRentalPreview([
            'Customer Name,Customer Phone,Product Name,Brand,Model Name,Dispatch Warehouse Code,Asset Serials,Quantity,Start Date,End Date,Rental Amount,Deposit Amount,Transport Amount,Status,Payment Status,Paid Amount,Invoice Status,Delivery Status,Delivery Date,Pickup Status,Pickup Date,Notes',
            'Aarav Sharma,9876543210,Oxygen Concentrator 5 LPM,Philips,SimplyGo,MAIN,RENT-OC-001,1,2026-05-01,2026-05-15,4500,5000,350,Active,paid,4500,paid,completed,2026-05-01,not_assigned,,Imported paid rental',
        ]);

        $result = $service->executePreview('rentals', $preview['key'], $organization->id, auth()->id());

        $rental = Rental::firstOrFail();
        $invoice = Invoice::firstOrFail();
        $delivery = Delivery::where('type', 'delivery')->firstOrFail();

        $this->assertSame(1, $result['created']);
        $this->assertSame('paid', $invoice->payment_status);
        $this->assertSame($rental->id, $invoice->rental_id);
        $this->assertSame(1, Payment::count());
        $this->assertSame(0.0, (float) $invoice->balance_amount);
        $this->assertCount(1, $invoice->items);
        $this->assertSame('rental', $invoice->items->first()->source_type);
        $this->assertSame(4500.0, (float) $invoice->subtotal);
        $this->assertSame(5000.0, (float) $invoice->deposit_amount);
        $this->assertSame(350.0, (float) $invoice->shipping_charges);
        $this->assertSame(9850.0, (float) $invoice->total_amount);
        $this->assertSame(9850.0, (float) Payment::firstOrFail()->amount);
        $this->assertSame('completed', $delivery->status);
        $this->assertSame(Asset::STATUS_RENTED, Asset::where('serial_number', 'RENT-OC-001')->firstOrFail()->asset_status);
        $this->assertSame('Aarav Sharma', $rental->customer_name);
    }

    public function test_imported_pending_rental_stays_unpaid_and_generates_invoice_only_when_requested(): void
    {
        [$organization, $warehouse, $product] = $this->bootRentalImportContext();

        [$service, $preview] = $this->buildRentalPreview([
            'Customer Name,Customer Phone,Product Name,Brand,Model Name,Dispatch Warehouse Code,Asset Serials,Quantity,Start Date,End Date,Rental Amount,Deposit Amount,Transport Amount,Status,Payment Status,Paid Amount,Invoice Status,Delivery Status,Delivery Date,Pickup Status,Pickup Date,Notes',
            'Aarav Sharma,9876543210,Oxygen Concentrator 5 LPM,Philips,SimplyGo,MAIN,,1,2026-05-01,2026-05-15,4500,5000,350,Active,pending,,generated,assigned,2026-05-01,not_assigned,,Imported pending rental',
        ]);

        $service->executePreview('rentals', $preview['key'], $organization->id, auth()->id());

        $invoice = Invoice::firstOrFail();
        $delivery = Delivery::where('type', 'delivery')->firstOrFail();

        $this->assertSame('unpaid', $invoice->payment_status);
        $this->assertSame(Rental::firstOrFail()->id, $invoice->rental_id);
        $this->assertSame(0.0, (float) $invoice->paid_amount);
        $this->assertGreaterThan(0, (float) $invoice->balance_amount);
        $this->assertSame(0, Payment::count());
        $this->assertSame('pending', $delivery->status);
        $this->assertCount(1, $invoice->items);
    }

    public function test_imported_pending_rental_creates_invoice_by_default_when_invoice_status_is_blank(): void
    {
        [$organization, $warehouse, $product] = $this->bootRentalImportContext();

        [$service, $preview] = $this->buildRentalPreview([
            'Customer Name,Customer Phone,Product Name,Brand,Model Name,Dispatch Warehouse Code,Asset Serials,Quantity,Start Date,End Date,Rental Amount,Deposit Amount,Transport Amount,Status,Payment Status,Paid Amount,Invoice Status,Delivery Status,Delivery Date,Pickup Status,Pickup Date,Notes',
            'Aarav Sharma,9876543210,Oxygen Concentrator 5 LPM,Philips,SimplyGo,MAIN,,1,2026-05-01,2026-05-15,4500,5000,350,Active,pending,,,,,not_assigned,,Imported default invoice rental',
        ]);

        $service->executePreview('rentals', $preview['key'], $organization->id, auth()->id());

        $invoice = Invoice::firstOrFail();

        $this->assertSame(Rental::firstOrFail()->id, $invoice->rental_id);
        $this->assertSame('unpaid', $invoice->payment_status);
        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_imported_rental_skips_invoice_only_when_invoice_status_is_not_generated(): void
    {
        [$organization, $warehouse, $product] = $this->bootRentalImportContext();

        [$service, $preview] = $this->buildRentalPreview([
            'Customer Name,Customer Phone,Product Name,Brand,Model Name,Dispatch Warehouse Code,Asset Serials,Quantity,Start Date,End Date,Rental Amount,Deposit Amount,Transport Amount,Status,Payment Status,Paid Amount,Invoice Status,Delivery Status,Delivery Date,Pickup Status,Pickup Date,Notes',
            'Aarav Sharma,9876543210,Oxygen Concentrator 5 LPM,Philips,SimplyGo,MAIN,,1,2026-05-01,2026-05-15,4500,0,0,Active,pending,,not_generated,assigned,2026-05-01,not_assigned,,Imported no invoice rental',
        ]);

        $service->executePreview('rentals', $preview['key'], $organization->id, auth()->id());

        $this->assertDatabaseCount('rentals', 1);
        $this->assertDatabaseCount('invoices', 0);
    }

    public function test_imported_completed_pickup_marks_rental_returned_and_releases_assets_to_awaiting_verification(): void
    {
        [$organization, $warehouse, $product] = $this->bootRentalImportContext();

        [$service, $preview] = $this->buildRentalPreview([
            'Customer Name,Customer Phone,Product Name,Brand,Model Name,Dispatch Warehouse Code,Asset Serials,Quantity,Start Date,End Date,Rental Amount,Deposit Amount,Transport Amount,Status,Payment Status,Paid Amount,Invoice Status,Delivery Status,Delivery Date,Pickup Status,Pickup Date,Notes',
            'Aarav Sharma,9876543210,Oxygen Concentrator 5 LPM,Philips,SimplyGo,MAIN,RENT-OC-001,1,2026-05-01,2026-05-15,4500,0,0,Completed,pending,,generated,completed,2026-05-01,completed,2026-05-15,Imported completed rental',
        ]);

        $service->executePreview('rentals', $preview['key'], $organization->id, auth()->id());

        $rental = Rental::firstOrFail();
        $delivery = Delivery::where('type', 'delivery')->firstOrFail();
        $pickup = Delivery::where('type', 'pickup')->firstOrFail();
        $asset = Asset::where('serial_number', 'RENT-OC-001')->firstOrFail();

        $this->assertSame('returned', $rental->status);
        $this->assertNotNull($rental->returned_at);
        $this->assertSame('completed', $delivery->status);
        $this->assertSame('completed', $pickup->status);
        $this->assertSame(Asset::STATUS_AWAITING_VERIFICATION, $asset->asset_status);
    }

    public function test_invalid_rental_import_status_values_are_rejected_in_preview(): void
    {
        [$organization, $warehouse, $product] = $this->bootRentalImportContext();

        [$service, $preview] = $this->buildRentalPreview([
            'Customer Name,Customer Phone,Product Name,Brand,Model Name,Dispatch Warehouse Code,Asset Serials,Quantity,Start Date,End Date,Rental Amount,Deposit Amount,Transport Amount,Status,Payment Status,Paid Amount,Invoice Status,Delivery Status,Delivery Date,Pickup Status,Pickup Date,Notes',
            'Aarav Sharma,9876543210,Oxygen Concentrator 5 LPM,Philips,SimplyGo,MAIN,,1,2026-05-01,2026-05-15,4500,0,0,Active,done,,issued,delivered-now,,pickedup,,Imported bad rental',
        ]);

        $this->assertSame(0, count($preview['valid_rows']));
        $this->assertNotEmpty($preview['invalid_rows']);

        $errorText = implode(' | ', $preview['invalid_rows'][0]['errors'] ?? []);
        $this->assertStringContainsString('Payment status must be paid, partial, pending, or unpaid.', $errorText);
        $this->assertStringContainsString('Invoice status must be generated, not_generated, paid, unpaid, partial, or pending.', $errorText);
        $this->assertStringContainsString('Delivery status must be not_assigned, assigned, pending, or completed.', $errorText);
        $this->assertStringContainsString('Pickup status must be not_assigned, assigned, pending, or completed.', $errorText);
    }

    public function test_duplicate_rental_import_execution_does_not_create_duplicate_rows(): void
    {
        [$organization, $warehouse, $product] = $this->bootRentalImportContext();

        [$service, $preview] = $this->buildRentalPreview([
            'Customer Name,Customer Phone,Product Name,Brand,Model Name,Dispatch Warehouse Code,Asset Serials,Quantity,Start Date,End Date,Rental Amount,Deposit Amount,Transport Amount,Status,Payment Status,Paid Amount,Invoice Status,Delivery Status,Delivery Date,Pickup Status,Pickup Date,Notes',
            'Aarav Sharma,9876543210,Oxygen Concentrator 5 LPM,Philips,SimplyGo,MAIN,,1,2026-05-01,2026-05-15,4500,0,0,Active,pending,,generated,assigned,2026-05-01,not_assigned,,Duplicate guard rental',
        ]);

        $service->executePreview('rentals', $preview['key'], $organization->id, auth()->id());
        $service->executePreview('rentals', $preview['key'], $organization->id, auth()->id());

        $this->assertDatabaseCount('rentals', 1);
        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_runtime_skipped_rental_rows_include_clear_reason_details(): void
    {
        [$organization] = $this->bootRentalImportContext();

        [$service, $preview] = $this->buildRentalPreview([
            'Customer Name,Customer Phone,Product Name,Brand,Model Name,Dispatch Warehouse Code,Asset Serials,Quantity,Start Date,End Date,Rental Amount,Deposit Amount,Transport Amount,Status,Payment Status,Paid Amount,Invoice Status,Delivery Status,Delivery Date,Pickup Status,Pickup Date,Notes',
            'Aarav Sharma,9876543210,Oxygen Concentrator 5 LPM,Philips,SimplyGo,MAIN,RENT-OC-001,1,2026-05-01,2026-05-15,4500,0,0,Active,pending,,generated,assigned,2026-05-01,not_assigned,,Runtime skip row',
        ]);

        Asset::where('organization_id', $organization->id)
            ->where('serial_number', 'RENT-OC-001')
            ->firstOrFail()
            ->update(['asset_status' => Asset::STATUS_MAINTENANCE]);

        $result = $service->executePreview('rentals', $preview['key'], $organization->id, auth()->id());

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(0, $result['failed']);
        $this->assertCount(1, $result['skipped_rows']);
        $this->assertSame('business_rule', $result['skipped_rows'][0]['reason_category']);
        $this->assertStringContainsString('No rental asset is available', $result['skipped_rows'][0]['reason']);
        $this->assertStringContainsString('Aarav Sharma', $result['skipped_rows'][0]['identifier']);
        $this->assertSame(1, $result['business_rule_skips_count']);
        $this->assertNotEmpty($result['reason_groups']);
    }

    public function test_importing_same_rental_row_twice_updates_existing_rental_instead_of_creating_duplicate(): void
    {
        [$organization] = $this->bootRentalImportContext();

        [$service, $firstPreview] = $this->buildRentalPreview([
            'Customer Name,Customer Phone,Product Name,Brand,Model Name,Dispatch Warehouse Code,Asset Serials,Quantity,Start Date,End Date,Rental Amount,Deposit Amount,Transport Amount,Status,Payment Status,Paid Amount,Invoice Status,Delivery Status,Delivery Date,Pickup Status,Pickup Date,Notes',
            'Aarav Sharma,9876543210,Oxygen Concentrator 5 LPM,Philips,SimplyGo,MAIN,,1,2026-05-01,2026-05-15,4500,5000,350,Active,pending,,generated,assigned,2026-05-01,not_assigned,,First import',
        ]);
        $service->executePreview('rentals', $firstPreview['key'], $organization->id, auth()->id());

        [$service, $secondPreview] = $this->buildRentalPreview([
            'Customer Name,Customer Phone,Product Name,Brand,Model Name,Dispatch Warehouse Code,Asset Serials,Quantity,Start Date,End Date,Rental Amount,Deposit Amount,Transport Amount,Status,Payment Status,Paid Amount,Invoice Status,Delivery Status,Delivery Date,Pickup Status,Pickup Date,Notes',
            'Aarav Sharma,9876543210,Oxygen Concentrator 5 LPM,Philips,SimplyGo,MAIN,,1,2026-05-01,2026-05-15,4500,5000,350,Active,pending,,generated,assigned,2026-05-01,not_assigned,,Second import',
        ]);
        $result = $service->executePreview('rentals', $secondPreview['key'], $organization->id, auth()->id());

        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['updated']);
        $this->assertDatabaseCount('rentals', 1);
        $this->assertDatabaseCount('invoices', 1);
    }

    public function test_changing_rental_invoice_status_only_updates_existing_invoice(): void
    {
        [$organization] = $this->bootRentalImportContext();

        [$service, $firstPreview] = $this->buildRentalPreview([
            'Customer Name,Customer Phone,Product Name,Brand,Model Name,Dispatch Warehouse Code,Asset Serials,Quantity,Start Date,End Date,Rental Amount,Deposit Amount,Transport Amount,Status,Payment Status,Paid Amount,Invoice Status,Delivery Status,Delivery Date,Pickup Status,Pickup Date,Notes',
            'Aarav Sharma,9876543210,Oxygen Concentrator 5 LPM,Philips,SimplyGo,MAIN,,1,2026-05-01,2026-05-15,4500,5000,350,Active,pending,,not_generated,not_assigned,,not_assigned,,No invoice first',
        ]);
        $service->executePreview('rentals', $firstPreview['key'], $organization->id, auth()->id());

        $this->assertDatabaseCount('rentals', 1);
        $this->assertDatabaseCount('invoices', 0);

        [$service, $secondPreview] = $this->buildRentalPreview([
            'Customer Name,Customer Phone,Product Name,Brand,Model Name,Dispatch Warehouse Code,Asset Serials,Quantity,Start Date,End Date,Rental Amount,Deposit Amount,Transport Amount,Status,Payment Status,Paid Amount,Invoice Status,Delivery Status,Delivery Date,Pickup Status,Pickup Date,Notes',
            'Aarav Sharma,9876543210,Oxygen Concentrator 5 LPM,Philips,SimplyGo,MAIN,,1,2026-05-01,2026-05-15,4500,5000,350,Active,pending,,generated,not_assigned,,not_assigned,,Generate invoice now',
        ]);
        $service->executePreview('rentals', $secondPreview['key'], $organization->id, auth()->id());

        $this->assertDatabaseCount('rentals', 1);
        $this->assertDatabaseCount('invoices', 1);
        $this->assertSame(Rental::firstOrFail()->id, Invoice::firstOrFail()->rental_id);
    }

    public function test_changing_rental_payment_status_to_paid_updates_existing_invoice_without_duplicate_payments(): void
    {
        [$organization] = $this->bootRentalImportContext();

        [$service, $firstPreview] = $this->buildRentalPreview([
            'Customer Name,Customer Phone,Product Name,Brand,Model Name,Dispatch Warehouse Code,Asset Serials,Quantity,Start Date,End Date,Rental Amount,Deposit Amount,Transport Amount,Status,Payment Status,Paid Amount,Invoice Status,Delivery Status,Delivery Date,Pickup Status,Pickup Date,Notes',
            'Aarav Sharma,9876543210,Oxygen Concentrator 5 LPM,Philips,SimplyGo,MAIN,,1,2026-05-01,2026-05-15,4500,5000,350,Active,pending,,generated,assigned,2026-05-01,not_assigned,,Pending first',
        ]);
        $service->executePreview('rentals', $firstPreview['key'], $organization->id, auth()->id());

        [$service, $secondPreview] = $this->buildRentalPreview([
            'Customer Name,Customer Phone,Product Name,Brand,Model Name,Dispatch Warehouse Code,Asset Serials,Quantity,Start Date,End Date,Rental Amount,Deposit Amount,Transport Amount,Status,Payment Status,Paid Amount,Invoice Status,Delivery Status,Delivery Date,Pickup Status,Pickup Date,Notes',
            'Aarav Sharma,9876543210,Oxygen Concentrator 5 LPM,Philips,SimplyGo,MAIN,,1,2026-05-01,2026-05-15,4500,5000,350,Active,paid,9850,generated,assigned,2026-05-01,not_assigned,,Paid second',
        ]);
        $service->executePreview('rentals', $secondPreview['key'], $organization->id, auth()->id());

        $invoice = Invoice::firstOrFail();
        $this->assertDatabaseCount('rentals', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame('paid', $invoice->fresh()->payment_status);
        $this->assertSame(0.0, (float) $invoice->fresh()->balance_amount);
        $this->assertSame(Rental::firstOrFail()->id, $invoice->fresh()->rental_id);
    }

    public function test_changing_rental_paid_amount_updates_existing_payment_without_duplicate_rows(): void
    {
        [$organization] = $this->bootRentalImportContext();

        [$service, $firstPreview] = $this->buildRentalPreview([
            'Customer Name,Customer Phone,Product Name,Brand,Model Name,Dispatch Warehouse Code,Asset Serials,Quantity,Start Date,End Date,Rental Amount,Deposit Amount,Transport Amount,Status,Payment Status,Paid Amount,Invoice Status,Delivery Status,Delivery Date,Pickup Status,Pickup Date,Notes',
            'Aarav Sharma,9876543210,Oxygen Concentrator 5 LPM,Philips,SimplyGo,MAIN,,1,2026-05-01,2026-05-15,4500,5000,350,Active,partial,2000,generated,assigned,2026-05-01,not_assigned,,Partial first',
        ]);
        $service->executePreview('rentals', $firstPreview['key'], $organization->id, auth()->id());

        [$service, $secondPreview] = $this->buildRentalPreview([
            'Customer Name,Customer Phone,Product Name,Brand,Model Name,Dispatch Warehouse Code,Asset Serials,Quantity,Start Date,End Date,Rental Amount,Deposit Amount,Transport Amount,Status,Payment Status,Paid Amount,Invoice Status,Delivery Status,Delivery Date,Pickup Status,Pickup Date,Notes',
            'Aarav Sharma,9876543210,Oxygen Concentrator 5 LPM,Philips,SimplyGo,MAIN,,1,2026-05-01,2026-05-15,4500,5000,350,Active,partial,3000,generated,assigned,2026-05-01,not_assigned,,Partial updated',
        ]);
        $service->executePreview('rentals', $secondPreview['key'], $organization->id, auth()->id());

        $this->assertDatabaseCount('rentals', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame(3000.0, (float) Payment::firstOrFail()->amount);
        $this->assertSame('partial', Invoice::firstOrFail()->fresh()->payment_status);
        $this->assertSame(Rental::firstOrFail()->id, Invoice::firstOrFail()->fresh()->rental_id);
    }

    public function test_changing_rental_delivery_status_updates_existing_delivery_without_duplicate_rental(): void
    {
        [$organization] = $this->bootRentalImportContext();

        [$service, $firstPreview] = $this->buildRentalPreview([
            'Customer Name,Customer Phone,Product Name,Brand,Model Name,Dispatch Warehouse Code,Asset Serials,Quantity,Start Date,End Date,Rental Amount,Deposit Amount,Transport Amount,Status,Payment Status,Paid Amount,Invoice Status,Delivery Status,Delivery Date,Pickup Status,Pickup Date,Notes',
            'Aarav Sharma,9876543210,Oxygen Concentrator 5 LPM,Philips,SimplyGo,MAIN,RENT-OC-001,1,2026-05-01,2026-05-15,4500,0,0,Active,pending,,generated,assigned,2026-05-01,not_assigned,,Assigned first',
        ]);
        $service->executePreview('rentals', $firstPreview['key'], $organization->id, auth()->id());

        [$service, $secondPreview] = $this->buildRentalPreview([
            'Customer Name,Customer Phone,Product Name,Brand,Model Name,Dispatch Warehouse Code,Asset Serials,Quantity,Start Date,End Date,Rental Amount,Deposit Amount,Transport Amount,Status,Payment Status,Paid Amount,Invoice Status,Delivery Status,Delivery Date,Pickup Status,Pickup Date,Notes',
            'Aarav Sharma,9876543210,Oxygen Concentrator 5 LPM,Philips,SimplyGo,MAIN,RENT-OC-001,1,2026-05-01,2026-05-15,4500,0,0,Active,pending,,generated,completed,2026-05-02,not_assigned,,Completed second',
        ]);
        $service->executePreview('rentals', $secondPreview['key'], $organization->id, auth()->id());

        $this->assertDatabaseCount('rentals', 1);
        $this->assertSame(1, Delivery::where('type', 'delivery')->count());
        $this->assertSame('completed', Delivery::where('type', 'delivery')->firstOrFail()->status);
    }

    private function bootRentalImportContext(): array
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
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_BOTH,
            'rental_price' => 4500,
            'price_per_day' => 4500,
            'sale_price' => 45000,
            'available_quantity' => 0,
            'total_quantity' => 0,
        ]);

        Asset::create([
            'organization_id' => $organization->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Rental Unit 1',
            'serial_number' => 'RENT-OC-001',
            'asset_stage' => Asset::STAGE_RENTAL_STOCK,
            'condition_status' => 'good',
            'asset_status' => Asset::STATUS_AVAILABLE,
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

    private function buildRentalPreview(array $lines): array
    {
        $csv = implode("\n", $lines);
        $upload = UploadedFile::fake()->createWithContent('rental-import.csv', $csv);
        $service = app(ImportService::class);
        $snapshot = $service->storeUpload('rentals', $upload, auth()->user()->organization_id, auth()->id());
        $mapping = $service->suggestMapping('rentals', $snapshot['headers']);
        $preview = $service->buildPreview('rentals', $snapshot['key'], $mapping, auth()->user()->organization_id);

        return [$service, $preview];
    }
}
