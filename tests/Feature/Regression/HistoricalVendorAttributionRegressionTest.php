<?php

namespace Tests\Feature\Regression;

use App\Models\Customer;
use App\Models\Delivery;
use App\Models\Product;
use App\Models\Rental;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestData;
use Tests\TestCase;

class HistoricalVendorAttributionRegressionTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('accountStates')]
    public function test_recorded_vendor_survives_account_and_role_changes_in_reports_exports_and_dashboard(string $state, string $type): void
    {
        $this->withoutVite();
        $organization = TestData::organization();
        $owner = TestData::user($organization);
        $vendor = TestData::user($organization, ['role' => User::ROLE_VENDOR, 'name' => 'Historical Vendor Sentinel']);
        $customer = Customer::create(['organization_id' => $organization->id, 'name' => 'Attribution Customer', 'phone' => '9876543210']);
        $product = Product::create(['organization_id' => $organization->id, 'name' => 'Attribution Equipment',
            'product_type' => 'rentable', 'stock_mode' => 'untracked', 'price_per_day' => 100, 'rental_price' => 100,
            'available_quantity' => 2, 'total_quantity' => 2]);
        $rental = Rental::create(['organization_id' => $organization->id, 'customer_id' => $customer->id, 'product_id' => $product->id,
            'customer_name' => $customer->name, 'phone' => $customer->phone, 'quantity' => 1, 'status' => 'active',
            'start_date' => now()->subDay(), 'end_date' => now()->addDays(10), 'rental_amount' => 100,
            'deposit_amount' => 0, 'transport_amount' => 0, 'other_amount' => 0]);
        $delivery = Delivery::create(['organization_id' => $organization->id, 'rental_id' => $rental->id,
            'type' => $type, 'assignment_type' => 'vendor', 'assigned_user_id' => $vendor->id,
            'status' => 'completed', 'scheduled_at' => now()->subDay(), 'completed_at' => now()->subDay()]);
        if ($state === 'inactive') {
            $vendor->update(['is_active' => false]);
        } elseif ($state === 'disabled-role') {
            $role = Role::create(['organization_id' => $organization->id, 'name' => 'Archived Vendor', 'slug' => 'vendor',
                'is_active' => false, 'permissions' => ['deliveries' => ['read']]]);
            $vendor->update(['role_id' => $role->id]);
        } else {
            $vendor->update(['role' => User::ROLE_SALES]);
        }
        if ($state === 'foreign') {
            $vendor->update(['organization_id' => TestData::organization(['name' => 'Other organization'])->id]);
        } elseif ($state === 'staff-assignment') {
            $delivery->update(['assignment_type' => 'staff']);
        }
        $this->assertFalse($vendor->fresh()->isVendor());
        if (in_array($state, ['inactive', 'disabled-role'], true)) {
            $this->assertFalse($vendor->fresh()->hasActiveAccess());
            $this->assertFalse($vendor->fresh()->canAccessModule('deliveries', 'read'));
        }
        $attributed = !in_array($state, ['foreign', 'staff-assignment'], true);
        $response = $this->actingAs($owner)->get(route('reports.index'))->assertOk();
        $this->assertSame([$attributed ? 'Historical Vendor Sentinel' : 'Unassigned'], $response->viewData('reportGroups')['rental_reports']['vendor_wise']->pluck('label')->all());
        $csv = $this->get(route('reports.export.csv', ['report' => 'filtered_rentals']))->assertOk()->streamedContent();
        $this->assertSame($attributed, str_contains($csv, 'Historical Vendor Sentinel'));
        $dashboard = $this->get(route('dashboard'))->assertOk();
        $this->assertSame($attributed, $dashboard->viewData('vendorSummary')->pluck('label')->contains('Historical Vendor Sentinel'));
    }

    public static function accountStates(): array
    {
        $cases = [];
        foreach (['inactive', 'disabled-role', 'reclassified'] as $state) {
            foreach (['delivery', 'pickup'] as $type) {
                $cases[$state.' '.$type] = [$state, $type];
            }
        }
        $cases['foreign organization assignment'] = ['foreign', 'delivery'];
        $cases['nonvendor assignment'] = ['staff-assignment', 'delivery'];
        return $cases;
    }
}
