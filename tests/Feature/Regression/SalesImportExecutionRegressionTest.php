<?php

namespace Tests\Feature\Regression;

use App\Models\Asset;
use App\Models\BusinessPartner;
use App\Models\City;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\PartnerClient;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\ImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use ZipArchive;
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

    public function test_sales_preview_page_renders_imported_and_skipped_rows_with_field_level_reasons(): void
    {
        [$organization, $warehouse, $product] = $this->bootSalesImportContext();

        City::create([
            'organization_id' => $organization->id,
            'name' => 'Bengaluru',
            'state' => 'Karnataka',
            'country' => 'India',
            'is_active' => true,
        ]);

        $partner = BusinessPartner::create([
            'organization_id' => $organization->id,
            'business_name' => 'Care Plus Clinic',
            'contact_person' => 'Ananya Rao',
            'phone' => '9810012345',
            'city' => 'Bengaluru',
            'state' => 'Karnataka',
            'status' => 'active',
        ]);

        PartnerClient::create([
            'organization_id' => $organization->id,
            'business_partner_id' => $partner->id,
            'client_name' => 'Rahul Verma',
            'phone' => '9810012345',
            'city' => 'Bengaluru',
            'state' => 'Karnataka',
            'status' => 'active',
        ]);

        Vendor::create([
            'organization_id' => $organization->id,
            'name' => 'KR Healthcare',
            'phone' => '9000001111',
            'city' => 'Bengaluru',
            'state' => 'Karnataka',
            'is_active' => true,
        ]);

        $response = $this->uploadSalesCsv([
            'Customer Type,Business Partner,Actual Client,Customer Phone,Customer Name,Product Name,Brand,Model,City,Fulfilment Source,Vendor,Delivery Responsibility,Quantity,Sale Amount,Tax Type,Payment Status,Paid Amount,Invoice Status,Delivery Status,Delivery Date,Sale Date,Warehouse,Notes',
            'business_partner,Care Plus Clinic,Rahul Verma,9810012345,Rahul Verma,Oxygen Concentrator 5 LPM,Philips,SimplyGo,Bengaluru,vendor_supplied,KR Healthcare,vendor_delivery,1,45000,exclusive,pending,,generated,assigned,2026-05-03,2026-05-01,,Vendor supplied partner sale',
            'direct_customer,,,9876543210,Aarav Sharma,Oxygen Concentrator 5 LPM,Philips,SimplyGo,,in_house,,ph_internal_delivery,1,45000,exclusive,done,,issued,delivered-now,,2026-05-01,Main Warehouse,Bad statuses',
        ]);

        $response->assertRedirect(route('imports.sales.preview.page'));

        $page = $this->get(route('imports.sales.preview.page'));

        $page->assertOk();
        $page->assertSee('Imported Rows Preview');
        $page->assertSee('Skipped Rows');
        $page->assertSee('Business Partner');
        $page->assertSee('Fulfilment Source');
        $page->assertSee('Vendor');
        $page->assertSee('Delivery Responsibility');
        $page->assertSeeText('Payment Status');
        $page->assertSeeText('Payment Status must be paid, partial, pending, or unpaid.');
        $page->assertSeeText('Invoice Status');
        $page->assertSeeText('Invoice Status must be generated, not_generated, paid, unpaid, partial, or pending.');
        $page->assertSeeText('Delivery Status');
        $page->assertSeeText('Delivery Status must be not_assigned, assigned, pending, or completed.');
    }

    public function test_sales_prefixed_excel_workbook_with_three_rows_does_not_return_zero_row_preview(): void
    {
        [$organization, $warehouse, $product] = $this->bootSalesImportContext();

        City::firstOrCreate([
            'organization_id' => $organization->id,
            'name' => 'Bengaluru',
        ], [
            'state' => 'Karnataka',
            'country' => 'India',
            'is_active' => true,
        ]);

        $response = $this->uploadSalesPrefixedXlsx([
            ['Customer Type', 'Business Partner', 'Actual Client', 'Customer Name', 'Customer Phone', 'Product Name', 'Brand', 'Model', 'City', 'Fulfilment Source', 'Vendor', 'Delivery Responsibility', 'Warehouse', 'Quantity', 'Sale Amount', 'Tax Type', 'Payment Status', 'Paid Amount', 'Invoice Status', 'Delivery Status', 'Delivery Date', 'Sale Date', 'Notes'],
            ['business_partner', 'Bengaluru Care Partners', '', 'Bengaluru Care Partners', '9876501001', 'Oxygen Concentrator 5 LPM', 'Philips', 'SimplyGo', 'Bengaluru', 'in_house', '', 'ph_internal_delivery', 'Main Warehouse', '1', '4500', 'exclusive', 'pending', '', 'generated', 'not_assigned', '', '2026-05-01', 'Sales row 1'],
            ['business_partner', 'Bengaluru Care Partners', '', 'Bengaluru Care Partners', '9876501002', 'Oxygen Concentrator 5 LPM', 'Philips', 'SimplyGo', 'Bengaluru', 'in_house', '', 'ph_internal_delivery', 'Main Warehouse', '1', '4500', 'exclusive', 'pending', '', 'generated', 'not_assigned', '', '2026-05-02', 'Sales row 2'],
            ['business_partner', 'Bengaluru Care Partners', '', 'Bengaluru Care Partners', '9876501003', 'Oxygen Concentrator 5 LPM', 'Philips', 'SimplyGo', 'Bengaluru', 'in_house', '', 'ph_internal_delivery', 'Main Warehouse', '1', '4500', 'exclusive', 'pending', '', 'generated', 'not_assigned', '', '2026-05-03', 'Sales row 3'],
        ]);

        $response->assertRedirect(route('imports.sales.preview.page'));

        $page = $this->get(route('imports.sales.preview.page'));
        $page->assertOk();
        $page->assertSeeText('Valid Rows');
        $page->assertSeeText('3');
        $page->assertDontSeeText('0 valid rows');
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

    public function test_vendor_supplied_business_partner_sale_import_executes_without_ph_stock_dependency(): void
    {
        [$organization, $warehouse, $product] = $this->bootSalesImportContext();

        City::create([
            'organization_id' => $organization->id,
            'name' => 'Bengaluru',
            'state' => 'Karnataka',
            'country' => 'India',
            'is_active' => true,
        ]);

        $partner = BusinessPartner::create([
            'organization_id' => $organization->id,
            'business_name' => 'Care Plus Clinic',
            'contact_person' => 'Ananya Rao',
            'phone' => '9810012345',
            'city' => 'Bengaluru',
            'state' => 'Karnataka',
            'status' => 'active',
        ]);

        $client = PartnerClient::create([
            'organization_id' => $organization->id,
            'business_partner_id' => $partner->id,
            'client_name' => 'Rahul Verma',
            'phone' => '9810012345',
            'city' => 'Bengaluru',
            'state' => 'Karnataka',
            'status' => 'active',
        ]);

        $vendor = Vendor::create([
            'organization_id' => $organization->id,
            'name' => 'KR Healthcare',
            'phone' => '9000001111',
            'city' => 'Bengaluru',
            'state' => 'Karnataka',
            'is_active' => true,
        ]);

        Asset::query()->update(['asset_status' => Asset::STATUS_SOLD]);

        $response = $this->uploadSalesCsv([
            'Customer Type,Business Partner,Actual Client,Customer Phone,Customer Name,Product Name,Brand,Model,City,Fulfilment Source,Vendor,Delivery Responsibility,Quantity,Sale Amount,Tax Type,Payment Status,Paid Amount,Invoice Status,Delivery Status,Delivery Date,Sale Date,Warehouse,Notes',
            'business_partner,Care Plus Clinic,Rahul Verma,9810012345,Rahul Verma,Oxygen Concentrator 5 LPM,Philips,SimplyGo,Bengaluru,vendor_supplied,KR Healthcare,vendor_delivery,1,45000,exclusive,pending,,generated,assigned,2026-05-03,2026-05-01,,Vendor supplied partner sale',
        ]);

        $response->assertRedirect(route('imports.sales.preview.page'));
        $this->post(route('imports.sales.execute'))->assertRedirect(route('imports.sales.preview.page'));

        $this->assertDatabaseHas('sales', [
            'organization_id' => $organization->id,
            'business_partner_id' => $partner->id,
            'partner_client_id' => $client->id,
            'vendor_id' => $vendor->id,
            'fulfilment_source' => 'vendor_supplied',
            'delivery_responsibility' => 'vendor_delivery',
        ]);
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

    private function uploadSalesPrefixedXlsx(array $rows)
    {
        $upload = UploadedFile::fake()->createWithContent(
            'sales-import.xlsx',
            $this->buildPrefixedSalesXlsxWorkbook($rows, 'Template', 'worksheets/sheet1.xml')
        );

        return $this->post(route('imports.sales.preview'), [
            'import_file' => $upload,
        ]);
    }

    private function buildPrefixedSalesXlsxWorkbook(array $rows, string $sheetName, string $worksheetTarget): string
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'sales-prefixed-import-xlsx-');
        $zip = new ZipArchive();
        $zip->open($tmpPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $sheetPath = ltrim($worksheetTarget, '/');
        $sheetPath = str_starts_with($sheetPath, 'xl/') ? $sheetPath : 'xl/' . $sheetPath;

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/' . htmlspecialchars($sheetPath, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '</Types>');

        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');

        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="utf-8"?>'
            . '<x:workbook xmlns:x="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<x:sheets><x:sheet name="' . htmlspecialchars($sheetName, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '" sheetId="1" r:id="R513d0a5de2c04ae6" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" /></x:sheets>'
            . '</x:workbook>');

        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="R513d0a5de2c04ae6" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="' . htmlspecialchars($worksheetTarget, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '"/>'
            . '</Relationships>');

        $zip->addFromString($sheetPath, $this->buildPrefixedSalesWorksheetXml($rows));
        $zip->close();

        $content = file_get_contents($tmpPath);
        @unlink($tmpPath);

        return (string) $content;
    }

    private function buildPrefixedSalesWorksheetXml(array $rows): string
    {
        $xmlRows = '';
        foreach ($rows as $rowIndex => $row) {
            $cells = '';
            foreach (array_values($row) as $columnIndex => $value) {
                $ref = $this->columnReference($columnIndex) . ($rowIndex + 1);
                $safeValue = htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $cells .= '<x:c r="' . $ref . '" t="inlineStr"><x:is><x:t xml:space="preserve">' . $safeValue . '</x:t></x:is></x:c>';
            }
            $xmlRows .= '<x:row r="' . ($rowIndex + 1) . '">' . $cells . '</x:row>';
        }

        return '<?xml version="1.0" encoding="utf-8"?>'
            . '<x:worksheet xmlns:x="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<x:sheetData>' . $xmlRows . '</x:sheetData>'
            . '</x:worksheet>';
    }

    private function columnReference(int $index): string
    {
        $index++;
        $reference = '';

        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $reference = chr(65 + $mod) . $reference;
            $index = intdiv($index - 1, 26);
        }

        return $reference;
    }
}
