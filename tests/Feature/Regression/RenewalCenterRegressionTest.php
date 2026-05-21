<?php

namespace Tests\Feature\Regression;

use App\Models\BusinessPartner;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\PartnerClient;
use App\Models\Product;
use App\Models\Rental;
use App\Models\RentalReminderLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class RenewalCenterRegressionTest extends TestCase
{
    use RefreshDatabase;

    private int $organizationId;

    protected function setUp(): void
    {
        parent::setUp();

        $organization = TestData::organization([
            'state' => 'Karnataka',
        ]);

        $this->organizationId = $organization->id;
        $this->actingAs(TestData::user($organization));
    }

    public function test_renewal_center_page_loads_and_due_today_filter_works(): void
    {
        $dueToday = $this->makeDirectCustomerRental([
            'end_date' => now()->toDateString(),
        ]);

        $this->makeDirectCustomerRental([
            'end_date' => now()->addDays(5)->toDateString(),
        ]);

        $response = $this->get(route('renewal-center.index', ['tab' => 'due_today']));

        $response->assertOk()
            ->assertSeeText('Renewal Center')
            ->assertSeeText('Due Today')
            ->assertSeeText('Rental #' . $dueToday->id)
            ->assertDontSeeText('day(s) overdue');
    }

    public function test_business_partner_rental_shows_partner_as_reminder_and_actual_client_as_service_location(): void
    {
        [$partner, $client] = $this->makeBusinessPartnerContext();
        $product = $this->makeRentalProduct();

        $rental = Rental::create([
            'organization_id' => $this->organizationId,
            'customer_type' => 'business_partner',
            'business_partner_id' => $partner->id,
            'partner_client_id' => $client->id,
            'customer_id' => null,
            'customer_name' => $client->displayName(),
            'phone' => $client->phone,
            'product_id' => $product->id,
            'quantity' => 1,
            'start_date' => now()->subDays(2)->toDateString(),
            'end_date' => now()->toDateString(),
            'status' => 'active',
            'rental_amount' => 1200,
            'deposit_amount' => 250,
            'transport_amount' => 0,
            'other_amount' => 0,
        ]);

        Delivery::create([
            'organization_id' => $this->organizationId,
            'rental_id' => $rental->id,
            'type' => 'delivery',
            'status' => 'completed',
            'scheduled_at' => now()->subDays(2),
            'completed_at' => now()->subDays(2),
        ]);

        $this->get(route('renewal-center.index'))
            ->assertOk()
            ->assertSeeText('Reminder To:')
            ->assertSeeText($partner->displayName())
            ->assertSeeText('Service Location:')
            ->assertSeeText($client->displayName())
            ->assertSeeText($client->address);
    }

    public function test_direct_customer_rental_shows_customer_as_reminder_contact(): void
    {
        $rental = $this->makeDirectCustomerRental([
            'end_date' => now()->toDateString(),
        ]);

        $this->get(route('renewal-center.index'))
            ->assertOk()
            ->assertSeeText($rental->customer->displayName())
            ->assertSeeText($rental->customer->phone);
    }

    public function test_mark_reminder_sent_stores_timestamp_and_log_entry(): void
    {
        $rental = $this->makeDirectCustomerRental([
            'end_date' => now()->toDateString(),
        ]);

        $this->from(route('renewal-center.index'))
            ->post(route('renewal-center.mark-reminder-sent', $rental))
            ->assertRedirect(route('renewal-center.index'));

        $this->assertDatabaseHas('rental_reminder_logs', [
            'organization_id' => $this->organizationId,
            'rental_id' => $rental->id,
            'reminder_type' => 'renewal',
        ]);

        $this->assertNotNull(RentalReminderLog::query()->where('rental_id', $rental->id)->latest('id')->first()?->sent_at);
    }

    public function test_mark_renewed_from_renewal_center_updates_rental_and_returns_to_center(): void
    {
        $rental = $this->makeDirectCustomerRental([
            'end_date' => now()->toDateString(),
            'rental_amount' => 1000,
        ]);

        $this->from(route('renewal-center.index'))
            ->post(route('rentals.renew', $rental), [
                'new_end_date' => now()->addDays(7)->toDateString(),
                'rental_amount_added' => 700,
                'deposit_amount_added' => 0,
                'transport_amount_added' => 0,
                'other_amount_added' => 0,
                'payment_amount' => 0,
                'payment_method' => null,
                'payment_date' => now()->toDateString(),
                'notes' => 'Renewed from Renewal Center',
                'return_to_renewal_center' => 1,
            ])
            ->assertRedirect(route('renewal-center.index'));

        $this->assertSame(now()->addDays(7)->toDateString(), $rental->fresh()->end_date?->toDateString());
    }

    public function test_schedule_pickup_creates_pickup_task_for_rental(): void
    {
        $rental = $this->makeDirectCustomerRental([
            'end_date' => now()->toDateString(),
        ]);

        $this->from(route('renewal-center.index'))
            ->post(route('renewal-center.schedule-pickup', $rental), [
                'pickup_date' => now()->addDay()->toDateString(),
                'pickup_time_slot' => '12:00-15:00',
                'pickup_notes' => 'Collect from front desk',
                'assignment_target' => '',
            ])
            ->assertRedirect(route('renewal-center.index'));

        $this->assertDatabaseHas('deliveries', [
            'organization_id' => $this->organizationId,
            'rental_id' => $rental->id,
            'type' => 'pickup',
            'status' => 'pending',
        ]);
    }

    public function test_dashboard_shows_renewal_center_summary_cards(): void
    {
        $this->makeDirectCustomerRental(['end_date' => now()->toDateString()]);
        $this->makeDirectCustomerRental(['end_date' => now()->addDays(4)->toDateString()]);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSeeText('Renewal Center')
            ->assertSeeText('Renewals Due Today')
            ->assertSeeText('Renewals Due This Week')
            ->assertSeeText('Overdue Renewals')
            ->assertSeeText('Pickup Requests');
    }

    private function makeDirectCustomerRental(array $overrides = []): Rental
    {
        $customer = Customer::create([
            'organization_id' => $this->organizationId,
            'name' => 'Direct Renewal Customer ' . uniqid(),
            'phone' => '990000' . random_int(1000, 9999),
            'address' => '12 Renewal Street',
            'city' => 'Bengaluru',
            'state' => 'Karnataka',
            'pincode' => '560001',
        ]);

        $product = $this->makeRentalProduct();

        $rental = Rental::create(array_merge([
            'organization_id' => $this->organizationId,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'phone' => $customer->phone,
            'product_id' => $product->id,
            'quantity' => 1,
            'start_date' => now()->subDays(3)->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'status' => 'active',
            'rental_amount' => 1500,
            'deposit_amount' => 300,
            'transport_amount' => 0,
            'other_amount' => 0,
        ], $overrides));

        Delivery::create([
            'organization_id' => $this->organizationId,
            'rental_id' => $rental->id,
            'type' => 'delivery',
            'status' => 'completed',
            'scheduled_at' => now()->subDays(3),
            'completed_at' => now()->subDays(3),
        ]);

        return $rental->fresh(['customer', 'product', 'deliveryRecord']);
    }

    private function makeRentalProduct(): Product
    {
        return Product::create([
            'organization_id' => $this->organizationId,
            'name' => 'Renewal Product ' . uniqid(),
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'price_per_day' => 350,
            'rental_price' => 350,
            'sale_price' => 0,
            'available_quantity' => 15,
            'total_quantity' => 15,
        ]);
    }

    private function makeBusinessPartnerContext(): array
    {
        $partner = BusinessPartner::create([
            'organization_id' => $this->organizationId,
            'business_name' => 'Portea Tie-up',
            'contact_person' => 'Renewal Desk',
            'phone' => '+919900001010',
            'whatsapp' => '+919900001010',
            'email' => 'renewals@example.test',
            'address' => 'Partner Address',
            'city' => 'Bengaluru',
            'state' => 'Karnataka',
            'status' => 'active',
        ]);

        $client = PartnerClient::create([
            'organization_id' => $this->organizationId,
            'business_partner_id' => $partner->id,
            'client_name' => 'Mr. Ramesh',
            'phone' => '+919988776655',
            'address' => 'Indiranagar, Bangalore',
            'city' => 'Bengaluru',
            'state' => 'Karnataka',
            'status' => 'active',
            'delivery_notes' => 'Second floor service entry',
        ]);

        return [$partner, $client];
    }
}
