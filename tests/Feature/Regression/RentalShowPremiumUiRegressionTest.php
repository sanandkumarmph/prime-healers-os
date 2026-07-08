<?php

namespace Tests\Feature\Regression;

use App\Models\BusinessPartner;
use App\Models\Customer;
use App\Models\PartnerClient;
use App\Models\Product;
use App\Models\Rental;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class RentalShowPremiumUiRegressionTest extends TestCase
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
    }

    public function test_rental_show_renders_premium_reference_sections_for_direct_customer(): void
    {
        $user = TestData::user(organization: null, attributes: [
            'organization_id' => $this->organizationId,
        ]);

        $this->actingAs($user);

        $customer = Customer::create([
            'organization_id' => $this->organizationId,
            'name' => 'Rohit Gupta',
            'phone' => '9556722014',
            'address' => '109, HSR Layout',
            'city' => 'Bengaluru',
            'state' => 'Karnataka',
            'pincode' => '560102',
            'map_location_url' => 'https://maps.example.test/customer',
        ]);

        $product = $this->makeRentalProduct();

        $rental = Rental::create([
            'organization_id' => $this->organizationId,
            'customer_id' => $customer->id,
            'customer_type' => 'direct_customer',
            'customer_name' => $customer->name,
            'phone' => $customer->phone,
            'product_id' => $product->id,
            'quantity' => 1,
            'start_date' => now()->subDays(1)->toDateString(),
            'end_date' => now()->addDays(7)->toDateString(),
            'status' => 'active',
            'rental_amount' => 1500,
            'deposit_amount' => 500,
            'transport_amount' => 0,
            'other_amount' => 0,
            'created_by_user_id' => $user->id,
        ]);

        $this->get(route('rentals.show', $rental))
            ->assertOk()
            ->assertSee('Rental Command Center')
            ->assertSee('Customer Summary')
            ->assertSee('Rental Lifecycle')
            ->assertSee('Operations Workspace')
            ->assertSee('Product Workspace')
            ->assertSee('href="#rental-notes-section"', false)
            ->assertSee('Timeline &amp; Communication', false)
            ->assertSee('Open notes and recent activity')
            ->assertSee('Direct Customer')
            ->assertSee('Finance Workspace')
            ->assertSee('1,500.00');
    }

    public function test_rental_show_separates_partner_contacts_and_shows_record_finance_for_sales_user(): void
    {
        $salesUser = TestData::user(organization: null, attributes: [
            'organization_id' => $this->organizationId,
            'role' => User::ROLE_SALES,
        ]);

        $this->actingAs($salesUser);

        $partner = BusinessPartner::create([
            'organization_id' => $this->organizationId,
            'business_name' => 'Portea',
            'contact_person' => 'Billing Desk',
            'phone' => '+919556722014',
            'whatsapp' => '+919556722014',
            'email' => 'billing@portea.test',
            'address' => '45 Business Street',
            'city' => 'Bengaluru',
            'state' => 'Karnataka',
            'pincode' => '560034',
            'location' => 'https://maps.example.test/partner',
            'status' => 'active',
            'gstin' => '29ABCDE1234F1Z5',
        ]);

        $client = PartnerClient::create([
            'organization_id' => $this->organizationId,
            'business_partner_id' => $partner->id,
            'client_name' => 'Lakshmi Home Care',
            'phone' => '+919955667788',
            'address' => '22 Lake Street',
            'city' => 'Bengaluru',
            'state' => 'Karnataka',
            'pincode' => '560095',
            'location' => 'https://maps.example.test/client',
            'delivery_notes' => 'Use side gate',
            'status' => 'active',
        ]);

        $product = $this->makeRentalProduct('ICU Bed');

        $rental = Rental::create([
            'organization_id' => $this->organizationId,
            'customer_type' => 'business_partner',
            'business_partner_id' => $partner->id,
            'partner_client_id' => $client->id,
            'customer_name' => $client->displayName(),
            'phone' => $client->primaryPhone(),
            'product_id' => $product->id,
            'quantity' => 1,
            'start_date' => now()->subDays(2)->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
            'status' => 'active',
            'rental_amount' => 2500,
            'deposit_amount' => 800,
            'transport_amount' => 0,
            'other_amount' => 0,
            'created_by_user_id' => $salesUser->id,
        ]);

        $this->get(route('rentals.show', $rental))
            ->assertOk()
            ->assertSee('Customer Summary')
            ->assertSee('Partner:')
            ->assertSee('Actual client:')
            ->assertSee('Finance Workspace')
            ->assertSee('2,500.00')
            ->assertSee('800.00')
            ->assertDontSee('Finance amounts are hidden for your role.');
    }

    public function test_record_finance_visibility_is_separate_from_dashboard_finance_for_core_roles(): void
    {
        $superAdmin = TestData::user(organization: null, attributes: [
            'organization_id' => $this->organizationId,
            'role' => User::ROLE_SUPER_ADMIN,
        ]);
        $finance = TestData::user(organization: null, attributes: [
            'organization_id' => $this->organizationId,
            'role' => User::ROLE_FINANCE,
        ]);
        $sales = TestData::user(organization: null, attributes: [
            'organization_id' => $this->organizationId,
            'role' => User::ROLE_SALES,
        ]);
        $operations = TestData::user(organization: null, attributes: [
            'organization_id' => $this->organizationId,
            'role' => User::ROLE_ADMIN_OPERATIONS,
        ]);
        $delivery = TestData::user(organization: null, attributes: [
            'organization_id' => $this->organizationId,
            'role' => User::ROLE_DELIVERY_EXECUTIVE,
        ]);
        $vendor = TestData::user(organization: null, attributes: [
            'organization_id' => $this->organizationId,
            'role' => User::ROLE_VENDOR,
        ]);

        $this->assertTrue($superAdmin->canViewFinance());
        $this->assertTrue($finance->canViewFinance());
        $this->assertFalse($sales->canViewFinance());
        $this->assertFalse($operations->canViewFinance());

        $this->assertTrue($superAdmin->canViewRecordFinance());
        $this->assertTrue($finance->canViewRecordFinance());
        $this->assertTrue($sales->canViewRecordFinance());
        $this->assertTrue($operations->canViewRecordFinance());
        $this->assertFalse($delivery->canViewRecordFinance());
        $this->assertFalse($vendor->canViewRecordFinance());
    }

    private function makeRentalProduct(string $name = 'Wheelchair'): Product
    {
        return Product::create([
            'organization_id' => $this->organizationId,
            'name' => $name,
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
    }
}
