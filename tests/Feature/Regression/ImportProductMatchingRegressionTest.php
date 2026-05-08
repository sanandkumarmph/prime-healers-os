<?php

namespace Tests\Feature\Regression;

use App\Models\Organization;
use App\Models\Product;
use App\Models\Warehouse;
use App\Services\ImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

class ImportProductMatchingRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_asset_import_matches_product_by_name_brand_and_model(): void
    {
        [$organization, $warehouse] = $this->seedImportContext();

        $expectedProduct = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Oxygen Concentrator',
            'brand' => 'Philips',
            'model_name' => 'SimplyGo',
            'product_code' => 'OXY-PH',
            'sku' => 'OXY-PH',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_RENTAL,
            'total_quantity' => 0,
            'available_quantity' => 0,
            'price_per_day' => 450,
            'rental_price' => 4500,
        ]);

        Product::create([
            'organization_id' => $organization->id,
            'name' => 'Oxygen Concentrator',
            'brand' => 'Drive',
            'model_name' => 'DeVilbiss',
            'product_code' => 'OXY-DR',
            'sku' => 'OXY-DR',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_RENTAL,
            'total_quantity' => 0,
            'available_quantity' => 0,
            'price_per_day' => 400,
            'rental_price' => 4000,
        ]);

        $preview = $this->buildAssetPreview($organization->id, <<<CSV
product_name,brand,model_name,warehouse_code,asset_stage,serial_number,asset_status,condition_status
Oxygen Concentrator,Philips,SimplyGo,MAIN,rental_stock,OC-100,available,good
CSV);

        $this->assertSame(0, $preview['invalid_count']);
        $this->assertSame(1, $preview['valid_count']);
        $this->assertSame($expectedProduct->id, $preview['valid_rows'][0]['payload']['product_id']);
        $this->assertSame($warehouse->id, $preview['valid_rows'][0]['payload']['warehouse_id']);
    }

    public function test_asset_import_falls_back_to_unique_product_name_only(): void
    {
        [$organization] = $this->seedImportContext();

        $expectedProduct = Product::create([
            'organization_id' => $organization->id,
            'name' => 'BiPAP Machine',
            'brand' => 'ResMed',
            'model_name' => 'AirCurve 10',
            'product_code' => 'BIPAP-1',
            'sku' => 'BIPAP-1',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_RENTAL,
            'total_quantity' => 0,
            'available_quantity' => 0,
            'price_per_day' => 600,
            'rental_price' => 6000,
        ]);

        $preview = $this->buildAssetPreview($organization->id, <<<CSV
product_name,brand,model_name,warehouse_code,asset_stage,serial_number,asset_status,condition_status
BiPAP Machine,,,MAIN,rental_stock,BP-100,available,good
CSV);

        $this->assertSame(0, $preview['invalid_count']);
        $this->assertSame(1, $preview['valid_count']);
        $this->assertSame($expectedProduct->id, $preview['valid_rows'][0]['payload']['product_id']);
    }

    public function test_asset_import_blocks_ambiguous_name_only_matches(): void
    {
        [$organization] = $this->seedImportContext();

        Product::create([
            'organization_id' => $organization->id,
            'name' => 'Oxygen Concentrator',
            'brand' => 'Philips',
            'model_name' => 'SimplyGo',
            'product_code' => 'OXY-PH',
            'sku' => 'OXY-PH',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_RENTAL,
            'total_quantity' => 0,
            'available_quantity' => 0,
            'price_per_day' => 450,
            'rental_price' => 4500,
        ]);

        Product::create([
            'organization_id' => $organization->id,
            'name' => 'Oxygen Concentrator',
            'brand' => 'Drive',
            'model_name' => 'DeVilbiss',
            'product_code' => 'OXY-DR',
            'sku' => 'OXY-DR',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_RENTAL,
            'total_quantity' => 0,
            'available_quantity' => 0,
            'price_per_day' => 400,
            'rental_price' => 4000,
        ]);

        $preview = $this->buildAssetPreview($organization->id, <<<CSV
product_name,brand,model_name,warehouse_code,asset_stage,serial_number,asset_status,condition_status
Oxygen Concentrator,,,MAIN,rental_stock,OC-200,available,good
CSV);

        $this->assertSame(0, $preview['valid_count']);
        $this->assertSame(1, $preview['invalid_count']);
        $this->assertContains(
            'Multiple products found. Please specify Brand and Model.',
            $preview['invalid_rows'][0]['errors']
        );
    }

    private function seedImportContext(): array
    {
        $organization = Organization::create([
            'name' => 'Import Match Org',
            'is_active' => true,
        ]);

        $warehouse = Warehouse::create([
            'organization_id' => $organization->id,
            'name' => 'Main Warehouse',
            'code' => 'MAIN',
            'is_active' => true,
        ]);

        return [$organization, $warehouse];
    }

    private function buildAssetPreview(int $organizationId, string $csv): array
    {
        $service = app(ImportService::class);
        $file = UploadedFile::fake()->createWithContent('assets.csv', $csv);
        $upload = $service->storeUpload('assets', $file, $organizationId, 1);

        return $service->buildPreview('assets', $upload['key'], [
            'product_name' => 'product_name',
            'brand' => 'brand',
            'model_name' => 'model_name',
            'warehouse_code' => 'warehouse_code',
            'asset_stage' => 'asset_stage',
            'serial_number' => 'serial_number',
            'asset_status' => 'asset_status',
            'condition_status' => 'condition_status',
        ], $organizationId);
    }
}
