<?php

namespace Tests\Feature\Regression;

use App\Models\Customer;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ReferralSource;
use App\Models\Rental;
use App\Models\RentalItem;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class ReportsSqliteRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_page_loads_under_sqlite_with_repeat_customer_counts(): void
    {
        $organization = TestData::organization();
        $reportUser = $this->userWithRole($organization, 'Report Reader', [
            'reports' => ['read'],
        ]);

        $product = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Report Rental Product',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'available_quantity' => 5,
            'total_quantity' => 5,
            'price_per_day' => 200,
            'rental_price' => 1200,
            'sale_price' => 0,
            'gst_tax_type' => 'none',
            'gst_calculation_mode' => 'exclusive',
        ]);

        $repeatCustomer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Repeat Customer',
            'phone' => '9000000001',
            'city' => 'Bengaluru',
        ]);

        $singleRentalCustomer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Single Rental Customer',
            'phone' => '9000000002',
            'city' => 'Bengaluru',
        ]);

        $noRentalCustomer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'No Rental Customer',
            'phone' => '9000000003',
            'city' => 'Bengaluru',
        ]);

        $this->createRental($organization->id, $product->id, $repeatCustomer, now()->subDays(10)->toDateString());
        $this->createRental($organization->id, $product->id, $repeatCustomer, now()->subDays(5)->toDateString());
        $this->createRental($organization->id, $product->id, $singleRentalCustomer, now()->subDays(2)->toDateString());

        $response = $this->actingAs($reportUser)->get(route('reports.index'));

        $response->assertOk()
            ->assertViewIs('reports.index')
            ->assertSee('Vendor Performance Summary', false)
            ->assertSee('Vendor Leaderboard', false)
            ->assertSee('Repeat Customer', false)
            ->assertDontSee('HAVING clause on a non-aggregate query', false);
    }

    public function test_reports_filters_feed_analytics_and_csv_export_still_streams(): void
    {
        $organization = TestData::organization();
        $reportUser = $this->userWithRole($organization, 'Report Analytics Reader', [
            'reports' => ['read'],
        ]);

        $product = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Filtered Report Product',
            'category' => 'Respiratory',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'available_quantity' => 5,
            'total_quantity' => 5,
            'price_per_day' => 200,
            'rental_price' => 1200,
            'sale_price' => 0,
            'gst_tax_type' => 'none',
            'gst_calculation_mode' => 'exclusive',
        ]);

        $bengaluruCustomer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Bengaluru Filter Customer',
            'phone' => '9000000101',
            'city' => 'Bengaluru',
        ]);

        $delhiCustomer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Delhi Filter Customer',
            'phone' => '9000000102',
            'city' => 'Delhi',
        ]);

        $this->createRental($organization->id, $product->id, $bengaluruCustomer, now()->subDays(3)->toDateString());
        $this->createRental($organization->id, $product->id, $delhiCustomer, now()->subDays(3)->toDateString(), 'vendor_supplied');

        $response = $this->actingAs($reportUser)->get(route('reports.index', [
            'city' => 'Bengaluru',
            'fulfilment_source' => 'in_house',
            'product_category' => 'Respiratory',
            'tab' => 'vendors',
        ]));

        $response->assertOk()
            ->assertSee('Global Filters', false)
            ->assertSee('name="tab" id="reports_active_tab" value="vendors"', false)
            ->assertSee('id="tab-vendors" name="reports-tab" value="vendors" checked', false)
            ->assertViewHas('reportGroups', function (array $reportGroups) {
                return ($reportGroups['rental_reports']['metrics']['totalRentals'] ?? null) === 1
                    && (float) ($reportGroups['revenue_analytics']['rental_revenue'] ?? 0) === 1200.0;
            });

        $exportResponse = $this->actingAs($reportUser)->get(route('reports.export.csv', [
            'city' => 'Bengaluru',
            'fulfilment_source' => 'in_house',
            'product_category' => 'Respiratory',
        ]));

        $exportResponse->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('Bengaluru Filter Customer', $exportResponse->streamedContent());
    }

    public function test_referral_analytics_counts_linked_rentals_and_manual_text_referrals(): void
    {
        $organization = TestData::organization();
        $reportUser = $this->userWithRole($organization, 'Referral Report Reader', [
            'reports' => ['read'],
        ]);

        $product = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Referral Report Product',
            'category' => 'Respiratory',
            'product_type' => Product::TYPE_BOTH,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'available_quantity' => 5,
            'total_quantity' => 5,
            'price_per_day' => 200,
            'rental_price' => 1200,
            'sale_price' => 9000,
            'gst_tax_type' => 'none',
            'gst_calculation_mode' => 'exclusive',
        ]);

        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Referral Customer',
            'phone' => '9000000201',
            'city' => 'Bengaluru',
        ]);

        $source = ReferralSource::create([
            'organization_id' => $organization->id,
            'source_type' => 'doctor',
            'name' => 'Srinivas',
            'contact' => '9000000301',
            'city' => 'Bengaluru',
            'is_active' => true,
        ]);

        foreach (['srinivas', 'Srinivas', 'Srinivas'] as $index => $referredBy) {
            $this->createRental($organization->id, $product->id, $customer, now()->subDays($index + 1)->toDateString())->update([
                'referral_source_id' => $source->id,
                'referral_source_type' => $source->source_type,
                'referred_by' => $referredBy,
                'referral_contact' => $source->contact,
                'referral_city' => $source->city,
            ]);
        }

        $this->createRental($organization->id, $product->id, $customer, now()->subDays(5)->toDateString())->update([
            'referred_by' => 'abc',
            'referral_source_id' => null,
            'referral_source_type' => null,
        ]);

        $this->createRental($organization->id, $product->id, $customer, now()->subDays(6)->toDateString());

        $response = $this->actingAs($reportUser)->get(route('reports.index', [
            'tab' => 'referrals',
        ]));

        $response->assertOk()
            ->assertSee('Srinivas', false)
            ->assertViewHas('reportGroups', function (array $reportGroups) {
                $referralAnalytics = $reportGroups['referral_analytics'] ?? [];
                $leaderboard = collect($referralAnalytics['leaderboard'] ?? []);
                $row = $leaderboard->firstWhere('name', 'Srinivas');

                $manualRow = $leaderboard->firstWhere('name', 'abc');
                $trend = collect($referralAnalytics['trend'] ?? []);
                $details = collect($referralAnalytics['order_details'] ?? []);

                return $row
                    && (int) ($referralAnalytics['referred_orders'] ?? 0) === 4
                    && (int) ($referralAnalytics['rental_orders'] ?? 0) === 4
                    && (int) ($referralAnalytics['sale_orders'] ?? 0) === 0
                    && (int) ($referralAnalytics['total_referrers'] ?? 0) === 2
                    && data_get($referralAnalytics, 'top_referrer.name') === 'Srinivas'
                    && $leaderboard->where('name', 'Srinivas')->count() === 1
                    && (int) ($row['rental_orders'] ?? 0) === 3
                    && (int) ($row['sale_orders'] ?? 0) === 0
                    && (int) ($row['orders'] ?? 0) === 3
                    && (float) ($row['rental_revenue'] ?? 0) > 0
                    && collect($row['orders_preview'] ?? [])->count() === 3
                    && $trend->sum('total_orders') === 4
                    && $details->count() === 4
                    && $manualRow
                    && ($manualRow['type'] ?? null) === 'Manual / Unlinked Referrals'
                    && ! $leaderboard->contains('name', 'Unspecified');
            });

        $exportResponse = $this->actingAs($reportUser)->get(route('reports.export.csv', [
            'tab' => 'referrals',
            'report' => 'referral_order_details',
        ]));

        $exportResponse->assertOk()
            ->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $csv = $exportResponse->streamedContent();
        $this->assertStringContainsString('Referral Source Name', $csv);
        $this->assertStringContainsString('Eligible Referral Revenue', $csv);
        $this->assertStringContainsString('Srinivas', $csv);
        $this->assertStringContainsString('abc', $csv);
    }

    public function test_referral_analytics_excludes_deposits_and_transport_from_rental_revenue(): void
    {
        $organization = TestData::organization();
        $reportUser = $this->userWithRole($organization, 'Referral Revenue Reader', [
            'reports' => ['read'],
        ]);

        $product = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Referral Revenue Product',
            'category' => 'Respiratory',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'available_quantity' => 5,
            'total_quantity' => 5,
            'price_per_day' => 200,
            'rental_price' => 10000,
            'sale_price' => 0,
            'gst_tax_type' => 'none',
            'gst_calculation_mode' => 'exclusive',
        ]);

        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Referral Revenue Customer',
            'phone' => '9000000401',
            'city' => 'Bengaluru',
        ]);

        $source = ReferralSource::create([
            'organization_id' => $organization->id,
            'source_type' => 'doctor',
            'name' => 'Srinivas',
            'contact' => '9000000402',
            'city' => 'Bengaluru',
            'is_active' => true,
        ]);

        $rental = $this->createRental($organization->id, $product->id, $customer, now()->subDay()->toDateString())->forceFill([
            'referral_source_id' => $source->id,
            'referral_source_type' => $source->source_type,
            'referred_by' => 'Srinivas',
            'rental_amount' => 10000,
            'deposit_amount' => 2000,
            'transport_amount' => 2000,
            'other_amount' => 0,
        ]);
        $rental->save();

        RentalItem::create([
            'organization_id' => $organization->id,
            'rental_id' => $rental->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_rental_amount' => 10000,
            'taxable_amount' => 10000,
            'line_total' => 10000,
        ]);

        $response = $this->actingAs($reportUser)->get(route('reports.index', [
            'tab' => 'referrals',
        ]));

        $response->assertOk()
            ->assertSee('Referral revenue excludes deposits and transport.', false)
            ->assertViewHas('reportGroups', function (array $reportGroups) {
                $referralAnalytics = $reportGroups['referral_analytics'] ?? [];
                $leaderboard = collect($referralAnalytics['leaderboard'] ?? []);
                $row = $leaderboard->firstWhere('name', 'Srinivas');

                return $row
                    && (float) ($referralAnalytics['referred_revenue'] ?? 0) === 10000.0
                    && (float) ($row['rental_revenue'] ?? 0) === 10000.0
                    && (float) ($row['revenue'] ?? 0) === 10000.0;
            });
    }

    private function createRental(int $organizationId, int $productId, Customer $customer, string $startDate, string $fulfilmentSource = 'in_house'): Rental
    {
        return Rental::create([
            'organization_id' => $organizationId,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'phone' => $customer->phone,
            'product_id' => $productId,
            'quantity' => 1,
            'start_date' => $startDate,
            'end_date' => now()->addDays(5)->toDateString(),
            'status' => 'active',
            'rental_amount' => 1200,
            'deposit_amount' => 0,
            'transport_amount' => 0,
            'other_amount' => 0,
            'fulfilment_source' => $fulfilmentSource,
        ]);
    }

    private function userWithRole(Organization $organization, string $name, array $permissions): User
    {
        $role = Role::create([
            'organization_id' => $organization->id,
            'name' => $name,
            'slug' => str($name)->slug('_'),
            'description' => $name,
            'permissions' => Role::normalizePermissions($permissions),
            'is_system' => false,
            'is_active' => true,
        ]);

        return TestData::user($organization, [
            'organization_id' => $organization->id,
            'role' => 'staff',
            'role_id' => $role->id,
        ]);
    }
}
