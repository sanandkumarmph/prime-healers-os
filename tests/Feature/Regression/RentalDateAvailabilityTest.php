<?php

namespace Tests\Feature\Regression;

use App\Models\Asset;
use App\Models\City;
use App\Models\Product;
use App\Models\Rental;
use App\Models\RentalAsset;
use App\Models\RentalItem;
use App\Models\Role;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class RentalDateAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private $user;

    private $product;

    private $warehouse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 25)->setTime(8, 0));
        $this->user = TestData::user(TestData::organization(['timezone' => 'Asia/Kolkata']));
        $this->product = Product::create(['organization_id' => $this->user->organization_id, 'name' => 'Bed', 'price_per_day' => 10, 'product_type' => 'rentable', 'stock_mode' => 'tracked_rental', 'is_rentable' => true, 'total_quantity' => 10, 'available_quantity' => 3]);
        $this->warehouse = Warehouse::create(['organization_id' => $this->user->organization_id, 'name' => 'Main', 'is_active' => true]);
        $this->actingAs($this->user);
    }

    private function asset(string $status = 'available', array $extra = []): Asset
    {
        return Asset::withoutEvents(fn () => Asset::create($extra + ['organization_id' => $this->user->organization_id, 'product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id, 'serial_number' => uniqid('A'), 'asset_stage' => 'rental_stock', 'condition_status' => 'good', 'asset_status' => $status]));
    }

    private function rental(string $start, string $end, array $extra = []): Rental
    {
        return Rental::create($extra + ['organization_id' => $this->user->organization_id, 'product_id' => $this->product->id, 'customer_name' => 'Private name', 'phone' => '9999999999', 'quantity' => 1, 'start_date' => $start, 'end_date' => $end, 'status' => 'active']);
    }

    private function hold(Asset $asset, string $start, string $end): RentalAsset
    {
        return RentalAsset::create(['organization_id' => $this->user->organization_id, 'asset_id' => $asset->id, 'rental_id' => $this->rental($start, $end)->id, 'assigned_at' => now()]);
    }

    private function check(array $extra = [])
    {
        return $this->postJson(route('availability.check'), $extra + ['start_date' => '2026-11-01', 'end_date' => '2026-11-07', 'lines' => [['product_id' => $this->product->id, 'quantity' => 3]]]);
    }

    public function test_forecast_separates_ready_returns_overdue_same_day_and_later_reservations(): void
    {
        $this->asset();
        $return = $this->asset('rented');
        $hold = $this->hold($return, '2026-10-20', '2026-10-31');
        $this->hold($return, '2026-10-20', '2026-10-31');
        $this->hold($this->asset('rented'), '2026-10-01', '2026-10-24');
        $this->hold($this->asset('rented'), '2026-10-20', '2026-11-01');
        $this->hold($this->asset('reserved'), '2026-11-08', '2026-11-15');
        $this->asset('awaiting_verification');
        $this->asset('maintenance');
        $result = $this->check()->assertOk()->assertJsonPath('rows.0.ready_now', 1)->assertJsonPath('rows.0.expected_back', 1)
            ->assertJsonPath('rows.0.reserved_later', 1)->assertJsonPath('rows.0.overdue', 1)->assertJsonPath('rows.0.committed', 1)
            ->assertJsonPath('rows.0.unavailable', 2)->assertJsonPath('rows.0.forecast', 3)->assertJsonPath('rows.0.status', 'Depends on scheduling / returns');
        $result->assertDontSee('Private name')->assertDontSee('rental_id');
        $this->assertSame('rented', $return->fresh()->asset_status);
        $this->assertDatabaseCount('quotations', 0);
    }

    public function test_wrong_tenant_warehouse_product_and_invalid_period_are_rejected(): void
    {
        $other = TestData::organization();
        $warehouse = Warehouse::create(['organization_id' => $other->id, 'name' => 'Secret', 'is_active' => true]);
        $this->check(['warehouse_id' => $warehouse->id])->assertUnprocessable();
        $this->check(['lines' => [['product_id' => 999999, 'quantity' => 1]]])->assertUnprocessable();
        $this->check(['start_date' => '2026-11-08'])->assertUnprocessable();
        $this->check(['start_date' => '2026-10-24'])->assertUnprocessable();
        $this->check(['end_date' => '2028-11-01'])->assertUnprocessable();
    }

    public function test_warehouse_and_city_scope_limit_counts_and_missing_city_denies_all_stock(): void
    {
        $city = City::create(['organization_id' => $this->user->organization_id, 'name' => 'Bengaluru', 'is_active' => true]);
        $this->warehouse->update(['city_id' => $city->id]);
        $this->asset();
        $other = Warehouse::create(['organization_id' => $this->user->organization_id, 'name' => 'Other', 'is_active' => true]);
        $this->asset('available', ['warehouse_id' => $other->id]);
        $role = Role::create(['organization_id' => $this->user->organization_id, 'name' => 'City sales', 'slug' => 'sales', 'scope_type' => 'city', 'is_active' => true, 'permissions' => ['rentals' => ['read']]]);
        $scoped = TestData::user($this->user->organization, ['role_id' => $role->id, 'city_id' => $city->id]);
        $this->actingAs($scoped);
        $this->check()->assertOk()->assertJsonPath('rows.0.ready_now', 1);
        $this->check(['warehouse_id' => $other->id])->assertUnprocessable();
        $scoped->update(['city_id' => null]);
        $this->actingAs($scoped->fresh());
        $this->check()->assertOk()->assertJsonPath('rows.0.ready_now', 0);
    }

    public function test_quantity_stock_excludes_vendor_supply_and_does_not_double_count_multiline_parent(): void
    {
        $this->product->update(['stock_mode' => 'untracked', 'available_quantity' => 2, 'total_quantity' => 10]);
        $rental = $this->rental('2026-10-20', '2026-10-31', ['quantity' => 5]);
        RentalItem::create(['organization_id' => $this->user->organization_id, 'rental_id' => $rental->id, 'product_id' => $this->product->id, 'fulfilment_source' => 'in_house', 'quantity' => 5, 'ordered_quantity' => 5, 'delivered_quantity' => 5, 'returned_quantity' => 2, 'start_date' => '2026-10-20', 'end_date' => '2026-10-31']);
        $this->rental('2026-10-20', '2026-10-31', ['quantity' => 20, 'fulfilment_source' => 'vendor_supplied']);
        $this->check()->assertOk()->assertJsonPath('rows.0.ready_now', 2)->assertJsonPath('rows.0.expected_back', 3)->assertJsonPath('rows.0.forecast', 5);
        $this->check(['warehouse_id' => $this->warehouse->id])->assertOk()->assertJsonPath('rows.0.location_unknown', true)->assertJsonPath('rows.0.forecast', 0);
    }

    public function test_unexplained_reservations_and_returned_assignment_mismatches_are_not_available(): void
    {
        $this->asset('reserved');
        $asset = $this->asset('available');
        $hold = $this->hold($asset, '2026-10-20', '2026-10-30');
        $hold->rental->update(['status' => 'returned']);
        $this->check()->assertOk()->assertJsonPath('rows.0.unavailable', 2)->assertJsonPath('rows.0.forecast', 0);
    }

    public function test_workspace_renders_with_accessible_products(): void
    {
        $response = $this->get(route('availability.index'))->assertOk()->assertSee('Check dates');
        if ($dir = getenv('GETTING_STARTED_PREVIEW_DIR')) {
            file_put_contents($dir.'/availability.html', $response->getContent());
        }
    }

    public function test_permissions_and_inactive_organisation_are_enforced(): void
    {
        $role = Role::create(['organization_id' => $this->user->organization_id, 'name' => 'No stock', 'slug' => 'no_stock', 'scope_type' => 'all', 'is_active' => true, 'permissions' => ['customers' => ['read']]]);
        $this->actingAs(TestData::user($this->user->organization, ['role_id' => $role->id]));
        $this->check()->assertForbidden();
        $this->actingAs($this->user);
        $this->user->organization->update(['is_active' => false]);
        $this->check()->assertForbidden();
    }
}
