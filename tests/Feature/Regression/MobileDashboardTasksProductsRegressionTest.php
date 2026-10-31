<?php

namespace Tests\Feature\Regression;

use App\Models\Customer;
use App\Models\Delivery;
use App\Models\Product;
use App\Models\Rental;
use App\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class MobileDashboardTasksProductsRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $organization = TestData::organization(['timezone' => 'Asia/Kolkata']);
        $owner = TestData::user($organization, ['name' => 'Alexandria Customer Service and Delivery Supervisor']);
        $this->actingAs($owner);
        $customer = Customer::create(['organization_id' => $organization->id,
            'name' => 'Synthetic customer with a long household and delivery contact name',
            'phone' => '9876543210', 'city' => 'Bengaluru',
            'address' => 'Apartment 123, A long synthetic building and neighbourhood address']);
        $product = Product::create(['organization_id' => $organization->id,
            'name' => 'Portable oxygen concentrator with extended accessories and carrying case',
            'product_type' => Product::TYPE_RENTABLE, 'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'available_quantity' => 10, 'total_quantity' => 10,
            'price_per_day' => 500, 'rental_price' => 500, 'sale_price' => 15000]);
        $rental = Rental::create(['organization_id' => $organization->id, 'customer_id' => $customer->id,
            'product_id' => $product->id, 'customer_name' => $customer->name, 'phone' => $customer->phone,
            'quantity' => 1, 'start_date' => now()->subDays(4)->toDateString(), 'end_date' => now()->addDays(7)->toDateString(),
            'rental_amount' => 1500, 'deposit_amount' => 500, 'transport_amount' => 100,
            'status' => 'active', 'created_by_user_id' => $owner->id]);
        foreach (range(1, 7) as $index) {
            $sale = Sale::create(['organization_id' => $organization->id, 'customer_id' => $customer->id,
                'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 1500, 'sale_amount' => 1500,
                'sale_date' => now()->toDateString(), 'payment_status' => 'pending']);
            Delivery::create(['organization_id' => $organization->id, 'sale_id' => $sale->id,
                'type' => 'delivery', 'status' => 'pending',
                'assigned_user_id' => $owner->id, 'scheduled_at' => now()->subDays(2)->addMinutes($index)]);
        }

        return [$customer, $product, $rental];
    }

    public function test_mobile_dashboard_task_and_product_pages_keep_records_and_form_actions(): void
    {
        [$customer, $product, $rental] = $this->fixture();
        $before = [$customer->fresh()->getRawOriginal(), $rental->fresh()->getRawOriginal(),
            Sale::orderBy('id')->get()->map->getRawOriginal()->all(),
            Delivery::orderBy('id')->get()->map->getRawOriginal()->all(),
            $product->fresh()->only(['name', 'product_type', 'stock_mode', 'price_per_day', 'sale_price'])];
        $pages = [
            ['products', route('products.index'), 'products', 'index'],
            ['products-create', route('products.create'), 'products', 'create'],
            ['products-edit', route('products.edit', $product), 'products', 'edit'],
            ['products-show', route('products.show', $product), 'products', 'show'],
            ['tasks-attention', route('deliveries.index', ['tab' => 'all']), 'deliveries', 'index'],
        ];
        foreach (['overview', 'team', 'operations', 'revenue', 'inventory', 'activity'] as $tab) {
            $pages[] = ['dashboard-' . $tab, route('dashboard', ['dashboard_tab' => $tab]), 'dashboard', 'index'];
        }
        $manifest = [];
        $folder = getenv('PHOS_MOBILE_LAYOUT_RENDER_DIR');
        if ($folder && !is_dir($folder)) mkdir($folder, 0777, true);
        foreach ($pages as [$page, $url, $module, $screen]) {
            $response = $this->get($url)->assertOk();
            if ($page === 'tasks-attention') {
                $this->assertNotEmpty($response->viewData('overdueTasks'));
                $response->assertSeeText('Alexandria Customer Service and Delivery Supervisor');
            }
            if (in_array($screen, ['create', 'edit'], true)) {
                $document = new \DOMDocument;
                @$document->loadHTML($response->getContent());
                $action = route($screen === 'create' ? 'products.store' : 'products.update', $screen === 'create' ? [] : $product);
                $xpath = new \DOMXPath($document);
                $this->assertSame(1, $xpath->query('//form[@action="' . $action . '"]//input[@name="_token"]')->length);
            }
            $file = $page . '.html';
            $manifest[] = compact('page', 'url', 'module', 'screen', 'file');
            if ($folder) file_put_contents($folder . '/' . $file, $response->getContent());
        }
        $this->assertSame($before, [$customer->fresh()->getRawOriginal(), $rental->fresh()->getRawOriginal(),
            Sale::orderBy('id')->get()->map->getRawOriginal()->all(),
            Delivery::orderBy('id')->get()->map->getRawOriginal()->all(),
            $product->fresh()->only(['name', 'product_type', 'stock_mode', 'price_per_day', 'sale_price'])]);
        if ($folder) file_put_contents($folder . '/mobile-fixtures.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }

    public function test_product_pages_render_readable_mobile_labels_instead_of_corrupted_text(): void
    {
        [, $product] = $this->fixture();
        foreach ([route('products.index'), route('products.create'), route('products.edit', $product), route('products.show', $product)] as $url) {
            $response = $this->get($url)->assertOk();
            foreach (['â‚¹', 'âœ“', 'âŒ„', 'âŒƒ', 'â–¡', 'â–£', 'â—‡', 'â—·', 'â€”'] as $broken) {
                $response->assertDontSee($broken, false);
            }
        }
    }
}
