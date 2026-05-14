<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Product;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class ProductCatalogPageTest extends TestCase
{
    use RefreshDatabase;

    private int $organizationId;

    protected function setUp(): void
    {
        parent::setUp();

        $organization = TestData::organization();
        $this->organizationId = $organization->id;

        $this->actingAs(TestData::user($organization));
    }

    public function test_product_catalog_loads(): void
    {
        $this->get(route('products.index'))
            ->assertOk()
            ->assertSee('Healthcare Equipment Catalog')
            ->assertSee('Product Master');
    }

    public function test_product_form_gst_inputs_opt_out_of_global_numeric_auto_select(): void
    {
        $this->get(route('products.create'))
            ->assertOk()
            ->assertSee('id="cgst_rate"', false)
            ->assertSee('id="sgst_rate"', false)
            ->assertSee('id="igst_rate"', false)
            ->assertSee('class="gst-percent-input no-auto-select"', false)
            ->assertSee('data-no-auto-select', false);
    }

    public function test_search_filter_works(): void
    {
        $this->makeProduct([
            'name' => 'Oxygen Concentrator',
            'brand' => 'Respicare',
            'model_name' => 'OX-100',
            'sku' => 'OXY-100',
            'product_code' => 'PC-OXY-100',
        ]);

        $this->makeProduct([
            'name' => 'BiPAP Machine',
            'brand' => 'SleepWell',
            'model_name' => 'BP-200',
        ]);

        $this->get(route('products.index', ['search' => 'respicare']))
            ->assertOk()
            ->assertSee('Oxygen Concentrator')
            ->assertDontSee('BiPAP Machine');
    }

    public function test_category_and_type_filter_work(): void
    {
        $this->makeProduct([
            'name' => 'Dual Inventory Unit',
            'category' => 'Respiratory',
            'product_type' => Product::TYPE_SELLABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_BOTH,
        ]);

        $this->makeProduct([
            'name' => 'Single Mode Unit',
            'category' => 'Respiratory',
            'product_type' => Product::TYPE_SELLABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_SALE,
        ]);

        $this->makeProduct([
            'name' => 'Wheelchair',
            'category' => 'Mobility',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_RENTAL,
        ]);

        $this->get(route('products.index', ['category' => 'Respiratory', 'type' => 'both']))
            ->assertOk()
            ->assertSee('Dual Inventory Unit')
            ->assertDontSee('Single Mode Unit')
            ->assertDontSee('Wheelchair');
    }

    public function test_sorting_works(): void
    {
        $warehouse = Warehouse::create([
            'organization_id' => $this->organizationId,
            'name' => 'Main Warehouse',
            'code' => 'MAIN',
            'is_active' => true,
        ]);

        $lowStock = $this->makeProduct([
            'name' => 'Low Stock Product',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_RENTAL,
        ]);

        $highStock = $this->makeProduct([
            'name' => 'High Stock Product',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_RENTAL,
        ]);

        Asset::create([
            'organization_id' => $this->organizationId,
            'product_id' => $lowStock->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Low Rental 1',
            'serial_number' => 'LOW-001',
            'asset_stage' => Asset::STAGE_RENTAL_STOCK,
            'condition_status' => 'good',
            'asset_status' => Asset::STATUS_AVAILABLE,
        ]);

        foreach (range(1, 3) as $index) {
            Asset::create([
                'organization_id' => $this->organizationId,
                'product_id' => $highStock->id,
                'warehouse_id' => $warehouse->id,
                'asset_name' => 'High Rental ' . $index,
                'serial_number' => 'HIGH-00' . $index,
                'asset_stage' => Asset::STAGE_RENTAL_STOCK,
                'condition_status' => 'good',
                'asset_status' => Asset::STATUS_AVAILABLE,
            ]);
        }

        $this->get(route('products.index', ['sort' => 'available_units', 'direction' => 'desc']))
            ->assertOk()
            ->assertSeeInOrder(['High Stock Product', 'Low Stock Product']);
    }

    public function test_invalid_sort_parameter_does_not_break_the_page(): void
    {
        $older = $this->makeProduct(['name' => 'Older Product']);
        $older->forceFill([
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ])->saveQuietly();

        $newer = $this->makeProduct(['name' => 'Newer Product']);
        $newer->forceFill([
            'created_at' => now(),
            'updated_at' => now(),
        ])->saveQuietly();

        $this->get(route('products.index', ['sort' => 'drop table products']))
            ->assertOk()
            ->assertSeeInOrder(['Newer Product', 'Older Product']);
    }

    public function test_serial_number_continues_across_pages_and_checkbox_markup_exists(): void
    {
        foreach (range(1, 13) as $index) {
            $this->makeProduct([
                'name' => 'Catalog Product ' . str_pad((string) $index, 2, '0', STR_PAD_LEFT),
            ])->forceFill([
                'created_at' => now()->subMinutes(13 - $index),
                'updated_at' => now()->subMinutes(13 - $index),
            ])->saveQuietly();
        }

        $response = $this->get(route('products.index', ['page' => 2]));

        $response->assertOk()
            ->assertSee('id="productSelectAll"', false)
            ->assertSee('class="product-row-check"', false)
            ->assertSee('Catalog Product 01');

        $this->assertMatchesRegularExpression(
            '/<td class="product-cell product-serial-col" data-label="No\.">\s*13\s*<\/td>/',
            $response->getContent()
        );
    }

    public function test_catalog_summary_cards_use_full_filtered_result_set_not_current_page(): void
    {
        foreach (range(1, 13) as $index) {
            $this->makeProduct([
                'name' => 'Paged Product ' . $index,
                'category' => $index <= 3 ? 'Respiratory' : 'General',
                'product_type' => $index <= 4 ? Product::TYPE_SELLABLE : Product::TYPE_RENTABLE,
                'stock_mode' => $index <= 4 ? Product::STOCK_MODE_UNTRACKED : Product::STOCK_MODE_TRACKED_RENTAL,
                'available_quantity' => $index <= 4 ? 2 : 0,
                'total_quantity' => $index <= 4 ? 2 : 0,
            ])->forceFill([
                'created_at' => now()->subMinutes(13 - $index),
                'updated_at' => now()->subMinutes(13 - $index),
            ])->saveQuietly();
        }

        $warehouse = Warehouse::create([
            'organization_id' => $this->organizationId,
            'name' => 'Catalog Totals Warehouse',
            'code' => 'CTW',
            'is_active' => true,
        ]);

        $rentableProducts = Product::query()
            ->where('organization_id', $this->organizationId)
            ->where('product_type', Product::TYPE_RENTABLE)
            ->orderBy('id')
            ->get();

        foreach ($rentableProducts->take(3) as $product) {
            Asset::create([
                'organization_id' => $this->organizationId,
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'asset_name' => $product->name . ' Rental Asset',
                'serial_number' => 'RA-' . $product->id,
                'asset_stage' => Asset::STAGE_RENTAL_STOCK,
                'condition_status' => 'good',
                'asset_status' => Asset::STATUS_AVAILABLE,
            ]);
        }

        $response = $this->get(route('products.index', ['page' => 2]));

        $response->assertOk()
            ->assertSee('Showing 13-13 of 13 results');

        $content = $response->getContent();

        $this->assertMatchesRegularExpression('/Product Master<\/div>\s*<div class="product-kpi-value">13<\/div>/', $content);
        $this->assertMatchesRegularExpression('/Sellable<\/div>\s*<div class="product-kpi-value">4<\/div>/', $content);
        $this->assertMatchesRegularExpression('/Rentable<\/div>\s*<div class="product-kpi-value">9<\/div>/', $content);
        $this->assertMatchesRegularExpression('/Sale Units<\/div>\s*<div class="product-kpi-value">8<\/div>/', $content);
        $this->assertMatchesRegularExpression('/Rental Available<\/div>\s*<div class="product-kpi-value">3<\/div>/', $content);
    }

    public function test_catalog_pagination_and_stats_preserve_filter_context(): void
    {
        foreach (range(1, 13) as $index) {
            $this->makeProduct([
                'name' => 'Resp Product ' . $index,
                'category' => 'Respiratory',
                'product_type' => Product::TYPE_SELLABLE,
                'stock_mode' => Product::STOCK_MODE_UNTRACKED,
                'available_quantity' => 1,
                'total_quantity' => 1,
            ])->forceFill([
                'created_at' => now()->subMinutes(13 - $index),
                'updated_at' => now()->subMinutes(13 - $index),
            ])->saveQuietly();
        }

        foreach (range(1, 2) as $index) {
            $this->makeProduct([
                'name' => 'General Product ' . $index,
                'category' => 'General',
            ]);
        }

        $response = $this->get(route('products.index', ['category' => 'Respiratory', 'page' => 2]));

        $response->assertOk()
            ->assertSee('Showing 13-13 of 13 results')
            ->assertSee('class="ph-pagination', false)
            ->assertSee('?category=Respiratory&amp;page=1', false);

        $this->assertMatchesRegularExpression(
            '/Product Master<\/div>\s*<div class="product-kpi-value">13<\/div>/',
            $response->getContent()
        );
    }

    public function test_product_master_export_csv_downloads_filtered_rows(): void
    {
        $this->makeProduct([
            'name' => 'Export Match Product',
            'brand' => 'ExportBrand',
        ]);

        $this->makeProduct([
            'name' => 'Other Product',
            'brand' => 'OtherBrand',
        ]);

        $response = $this->get(route('products.export.csv', ['search' => 'ExportBrand']));

        $response->assertOk();
        $response->assertStreamed();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('Export Match Product', $response->streamedContent());
        $this->assertStringNotContainsString('Other Product', $response->streamedContent());
    }

    public function test_asset_register_export_csv_downloads_filtered_rows(): void
    {
        $warehouse = Warehouse::create([
            'organization_id' => $this->organizationId,
            'name' => 'Export Warehouse',
            'code' => 'EXP',
            'is_active' => true,
        ]);

        $matchingProduct = $this->makeProduct([
            'name' => 'Export Asset Product',
        ]);

        $otherProduct = $this->makeProduct([
            'name' => 'Other Asset Product',
        ]);

        Asset::create([
            'organization_id' => $this->organizationId,
            'product_id' => $matchingProduct->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Matching Asset',
            'serial_number' => 'EXP-001',
            'asset_stage' => Asset::STAGE_RENTAL_STOCK,
            'condition_status' => 'good',
            'asset_status' => Asset::STATUS_AVAILABLE,
        ]);

        Asset::create([
            'organization_id' => $this->organizationId,
            'product_id' => $otherProduct->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Other Asset',
            'serial_number' => 'OTH-001',
            'asset_stage' => Asset::STAGE_RENTAL_STOCK,
            'condition_status' => 'good',
            'asset_status' => Asset::STATUS_AVAILABLE,
        ]);

        $response = $this->get(route('assets.export.csv', ['search' => 'EXP-001']));

        $response->assertOk();
        $response->assertStreamed();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('Matching Asset', $response->streamedContent());
        $this->assertStringNotContainsString('Other Asset', $response->streamedContent());
    }

    public function test_asset_register_pagination_renders_inline_and_preserves_filters(): void
    {
        $warehouse = Warehouse::create([
            'organization_id' => $this->organizationId,
            'name' => 'Pagination Warehouse',
            'code' => 'PGW',
            'is_active' => true,
        ]);

        $product = $this->makeProduct([
            'name' => 'Pagination Asset Product',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_RENTAL,
        ]);

        foreach (range(1, 13) as $index) {
            Asset::create([
                'organization_id' => $this->organizationId,
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'asset_name' => 'Pagination Asset ' . $index,
                'serial_number' => 'PAGE-ASSET-' . str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                'asset_stage' => Asset::STAGE_RENTAL_STOCK,
                'condition_status' => 'good',
                'asset_status' => Asset::STATUS_AVAILABLE,
            ]);
        }

        $response = $this->get(route('assets.index', ['search' => 'PAGE-ASSET', 'page' => 2]));

        $response->assertOk()
            ->assertSee('Serialized Sale Units')
            ->assertSee('Showing 13-13 of 13 assets')
            ->assertSee('class="ph-pagination', false)
            ->assertSee('?search=PAGE-ASSET&amp;page=1', false);
    }

    public function test_layout_includes_global_double_delete_confirmation_script(): void
    {
        $product = $this->makeProduct([
            'name' => 'Delete Guard Product',
        ]);

        $response = $this->get(route('products.index'));

        $response->assertOk()
            ->assertSee('Please confirm again. This action is permanent and cannot be undone.', false)
            ->assertSee('form.dataset.deleteConfirmAccepted = \'true\';', false)
            ->assertSee(route('products.destroy', $product), false);
    }

    public function test_untracked_product_show_uses_opening_quantity_in_stock_summary(): void
    {
        $product = $this->makeProduct([
            'name' => 'BiPAP Disposable Filter',
            'product_type' => Product::TYPE_SELLABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'available_quantity' => 1,
            'total_quantity' => 1,
        ]);

        $response = $this->get(route('products.show', $product));

        $response->assertOk()
            ->assertSee('Stock Summary')
            ->assertSee('opening quantity stored in Product Master')
            ->assertSeeInOrder(['Sale Units', '>1<'], false);
    }

    public function test_product_master_gst_fields_can_be_saved_and_updated(): void
    {
        $storeResponse = $this->post(route('products.store'), [
            'name' => 'GST Product',
            'category' => 'Respiratory',
            'brand' => 'Prime',
            'model_name' => 'GST-01',
            'product_code' => 'GST-001',
            'sku' => 'GST-SKU-001',
            'product_type' => Product::TYPE_SELLABLE,
            'sale_price' => 5000,
            'price_per_day' => 0,
            'quantity' => 2,
            'gst_tax_type' => Product::GST_TAX_TYPE_CGST_SGST,
            'gst_calculation_mode' => 'inclusive',
            'cgst_rate' => 9,
            'sgst_rate' => 9,
            'igst_rate' => 18,
        ]);

        $product = Product::query()->where('organization_id', $this->organizationId)->where('sku', 'GST-SKU-001')->firstOrFail();

        $storeResponse->assertRedirect(route('products.show', $product));
        $this->assertSame(Product::GST_TAX_TYPE_CGST_SGST, $product->gst_tax_type);
        $this->assertSame('inclusive', $product->gst_calculation_mode);
        $this->assertSame(9.0, (float) $product->cgst_rate);
        $this->assertSame(9.0, (float) $product->sgst_rate);
        $this->assertSame(0.0, (float) $product->igst_rate);

        $updateResponse = $this->put(route('products.update', $product), [
            'name' => 'GST Product',
            'category' => 'Respiratory',
            'brand' => 'Prime',
            'model_name' => 'GST-01',
            'product_code' => 'GST-001',
            'sku' => 'GST-SKU-001',
            'product_type' => Product::TYPE_SELLABLE,
            'sale_price' => 5000,
            'price_per_day' => 0,
            'quantity' => 2,
            'gst_tax_type' => Product::GST_TAX_TYPE_IGST,
            'gst_calculation_mode' => 'exclusive',
            'cgst_rate' => 9,
            'sgst_rate' => 9,
            'igst_rate' => 18,
        ]);

        $updateResponse->assertRedirect(route('products.show', $product));
        $this->assertSame(Product::GST_TAX_TYPE_IGST, $product->fresh()->gst_tax_type);
        $this->assertSame('exclusive', $product->fresh()->gst_calculation_mode);
        $this->assertSame(0.0, (float) $product->fresh()->cgst_rate);
        $this->assertSame(0.0, (float) $product->fresh()->sgst_rate);
        $this->assertSame(18.0, (float) $product->fresh()->igst_rate);
    }

    private function makeProduct(array $attributes = []): Product
    {
        return Product::create(array_merge([
            'organization_id' => $this->organizationId,
            'name' => 'Test Product',
            'category' => 'General',
            'brand' => 'Prime',
            'model_name' => 'Base',
            'product_type' => Product::TYPE_SELLABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'price_per_day' => 100,
            'rental_price_15_days' => 1200,
            'rental_price_30_days' => 2200,
            'rental_price_3_months' => 6000,
            'sale_price' => 5000,
            'rental_price' => 2200,
            'available_quantity' => 0,
            'total_quantity' => 0,
        ], $attributes));
    }
}
