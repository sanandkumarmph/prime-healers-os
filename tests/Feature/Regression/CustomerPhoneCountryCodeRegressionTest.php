<?php

namespace Tests\Feature\Regression;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestData;
use Tests\TestCase;

class CustomerPhoneCountryCodeRegressionTest extends TestCase
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

    public static function quickCustomerRoutes(): array
    {
        return [
            'customers' => ['customers.quick-store'],
            'rentals' => ['rentals.customers.quick-store'],
            'sales' => ['sales.customers.quick-store'],
            'invoices' => ['invoices.customers.quick-store'],
        ];
    }

    #[DataProvider('quickCustomerRoutes')]
    public function test_quick_create_persists_each_selected_country_code(string $routeName): void
    {
        $response = $this->postJson(route($routeName), $this->internationalContact());

        $response->assertOk()
            ->assertJsonPath('customer.phone', '+12025550100')
            ->assertJsonPath('customer.phone_country_code', '+1')
            ->assertJsonPath('customer.whatsapp_number', '+447700900101')
            ->assertJsonPath('customer.whatsapp_number_country_code', '+44');

        $this->assertDatabaseHas('customers', [
            'id' => $response->json('customer.id'),
            'organization_id' => $this->organizationId,
            'phone' => '+12025550100',
            'whatsapp_number' => '+447700900101',
        ]);
    }

    public function test_full_create_persists_international_phone_and_whatsapp(): void
    {
        $this->post(route('customers.store'), $this->internationalContact())
            ->assertRedirect(route('customers.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('customers', [
            'organization_id' => $this->organizationId,
            'phone' => '+12025550100',
            'whatsapp_number' => '+447700900101',
        ]);
    }

    public function test_update_applies_new_country_codes_to_the_existing_customer(): void
    {
        $customer = Customer::create([
            'organization_id' => $this->organizationId,
            'customer_type' => 'Individual',
            'first_name' => 'Country code test',
            'name' => 'Country code test',
            'phone' => '+912025550100',
            'whatsapp_number' => '+917700900101',
        ]);

        $this->put(route('customers.update', $customer->id), $this->internationalContact())
            ->assertRedirect(route('customers.index'))
            ->assertSessionHasNoErrors();

        $customer->refresh();
        $this->assertSame('+12025550100', $customer->phone);
        $this->assertSame('+447700900101', $customer->whatsapp_number);
        $this->assertDatabaseCount('customers', 1);
    }

    public function test_repeating_international_quick_create_reuses_the_same_customer(): void
    {
        $first = $this->postJson(route('rentals.customers.quick-store'), $this->internationalContact());
        $first->assertOk();

        $second = $this->postJson(route('rentals.customers.quick-store'), $this->internationalContact());
        $second->assertOk()
            ->assertJsonPath('customer.id', $first->json('customer.id'))
            ->assertJsonPath('customer.phone', '+12025550100');

        $this->assertDatabaseCount('customers', 1);
    }

    public function test_same_local_digits_in_different_countries_do_not_overwrite_or_collide(): void
    {
        $existing = Customer::create([
            'organization_id' => $this->organizationId,
            'customer_type' => 'Individual',
            'first_name' => 'Existing country test',
            'name' => 'Existing country test',
            'phone' => '+912025550100',
        ]);

        $this->post(route('customers.store'), $this->internationalContact())
            ->assertRedirect(route('customers.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('customers', 2);
        $this->assertSame('+912025550100', $existing->fresh()->phone);
        $this->assertDatabaseHas('customers', ['phone' => '+12025550100']);

        $this->from(route('customers.create'))
            ->post(route('customers.store'), $this->internationalContact())
            ->assertRedirect(route('customers.create'))
            ->assertSessionHasErrors('phone');
        $this->assertDatabaseCount('customers', 2);
    }

    public function test_full_create_still_accepts_blank_optional_contacts(): void
    {
        $this->post(route('customers.store'), [
            'customer_type' => 'Individual',
            'first_name' => 'Contact-free test',
            'phone_country_code' => '+1',
            'phone' => '',
            'whatsapp_number_country_code' => '+44',
            'whatsapp_number' => '',
        ])->assertRedirect(route('customers.index'))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('customers', [
            'organization_id' => $this->organizationId,
            'name' => 'Contact-free test',
            'phone' => null,
            'whatsapp_number' => null,
        ]);
    }

    public function test_full_international_values_remain_compatible_without_country_selectors(): void
    {
        $this->post(route('customers.store'), [
            'customer_type' => 'Individual',
            'first_name' => 'International format test',
            'phone' => '+12025550100',
            'whatsapp_number' => '+447700900101',
        ])->assertRedirect(route('customers.index'))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('customers', [
            'organization_id' => $this->organizationId,
            'phone' => '+12025550100',
            'whatsapp_number' => '+447700900101',
        ]);
    }

    private function internationalContact(): array
    {
        return [
            'customer_type' => 'Individual',
            'first_name' => 'Country code test',
            'phone_country_code' => '+1',
            'phone' => '2025550100',
            'whatsapp_number_country_code' => '+44',
            'whatsapp_number' => '7700900101',
        ];
    }
}
