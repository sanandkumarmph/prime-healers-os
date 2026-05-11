<?php

namespace Tests\Feature\Regression;

use App\Models\Asset;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\ImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Support\TestData;
use Tests\TestCase;

class GenericImportExecutionRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_import_preview_rejects_ambiguous_name_without_phone_or_email(): void
    {
        $organization = TestData::organization();
        $user = TestData::user($organization);
        $this->actingAs($user);

        Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Aarav Sharma',
            'phone' => '9000000001',
        ]);

        Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Aarav Sharma',
            'phone' => '9000000002',
        ]);

        [$service, $preview] = $this->buildPreview('customers', [
            'Name,Phone,Email,Address,City,GSTIN',
            'Aarav Sharma,,,,Bengaluru,',
        ]);

        $this->assertSame(0, count($preview['valid_rows']));
        $this->assertNotEmpty($preview['invalid_rows']);
        $this->assertStringContainsString(
            'Multiple customers already use this name. Provide phone or email so the importer can match the correct customer.',
            implode(' | ', $preview['invalid_rows'][0]['errors'] ?? [])
        );
    }

    public function test_product_import_preview_rejects_ambiguous_name_without_brand_and_model(): void
    {
        $organization = TestData::organization();
        $user = TestData::user($organization);
        $this->actingAs($user);

        Product::create([
            'organization_id' => $organization->id,
            'name' => 'Oxygen Concentrator 5 LPM',
            'brand' => 'Philips',
            'model_name' => 'SimplyGo',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_RENTAL,
            'price_per_day' => 4500,
            'rental_price' => 4500,
            'total_quantity' => 0,
            'available_quantity' => 0,
        ]);

        Product::create([
            'organization_id' => $organization->id,
            'name' => 'Oxygen Concentrator 5 LPM',
            'brand' => 'Oxymed',
            'model_name' => 'Mini',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_RENTAL,
            'price_per_day' => 4200,
            'rental_price' => 4200,
            'total_quantity' => 0,
            'available_quantity' => 0,
        ]);

        [$service, $preview] = $this->buildPreview('products', [
            'Product Name,Category,Brand,Model Name,SKU,Product Code,Sellable,Rentable,Stock Mode,Sale Price,Rental Price,Deposit',
            'Oxygen Concentrator 5 LPM,Respiratory,,,,,No,Yes,tracked_rental,,4500,5000',
        ]);

        $this->assertSame(0, count($preview['valid_rows']));
        $this->assertNotEmpty($preview['invalid_rows']);
        $this->assertStringContainsString(
            'Multiple products share this name. Specify Brand and Model.',
            implode(' | ', $preview['invalid_rows'][0]['errors'] ?? [])
        );
    }

    public function test_product_import_updates_exact_variant_without_touching_other_variants(): void
    {
        $organization = TestData::organization();
        $user = TestData::user($organization);
        $this->actingAs($user);

        $philips = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Oxygen Concentrator 5 LPM',
            'brand' => 'Philips',
            'model_name' => 'SimplyGo',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_RENTAL,
            'price_per_day' => 4500,
            'rental_price' => 4500,
            'total_quantity' => 0,
            'available_quantity' => 0,
        ]);

        $oxymed = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Oxygen Concentrator 5 LPM',
            'brand' => 'Oxymed',
            'model_name' => 'Mini',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_RENTAL,
            'price_per_day' => 4200,
            'rental_price' => 4200,
            'total_quantity' => 0,
            'available_quantity' => 0,
        ]);

        [$service, $preview] = $this->buildPreview('products', [
            'Product Name,Category,Brand,Model Name,SKU,Product Code,Sellable,Rentable,Stock Mode,Price Per Day,Rental Price,Sale Price,Deposit',
            'Oxygen Concentrator 5 LPM,Respiratory,Oxymed,Mini,,,No,Yes,tracked_rental,5000,5500,,',
        ]);

        $result = $service->executePreview('products', $preview['key'], $organization->id, $user->id);

        $this->assertSame(1, $result['updated']);
        $this->assertSame(5000.0, (float) $oxymed->fresh()->price_per_day);
        $this->assertSame(5500.0, (float) $oxymed->fresh()->rental_price);
        $this->assertSame(4500.0, (float) $philips->fresh()->rental_price);
    }

    public function test_product_import_preview_rejects_duplicate_product_identity_within_same_file(): void
    {
        $organization = TestData::organization();
        $user = TestData::user($organization);
        $this->actingAs($user);

        [$service, $preview] = $this->buildPreview('products', [
            'Product Name,Category,Brand,Model Name,SKU,Product Code,Sellable,Rentable,Stock Mode,Price Per Day,Rental Price,Sale Price,Deposit',
            'Oxygen Concentrator 5 LPM,Respiratory,,,,,No,Yes,tracked_rental,4500,4500,,',
            'Oxygen Concentrator 5 LPM,Respiratory,,,,,No,Yes,tracked_rental,4800,4800,,',
        ]);

        $this->assertSame(1, count($preview['valid_rows']));
        $this->assertSame(1, count($preview['invalid_rows']));
        $this->assertStringContainsString(
            'This file contains another row with the same Product Name, Brand, and Model',
            implode(' | ', $preview['invalid_rows'][0]['errors'] ?? [])
        );
    }

    public function test_product_import_creates_two_distinct_variants_from_same_file(): void
    {
        $organization = TestData::organization();
        $user = TestData::user($organization);
        $this->actingAs($user);

        [$service, $preview] = $this->buildPreview('products', [
            'Product Name,Category,Brand,Model Name,SKU,Product Code,Sellable,Rentable,Stock Mode,Price Per Day,Rental Price,Sale Price,Deposit',
            'Oxygen Concentrator 5 LPM,Respiratory,Philips,SimplyGo,,,No,Yes,tracked_rental,4500,4500,,',
            'Oxygen Concentrator 5 LPM,Respiratory,Oxymed,Mini,,,No,Yes,tracked_rental,4200,4200,,',
        ]);

        $this->assertSame(2, count($preview['valid_rows']));

        $result = $service->executePreview('products', $preview['key'], $organization->id, $user->id);

        $this->assertSame(2, $result['created']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(2, Product::query()->where('organization_id', $organization->id)->count());
        $this->assertDatabaseHas('products', [
            'organization_id' => $organization->id,
            'name' => 'Oxygen Concentrator 5 LPM',
            'brand' => 'Philips',
            'model_name' => 'SimplyGo',
        ]);
        $this->assertDatabaseHas('products', [
            'organization_id' => $organization->id,
            'name' => 'Oxygen Concentrator 5 LPM',
            'brand' => 'Oxymed',
            'model_name' => 'Mini',
        ]);
    }

    public function test_product_import_allows_sellable_bipap_row_with_blank_rental_price(): void
    {
        $organization = TestData::organization();
        $user = TestData::user($organization);
        $this->actingAs($user);

        [$service, $preview] = $this->buildPreview('products', [
            'Product Name,Category,Brand,Model Name,SKU,Product Code,Sellable,Rentable,Stock Mode,Sale Price,Rental Price,Deposit',
            'BiPAP Disposable Filter,Consumables,ResMed,Filter Pack,BF-180,BIPAP-FLTR,Yes,No,untracked,180,,0',
        ]);

        $this->assertSame(1, count($preview['valid_rows']));

        $result = $service->executePreview('products', $preview['key'], $organization->id, $user->id);

        $this->assertSame(1, $result['created']);
        $this->assertSame(0, $result['skipped']);
        $this->assertDatabaseHas('products', [
            'organization_id' => $organization->id,
            'name' => 'BiPAP Disposable Filter',
            'brand' => 'ResMed',
            'model_name' => 'Filter Pack',
            'sku' => 'BF-180',
            'product_code' => 'BIPAP-FLTR',
            'product_type' => Product::TYPE_SELLABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
        ]);
        $this->assertSame(
            0.0,
            (float) Product::query()
                ->where('organization_id', $organization->id)
                ->where('sku', 'BF-180')
                ->value('price_per_day')
        );
    }

    public function test_asset_import_allows_existing_serial_update_and_generic_preview_is_idempotent(): void
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
            'name' => 'BiPAP Machine',
            'brand' => 'ResMed',
            'model_name' => 'AirCurve 10',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_BOTH,
            'price_per_day' => 7000,
            'rental_price' => 7000,
            'sale_price' => 45000,
            'total_quantity' => 0,
            'available_quantity' => 0,
        ]);

        Asset::create([
            'organization_id' => $organization->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Original Asset',
            'serial_number' => 'BIPAP-001',
            'barcode_value' => 'BIPAP-001',
            'asset_stage' => Asset::STAGE_RENTAL_STOCK,
            'condition_status' => 'good',
            'asset_status' => Asset::STATUS_AVAILABLE,
            'purchase_cost' => 30000,
        ]);

        [$service, $preview] = $this->buildPreview('assets', [
            'Product Name,Brand,Model Name,Warehouse Code,Asset Type,Serial Number,Barcode,Condition,Status,Purchase Date,Purchase Cost',
            'BiPAP Machine,ResMed,AirCurve 10,MAIN,rental_stock,BIPAP-001,BIPAP-001,good,available,2026-05-01,35000',
        ]);

        $this->assertSame(1, count($preview['valid_rows']));

        $first = $service->executePreview('assets', $preview['key'], $organization->id, $user->id);
        $second = $service->executePreview('assets', $preview['key'], $organization->id, $user->id);

        $this->assertSame(1, $first['updated']);
        $this->assertTrue(!empty($second['already_imported']));
        $this->assertSame(1, Asset::count());
        $this->assertSame(35000.0, (float) Asset::firstOrFail()->fresh()->purchase_cost);
    }

    private function buildPreview(string $module, array $lines): array
    {
        $csv = implode("\n", $lines);
        $upload = UploadedFile::fake()->createWithContent($module . '-import.csv', $csv);
        $service = app(ImportService::class);
        $snapshot = $service->storeUpload($module, $upload, auth()->user()->organization_id, auth()->id());
        $mapping = $service->suggestMapping($module, $snapshot['headers']);
        $preview = $service->buildPreview($module, $snapshot['key'], $mapping, auth()->user()->organization_id);

        return [$service, $preview];
    }
}
