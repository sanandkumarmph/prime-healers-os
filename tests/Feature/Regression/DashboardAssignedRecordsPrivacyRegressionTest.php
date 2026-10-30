<?php

namespace Tests\Feature\Regression;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\FollowUp;
use App\Models\Product;
use App\Models\Rental;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\TestData;
use Tests\TestCase;

class DashboardAssignedRecordsPrivacyRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_task_facing_profiles_only_receive_assigned_identifiable_records_on_every_tab_url(): void
    {
        $this->travelTo(Carbon::parse('2026-10-30 12:00:00', 'Asia/Kolkata'));
        $organization = TestData::organization();
        $owner = TestData::user($organization);
        $otherAssignee = TestData::user($organization, ['role' => User::ROLE_DELIVERY]);
        $profiles = [];
        foreach ([User::ROLE_DELIVERY, User::ROLE_VENDOR, User::ROLE_THIRD_PARTY] as $slug) {
            $profiles['legacy_'.$slug] = TestData::user($organization, ['role' => $slug]);
        }
        foreach ([User::ROLE_DELIVERY, User::ROLE_DELIVERY_EXECUTIVE] as $slug) {
            $role = Role::create(['organization_id' => $organization->id, 'slug' => $slug, 'name' => 'Customized '.$slug,
                'permissions' => ['dashboard' => ['read'], 'rentals' => ['read'], 'deliveries' => ['read', 'update'], '__special' => []],
                'is_active' => true, 'is_system' => false]);
            // The active assigned role must determine scope even when the old role column says owner.
            $profiles['assigned_role_'.$slug] = TestData::user($organization, ['role' => User::ROLE_SUPER_ADMIN, 'role_id' => $role->id]);
        }

        // Unrelated rows sort first and exceed the list limit, so filtering after ->limit() cannot pass.
        for ($index = 0; $index < 6; $index++) {
            $this->subject($organization->id, $otherAssignee->id, 'UNASSIGNED_CUSTOMER_SENTINEL_'.$index, '9876540091');
        }
        foreach ([-1, 0, 3] as $endOffset) {
            $this->subject($organization->id, $otherAssignee->id, 'UNASSIGNED_CUSTOMER_SENTINEL_DATE_'.$endOffset, '9876540091', $endOffset);
        }
        $ownRecords = [];
        foreach ($profiles as $name => $user) {
            $ownRecords[$name] = $this->subject($organization->id, $user->id, 'MY_CUSTOMER_'.$name, '9876540011');
            foreach ([-1, 0, 3] as $endOffset) {
                $this->subject($organization->id, $user->id, 'MY_RENTAL_'.$name.'_'.$endOffset, '9876540011', $endOffset);
            }
        }
        $rentalCount = Rental::count();

        foreach ($profiles as $name => $user) {
            $this->assertSame('assigned', $user->scopeType('deliveries'));
            foreach (['overview', 'team', 'operations', 'revenue', 'inventory', 'activity'] as $tab) {
                $response = $this->actingAs($user)->get(route('dashboard', ['dashboard_tab' => $tab]))->assertOk();
                $response->assertSeeText('MY_CUSTOMER_'.$name)->assertSee('tel:9876540011', false)
                    ->assertDontSee('UNASSIGNED_CUSTOMER_SENTINEL')->assertDontSee('9876540091');
                $this->assertSame([$ownRecords[$name]['delivery']->id], $response->viewData('todayDeliveryItems')->pluck('id')->all());
                $this->assertSame(4, $response->viewData('todayPickupItems')->count());
                foreach (['recentRentals', 'endingSoonRentals', 'overdueRentals', 'returnsDueToday', 'todayRenewalItems',
                    'todayDeliveryItems', 'todayPickupItems', 'recentDeliveries', 'todayFollowUps', 'highPriorityFollowUps',
                    'recentFollowUps', 'recentCustomers', 'recentActivities'] as $field) {
                    $json = json_encode($response->viewData($field), JSON_THROW_ON_ERROR);
                    $this->assertStringNotContainsString('UNASSIGNED_CUSTOMER_SENTINEL', $json, $name.' '.$tab.' '.$field);
                    $this->assertStringNotContainsString('9876540091', $json, $name.' '.$tab.' '.$field);
                }
                // Public operational aggregates remain organization-wide; no financial calculation is changed.
                $this->assertSame($rentalCount, $response->viewData('totalRentals'));
            }
        }

        $ownerResponse = $this->actingAs($owner)->get(route('dashboard'))->assertOk()
            ->assertSeeText('UNASSIGNED_CUSTOMER_SENTINEL_0')->assertSee('tel:9876540091', false);
        $this->assertSame($rentalCount, $ownerResponse->viewData('totalRentals'));
        $this->assertEquals(100 * $rentalCount, $ownerResponse->viewData('totalRentalValue'));
        $this->assertSame(5, $ownerResponse->viewData('todayDeliveryItems')->count());
        $this->assertTrue(str_contains($ownerResponse->viewData('todayDeliveryItems')->toJson(), 'UNASSIGNED_CUSTOMER_SENTINEL'));
        $this->assertSame($rentalCount, Rental::count());
    }

    private function subject(int $organizationId, int $assigneeId, string $name, string $phone, ?int $deliveredEndOffset = null): array
    {
        $customer = Customer::create(['organization_id' => $organizationId, 'name' => $name, 'phone' => $phone]);
        $product = Product::create(['organization_id' => $organizationId, 'name' => $name.' equipment', 'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED, 'price_per_day' => 100, 'rental_price' => 100, 'available_quantity' => 2, 'total_quantity' => 2]);
        $rental = Rental::create(['organization_id' => $organizationId, 'customer_id' => $customer->id, 'product_id' => $product->id,
            'customer_name' => $name, 'phone' => $phone, 'created_by_user_id' => $assigneeId, 'quantity' => 1,
            'start_date' => now()->subDays(5), 'end_date' => now()->addDays($deliveredEndOffset ?? 5), 'status' => 'active',
            'rental_amount' => 100, 'deposit_amount' => 0, 'transport_amount' => 0, 'other_amount' => 0]);
        $delivery = Delivery::create(['organization_id' => $organizationId, 'rental_id' => $rental->id, 'type' => 'delivery',
            'assigned_user_id' => $assigneeId, 'status' => $deliveredEndOffset === null ? 'pending' : 'completed',
            'scheduled_at' => $deliveredEndOffset === null ? now()->startOfDay()->addHours(10) : now()->subDays(5),
            'completed_at' => $deliveredEndOffset === null ? null : now()->subDays(5)]);
        Delivery::create(['organization_id' => $organizationId, 'rental_id' => $rental->id, 'type' => 'pickup', 'status' => 'pending',
            'pickup_status' => 'scheduled', 'assigned_user_id' => $assigneeId, 'scheduled_at' => now()->startOfDay()->addHours(16)]);
        FollowUp::create(['organization_id' => $organizationId, 'customer_id' => $customer->id, 'rental_id' => $rental->id,
            'delivery_id' => $delivery->id, 'assigned_user_id' => $assigneeId, 'created_by_user_id' => $assigneeId,
            'followup_type' => 'delivery', 'title' => $name.' followup', 'due_at' => now()->subHour(), 'status' => 'pending', 'priority' => 'urgent']);
        // A task can be changed by an administrator; visibility follows the linked assignment, not the actor.
        ActivityLog::create(['organization_id' => $organizationId, 'user_id' => $assigneeId, 'action' => 'delivery.updated',
            'delivery_id' => $delivery->id, 'rental_id' => $rental->id, 'customer_id' => $customer->id, 'description' => $name.' '.$phone]);

        return compact('customer', 'rental', 'delivery');
    }
}
