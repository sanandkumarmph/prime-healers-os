<?php

namespace Tests\Feature\Regression;

use App\Models\BusinessPartner;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\Product;
use App\Models\Rental;
use App\Models\Role;
use App\Models\Sale;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class MobileEntryFlowsRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_entry_flow_pages_preserve_business_records_and_native_forms(): void
    {
        $organization = TestData::organization(['timezone' => 'Asia/Kolkata', 'state' => 'Karnataka']);
        $owner = TestData::user($organization, ['name' => 'Synthetic mobile administrator']);
        $this->actingAs($owner);
        $customer = Customer::create(['organization_id' => $organization->id, 'name' => 'Synthetic mobile customer with a long name', 'phone' => '9876543210', 'city' => 'Bengaluru', 'state' => 'Karnataka', 'address' => '12 Synthetic Preview Street', 'email' => 'mobile-customer@example.test']);
        $product = Product::create(['organization_id' => $organization->id, 'name' => 'Synthetic oxygen concentrator with accessories', 'product_type' => Product::TYPE_RENTABLE, 'stock_mode' => Product::STOCK_MODE_UNTRACKED, 'available_quantity' => 10, 'total_quantity' => 10, 'price_per_day' => 500, 'rental_price' => 500, 'sale_price' => 15000]);
        $rental = Rental::create(['organization_id' => $organization->id, 'customer_id' => $customer->id, 'product_id' => $product->id, 'customer_name' => $customer->name, 'phone' => $customer->phone, 'quantity' => 1, 'start_date' => now()->subDay()->toDateString(), 'end_date' => now()->addDays(7)->toDateString(), 'rental_amount' => 1500, 'deposit_amount' => 500, 'transport_amount' => 100, 'other_amount' => 0, 'status' => 'active', 'created_by_user_id' => $owner->id]);
        $sale = Sale::create(['organization_id' => $organization->id, 'customer_id' => $customer->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 15000, 'sale_amount' => 15000, 'sale_date' => now()->toDateString(), 'payment_status' => 'pending']);
        $partner = BusinessPartner::create(['organization_id' => $organization->id, 'business_name' => 'Synthetic business partner with a long name', 'contact_person' => 'Synthetic coordinator', 'phone' => '9876543211', 'city' => 'Bengaluru', 'state' => 'Karnataka', 'status' => 'active']);
        $role = Role::create(['organization_id' => $organization->id, 'name' => 'Synthetic mobile reader', 'slug' => 'synthetic_mobile_reader', 'permissions' => Role::normalizePermissions(['customers' => ['read']]), 'is_system' => false, 'is_active' => true]);
        $staff = TestData::user($organization, ['name' => 'Synthetic mobile team member', 'role' => $role->slug, 'role_id' => $role->id]);
        Delivery::create(['organization_id' => $organization->id, 'sale_id' => $sale->id, 'type' => 'delivery', 'status' => 'pending', 'assigned_user_id' => $owner->id, 'scheduled_at' => now()->subDays(2)]);
        $records = ['rentals' => $rental, 'sales' => $sale, 'customers' => $customer, 'business-partners' => $partner, 'users' => $staff, 'roles' => $role, 'products' => $product];
        $before = collect([$rental, $sale, $customer, $partner])->mapWithKeys(fn ($model) => [get_class($model) => $model->fresh()->getRawOriginal()]);
        $manifest = [];
        foreach ($records as $module => $record) {
            foreach (['create', 'edit', 'show'] as $screen) {
                $url = route($module . '.' . $screen, $screen === 'create' ? [] : $record);
                $response = $this->get($url)->assertOk();
                if ($module === 'products') {
                    foreach (['â‚¹', 'âœ“', 'âŒ„', 'â–¡', 'â–£', 'â—‡', 'â—·', 'â€”'] as $brokenText) $response->assertDontSee($brokenText, false);
                    if ($screen !== 'show') $response->assertSee('✓ Rental')->assertSee('Pricing &amp; Tax', false);
                }
                if ($screen !== 'show') {
                    $document = new DOMDocument();
                    @$document->loadHTML($response->getContent());
                    $dom = new DOMXPath($document);
                    $action = route($module . ($screen === 'create' ? '.store' : '.update'), $screen === 'create' ? [] : $record);
                    $this->assertSame(1, $dom->query('//form[@action="' . $action . '"]//input[@name="_token"]')->length, $module . ' ' . $screen);
                    if ($module === 'rentals') {
                        $this->assertSame('rentalCreateForm', $dom->evaluate('string(//form[@action="' . $action . '"]/@id)'), 'Both page variants must own their external save controls.');
                        $this->assertSame(2, $dom->query('//button[@type="submit" and @form="rentalCreateForm" and @data-save-rental]')->length);
                    }
                }
                $file = $module . '-' . $screen . '.html';
                $manifest[] = ['page' => $module . '-' . $screen, 'file' => $file, 'url' => $url, 'module' => $module, 'screen' => $screen, 'synthetic' => true];
                $directory = getenv('PHOS_ACCESS_PREVIEW_DIR');
                if ($directory) {
                    if (!is_dir($directory)) mkdir($directory, 0777, true);
                    file_put_contents($directory . DIRECTORY_SEPARATOR . $file, $response->getContent());
                }
            }
        }
        foreach (['products' => route('products.index'), 'tasks-attention' => route('deliveries.index', ['tab' => 'all'])] + collect(['overview', 'team', 'operations', 'revenue', 'inventory', 'activity'])->mapWithKeys(fn ($tab) => ['dashboard-' . $tab => route('dashboard', ['dashboard_tab' => $tab])])->all() as $page => $url) {
            $response = $this->get($url)->assertOk();
            if ($page === 'tasks-attention') $this->assertNotEmpty($response->viewData('overdueTasks'), 'The synthetic attention section must contain a real overdue task.');
            $file = $page . '.html';
            $manifest[] = ['page' => $page, 'file' => $file, 'url' => $url, 'module' => $page, 'screen' => 'index', 'synthetic' => true];
            if ($directory = getenv('PHOS_ACCESS_PREVIEW_DIR')) file_put_contents($directory . DIRECTORY_SEPARATOR . $file, $response->getContent());
        }
        foreach ([$rental, $sale, $customer, $partner] as $record) $this->assertSame($before[get_class($record)], $record->fresh()->getRawOriginal());
        if ($directory = getenv('PHOS_ACCESS_PREVIEW_DIR')) file_put_contents($directory . DIRECTORY_SEPARATOR . 'mobile-fixtures.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
}
