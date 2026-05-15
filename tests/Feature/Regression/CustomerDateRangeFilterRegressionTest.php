<?php

namespace Tests\Feature\Regression;

use App\Models\Customer;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class CustomerDateRangeFilterRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_list_filters_by_from_date_only(): void
    {
        [$organization] = $this->customerContext();

        $older = $this->createCustomer($organization->id, 'Older Customer', '2026-05-01 09:00:00');
        $newer = $this->createCustomer($organization->id, 'Newer Customer', '2026-05-10 09:00:00');

        $response = $this->get(route('customers.index', [
            'from_date' => '2026-05-05',
        ]));

        $response->assertOk();
        $response->assertDontSee($older->name);
        $response->assertSee($newer->name);
    }

    public function test_customer_list_filters_by_to_date_only(): void
    {
        [$organization] = $this->customerContext();

        $older = $this->createCustomer($organization->id, 'Older Customer', '2026-05-01 09:00:00');
        $newer = $this->createCustomer($organization->id, 'Newer Customer', '2026-05-10 09:00:00');

        $response = $this->get(route('customers.index', [
            'to_date' => '2026-05-05',
        ]));

        $response->assertOk();
        $response->assertSee($older->name);
        $response->assertDontSee($newer->name);
    }

    public function test_customer_list_filters_by_inclusive_date_range_using_full_end_date(): void
    {
        [$organization] = $this->customerContext();

        $insideEarly = $this->createCustomer($organization->id, 'Inside Early', '2026-05-05 00:00:00');
        $insideLate = $this->createCustomer($organization->id, 'Inside Late', '2026-05-10 23:59:59');
        $outside = $this->createCustomer($organization->id, 'Outside Customer', '2026-05-11 00:00:00');

        $response = $this->get(route('customers.index', [
            'from_date' => '2026-05-05',
            'to_date' => '2026-05-10',
        ]));

        $response->assertOk();
        $response->assertSee($insideEarly->name);
        $response->assertSee($insideLate->name);
        $response->assertDontSee($outside->name);
    }

    public function test_customer_date_range_works_with_search_sorting_and_pagination(): void
    {
        [$organization] = $this->customerContext();

        foreach (range(1, 15) as $index) {
            $this->createCustomer(
                $organization->id,
                'Alpha Filter ' . str_pad((string) $index, 2, '0', STR_PAD_LEFT),
                '2026-05-10 10:00:00'
            );
        }

        $this->createCustomer($organization->id, 'Beta Outside', '2026-05-12 10:00:00');

        $response = $this->get(route('customers.index', [
            'search' => 'Alpha Filter',
            'from_date' => '2026-05-01',
            'to_date' => '2026-05-11',
            'sort_by' => 'name_desc',
            'page' => 2,
        ]));

        $response->assertOk();
        $response->assertSee('from_date=2026-05-01', false);
        $response->assertSee('to_date=2026-05-11', false);
        $response->assertSee('sort_by=name_desc', false);
        $response->assertSee('Alpha Filter 03');
        $response->assertDontSee('Beta Outside');
    }

    public function test_customer_date_range_preserves_organization_scoping(): void
    {
        [$organization] = $this->customerContext();
        $otherOrganization = TestData::organization(['name' => 'Other Org']);

        $visible = $this->createCustomer($organization->id, 'Scoped Customer', '2026-05-10 12:00:00');
        $hidden = $this->createCustomer($otherOrganization->id, 'Other Org Customer', '2026-05-10 12:00:00');

        $response = $this->get(route('customers.index', [
            'from_date' => '2026-05-01',
            'to_date' => '2026-05-11',
        ]));

        $response->assertOk();
        $response->assertSee($visible->name);
        $response->assertDontSee($hidden->name);
    }

    public function test_customer_export_respects_date_range_filters(): void
    {
        [$organization] = $this->customerContext();

        $inside = $this->createCustomer($organization->id, 'Export Inside', '2026-05-10 12:00:00');
        $outside = $this->createCustomer($organization->id, 'Export Outside', '2026-05-15 12:00:00');

        $response = $this->get(route('customers.export.csv', [
            'from_date' => '2026-05-01',
            'to_date' => '2026-05-10',
        ]));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');

        $content = $response->streamedContent();

        $this->assertStringContainsString($inside->name, $content);
        $this->assertStringNotContainsString($outside->name, $content);
    }

    public function test_customer_date_range_rejects_invalid_ranges(): void
    {
        $this->customerContext();

        $response = $this->from(route('customers.index'))->get(route('customers.index', [
            'from_date' => '2026-05-11',
            'to_date' => '2026-05-01',
        ]));

        $response->assertRedirect(route('customers.index'));
        $response->assertSessionHasErrors('to_date');
    }

    private function customerContext(): array
    {
        $organization = TestData::organization();
        $user = TestData::user($organization);
        $this->actingAs($user);

        return [$organization, $user];
    }

    private function createCustomer(int $organizationId, string $name, string $createdAt): Customer
    {
        $customer = Customer::create([
            'organization_id' => $organizationId,
            'name' => $name,
            'phone' => '9' . str_pad((string) random_int(100000000, 999999999), 9, '0', STR_PAD_LEFT),
        ]);

        $customer->forceFill([
            'created_at' => Carbon::parse($createdAt),
            'updated_at' => Carbon::parse($createdAt),
        ])->saveQuietly();

        return $customer->fresh();
    }
}
