<?php

namespace Tests\Feature\Regression;

use App\Models\Asset;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Delivery;
use App\Models\Rental;
use App\Models\RentalAsset;
use App\Models\RentalItem;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockMovement;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Inventory\InventoryIntelligenceService;
use App\Services\Metrics\SalesMetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestData;
use Tests\TestCase;

class InventoryIntelligenceRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_inventory_intelligence_page_and_export_follow_stock_history_permissions(): void
    {
        $organization = TestData::organization();
        $viewer = $this->userWithRole($organization, 'Inventory Intelligence Viewer', [
            'products' => ['read'],
            'assets' => ['read'],
            '__special' => ['stock_history.view'],
        ]);
        $exporter = $this->userWithRole($organization, 'Inventory Intelligence Exporter', [
            'products' => ['read'],
            'assets' => ['read'],
            '__special' => ['stock_history.view', 'stock_history.export'],
        ]);
        $blocked = TestData::user($organization, [
            'role' => 'staff',
        ]);

        $this->actingAs($viewer)
            ->get(route('inventory-intelligence.index'))
            ->assertOk()
            ->assertSee('Inventory Intelligence');

        $this->actingAs($viewer)
            ->get(route('inventory-intelligence.export-matrix'))
            ->assertStatus(302);

        $this->actingAs($exporter)
            ->get(route('inventory-intelligence.export-matrix'))
            ->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $this->actingAs($blocked)
            ->get(route('inventory-intelligence.index'))
            ->assertStatus(302);
    }

    public function test_inventory_intelligence_matrix_covers_every_day_in_selected_month(): void
    {
        $organization = TestData::organization();
        $product = $this->makeProduct($organization->id, [
            'name' => 'Matrix Product',
        ]);

        $this->movement($organization->id, $product->id, [
            'movement_type' => StockMovement::TYPE_OPENING,
            'quantity' => 10,
            'to_status' => 'available',
            'movement_at' => '2026-05-01 09:00:00',
        ]);

        $report = app(InventoryIntelligenceService::class)->build($organization->id, [
            'month' => 5,
            'year' => 2026,
        ]);

        $this->assertCount(31, $report['dates']);
        $this->assertSame('2026-05-01', $report['dates']->first()['key']);
        $this->assertSame('2026-05-31', $report['dates']->last()['key']);
    }

    public function test_inventory_intelligence_date_range_mode_uses_daily_buckets_for_short_ranges(): void
    {
        $organization = TestData::organization();
        $product = $this->makeProduct($organization->id, [
            'name' => 'Date Range Product',
        ]);

        $this->movement($organization->id, $product->id, [
            'movement_type' => StockMovement::TYPE_OPENING,
            'quantity' => 10,
            'to_status' => 'available',
            'movement_at' => '2026-05-10 09:00:00',
        ]);
        $this->movement($organization->id, $product->id, [
            'movement_type' => StockMovement::TYPE_SALE,
            'quantity' => 2,
            'from_status' => 'available',
            'to_status' => 'sold',
            'movement_at' => '2026-05-12 09:00:00',
        ]);

        $report = app(InventoryIntelligenceService::class)->build($organization->id, [
            'time_scope' => 'date_range',
            'from_date' => '2026-05-10',
            'to_date' => '2026-05-20',
            'product_id' => $product->id,
        ]);

        $this->assertSame('date_range', $report['time_scope']);
        $this->assertSame('daily', $report['bucket_mode']);
        $this->assertCount(11, $report['dates']);
        $this->assertSame('2026-05-10', $report['dates']->first()['key']);
        $this->assertSame('2026-05-20', $report['dates']->last()['key']);
        $this->assertSame(8, $report['summary']['closing_stock']);
    }

    public function test_inventory_intelligence_longer_ranges_switch_bucket_aggregation(): void
    {
        $organization = TestData::organization();
        $product = $this->makeProduct($organization->id, [
            'name' => 'Aggregation Product',
        ]);

        $this->movement($organization->id, $product->id, [
            'movement_type' => StockMovement::TYPE_OPENING,
            'quantity' => 12,
            'to_status' => 'available',
            'movement_at' => '2026-01-01 09:00:00',
        ]);

        $weekly = app(InventoryIntelligenceService::class)->build($organization->id, [
            'time_scope' => 'date_range',
            'from_date' => '2026-01-01',
            'to_date' => '2026-02-20',
            'product_id' => $product->id,
        ]);
        $monthly = app(InventoryIntelligenceService::class)->build($organization->id, [
            'time_scope' => 'date_range',
            'from_date' => '2026-01-01',
            'to_date' => '2026-05-31',
            'product_id' => $product->id,
        ]);

        $this->assertSame('weekly', $weekly['bucket_mode']);
        $this->assertGreaterThan(1, $weekly['dates']->count());
        $this->assertSame('monthly', $monthly['bucket_mode']);
        $this->assertSame('2026-01', $monthly['dates']->first()['key']);
        $this->assertSame('2026-05', $monthly['dates']->last()['key']);
    }

    public function test_inventory_intelligence_all_time_mode_uses_monthly_aggregation(): void
    {
        $organization = TestData::organization();
        $product = $this->makeProduct($organization->id, [
            'name' => 'All Time Product',
        ]);

        $this->movement($organization->id, $product->id, [
            'movement_type' => StockMovement::TYPE_OPENING,
            'quantity' => 5,
            'to_status' => 'available',
            'movement_at' => '2026-01-15 09:00:00',
        ]);
        $this->movement($organization->id, $product->id, [
            'movement_type' => StockMovement::TYPE_SALE,
            'quantity' => 1,
            'from_status' => 'available',
            'to_status' => 'sold',
            'movement_at' => '2026-03-02 09:00:00',
        ]);

        $report = app(InventoryIntelligenceService::class)->build($organization->id, [
            'time_scope' => 'all_time',
            'product_id' => $product->id,
        ]);

        $this->assertSame('all_time', $report['time_scope']);
        $this->assertSame('monthly', $report['bucket_mode']);
        $this->assertSame('All Time', $report['period_label']);
        $this->assertSame('2026-01', $report['dates']->first()['key']);
    }

    public function test_inventory_intelligence_exports_respect_time_scope_filters(): void
    {
        $organization = TestData::organization();
        $exporter = $this->userWithRole($organization, 'Inventory Intelligence Exporter', [
            'products' => ['read'],
            'assets' => ['read'],
            '__special' => ['stock_history.view', 'stock_history.export'],
        ]);
        $product = $this->makeProduct($organization->id, [
            'name' => 'Export Product',
        ]);

        $this->movement($organization->id, $product->id, [
            'movement_type' => StockMovement::TYPE_OPENING,
            'quantity' => 9,
            'to_status' => 'available',
            'movement_at' => '2026-05-10 09:00:00',
        ]);

        $response = $this->actingAs($exporter)
            ->get(route('inventory-intelligence.export-matrix', [
                'time_scope' => 'date_range',
                'from_date' => '2026-05-10',
                'to_date' => '2026-05-12',
                'product_id' => $product->id,
            ]));

        $response->assertOk();
        $content = $response->streamedContent();
        $this->assertStringContainsString('2026-05-10', $content);
        $this->assertStringContainsString('2026-05-12', $content);
        $this->assertStringNotContainsString('2026-05-31', $content);
    }

    public function test_inventory_intelligence_aggregates_sale_rental_return_and_transfer_movements_correctly(): void
    {
        $organization = TestData::organization();
        $product = $this->makeProduct($organization->id, [
            'name' => 'Ledger Driven Product',
        ]);
        $warehouseA = $this->makeWarehouse($organization->id, [
            'name' => 'Warehouse A',
            'city' => 'Bengaluru',
        ]);
        $warehouseB = $this->makeWarehouse($organization->id, [
            'name' => 'Warehouse B',
            'city' => 'Mysuru',
        ]);

        $this->movement($organization->id, $product->id, [
            'movement_type' => StockMovement::TYPE_OPENING,
            'quantity' => 10,
            'to_status' => 'available',
            'to_warehouse_id' => $warehouseA->id,
            'movement_at' => '2026-05-01 09:00:00',
        ]);
        $this->movement($organization->id, $product->id, [
            'movement_type' => StockMovement::TYPE_SALE,
            'quantity' => 2,
            'from_status' => 'available',
            'to_status' => 'sold',
            'from_warehouse_id' => $warehouseA->id,
            'to_warehouse_id' => $warehouseA->id,
            'movement_at' => '2026-05-02 09:00:00',
        ]);
        $this->movement($organization->id, $product->id, [
            'movement_type' => StockMovement::TYPE_DELIVERY,
            'quantity' => 1,
            'from_status' => 'reserved',
            'to_status' => 'rented',
            'from_warehouse_id' => $warehouseA->id,
            'to_warehouse_id' => $warehouseA->id,
            'movement_at' => '2026-05-03 09:00:00',
        ]);
        $this->movement($organization->id, $product->id, [
            'movement_type' => StockMovement::TYPE_PICKUP_RETURN,
            'quantity' => 1,
            'from_status' => 'with_customer',
            'to_status' => 'available',
            'from_warehouse_id' => $warehouseA->id,
            'to_warehouse_id' => $warehouseA->id,
            'movement_at' => '2026-05-04 09:00:00',
        ]);
        $this->movement($organization->id, $product->id, [
            'movement_type' => StockMovement::TYPE_WAREHOUSE_TRANSFER,
            'quantity' => 3,
            'from_status' => 'available',
            'to_status' => 'available',
            'from_warehouse_id' => $warehouseA->id,
            'to_warehouse_id' => $warehouseB->id,
            'movement_at' => '2026-05-05 09:00:00',
        ]);

        $global = app(InventoryIntelligenceService::class)->build($organization->id, [
            'month' => 5,
            'year' => 2026,
            'product_id' => $product->id,
        ]);
        $warehouseAReport = app(InventoryIntelligenceService::class)->build($organization->id, [
            'month' => 5,
            'year' => 2026,
            'product_id' => $product->id,
            'warehouse_id' => $warehouseA->id,
        ]);
        $warehouseBReport = app(InventoryIntelligenceService::class)->build($organization->id, [
            'month' => 5,
            'year' => 2026,
            'product_id' => $product->id,
            'warehouse_id' => $warehouseB->id,
        ]);

        $globalDaily = $global['rows']->first()['daily'];
        $aDaily = $warehouseAReport['rows']->first()['daily'];
        $bDaily = $warehouseBReport['rows']->first()['daily'];

        $this->assertSame(10, $globalDaily['2026-05-01']['closing_stock']);
        $this->assertSame(8, $globalDaily['2026-05-02']['closing_stock']);
        $this->assertSame(7, $globalDaily['2026-05-03']['closing_stock']);
        $this->assertSame(8, $globalDaily['2026-05-04']['closing_stock']);
        $this->assertSame(8, $globalDaily['2026-05-05']['closing_stock']);
        $this->assertSame(2, $globalDaily['2026-05-02']['sale_out']);
        $this->assertSame(1, $globalDaily['2026-05-03']['rental_out']);
        $this->assertSame(1, $globalDaily['2026-05-04']['rental_return']);
        $this->assertSame(10, $global['summary']['opening_stock']);
        $this->assertSame(8, $global['summary']['closing_stock']);
        $this->assertSame(1, $global['summary']['total_rental_out']);
        $this->assertSame(1, $global['summary']['total_returns']);
        $this->assertSame(2, $global['summary']['total_sale_out']);
        $this->assertSame(-2, $global['summary']['net_change']);

        $this->assertSame(5, $aDaily['2026-05-05']['closing_stock']);
        $this->assertSame(3, $aDaily['2026-05-05']['transfers_out']);

        $this->assertSame(3, $bDaily['2026-05-05']['closing_stock']);
        $this->assertSame(3, $bDaily['2026-05-05']['transfers_in']);
    }

    public function test_inventory_intelligence_uses_active_rental_state_for_utilization_summary(): void
    {
        $organization = TestData::organization();
        $product = $this->makeProduct($organization->id, [
            'name' => 'Active Rental Product',
            'total_quantity' => 10,
            'available_quantity' => 6,
        ]);

        $this->movement($organization->id, $product->id, [
            'movement_type' => StockMovement::TYPE_OPENING,
            'quantity' => 10,
            'to_status' => 'available',
            'movement_at' => '2026-05-01 09:00:00',
        ]);

        $rental = Rental::create([
            'organization_id' => $organization->id,
            'customer_type' => 'direct',
            'customer_name' => 'Rental Customer',
            'phone' => '9999999999',
            'product_id' => $product->id,
            'quantity' => 4,
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-31',
            'rental_amount' => 4000,
            'total_amount' => 4000,
            'status' => 'active',
            'delivery_status' => 'completed',
            'pickup_status' => 'pending',
        ]);

        if (Rental::hasRentalItemsTable()) {
            RentalItem::create([
                'organization_id' => $organization->id,
                'rental_id' => $rental->id,
                'product_id' => $product->id,
                'quantity' => 4,
                'ordered_quantity' => 4,
                'delivered_quantity' => 4,
                'returned_quantity' => 0,
                'unit_rental_amount' => 1000,
                'line_total' => 4000,
            ]);
        }

        $report = app(InventoryIntelligenceService::class)->build($organization->id, [
            'mode' => 'rent',
            'month' => 5,
            'year' => 2026,
            'product_id' => $product->id,
        ]);

        $this->assertSame(40.0, $report['summary']['rental_utilization_pct']);
    }

    public function test_inventory_intelligence_asset_state_distribution_reconciles_to_total_assets(): void
    {
        $organization = TestData::organization();
        $product = $this->makeProduct($organization->id, [
            'name' => 'Asset Reconciliation Product',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_BOTH,
        ]);
        $warehouse = $this->makeWarehouse($organization->id);

        Asset::create([
            'organization_id' => $organization->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Available Asset',
            'serial_number' => 'AS-001',
            'asset_stage' => Asset::STAGE_RENTAL_STOCK,
            'asset_status' => Asset::STATUS_AVAILABLE,
        ]);
        Asset::create([
            'organization_id' => $organization->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Rented Asset',
            'serial_number' => 'AS-002',
            'asset_stage' => Asset::STAGE_RENTAL_STOCK,
            'asset_status' => Asset::STATUS_RENTED,
        ]);
        Asset::create([
            'organization_id' => $organization->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Reserved Asset',
            'serial_number' => 'AS-003',
            'asset_stage' => Asset::STAGE_RENTAL_STOCK,
            'asset_status' => Asset::STATUS_RESERVED,
        ]);
        Asset::create([
            'organization_id' => $organization->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Awaiting Verification Asset',
            'serial_number' => 'AS-004',
            'asset_stage' => Asset::STAGE_RENTAL_STOCK,
            'asset_status' => Asset::STATUS_AWAITING_VERIFICATION,
        ]);
        Asset::create([
            'organization_id' => $organization->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Maintenance Asset',
            'serial_number' => 'AS-005',
            'asset_stage' => Asset::STAGE_RENTAL_STOCK,
            'asset_status' => Asset::STATUS_MAINTENANCE,
        ]);
        Asset::create([
            'organization_id' => $organization->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Sold Asset',
            'serial_number' => 'AS-006',
            'asset_stage' => Asset::STAGE_NEW_STOCK,
            'asset_status' => Asset::STATUS_SOLD,
        ]);

        $report = app(InventoryIntelligenceService::class)->build($organization->id, [
            'mode' => 'all',
            'month' => 5,
            'year' => 2026,
            'product_id' => $product->id,
        ]);

        $assetReconciliation = $report['asset_reconciliation'];

        $this->assertSame(6, $assetReconciliation['total_assets']);
        $this->assertSame(1, $assetReconciliation['states']['available']);
        $this->assertSame(1, $assetReconciliation['states']['rented']);
        $this->assertSame(1, $assetReconciliation['states']['reserved']);
        $this->assertSame(1, $assetReconciliation['states']['awaiting_verification']);
        $this->assertSame(1, $assetReconciliation['states']['maintenance']);
        $this->assertSame(1, $assetReconciliation['states']['sold']);
        $this->assertSame(0, $assetReconciliation['states']['retired']);
        $this->assertSame(0, $assetReconciliation['states']['transfer_in_progress']);
        $this->assertSame(
            $assetReconciliation['states']['available']
            + $assetReconciliation['states']['rented']
            + $assetReconciliation['states']['maintenance']
            + $assetReconciliation['states']['reserved']
            + $assetReconciliation['states']['awaiting_verification']
            + $assetReconciliation['states']['sold']
            + $assetReconciliation['states']['retired']
            + $assetReconciliation['states']['transfer_in_progress'],
            $assetReconciliation['total_assets']
        );
        $this->assertTrue($assetReconciliation['reconciles']);
        $this->assertSame(0, $assetReconciliation['unaccounted_assets_count']);
        $this->assertCount(1, $assetReconciliation['warehouse_breakdown']);
        $this->assertSame(5, $assetReconciliation['warehouse_breakdown']->first()['total_rental_assets']);
        $this->assertSame(1, $assetReconciliation['warehouse_breakdown']->first()['available']);
        $this->assertSame(0, $assetReconciliation['warehouse_breakdown']->first()['active_rented']);
        $this->assertSame(1, $assetReconciliation['warehouse_breakdown']->first()['maintenance']);
        $this->assertSame(1, $assetReconciliation['warehouse_breakdown']->first()['awaiting_verification']);
        $this->assertSame(1, $assetReconciliation['warehouse_breakdown']->first()['reserved']);
        $this->assertSame(5, $assetReconciliation['warehouse_breakdown']->first()['state_sum']);
        $this->assertSame(1, $assetReconciliation['rental_reconciliation']['available']);
        $this->assertSame(0, $assetReconciliation['rental_reconciliation']['active_rented']);
        $this->assertSame(1, $assetReconciliation['rental_reconciliation']['maintenance']);
        $this->assertSame(1, $assetReconciliation['rental_reconciliation']['awaiting_verification']);
        $this->assertSame(1, $assetReconciliation['rental_reconciliation']['reserved']);
    }

    public function test_inventory_intelligence_falls_back_to_live_operational_records_when_month_ledger_totals_are_missing(): void
    {
        $organization = TestData::organization();
        $product = $this->makeProduct($organization->id, [
            'name' => 'Fallback Product',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_BOTH,
            'total_quantity' => 10,
            'available_quantity' => 6,
        ]);

        $warehouse = $this->makeWarehouse($organization->id);

        $this->movement($organization->id, $product->id, [
            'movement_type' => StockMovement::TYPE_OPENING,
            'quantity' => 10,
            'to_status' => 'available',
            'movement_at' => '2026-05-01 09:00:00',
        ]);

        $rental = Rental::create([
            'organization_id' => $organization->id,
            'customer_type' => 'direct',
            'customer_name' => 'Fallback Rental Customer',
            'phone' => '9888888888',
            'product_id' => $product->id,
            'dispatch_warehouse_id' => $warehouse->id,
            'quantity' => 2,
            'start_date' => '2026-05-05',
            'end_date' => '2026-06-05',
            'rental_amount' => 2000,
            'total_amount' => 2000,
            'status' => 'active',
            'delivery_status' => 'completed',
            'pickup_status' => 'pending',
        ]);

        if (Rental::hasRentalItemsTable()) {
            RentalItem::create([
                'organization_id' => $organization->id,
                'rental_id' => $rental->id,
                'product_id' => $product->id,
                'quantity' => 2,
                'ordered_quantity' => 2,
                'delivered_quantity' => 2,
                'returned_quantity' => 1,
                'unit_rental_amount' => 1000,
                'line_total' => 2000,
            ]);
        }

        Delivery::create([
            'organization_id' => $organization->id,
            'rental_id' => $rental->id,
            'type' => 'delivery',
            'status' => 'completed',
            'scheduled_at' => '2026-05-06 10:00:00',
            'completed_at' => '2026-05-06 12:00:00',
        ]);

        Delivery::create([
            'organization_id' => $organization->id,
            'rental_id' => $rental->id,
            'type' => 'pickup',
            'status' => 'completed',
            'scheduled_at' => '2026-05-20 10:00:00',
            'completed_at' => '2026-05-20 12:00:00',
        ]);

        $sale = Sale::create([
            'organization_id' => $organization->id,
            'customer_name' => 'Fallback Sale Customer',
            'phone' => '9777777777',
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 3,
            'sale_date' => '2026-05-10',
            'unit_price' => 500,
            'sale_amount' => 1500,
            'total_amount' => 1500,
            'status' => 'completed',
        ]);
        $sale->forceFill(['created_at' => '2026-05-10 09:00:00'])->saveQuietly();

        if (Sale::hasSaleItemsTable()) {
            SaleItem::create([
                'organization_id' => $organization->id,
                'sale_id' => $sale->id,
                'product_id' => $product->id,
                'quantity' => 3,
                'unit_price' => 500,
                'line_total' => 1500,
            ]);
        }

        $report = app(InventoryIntelligenceService::class)->build($organization->id, [
            'mode' => 'all',
            'month' => 5,
            'year' => 2026,
            'product_id' => $product->id,
        ]);

        $this->assertSame(2, $report['summary']['total_rental_out']);
        $this->assertSame(1, $report['summary']['total_returns']);
        $this->assertSame(3, $report['summary']['total_sale_out']);
        $this->assertSame(3, $report['summary']['sale_stock_consumed']);
        $this->assertSame(0, $report['summary']['low_stock_products']);
        $this->assertSame(10, $report['summary']['opening_stock']);
        $this->assertSame(6, $report['summary']['closing_stock']);
        $this->assertSame(6, $report['summary']['total_available_stock']);
        $this->assertSame(-4, $report['summary']['net_change']);
        $this->assertSame(6, $report['rows']->first()['daily']['2026-05-31']['closing_stock']);
        $this->assertSame(3, $report['reconciliation']['missing_ledger_records_detected']);
        $this->assertSame(3, $report['reconciliation']['fallback_movement_count']);
        $this->assertSame(3, $report['reconciliation']['fallback_quantities']['sale_out']);
        $this->assertSame(2, $report['reconciliation']['fallback_quantities']['rental_out']);
        $this->assertSame(1, $report['reconciliation']['fallback_quantities']['returns']);
        $this->assertSame([], $report['reconciliation']['warnings']->all());
    }

    public function test_inventory_intelligence_uses_quantity_formula_for_opening_closing_and_month_activity(): void
    {
        $organization = TestData::organization();
        $product = $this->makeProduct($organization->id, [
            'name' => 'Formula Product',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_BOTH,
            'total_quantity' => 180,
            'available_quantity' => 153,
        ]);
        $warehouse = $this->makeWarehouse($organization->id, ['city' => 'Bengaluru']);

        $this->movement($organization->id, $product->id, [
            'movement_type' => StockMovement::TYPE_OPENING,
            'quantity' => 180,
            'to_status' => 'available',
            'to_warehouse_id' => $warehouse->id,
            'movement_at' => '2026-05-01 09:00:00',
        ]);

        $rental = Rental::create([
            'organization_id' => $organization->id,
            'customer_type' => 'direct',
            'customer_name' => 'Formula Rental Customer',
            'phone' => '9666666666',
            'product_id' => $product->id,
            'dispatch_warehouse_id' => $warehouse->id,
            'quantity' => 7,
            'start_date' => '2026-05-03',
            'end_date' => '2026-06-03',
            'rental_amount' => 7000,
            'total_amount' => 7000,
            'status' => 'active',
            'delivery_status' => 'completed',
            'pickup_status' => 'pending',
        ]);

        if (Rental::hasRentalItemsTable()) {
            RentalItem::create([
                'organization_id' => $organization->id,
                'rental_id' => $rental->id,
                'product_id' => $product->id,
                'quantity' => 7,
                'ordered_quantity' => 7,
                'delivered_quantity' => 7,
                'returned_quantity' => 0,
                'unit_rental_amount' => 1000,
                'line_total' => 7000,
            ]);
        }

        Delivery::create([
            'organization_id' => $organization->id,
            'rental_id' => $rental->id,
            'type' => 'delivery',
            'status' => 'completed',
            'scheduled_at' => '2026-05-03 09:00:00',
            'completed_at' => '2026-05-03 12:00:00',
        ]);

        $sale = Sale::create([
            'organization_id' => $organization->id,
            'customer_name' => 'Formula Sale Customer',
            'phone' => '9555555555',
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 20,
            'sale_date' => '2026-05-10',
            'unit_price' => 500,
            'sale_amount' => 10000,
            'total_amount' => 10000,
            'status' => 'completed',
        ]);

        if (Sale::hasSaleItemsTable()) {
            SaleItem::create([
                'organization_id' => $organization->id,
                'sale_id' => $sale->id,
                'product_id' => $product->id,
                'quantity' => 20,
                'unit_price' => 500,
                'line_total' => 10000,
            ]);
        }

        $report = app(InventoryIntelligenceService::class)->build($organization->id, [
            'mode' => 'all',
            'month' => 5,
            'year' => 2026,
            'product_id' => $product->id,
        ]);

        $this->assertSame(180, $report['summary']['opening_stock']);
        $this->assertSame(7, $report['summary']['total_rental_out']);
        $this->assertSame(20, $report['summary']['total_sale_out']);
        $this->assertSame(20, $report['summary']['sale_stock_consumed']);
        $this->assertSame(153, $report['summary']['closing_stock']);
        $this->assertSame(153, $report['summary']['total_available_stock']);
        $this->assertSame(-27, $report['summary']['net_change']);
        $this->assertSame(153, $report['rows']->first()['daily']['2026-05-31']['closing_stock']);
    }

    public function test_inventory_intelligence_does_not_double_count_sales_when_ledger_sale_movement_exists(): void
    {
        $organization = TestData::organization();
        $product = $this->makeProduct($organization->id, [
            'name' => 'No Double Count Product',
            'stock_mode' => Product::STOCK_MODE_TRACKED_BOTH,
            'total_quantity' => 50,
            'available_quantity' => 45,
        ]);
        $warehouse = $this->makeWarehouse($organization->id);

        $this->movement($organization->id, $product->id, [
            'movement_type' => StockMovement::TYPE_OPENING,
            'quantity' => 50,
            'to_status' => 'available',
            'to_warehouse_id' => $warehouse->id,
            'movement_at' => '2026-05-01 09:00:00',
        ]);

        $sale = Sale::create([
            'organization_id' => $organization->id,
            'customer_name' => 'Counted Once Customer',
            'phone' => '9444444444',
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 5,
            'sale_date' => '2026-05-08',
            'unit_price' => 100,
            'sale_amount' => 500,
            'total_amount' => 500,
            'status' => 'completed',
        ]);

        if (Sale::hasSaleItemsTable()) {
            SaleItem::create([
                'organization_id' => $organization->id,
                'sale_id' => $sale->id,
                'product_id' => $product->id,
                'quantity' => 5,
                'unit_price' => 100,
                'line_total' => 500,
            ]);
        }

        $this->movement($organization->id, $product->id, [
            'movement_type' => StockMovement::TYPE_SALE,
            'sale_id' => $sale->id,
            'quantity' => 5,
            'from_status' => 'available',
            'to_status' => 'sold',
            'from_warehouse_id' => $warehouse->id,
            'movement_at' => '2026-05-08 11:00:00',
        ]);

        $report = app(InventoryIntelligenceService::class)->build($organization->id, [
            'mode' => 'all',
            'month' => 5,
            'year' => 2026,
            'product_id' => $product->id,
        ]);

        $this->assertSame(5, $report['summary']['total_sale_out']);
        $this->assertSame(45, $report['summary']['closing_stock']);
        $this->assertSame(-5, $report['summary']['net_change']);
        $this->assertSame(0, $report['reconciliation']['fallback_quantities']['sale_out']);
    }

    public function test_inventory_intelligence_uses_legacy_direct_sale_quantity_when_sale_items_are_empty(): void
    {
        $organization = TestData::organization();
        $product = $this->makeProduct($organization->id, [
            'name' => 'Legacy Direct Sale Product',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_BOTH,
            'total_quantity' => 214,
            'available_quantity' => 161,
        ]);
        $warehouse = $this->makeWarehouse($organization->id);

        $this->movement($organization->id, $product->id, [
            'movement_type' => StockMovement::TYPE_OPENING,
            'quantity' => 180,
            'to_status' => 'available',
            'to_warehouse_id' => $warehouse->id,
            'movement_at' => '2026-05-01 09:00:00',
        ]);

        $rental = Rental::create([
            'organization_id' => $organization->id,
            'customer_type' => 'direct',
            'customer_name' => 'Legacy Rental Customer',
            'phone' => '9333333333',
            'product_id' => $product->id,
            'dispatch_warehouse_id' => $warehouse->id,
            'quantity' => 7,
            'start_date' => '2026-05-03',
            'end_date' => '2026-06-03',
            'rental_amount' => 7000,
            'total_amount' => 7000,
            'status' => 'active',
            'delivery_status' => 'completed',
            'pickup_status' => 'pending',
        ]);

        if (Rental::hasRentalItemsTable()) {
            RentalItem::create([
                'organization_id' => $organization->id,
                'rental_id' => $rental->id,
                'product_id' => $product->id,
                'quantity' => 7,
                'ordered_quantity' => 7,
                'delivered_quantity' => 7,
                'returned_quantity' => 0,
                'unit_rental_amount' => 1000,
                'line_total' => 7000,
            ]);
        }

        Delivery::create([
            'organization_id' => $organization->id,
            'rental_id' => $rental->id,
            'type' => 'delivery',
            'status' => 'completed',
            'scheduled_at' => '2026-05-03 09:00:00',
            'completed_at' => '2026-05-03 12:00:00',
        ]);

        Sale::create([
            'organization_id' => $organization->id,
            'customer_name' => 'Legacy Sale Customer 1',
            'phone' => '9222222221',
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 20,
            'sale_date' => '2026-05-10',
            'unit_price' => 500,
            'sale_amount' => 10000,
            'total_amount' => 10000,
            'status' => 'completed',
        ]);

        Sale::create([
            'organization_id' => $organization->id,
            'customer_name' => 'Legacy Sale Customer 2',
            'phone' => '9222222222',
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'quantity' => 13,
            'sale_date' => '2026-05-11',
            'unit_price' => 500,
            'sale_amount' => 6500,
            'total_amount' => 6500,
            'status' => 'completed',
        ]);

        $report = app(InventoryIntelligenceService::class)->build($organization->id, [
            'mode' => 'all',
            'month' => 5,
            'year' => 2026,
            'product_id' => $product->id,
        ]);

        $this->assertSame(201, $report['summary']['opening_stock']);
        $this->assertSame(7, $report['summary']['total_rental_out']);
        $this->assertSame(33, $report['summary']['total_sale_out']);
        $this->assertSame(33, $report['summary']['sale_stock_consumed']);
        $this->assertSame(161, $report['summary']['closing_stock']);
        $this->assertSame(161, $report['summary']['total_available_stock']);
        $this->assertSame(-40, $report['summary']['net_change']);
        $this->assertSame(161, $report['rows']->first()['daily']['2026-05-31']['closing_stock']);
    }

    public function test_inventory_intelligence_separates_sale_orders_from_sale_units_out(): void
    {
        $organization = TestData::organization();
        $product = $this->makeProduct($organization->id, [
            'name' => 'Sale Orders vs Units Product',
            'product_type' => Product::TYPE_SELLABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_BOTH,
            'total_quantity' => 50,
            'available_quantity' => 16,
        ]);
        $warehouse = $this->makeWarehouse($organization->id);

        $this->movement($organization->id, $product->id, [
            'movement_type' => StockMovement::TYPE_OPENING,
            'quantity' => 50,
            'to_status' => 'available',
            'to_warehouse_id' => $warehouse->id,
            'movement_at' => '2026-05-01 09:00:00',
        ]);

        $quantities = [2,2,2,2,2,2,2,2,2,2,2,2,2,2,1,1,1,1,1,1];
        foreach ($quantities as $index => $quantity) {
            Sale::create([
                'organization_id' => $organization->id,
                'customer_name' => 'Sale Customer '.($index + 1),
                'phone' => '91111111'.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'quantity' => $quantity,
                'sale_date' => '2026-05-'.str_pad((string) min($index + 1, 28), 2, '0', STR_PAD_LEFT),
                'unit_price' => 100,
                'sale_amount' => $quantity * 100,
                'total_amount' => $quantity * 100,
                'status' => 'completed',
            ]);
        }

        $salesQuery = Sale::query()
            ->where('organization_id', $organization->id)
            ->whereYear('sale_date', 2026)
            ->whereMonth('sale_date', 5);

        $salesSummary = app(SalesMetricsService::class)->summary($salesQuery, $organization->id, false);
        $report = app(InventoryIntelligenceService::class)->build($organization->id, [
            'mode' => 'all',
            'month' => 5,
            'year' => 2026,
            'product_id' => $product->id,
        ]);

        $this->assertSame(20, (int) ($salesSummary['totalSales'] ?? 0));
        $this->assertSame(20, $report['summary']['sale_orders']);
        $this->assertSame(34, $report['summary']['total_sale_out']);
        $this->assertSame(34, $report['summary']['sale_stock_consumed']);
    }

    public function test_inventory_intelligence_global_current_month_reconciles_sale_and_rental_stock_basis(): void
    {
        $organization = TestData::organization();
        $warehouse = $this->makeWarehouse($organization->id);

        $saleProduct = $this->makeProduct($organization->id, [
            'name' => 'Global Sale Basis Product',
            'product_type' => Product::TYPE_SELLABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'total_quantity' => 160,
            'available_quantity' => 126,
        ]);

        $rentalProduct = $this->makeProduct($organization->id, [
            'name' => 'Global Rental Basis Product',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_RENTAL,
            'total_quantity' => 54,
            'available_quantity' => 0,
        ]);

        foreach (range(1, 35) as $index) {
            Asset::create([
                'organization_id' => $organization->id,
                'product_id' => $rentalProduct->id,
                'warehouse_id' => $warehouse->id,
                'asset_name' => 'Available Rental Asset '.$index,
                'serial_number' => 'GRA-A-'.$index,
                'asset_stage' => Asset::STAGE_RENTAL_STOCK,
                'asset_status' => Asset::STATUS_AVAILABLE,
            ]);
        }

        foreach (range(1, 7) as $index) {
            Asset::create([
                'organization_id' => $organization->id,
                'product_id' => $rentalProduct->id,
                'warehouse_id' => $warehouse->id,
                'asset_name' => 'Rented Rental Asset '.$index,
                'serial_number' => 'GRA-R-'.$index,
                'asset_stage' => Asset::STAGE_RENTAL_STOCK,
                'asset_status' => Asset::STATUS_RENTED,
            ]);
        }

        foreach (range(1, 4) as $index) {
            Asset::create([
                'organization_id' => $organization->id,
                'product_id' => $rentalProduct->id,
                'warehouse_id' => $warehouse->id,
                'asset_name' => 'Maintenance Rental Asset '.$index,
                'serial_number' => 'GRA-M-'.$index,
                'asset_stage' => Asset::STAGE_RENTAL_STOCK,
                'asset_status' => Asset::STATUS_MAINTENANCE,
            ]);
        }

        Asset::create([
            'organization_id' => $organization->id,
            'product_id' => $rentalProduct->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Repair-blocked Rental Asset',
            'serial_number' => 'GRA-RP-1',
            'asset_stage' => Asset::STAGE_RENTAL_STOCK,
            'asset_status' => Asset::STATUS_AVAILABLE,
            'condition_status' => 'repair',
        ]);

        foreach (range(1, 7) as $index) {
            Asset::create([
                'organization_id' => $organization->id,
                'product_id' => $rentalProduct->id,
                'warehouse_id' => $warehouse->id,
                'asset_name' => 'Unaccounted Rental Asset '.$index,
                'serial_number' => 'GRA-S-'.$index,
                'asset_stage' => Asset::STAGE_RENTAL_STOCK,
                'asset_status' => Asset::STATUS_RENTED,
            ]);
        }

        foreach (range(1, 8) as $index) {
            Asset::create([
                'organization_id' => $organization->id,
                'product_id' => $saleProduct->id,
                'warehouse_id' => $warehouse->id,
                'asset_name' => 'Sale Unit '.$index,
                'serial_number' => 'GSU-'.$index,
                'asset_stage' => Asset::STAGE_NEW_STOCK,
                'asset_status' => Asset::STATUS_AVAILABLE_FOR_SALE,
            ]);
        }

        $quantities = [2,2,2,2,2,2,2,2,2,2,2,2,2,2,1,1,1,1,1,1];
        foreach ($quantities as $index => $quantity) {
            Sale::create([
                'organization_id' => $organization->id,
                'customer_name' => 'Global Sale Customer '.($index + 1),
                'phone' => '90000000'.str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                'product_id' => $saleProduct->id,
                'warehouse_id' => $warehouse->id,
                'quantity' => $quantity,
                'sale_date' => '2026-05-'.str_pad((string) min($index + 1, 28), 2, '0', STR_PAD_LEFT),
                'unit_price' => 100,
                'sale_amount' => $quantity * 100,
                'total_amount' => $quantity * 100,
                'status' => 'completed',
            ]);
        }

        $rental = Rental::create([
            'organization_id' => $organization->id,
            'customer_type' => 'direct',
            'customer_name' => 'Global Rental Customer',
            'phone' => '9888888888',
            'product_id' => $rentalProduct->id,
            'dispatch_warehouse_id' => $warehouse->id,
            'quantity' => 7,
            'start_date' => '2026-05-03',
            'end_date' => '2026-06-03',
            'rental_amount' => 7000,
            'total_amount' => 7000,
            'status' => 'active',
            'delivery_status' => 'completed',
            'pickup_status' => 'pending',
        ]);

        if (Rental::hasRentalItemsTable()) {
            RentalItem::create([
                'organization_id' => $organization->id,
                'rental_id' => $rental->id,
                'product_id' => $rentalProduct->id,
                'quantity' => 7,
                'ordered_quantity' => 7,
                'delivered_quantity' => 7,
                'returned_quantity' => 0,
                'unit_rental_amount' => 1000,
                'line_total' => 7000,
            ]);
        }

        Delivery::create([
            'organization_id' => $organization->id,
            'rental_id' => $rental->id,
            'type' => 'delivery',
            'status' => 'completed',
            'scheduled_at' => '2026-05-03 09:00:00',
            'completed_at' => '2026-05-03 12:00:00',
        ]);

        $report = app(InventoryIntelligenceService::class)->build($organization->id, [
            'mode' => 'all',
            'month' => 5,
            'year' => 2026,
        ]);

        $this->assertSame(214, $report['summary']['opening_stock']);
        $this->assertSame(161, $report['summary']['total_available_stock']);
        $this->assertSame(161, $report['summary']['closing_stock']);
        $this->assertSame(20, $report['summary']['sale_orders']);
        $this->assertSame(34, $report['summary']['total_sale_out']);
        $this->assertSame(7, $report['summary']['total_rental_out']);
        $this->assertSame(-53, $report['summary']['net_change']);
        $this->assertSame(126, $report['summary']['stock_basis_reconciliation']['sale_units_available_now']);
        $this->assertSame(54, $report['summary']['stock_basis_reconciliation']['rental_assets_total']);
        $this->assertSame(35, $report['summary']['stock_basis_reconciliation']['rental_assets_available_now']);
        $this->assertSame(19, $report['summary']['stock_basis_reconciliation']['rental_unavailable_now']);
        $this->assertSame(11, $report['summary']['stock_basis_reconciliation']['rental_unavailable_explained']);
        $this->assertSame(8, $report['summary']['stock_basis_reconciliation']['rental_unavailable_unexplained']);
        $this->assertSame(20, $report['summary']['stock_basis_reconciliation']['sale_orders']);
        $this->assertSame(7, $report['summary']['stock_basis_reconciliation']['rental_unavailable_breakdown']['rented']);
        $this->assertSame(4, $report['summary']['stock_basis_reconciliation']['rental_unavailable_breakdown']['maintenance']);
        $this->assertSame(0, $report['summary']['stock_basis_reconciliation']['rental_unavailable_breakdown']['awaiting_verification']);
        $this->assertSame(35, $report['asset_reconciliation']['rental_reconciliation']['available']);
        $this->assertSame(7, $report['asset_reconciliation']['rental_reconciliation']['active_rented']);
        $this->assertSame(4, $report['asset_reconciliation']['rental_reconciliation']['maintenance']);
        $this->assertSame(0, $report['asset_reconciliation']['rental_reconciliation']['awaiting_verification']);
        $this->assertSame(0, $report['asset_reconciliation']['rental_reconciliation']['reserved']);
        $this->assertSame(8, $report['asset_reconciliation']['rental_reconciliation']['unaccounted']);
        $this->assertSame(54, $report['asset_reconciliation']['warehouse_breakdown']->first()['total_rental_assets']);
        $this->assertSame(35, $report['asset_reconciliation']['warehouse_breakdown']->first()['available']);
        $this->assertSame(7, $report['asset_reconciliation']['warehouse_breakdown']->first()['active_rented']);
        $this->assertSame(4, $report['asset_reconciliation']['warehouse_breakdown']->first()['maintenance']);
        $this->assertSame(0, $report['asset_reconciliation']['warehouse_breakdown']->first()['awaiting_verification']);
        $this->assertSame(8, $report['asset_reconciliation']['warehouse_breakdown']->first()['unaccounted']);
        $this->assertSame(54, $report['asset_reconciliation']['warehouse_breakdown']->first()['state_sum']);
        $unclassifiedAssets = collect($report['asset_reconciliation']['rental_reconciliation']['unclassified_assets']);
        $this->assertCount(8, $unclassifiedAssets);
        $repairBlocked = $unclassifiedAssets->firstWhere('serial_number', 'GRA-RP-1');
        $this->assertNotNull($repairBlocked);
        $this->assertStringContainsString(
            'excluded from rental-ready availability because condition is "repair"',
            $repairBlocked['reason']
        );
        $staleRentedCount = $unclassifiedAssets
            ->filter(fn (array $asset) => str_contains($asset['reason'], 'Marked rented, but not part of the canonical active-rented quantity'))
            ->count();
        $this->assertSame(7, $staleRentedCount);
    }

    public function test_inventory_intelligence_counts_return_verification_repair_and_scrap_in_closing_available(): void
    {
        $organization = TestData::organization();
        $product = $this->makeProduct($organization->id, [
            'name' => 'Verification Product',
            'total_quantity' => 10,
            'available_quantity' => 7,
        ]);

        $this->movement($organization->id, $product->id, [
            'movement_type' => StockMovement::TYPE_OPENING,
            'quantity' => 10,
            'to_status' => 'available',
            'movement_at' => '2026-05-01 09:00:00',
        ]);
        $this->movement($organization->id, $product->id, [
            'movement_type' => StockMovement::TYPE_PICKUP_RETURN,
            'quantity' => 2,
            'from_status' => 'with_customer',
            'to_status' => 'available',
            'movement_at' => '2026-05-10 09:00:00',
        ]);
        $this->movement($organization->id, $product->id, [
            'movement_type' => StockMovement::TYPE_REPAIR,
            'quantity' => 1,
            'from_status' => 'available',
            'to_status' => 'maintenance',
            'movement_at' => '2026-05-11 09:00:00',
        ]);
        $this->movement($organization->id, $product->id, [
            'movement_type' => StockMovement::TYPE_SCRAP,
            'quantity' => 1,
            'from_status' => 'available',
            'to_status' => 'retired',
            'movement_at' => '2026-05-12 09:00:00',
        ]);

        $report = app(InventoryIntelligenceService::class)->build($organization->id, [
            'month' => 5,
            'year' => 2026,
            'product_id' => $product->id,
        ]);

        $this->assertSame(10, $report['summary']['opening_stock']);
        $this->assertSame(10, $report['summary']['closing_stock']);
        $this->assertSame(2, $report['summary']['total_returns']);
        $this->assertSame(0, $report['summary']['net_change']);
        $this->assertSame(10, $report['rows']->first()['daily']['2026-05-31']['closing_stock']);
    }

    public function test_inventory_intelligence_page_is_read_only_and_does_not_mutate_stock_movements(): void
    {
        $organization = TestData::organization();
        $viewer = $this->userWithRole($organization, 'Inventory Intelligence Viewer', [
            'products' => ['read'],
            'assets' => ['read'],
            '__special' => ['stock_history.view'],
        ]);
        $product = $this->makeProduct($organization->id);
        $this->movement($organization->id, $product->id, [
            'movement_type' => StockMovement::TYPE_OPENING,
            'quantity' => 6,
            'movement_at' => '2026-05-01 08:00:00',
        ]);

        $countBefore = StockMovement::query()->count();

        $this->actingAs($viewer)
            ->get(route('inventory-intelligence.index', [
                'month' => 5,
                'year' => 2026,
                'product_id' => $product->id,
                'drill_product_id' => $product->id,
                'drill_date' => '2026-05-01',
            ]))
            ->assertOk();

        $this->assertSame($countBefore, StockMovement::query()->count());
    }

    public function test_inventory_intelligence_renders_linked_rental_reference_without_invalid_column_usage(): void
    {
        $organization = TestData::organization();
        $viewer = $this->userWithRole($organization, 'Inventory Rental Link Exporter', [
            'products' => ['read'],
            'assets' => ['read'],
            '__special' => ['stock_history.view', 'stock_history.export'],
        ]);

        $product = $this->makeProduct($organization->id);
        $rental = Rental::create([
            'organization_id' => $organization->id,
            'customer_name' => 'Inventory Rental Customer',
            'phone' => '9876543210',
            'product_id' => $product->id,
            'quantity' => 1,
            'start_date' => '2026-05-05',
            'end_date' => '2026-05-10',
            'status' => 'active',
        ]);

        $this->movement($organization->id, $product->id, [
            'rental_id' => $rental->id,
            'movement_type' => StockMovement::TYPE_RENTAL_OUT,
            'quantity' => 1,
            'movement_at' => '2026-05-05 10:00:00',
            'from_status' => 'available',
            'to_status' => 'rented',
        ]);

        $this->actingAs($viewer)
            ->get(route('inventory-intelligence.index', [
                'month' => 5,
                'year' => 2026,
                'product_id' => $product->id,
                'drill_product_id' => $product->id,
                'drill_date' => '2026-05-05',
            ]))
            ->assertOk()
            ->assertSee('Rental #' . $rental->id);
    }

    public function test_inventory_intelligence_handles_missing_rental_relationship_without_crashing(): void
    {
        $organization = TestData::organization();
        $viewer = $this->userWithRole($organization, 'Inventory Intelligence Viewer', [
            'products' => ['read'],
            'assets' => ['read'],
            '__special' => ['stock_history.view'],
        ]);

        $product = $this->makeProduct($organization->id);
        $rental = Rental::create([
            'organization_id' => $organization->id,
            'customer_name' => 'Deleted Rental Customer',
            'phone' => '9876543210',
            'product_id' => $product->id,
            'quantity' => 1,
            'start_date' => '2026-05-05',
            'end_date' => '2026-05-10',
            'status' => 'active',
        ]);

        $this->movement($organization->id, $product->id, [
            'rental_id' => $rental->id,
            'movement_type' => StockMovement::TYPE_PICKUP_RETURN,
            'quantity' => 1,
            'movement_at' => '2026-05-05 11:00:00',
            'from_status' => 'rented',
            'to_status' => 'awaiting_verification',
        ]);

        DB::statement('PRAGMA foreign_keys = OFF');
        DB::table('rentals')->where('id', $rental->id)->delete();
        DB::statement('PRAGMA foreign_keys = ON');

        $this->actingAs($viewer)
            ->get(route('inventory-intelligence.index', [
                'month' => 5,
                'year' => 2026,
                'product_id' => $product->id,
                'drill_product_id' => $product->id,
                'drill_date' => '2026-05-05',
            ]))
            ->assertOk();
    }

    public function test_inventory_intelligence_does_not_keep_awaiting_verification_asset_in_active_rented_bucket(): void
    {
        $organization = TestData::organization();
        $warehouse = $this->makeWarehouse($organization->id);
        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Awaiting Verification Customer',
            'phone' => '9000000099',
        ]);
        $product = $this->makeProduct($organization->id, [
            'name' => 'Awaiting Verification Product',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_RENTAL,
            'available_quantity' => 0,
            'total_quantity' => 0,
        ]);

        $asset = Asset::create([
            'organization_id' => $organization->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Awaiting Verification Asset',
            'serial_number' => 'AWAIT-VERIFY-001',
            'asset_stage' => Asset::STAGE_RENTAL_STOCK,
            'condition_status' => 'good',
            'asset_status' => Asset::STATUS_AWAITING_VERIFICATION,
        ]);

        $rental = Rental::create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'phone' => $customer->phone,
            'product_id' => $product->id,
            'dispatch_warehouse_id' => $warehouse->id,
            'quantity' => 1,
            'start_date' => now()->subDays(4)->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'rental_amount' => 1000,
            'status' => 'active',
        ]);

        RentalItem::create([
            'organization_id' => $organization->id,
            'rental_id' => $rental->id,
            'product_id' => $product->id,
            'asset_ids' => [$asset->id],
            'quantity' => 1,
            'delivered_quantity' => 1,
            'returned_quantity' => 0,
            'unit_rental_amount' => 1000,
            'line_total' => 1000,
        ]);

        RentalAsset::create([
            'organization_id' => $organization->id,
            'rental_id' => $rental->id,
            'asset_id' => $asset->id,
            'assigned_at' => now()->subDays(4),
            'delivered_at' => now()->subDays(4),
        ]);

        Delivery::create([
            'organization_id' => $organization->id,
            'rental_id' => $rental->id,
            'type' => 'delivery',
            'scheduled_at' => now()->subDays(4),
            'completed_at' => now()->subDays(4),
            'status' => 'completed',
        ]);

        $report = app(InventoryIntelligenceService::class)->build($organization->id, [
            'scope' => 'monthly',
            'month' => now()->month,
            'year' => now()->year,
            'mode' => 'all',
        ]);

        $this->assertSame(0, $report['asset_reconciliation']['rental_reconciliation']['active_rented']);
        $this->assertSame(1, $report['asset_reconciliation']['rental_reconciliation']['awaiting_verification']);
        $this->assertSame(0, $report['summary']['stock_basis_reconciliation']['rental_unavailable_breakdown']['rented']);
        $this->assertSame(1, $report['summary']['stock_basis_reconciliation']['rental_unavailable_breakdown']['awaiting_verification']);
    }

    private function userWithRole($organization, string $name, array $permissions): User
    {
        $role = Role::create([
            'organization_id' => $organization->id,
            'name' => $name,
            'slug' => str($name)->slug()->value(),
            'permissions' => $permissions,
            'is_system' => false,
            'is_active' => true,
        ]);

        return TestData::user($organization, [
            'role' => 'staff',
            'role_id' => $role->id,
        ]);
    }

    private function makeProduct(int $organizationId, array $attributes = []): Product
    {
        return Product::create(array_merge([
            'organization_id' => $organizationId,
            'name' => 'Inventory Matrix Product',
            'category' => 'Respiratory',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_RENTAL,
            'total_quantity' => 0,
            'available_quantity' => 0,
            'sale_price' => 1000,
            'price_per_day' => 100,
        ], $attributes));
    }

    private function makeWarehouse(int $organizationId, array $attributes = []): Warehouse
    {
        return Warehouse::create(array_merge([
            'organization_id' => $organizationId,
            'name' => 'Main Warehouse',
            'code' => strtoupper(substr(md5((string) microtime(true)), 0, 6)),
            'city' => 'Bengaluru',
            'state' => 'Karnataka',
            'is_active' => true,
        ], $attributes));
    }

    private function movement(int $organizationId, int $productId, array $overrides = []): StockMovement
    {
        $payload = array_merge([
            'organization_id' => $organizationId,
            'product_id' => $productId,
            'movement_type' => StockMovement::TYPE_OPENING,
            'quantity' => 1,
            'from_status' => null,
            'to_status' => 'available',
            'from_warehouse_id' => null,
            'to_warehouse_id' => null,
            'movement_at' => '2026-05-01 00:00:00',
            'notes' => 'Inventory intelligence seed.',
        ], $overrides);

        $payload['checksum'] = StockMovement::checksumFor($payload);

        return StockMovement::create($payload);
    }
}
