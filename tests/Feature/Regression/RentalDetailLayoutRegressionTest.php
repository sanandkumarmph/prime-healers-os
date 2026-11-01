<?php

namespace Tests\Feature\Regression;

use App\Models\{ActivityLog, Customer, Product, Rental};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class RentalDetailLayoutRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_rental_overview_keeps_activity_times_customer_data_and_links_without_writing_records(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 1)->setTime(17, 18, 19));
        $organization = TestData::organization();
        $owner = TestData::user($organization);
        $this->actingAs($owner);
        $customer = Customer::create(['organization_id' => $organization->id,
            'name' => 'Synthetic customer with a long household contact name',
            'phone' => '9876543210', 'city' => 'Bengaluru', 'state' => 'Karnataka']);
        $product = Product::create(['organization_id' => $organization->id, 'name' => 'Synthetic oxygen concentrator',
            'product_type' => Product::TYPE_RENTABLE, 'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'available_quantity' => 5, 'total_quantity' => 5, 'price_per_day' => 100]);
        $rental = Rental::create(['organization_id' => $organization->id, 'customer_id' => $customer->id,
            'product_id' => $product->id, 'quantity' => 1, 'customer_name' => $customer->name,
            'phone' => $customer->phone, 'start_date' => '2026-10-01', 'end_date' => '2026-11-04',
            'rental_amount' => 2000, 'status' => 'active']);
        foreach (['Partial delivery recorded.', 'Rental renewal and accessories confirmed.',
            'EquipmentCheckedAndAcceptedByCustomerWithCaregiver', 'Customer note saved.'] as $description) {
            $log = ActivityLog::create(['organization_id' => $organization->id, 'user_id' => $owner->id,
                'rental_id' => $rental->id, 'subject_type' => Rental::class, 'subject_id' => $rental->id,
                'action' => 'rental.updated', 'description' => $description]);
            $log->forceFill(['created_at' => '2026-11-01 17:18:19'])->save();
        }
        $before = [$rental->fresh()->getRawOriginal(), ActivityLog::orderBy('id')->get()->map->getRawOriginal()->all()];
        $response = $this->get(route('rentals.show', $rental))->assertOk()
            ->assertSeeText($customer->name)->assertSeeText('Partial delivery recorded.')
            ->assertSee('05:18:19 PM IST')->assertSee('datetime="2026-11-01T17:18:19+05:30"', false)
            ->assertSee('href="#rental-products-section"', false)->assertSee('href="#rental-timeline-workspace"', false)
            ->assertSee('href="' . route('customers.show', $customer) . '"', false);
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);
        $this->assertSame(3, $xpath->query('//*[@id="rental-overview-section"]/article')->length);
        $this->assertSame(4, $xpath->query('//*[@id="rental-overview-section"]//div[@class="rental-activity-mini-copy"]/time')->length);
        $this->assertSame($before, [$rental->fresh()->getRawOriginal(), ActivityLog::orderBy('id')->get()->map->getRawOriginal()->all()]);
        $folder = getenv('PHOS_RENTAL_DETAIL_RENDER_DIR');
        if ($folder) {
            if (!is_dir($folder)) mkdir($folder, 0777, true);
            file_put_contents($folder . '/rental.html', $response->getContent());
            file_put_contents($folder . '/fixture.json', json_encode(['url' => route('rentals.show', $rental)], JSON_THROW_ON_ERROR));
        }
        ActivityLog::query()->delete();
        $empty = $this->get(route('rentals.show', $rental))->assertOk()->assertSeeText('No recent activity');
        if ($folder) file_put_contents($folder . '/rental-empty.html', $empty->getContent());
        $this->travelBack();
    }
}
