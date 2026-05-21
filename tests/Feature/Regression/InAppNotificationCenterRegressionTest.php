<?php

namespace Tests\Feature\Regression;

use App\Models\Customer;
use App\Models\Delivery;
use App\Models\FollowUp;
use App\Models\Product;
use App\Models\Rental;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Support\TestData;
use Tests\TestCase;

class InAppNotificationCenterRegressionTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;
    private int $organizationId;

    protected function setUp(): void
    {
        parent::setUp();

        $organization = TestData::organization([
            'state' => 'Karnataka',
        ]);

        $this->organizationId = $organization->id;
        $this->manager = TestData::user($organization);
        $this->actingAs($this->manager);
    }

    public function test_pickup_assignment_creates_user_scoped_notification_and_read_endpoint_marks_it_read(): void
    {
        $deliveryUser = $this->makeDeliveryUser();
        $otherUser = $this->makeDeliveryUser('other-' . uniqid() . '@example.test');
        $pickup = $this->makePickupTask();

        $this->post(route('pickup-center.assign', $pickup), [
            'pickup_date' => now()->addDay()->toDateString(),
            'pickup_time_slot' => '12:00-15:00',
            'assignment_target' => 'user:' . $deliveryUser->id,
            'notes' => 'Pickup from security gate',
        ])->assertRedirect();

        $deliveryUser->refresh();
        $this->assertSame(1, $deliveryUser->unreadNotifications()->count());

        $notificationId = (string) $deliveryUser->unreadNotifications()->value('id');

        $this->actingAs($deliveryUser)
            ->getJson(route('notifications.latest'))
            ->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonPath('notifications.0.title', 'New pickup task assigned')
            ->assertJsonPath('notifications.0.action_url', route('deliveries.show', $pickup));

        $this->actingAs($otherUser)
            ->getJson(route('notifications.latest'))
            ->assertOk()
            ->assertJsonPath('unread_count', 0)
            ->assertJsonCount(0, 'notifications');

        $this->actingAs($deliveryUser)
            ->postJson(route('notifications.read', $notificationId))
            ->assertOk()
            ->assertJsonPath('unread_count', 0);

        $this->assertSame(0, $deliveryUser->fresh()->unreadNotifications()->count());
    }

    public function test_follow_up_assignment_and_mark_all_as_read_work(): void
    {
        $deliveryUser = $this->makeDeliveryUser();
        $rental = $this->makeRental();

        $this->post(route('communication-center.store'), [
            'rental_id' => $rental->id,
            'followup_type' => FollowUp::TYPE_RENEWAL,
            'due_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'assigned_user_id' => $deliveryUser->id,
            'priority' => FollowUp::PRIORITY_HIGH,
            'note' => 'Renewal confirmation needed.',
        ])->assertSessionHas('success');

        $this->actingAs($deliveryUser)
            ->getJson(route('notifications.latest'))
            ->assertOk()
            ->assertJsonPath('unread_count', 1)
            ->assertJsonPath('notifications.0.title', 'New follow-up assigned');

        $this->actingAs($deliveryUser)
            ->postJson(route('notifications.read-all'))
            ->assertOk()
            ->assertJsonPath('unread_count', 0);

        $this->assertSame(0, $deliveryUser->fresh()->unreadNotifications()->count());
    }

    public function test_authenticated_layout_renders_live_notification_hooks(): void
    {
        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-in-app-notifications', false)
            ->assertSee(Route::has('notifications.latest') ? route('notifications.latest') : '', false)
            ->assertSee(Route::has('notifications.preferences') ? route('notifications.preferences') : '', false)
            ->assertSee('data-notification-sound-toggle', false)
            ->assertSee('data-notification-voice-toggle', false)
            ->assertSee('data-notification-toast-stack', false);
    }

    public function test_notification_preferences_can_be_updated_for_authenticated_user_only(): void
    {
        $this->postJson(route('notifications.preferences'), [
            'sound_alerts_enabled' => true,
            'voice_alerts_enabled' => true,
        ])->assertOk()
            ->assertJsonPath('sound_alerts_enabled', true)
            ->assertJsonPath('voice_alerts_enabled', true);

        $this->assertTrue((bool) $this->manager->fresh()->notification_sound_enabled);
        $this->assertTrue((bool) $this->manager->fresh()->notification_voice_enabled);
    }

    private function makeDeliveryUser(?string $email = null): User
    {
        return User::factory()->create([
            'organization_id' => $this->organizationId,
            'role' => User::ROLE_DELIVERY,
            'email' => $email ?: ('delivery-' . uniqid() . '@example.test'),
            'is_internal' => true,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function makePickupTask(): Delivery
    {
        $rental = $this->makeRental();

        return Delivery::create([
            'organization_id' => $this->organizationId,
            'rental_id' => $rental->id,
            'type' => 'pickup',
            'status' => 'pending',
            'pickup_status' => 'requested',
            'scheduled_at' => now()->addDay(),
        ]);
    }

    private function makeRental(): Rental
    {
        $customer = Customer::create([
            'organization_id' => $this->organizationId,
            'name' => 'Mr. Ramesh',
            'phone' => '9988776655',
            'address' => 'HSR Layout',
            'city' => 'Bengaluru',
            'state' => 'Karnataka',
            'pincode' => '560102',
        ]);

        $product = Product::create([
            'organization_id' => $this->organizationId,
            'name' => 'Wheelchair',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'available_quantity' => 10,
            'total_quantity' => 10,
            'status' => 'active',
            'rental_price' => 1500,
            'rental_price_15_days' => 1500,
            'rental_price_30_days' => 3000,
            'rental_price_3_months' => 9000,
            'sale_price' => 0,
            'price_per_day' => 75,
        ]);

        return Rental::create([
            'organization_id' => $this->organizationId,
            'customer_id' => $customer->id,
            'customer_name' => $customer->displayName(),
            'phone' => $customer->phone,
            'product_id' => $product->id,
            'quantity' => 1,
            'start_date' => now()->subDays(2)->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
            'status' => 'active',
            'rental_amount' => 1500,
            'deposit_amount' => 300,
        ]);
    }
}
