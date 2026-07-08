<?php

namespace Tests\Feature\Regression;

use App\Models\Asset;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TestData;
use Tests\TestCase;

class ProductImageRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_product_image_can_be_uploaded_on_create(): void
    {
        Storage::fake('public');
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization));

        $response = $this->post(route('products.store'), $this->payload([
            'product_image' => UploadedFile::fake()->create('oxygen.png', 8, 'image/png'),
        ]));

        $product = Product::query()->where('name', 'Image Enabled Product')->first();

        $response->assertRedirect();
        $this->assertNotNull($product);
        $this->assertNotNull($product->product_image_path);
        $this->assertStringStartsWith('products/', $product->product_image_path);
        Storage::disk('public')->assertExists($product->product_image_path);
    }

    public function test_product_image_can_be_replaced_on_edit(): void
    {
        Storage::fake('public');
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization));
        Storage::disk('public')->put('products/old.png', 'old');

        $product = $this->product($organization->id, [
            'product_image_path' => 'products/old.png',
        ]);

        $response = $this->put(route('products.update', $product), $this->payload([
            'name' => $product->name,
            'product_code' => $product->product_code,
            'sku' => $product->sku,
            'product_image' => UploadedFile::fake()->create('new.png', 8, 'image/png'),
        ]));

        $response->assertRedirect();
        $product->refresh();

        $this->assertNotSame('products/old.png', $product->product_image_path);
        Storage::disk('public')->assertMissing('products/old.png');
        Storage::disk('public')->assertExists($product->product_image_path);
    }

    public function test_product_image_can_be_removed_on_edit(): void
    {
        Storage::fake('public');
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization));
        Storage::disk('public')->put('products/remove.png', 'remove');

        $product = $this->product($organization->id, [
            'product_image_path' => 'products/remove.png',
        ]);

        $response = $this->put(route('products.update', $product), $this->payload([
            'name' => $product->name,
            'product_code' => $product->product_code,
            'sku' => $product->sku,
            'remove_product_image' => '1',
        ]));

        $response->assertRedirect();
        $product->refresh();

        $this->assertNull($product->product_image_path);
        Storage::disk('public')->assertMissing('products/remove.png');
    }

    public function test_product_pages_render_when_image_is_missing(): void
    {
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization));
        $product = $this->product($organization->id);

        $this->get(route('products.index'))->assertOk();
        $this->get(route('products.show', $product))->assertOk();
        $this->get(route('products.edit', $product))->assertOk();
    }

    public function test_asset_register_renders_linked_product_image_without_error(): void
    {
        Storage::fake('public');
        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization));
        Storage::disk('public')->put('products/asset-product.png', 'image');

        $product = $this->product($organization->id, [
            'name' => 'Asset Linked Image Product',
            'product_image_path' => 'products/asset-product.png',
        ]);

        $warehouse = Warehouse::create([
            'organization_id' => $organization->id,
            'name' => 'Image Warehouse',
            'code' => 'IMG',
            'is_active' => true,
        ]);

        $asset = Asset::create([
            'organization_id' => $organization->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Image Asset Unit',
            'serial_number' => 'IMG-ASSET-001',
            'asset_stage' => Asset::STAGE_RENTAL_STOCK,
            'asset_status' => Asset::STATUS_AVAILABLE,
            'condition_status' => 'good',
        ]);

        $asset->load(['product', 'warehouse']);

        $html = view('assets.index', [
            'assets' => new LengthAwarePaginator(collect([$asset]), 1, 25, 1, ['path' => route('assets.index')]),
            'warehouses' => collect([$warehouse]),
            'cityOptions' => collect(),
            'assetStages' => Asset::ASSET_STAGES,
            'assetStatuses' => Asset::ASSET_STATUSES,
            'newStockStatuses' => Asset::NEW_STOCK_ASSET_STATUSES,
            'rentalAssetStatuses' => Asset::RENTAL_ASSET_STATUSES,
            'conditionStatuses' => Asset::CONDITION_STATUSES,
            'summary' => [
                'total_assets' => 1,
                'sale_stock' => 0,
                'rental_stock_assets' => 1,
                'available_assets' => 1,
                'awaiting_verification_assets' => 0,
                'rented_assets' => 0,
                'maintenance_assets' => 0,
                'reserved_assets' => 0,
                'retired_assets' => 0,
                'missing_risk_assets' => 0,
                'risk_assets' => 0,
                'attention_awaiting_3_days' => 0,
                'attention_repair_30_days' => 0,
                'attention_no_location' => 0,
                'attention_no_movement_60_days' => 0,
                'attention_no_movement_90_days' => 0,
                'attention_overdue_returns' => 0,
            ],
            'duplicateProductNameGroups' => collect(),
            'mixedAssetProductGroups' => collect(),
        ])->render();

        $this->assertStringContainsString('products/asset-product.png', $html);
    }

    private function product(int $organizationId, array $overrides = []): Product
    {
        return Product::create(array_merge([
            'organization_id' => $organizationId,
            'name' => 'Image Enabled Product',
            'product_code' => 'IMG-PROD-' . fake()->unique()->numberBetween(1000, 9999),
            'sku' => 'IMG-SKU-' . fake()->unique()->numberBetween(1000, 9999),
            'product_type' => Product::TYPE_SELLABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'sale_price' => 1200,
            'price_per_day' => 0,
            'available_quantity' => 4,
            'total_quantity' => 4,
            'gst_tax_type' => Product::GST_TAX_TYPE_CGST_SGST,
            'gst_calculation_mode' => 'exclusive',
            'cgst_rate' => 2.5,
            'sgst_rate' => 2.5,
            'igst_rate' => 0,
        ], $overrides));
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Image Enabled Product',
            'product_code' => 'IMG-CODE-001',
            'sku' => 'IMG-SKU-001',
            'product_type' => Product::TYPE_SELLABLE,
            'sale_price' => 1200,
            'price_per_day' => 0,
            'quantity' => 4,
            'gst_tax_type' => Product::GST_TAX_TYPE_CGST_SGST,
            'gst_calculation_mode' => 'exclusive',
            'cgst_rate' => 2.5,
            'sgst_rate' => 2.5,
            'igst_rate' => 0,
        ], $overrides);
    }
}
