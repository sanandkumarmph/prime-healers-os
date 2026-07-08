<?php

namespace Tests\Feature;

use App\Models\Asset;
use App\Models\Product;
use App\Models\ProductBrand;
use App\Models\ProductCategory;
use App\Models\User;
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
            ->assertSee('Product Master')
            ->assertSee('Manage products, pricing and inventory.')
            ->assertSee('Export CSV')
            ->assertSee('Add Product')
            ->assertSee('Available Assets')
            ->assertSee('Low Stock')
            ->assertSee('Search product name, brand, model, SKU, or product code')
            ->assertDontSee('Filters Active');
    }

    public function test_product_form_uses_gst_dropdowns_instead_of_numeric_inputs(): void
    {
        $this->get(route('products.create'))
            ->assertOk()
            ->assertSee('id="gst_split_total_rate"', false)
            ->assertSee('id="gst_igst_total_rate"', false)
            ->assertSee('value="5.00"', false)
            ->assertSee('value="12.00"', false)
            ->assertSee('value="18.00"', false)
            ->assertDontSee('type="number" min="0" max="100" step="0.01" name="cgst_rate"', false)
            ->assertDontSee('type="number" min="0" max="100" step="0.01" name="sgst_rate"', false)
            ->assertDontSee('type="number" min="0" max="100" step="0.01" name="igst_rate"', false);
    }

    public function test_product_form_preserves_non_standard_saved_gst_values_in_dropdowns(): void
    {
        $product = $this->makeProduct([
            'name' => 'Legacy GST Product',
            'gst_tax_type' => Product::GST_TAX_TYPE_CGST_SGST,
            'gst_calculation_mode' => 'exclusive',
            'cgst_rate' => 3.5,
            'sgst_rate' => 3.5,
            'igst_rate' => 0,
        ]);

        $this->get(route('products.edit', $product))
            ->assertOk()
            ->assertSee('value="7.00" selected', false)
            ->assertSee('id="gst_split_total_rate"', false);
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
            ->assertDontSee('BiPAP Machine')
            ->assertSee('Filters Active');
    }

    public function test_search_works_with_type_filter(): void
    {
        $this->makeProduct([
            'name' => 'RespiRent Unit',
            'brand' => 'Respicare',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_RENTAL,
        ]);

        $this->makeProduct([
            'name' => 'RespiSale Unit',
            'brand' => 'Respicare',
            'product_type' => Product::TYPE_SELLABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
        ]);

        $this->get(route('products.index', [
            'search' => 'respi',
            'type' => 'rentable',
        ]))
            ->assertOk()
            ->assertSee('RespiRent Unit')
            ->assertDontSee('RespiSale Unit')
            ->assertSee('Filters Active');
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

    public function test_summary_card_quick_filters_still_work(): void
    {
        $this->makeProduct([
            'name' => 'Available Rental Device',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_RENTAL,
        ]);

        $this->get(route('products.index', ['type' => 'sale_only']))
            ->assertOk()
            ->assertSee('Filters Active');
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

    public function test_catalog_summary_cards_remain_global_when_table_is_filtered(): void
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

        $response = $this->get(route('products.index', ['type' => 'rentable', 'page' => 1]));

        $response->assertOk()
            ->assertSee('Showing:')
            ->assertSee('Rentable')
            ->assertSee('Paged Product 13')
            ->assertDontSee('Paged Product 01');

        $content = $response->getContent();

        $this->assertProductKpiValue($content, 'Products', 13);
        $this->assertProductKpiValue($content, 'Available Assets', 11);
        $this->assertProductKpiValue($content, 'Low Stock', 4);
        $this->assertProductKpiValue($content, 'Out of Stock', 4);
        $this->assertProductKpiValue($content, 'Under Repair', 0);
        $this->assertProductKpiValue($content, 'Top Renting', 0);
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
            ->assertSee('Showing 13-13 of 13 products')
            ->assertSee('class="ph-pagination', false)
            ->assertSee('?category=Respiratory&amp;page=1', false);

        $this->assertProductKpiValue($response->getContent(), 'Products', 15);
    }

    public function test_clear_filters_link_resets_search_and_filters(): void
    {
        $response = $this->get(route('products.index', [
            'search' => 'Resp',
            'category' => 'Respiratory',
            'type' => 'rentable',
            'stock_status' => 'available_to_rent',
            'sort' => 'name',
            'direction' => 'asc',
        ]));

        $response->assertOk()
            ->assertSee('Filters Active')
            ->assertSee('href="' . route('products.index') . '"', false);
    }

    public function test_search_visible_by_default_and_works_with_quick_filter(): void
    {
        $this->makeProduct([
            'name' => 'Rentable Search Target',
            'brand' => 'Prime Search',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_RENTAL,
        ]);

        $this->makeProduct([
            'name' => 'Sellable Search Target',
            'brand' => 'Prime Search',
            'product_type' => Product::TYPE_SELLABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
        ]);

        $this->get(route('products.index', [
            'type' => 'rentable',
            'search' => 'Prime Search',
        ]))
            ->assertOk()
            ->assertSee('Search product name, brand, model, SKU, or product code')
            ->assertSee('Rentable Search Target')
            ->assertDontSee('Sellable Search Target')
            ->assertSee('Showing:')
            ->assertSee('Rentable');
    }

    public function test_mobile_search_layout_hooks_are_present(): void
    {
        $this->get(route('products.index'))
            ->assertOk()
            ->assertSee('class="product-primary-search-form"', false)
            ->assertSee('grid-template-columns:1fr;', false);
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

        $response = $this->get(route('assets.index', ['search' => 'PAGE-ASSET', 'per_page' => 10, 'page' => 2]));

        $response->assertOk()
            ->assertSee('Asset Register')
            ->assertSee('Asset Identity')
            ->assertSee('Current Custody')
            ->assertSee('Showing 11-13 of 13 assets')
            ->assertSee('class="ph-pagination', false)
            ->assertSee('search=PAGE-ASSET', false)
            ->assertSee('per_page=10', false)
            ->assertSee('page=1', false);
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

    public function test_product_show_get_does_not_mutate_tracked_stock_or_product_type(): void
    {
        $warehouse = Warehouse::create([
            'organization_id' => $this->organizationId,
            'name' => 'Read Only Warehouse',
            'code' => 'ROW',
            'is_active' => true,
        ]);

        $product = $this->makeProduct([
            'name' => 'Tracked Oxygen Unit',
            'product_type' => Product::TYPE_SELLABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_RENTAL,
            'total_quantity' => 0,
            'available_quantity' => 0,
            'is_sellable' => true,
            'is_rentable' => false,
        ]);

        Asset::create([
            'organization_id' => $this->organizationId,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Tracked Oxygen Unit Asset',
            'serial_number' => 'ROW-001',
            'asset_stage' => Asset::STAGE_RENTAL_STOCK,
            'condition_status' => 'good',
            'asset_status' => Asset::STATUS_AVAILABLE,
        ]);

        $before = $product->fresh(['saleUnits', 'rentalUnits']);

        $this->get(route('products.show', $product))
            ->assertOk()
            ->assertSee('Stock Summary')
            ->assertSee('Rental Assets');

        $after = $product->fresh();

        $this->assertSame($before->product_type, $after->product_type);
        $this->assertSame($before->stock_mode, $after->stock_mode);
        $this->assertSame((int) $before->total_quantity, (int) $after->total_quantity);
        $this->assertSame((int) $before->available_quantity, (int) $after->available_quantity);
        $this->assertSame((bool) $before->is_sellable, (bool) $after->is_sellable);
        $this->assertSame((bool) $before->is_rentable, (bool) $after->is_rentable);
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

    public function test_authorized_user_can_create_category_from_product_create_page(): void
    {
        $response = $this->postJson(route('product-categories.quick-store'), [
            'name' => '  Respiratory   Devices  ',
            'description' => 'Reusable oxygen and sleep devices',
            'is_active' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('category.name', 'Respiratory Devices');

        $this->assertDatabaseHas('product_categories', [
            'organization_id' => $this->organizationId,
            'name' => 'Respiratory Devices',
            'normalized_name' => 'respiratory devices',
        ]);

        $this->get(route('products.create'))
            ->assertOk()
            ->assertSee('Respiratory Devices');
    }

    public function test_authorized_user_can_create_brand_from_product_create_page(): void
    {
        $response = $this->postJson(route('product-brands.quick-store'), [
            'name' => '  Prime   Devices  ',
            'manufacturer' => 'Prime Healers',
            'description' => 'Internal brand',
            'is_active' => true,
        ]);

        $response->assertCreated()
            ->assertJsonPath('brand.name', 'Prime Devices');

        $this->assertDatabaseHas('product_brands', [
            'organization_id' => $this->organizationId,
            'name' => 'Prime Devices',
            'normalized_name' => 'prime devices',
        ]);

        $this->get(route('products.create'))
            ->assertOk()
            ->assertSee('Prime Devices');
    }

    public function test_duplicate_category_is_blocked(): void
    {
        ProductCategory::create([
            'organization_id' => $this->organizationId,
            'name' => 'Respiratory Care',
            'is_active' => true,
        ]);

        $this->postJson(route('product-categories.quick-store'), [
            'name' => ' respiratory   care ',
            'is_active' => true,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name')
            ->assertJsonPath('errors.name.0', 'This category already exists. Please select it from the list.');
    }

    public function test_duplicate_brand_is_blocked(): void
    {
        ProductBrand::create([
            'organization_id' => $this->organizationId,
            'name' => 'Philips',
            'is_active' => true,
        ]);

        $this->postJson(route('product-brands.quick-store'), [
            'name' => ' philips ',
            'is_active' => true,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name')
            ->assertJsonPath('errors.name.0', 'This brand already exists. Please select it from the list.');
    }

    public function test_unauthorized_user_cannot_create_category_or_brand(): void
    {
        $staff = TestData::user(
            \App\Models\Organization::findOrFail($this->organizationId),
            ['role' => User::ROLE_SALES]
        );

        $this->actingAs($staff);

        $this->postJson(route('product-categories.quick-store'), ['name' => 'Hidden Category'])
            ->assertForbidden();

        $this->postJson(route('product-brands.quick-store'), ['name' => 'Hidden Brand'])
            ->assertForbidden();
    }

    private function assertProductKpiValue(string $content, string $label, int $expected): void
    {
        preg_match_all(
            '/<div class="product-kpi-label">\s*(.*?)\s*<\/div>\s*<div class="product-kpi-value">\s*(.*?)\s*<\/div>/s',
            $content,
            $matches,
            PREG_SET_ORDER
        );

        $kpis = [];

        foreach ($matches as $match) {
            $kpis[trim(strip_tags($match[1]))] = trim(strip_tags($match[2]));
        }

        $this->assertArrayHasKey($label, $kpis, "Expected the redesigned Product Master KPI [{$label}] to be present.");
        $this->assertSame((string) $expected, $kpis[$label], "Expected the redesigned Product Master KPI [{$label}] to show [{$expected}].");
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

