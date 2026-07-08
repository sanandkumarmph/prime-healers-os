<?php

namespace Tests\Feature\Regression;

use App\Models\BusinessPartner;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\FollowUp;
use App\Models\Invoice;
use App\Models\PartnerClient;
use App\Models\Product;
use App\Models\Rental;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class CommunicationCenterRegressionTest extends TestCase
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

    public function test_communication_center_page_loads_and_today_filter_works(): void
    {
        $rental = $this->makeRental();

        FollowUp::create([
            'organization_id' => $this->organizationId,
            'customer_id' => $rental->customer_id,
            'rental_id' => $rental->id,
            'followup_type' => FollowUp::TYPE_RENEWAL,
            'title' => 'Today renewal follow-up',
            'due_at' => now(),
            'status' => FollowUp::STATUS_PENDING,
            'priority' => FollowUp::PRIORITY_HIGH,
        ]);

        FollowUp::create([
            'organization_id' => $this->organizationId,
            'customer_id' => $rental->customer_id,
            'rental_id' => $rental->id,
            'followup_type' => FollowUp::TYPE_GENERAL,
            'title' => 'Future callback',
            'due_at' => now()->addDays(3),
            'status' => FollowUp::STATUS_PENDING,
            'priority' => FollowUp::PRIORITY_LOW,
        ]);

        $this->get(route('communication-center.index', ['tab' => 'today']))
            ->assertOk()
            ->assertSeeText('Communication Center')
            ->assertSeeText('Today renewal follow-up')
            ->assertDontSeeText('Future callback');
    }

    public function test_add_follow_up_works_and_logs_timeline_activity(): void
    {
        $rental = $this->makeRental();

        $this->post(route('communication-center.store'), [
            'rental_id' => $rental->id,
            'followup_type' => FollowUp::TYPE_RENEWAL,
            'due_at' => now()->addDay()->format('Y-m-d H:i:s'),
            'priority' => FollowUp::PRIORITY_HIGH,
            'note' => 'Call customer tomorrow morning.',
        ])->assertSessionHas('success');

        $this->assertDatabaseHas('follow_ups', [
            'organization_id' => $this->organizationId,
            'rental_id' => $rental->id,
            'followup_type' => FollowUp::TYPE_RENEWAL,
            'priority' => FollowUp::PRIORITY_HIGH,
        ]);

        $this->get(route('rentals.show', $rental))
            ->assertOk()
            ->assertSeeText('Follow-up added');
    }

    public function test_overdue_filter_and_mark_complete_work(): void
    {
        $rental = $this->makeRental();

        $followUp = FollowUp::create([
            'organization_id' => $this->organizationId,
            'customer_id' => $rental->customer_id,
            'rental_id' => $rental->id,
            'followup_type' => FollowUp::TYPE_PAYMENT,
            'title' => 'Overdue payment follow-up',
            'due_at' => now()->subDay(),
            'status' => FollowUp::STATUS_PENDING,
            'priority' => FollowUp::PRIORITY_URGENT,
        ]);

        $this->get(route('communication-center.index', ['tab' => 'overdue']))
            ->assertOk()
            ->assertSeeText('Overdue payment follow-up');

        $this->post(route('communication-center.complete', $followUp), [
            'completion_note' => 'Customer settled the invoice.',
        ])->assertSessionHas('success');

        $this->assertDatabaseHas('follow_ups', [
            'id' => $followUp->id,
            'status' => FollowUp::STATUS_COMPLETED,
        ]);
    }

    public function test_reschedule_updates_due_at(): void
    {
        $rental = $this->makeRental();
        $followUp = FollowUp::create([
            'organization_id' => $this->organizationId,
            'customer_id' => $rental->customer_id,
            'rental_id' => $rental->id,
            'followup_type' => FollowUp::TYPE_CALLBACK,
            'title' => 'Callback follow-up',
            'due_at' => now()->addHours(2),
            'status' => FollowUp::STATUS_PENDING,
            'priority' => FollowUp::PRIORITY_MEDIUM,
        ]);

        $newDueAt = now()->addDays(2)->setTime(11, 30);

        $this->post(route('communication-center.reschedule', $followUp), [
            'due_at' => $newDueAt->format('Y-m-d H:i:s'),
            'reschedule_note' => 'Customer asked for later call.',
            'priority' => FollowUp::PRIORITY_HIGH,
        ])->assertSessionHas('success');

        $this->assertSame(
            $newDueAt->format('Y-m-d H:i:s'),
            $followUp->fresh()->due_at?->format('Y-m-d H:i:s')
        );
        $this->assertSame(FollowUp::PRIORITY_HIGH, $followUp->fresh()->priority);
    }

    public function test_business_partner_payment_follow_up_uses_partner_and_service_follow_up_uses_actual_client(): void
    {
        [$partner, $client, $rental] = $this->makeBusinessPartnerRental();

        $paymentFollowUp = FollowUp::create([
            'organization_id' => $this->organizationId,
            'business_partner_id' => $partner->id,
            'partner_client_id' => $client->id,
            'rental_id' => $rental->id,
            'followup_type' => FollowUp::TYPE_PAYMENT,
            'title' => 'Payment follow-up',
            'due_at' => now()->addDay(),
            'status' => FollowUp::STATUS_PENDING,
            'priority' => FollowUp::PRIORITY_MEDIUM,
        ]);

        $serviceFollowUp = FollowUp::create([
            'organization_id' => $this->organizationId,
            'business_partner_id' => $partner->id,
            'partner_client_id' => $client->id,
            'rental_id' => $rental->id,
            'followup_type' => FollowUp::TYPE_PICKUP,
            'title' => 'Pickup follow-up',
            'due_at' => now()->addDay(),
            'status' => FollowUp::STATUS_PENDING,
            'priority' => FollowUp::PRIORITY_MEDIUM,
        ]);

        $response = $this->get(route('communication-center.index'));
        $response->assertOk()
            ->assertSeeText($paymentFollowUp->title)
            ->assertSeeText($serviceFollowUp->title)
            ->assertSeeText($partner->displayName())
            ->assertSeeText($client->displayName());
    }

    public function test_failed_pickup_only_creates_one_auto_follow_up(): void
    {
        $rental = $this->makeRental();

        $delivery = Delivery::create([
            'organization_id' => $this->organizationId,
            'rental_id' => $rental->id,
            'type' => 'pickup',
            'status' => 'pending',
            'pickup_status' => 'failed_attempt',
            'failed_attempt_reason' => 'customer_not_available',
            'failed_attempt_note' => 'Nobody answered at the location.',
            'scheduled_at' => now()->subHour(),
        ]);

        $this->get(route('communication-center.index'))->assertOk();
        $this->get(route('communication-center.index'))->assertOk();

        $this->assertSame(1, FollowUp::query()
            ->where('organization_id', $this->organizationId)
            ->where('delivery_id', $delivery->id)
            ->where('source', 'auto:pickup_failed')
            ->count());
    }

    public function test_dashboard_shows_communication_center_cards(): void
    {
        $rental = $this->makeRental();

        FollowUp::create([
            'organization_id' => $this->organizationId,
            'customer_id' => $rental->customer_id,
            'rental_id' => $rental->id,
            'followup_type' => FollowUp::TYPE_RENEWAL,
            'title' => 'Renewal follow-up',
            'due_at' => now(),
            'status' => FollowUp::STATUS_PENDING,
            'priority' => FollowUp::PRIORITY_HIGH,
        ]);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSeeText('Activity & Communication Center')
            ->assertSeeText('Recent Activity')
            ->assertSeeText('Notifications Summary')
            ->assertSeeText('Alerts & Escalations')
            ->assertSeeText('Communication Queue')
            ->assertSeeText('Escalation Queue');
    }

    private function makeRental(): Rental
    {
        $customer = Customer::create([
            'organization_id' => $this->organizationId,
            'name' => 'Communication Customer ' . uniqid(),
            'phone' => '95567' . str_pad((string) random_int(10000, 99999), 5, '0', STR_PAD_LEFT),
            'whatsapp_number' => '95567' . str_pad((string) random_int(10000, 99999), 5, '0', STR_PAD_LEFT),
            'address' => '109, HSR Layout',
            'city' => 'Bengaluru',
            'state' => 'Karnataka',
            'pincode' => '560102',
            'map_url' => 'https://maps.example.test/customer-' . uniqid(),
        ]);
        $product = Product::create([
            'organization_id' => $this->organizationId,
            'name' => 'Wheelchair ' . uniqid(),
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'available_quantity' => 12,
            'total_quantity' => 12,
            'price_per_day' => 500,
            'rental_price' => 1500,
            'sale_price' => 0,
        ]);

        return Rental::create([
            'organization_id' => $this->organizationId,
            'customer_id' => $customer->id,
            'customer_name' => $customer->name,
            'phone' => $customer->phone,
            'product_id' => $product->id,
            'quantity' => 1,
            'start_date' => now()->subDays(3)->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
            'status' => 'active',
            'rental_amount' => 1500,
            'deposit_amount' => 250,
            'transport_amount' => 0,
            'other_amount' => 0,
        ]);
    }

    private function makeBusinessPartnerRental(): array
    {
        $product = Product::create([
            'organization_id' => $this->organizationId,
            'name' => 'Hospital Bed ' . uniqid(),
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'available_quantity' => 8,
            'total_quantity' => 8,
            'price_per_day' => 700,
            'rental_price' => 2400,
            'sale_price' => 0,
        ]);

        $partner = BusinessPartner::create([
            'organization_id' => $this->organizationId,
            'business_name' => 'Portea',
            'contact_person' => 'Billing Desk',
            'phone' => '+919811110001',
            'whatsapp' => '+919811110001',
            'address' => '45 Business Street',
            'city' => 'Bengaluru',
            'state' => 'Karnataka',
            'pincode' => '560034',
            'status' => 'active',
        ]);

        $client = PartnerClient::create([
            'organization_id' => $this->organizationId,
            'business_partner_id' => $partner->id,
            'client_name' => 'Mr. Ramesh',
            'phone' => '+919822220002',
            'address' => 'Indiranagar, Bangalore',
            'city' => 'Bengaluru',
            'state' => 'Karnataka',
            'pincode' => '560038',
            'delivery_notes' => 'Ring before entering.',
            'status' => 'active',
        ]);

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
            'end_date' => now()->addDays(5)->toDateString(),
            'status' => 'active',
            'rental_amount' => 2200,
            'deposit_amount' => 300,
            'transport_amount' => 0,
            'other_amount' => 0,
        ]);

        Invoice::create([
            'organization_id' => $this->organizationId,
            'invoice_number' => 'INV-COM-' . uniqid(),
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->subDay()->toDateString(),
            'rental_id' => $rental->id,
            'bill_to_name' => $partner->displayName(),
            'bill_to_phone' => $partner->phone,
            'bill_to_address' => $partner->address,
            'bill_to_city' => $partner->city,
            'bill_to_state' => $partner->state,
            'bill_to_pincode' => $partner->pincode,
            'ship_to_name' => $client->displayName(),
            'ship_to_phone' => $client->primaryPhone(),
            'ship_to_address' => $client->address,
            'ship_to_city' => $client->city,
            'ship_to_state' => $client->state,
            'ship_to_pincode' => $client->pincode,
            'place_of_supply_state' => $partner->state,
            'tax_type' => 'cgst_sgst',
            'tax_calculation_mode' => 'exclusive',
            'status' => 'open',
            'payment_status' => 'unpaid',
            'subtotal' => 2200,
            'discount_amount' => 0,
            'deposit_amount' => 0,
            'shipping_charges' => 0,
            'taxable_amount' => 2200,
            'cgst_amount' => 0,
            'sgst_amount' => 0,
            'igst_amount' => 0,
            'total_tax_amount' => 0,
            'total_amount' => 2200,
            'paid_amount' => 0,
            'balance_amount' => 2200,
        ]);

        return [$partner, $client, $rental];
    }
}
