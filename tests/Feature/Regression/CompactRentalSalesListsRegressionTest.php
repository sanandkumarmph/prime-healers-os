<?php

namespace Tests\Feature\Regression;

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

class CompactRentalSalesListsRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_compact_lists_preserve_filter_values_record_actions_and_query_links(): void
    {
        [$organization, $customer, $rental, $sale] = $this->records();
        $this->actingAs(TestData::user($organization));
        $rentalBefore = $rental->fresh()->getRawOriginal();
        $saleBefore = $sale->fresh()->getRawOriginal();
        $range = [
            'search' => 'Compact list customer',
            'from_date' => now()->subDays(10)->toDateString(),
            'to_date' => now()->addDays(10)->toDateString(),
            'sort_by' => 'amount_desc',
        ];

        $rentals = $this->get(route('rentals.index', $range))->assertOk();
        $rentals->assertSee('data-phos-compact-page="rentals"', false);
        $rentalDom = $this->dom($rentals->getContent());
        $this->assertSame(1, $rentalDom->query('//details[@id="rentals-list-filters" and @data-phos-list-filter and not(@open)]')->length);
        foreach (['search', 'status', 'delivery_status', 'pickup_status', 'dispatch_warehouse_id', 'city', 'from_date', 'to_date', 'vendor_id', 'referred_by', 'sort_by'] as $field) {
            $this->assertSame(1, $rentalDom->query('//details[@id="rentals-list-filters"]//form//*[@name="' . $field . '"]')->length, $field);
        }
        $this->assertSame($range['search'], $rentalDom->evaluate('string(//*[@id="smart_search"]/@value)'));
        $this->assertSame($range['from_date'], $rentalDom->evaluate('string(//*[@id="smart_from_date"]/@value)'));
        $this->assertSame($range['to_date'], $rentalDom->evaluate('string(//*[@id="smart_to_date"]/@value)'));
        $this->assertSame('amount_desc', $rentalDom->evaluate('string(//*[@id="smart_sort_by"]/option[@selected]/@value)'));
        $this->assertLink($rentalDom, route('rentals.export.csv', $range));
        $this->assertLink($rentalDom, route('rentals.index', array_merge($range, ['filter' => 'overdue'])));
        $this->assertLink($rentalDom, route('rentals.show', $rental));
        $this->assertLink($rentalDom, route('rentals.edit', $rental));
        $this->assertPostForm($rentalDom, route('rentals.cancel', $rental), 'PUT');
        $this->assertPostForm($rentalDom, route('rentals.destroy', $rental), 'DELETE');
        $this->assertPostForm($rentalDom, route('rentals.quick-renew', $rental));

        $salesQuery = array_merge($range, ['customer_id' => $customer->id, 'payment_status' => 'pending']);
        $sales = $this->get(route('sales.index', $salesQuery))->assertOk();
        $sales->assertSee('data-phos-compact-page="sales"', false);
        $salesDom = $this->dom($sales->getContent());
        $this->assertSame(1, $salesDom->query('//details[@id="sales-list-filters" and @data-phos-list-filter and not(@open)]')->length);
        foreach (['search', 'customer_id', 'payment_status', 'fulfilment_source', 'from_date', 'to_date', 'sort_by'] as $field) {
            $this->assertSame(1, $salesDom->query('//*[@id="salesFilterPanel"]//form//*[@name="' . $field . '"]')->length, $field);
        }
        $this->assertSame($range['search'], $salesDom->evaluate('string(//*[@id="salesFilterPanel"]//*[@name="search"]/@value)'));
        $this->assertSame((string) $customer->id, $salesDom->evaluate('string(//*[@id="customer_id"]/option[@selected]/@value)'));
        $this->assertLink($salesDom, route('sales.show', $sale));
        $this->assertLink($salesDom, route('sales.edit', $sale));
        $this->assertPostForm($salesDom, route('sales.invoice', $sale));
        $this->assertPostForm($salesDom, route('sales.markPaid', $sale));
        $this->assertPostForm($salesDom, route('sales.void', $sale), 'PUT');
        $this->assertPostForm($salesDom, route('sales.destroy', $sale), 'DELETE');

        $this->exportFixture('rentals.html', $rentals->getContent(), 'rentals', 'super_admin', [
            'records' => $rentals->viewData('rentals')->total(),
            'active_filters' => $range,
        ]);
        $this->exportFixture('sales.html', $sales->getContent(), 'sales', 'super_admin', [
            'records' => $sales->viewData('sales')->total(),
            'active_filters' => $salesQuery,
        ]);

        $this->assertSame($rentalBefore, $rental->fresh()->getRawOriginal());
        $this->assertSame($saleBefore, $sale->fresh()->getRawOriginal());
    }

    public function test_read_only_list_permissions_do_not_expose_create_edit_or_mutating_row_actions(): void
    {
        [$organization, , $rental, $sale] = $this->records();
        $role = Role::create([
            'organization_id' => $organization->id,
            'name' => 'Compact list reader',
            'slug' => 'compact_list_reader',
            'permissions' => Role::normalizePermissions(['rentals' => ['read'], 'sales' => ['read']]),
            'is_system' => false,
            'is_active' => true,
        ]);
        $this->actingAs(TestData::user($organization, ['role' => $role->slug, 'role_id' => $role->id]));

        foreach ([['rentals', $rental], ['sales', $sale]] as [$module, $record]) {
            $response = $this->get(route($module . '.index'))->assertOk();
            $dom = $this->dom($response->getContent());
            $this->assertLink($dom, route($module . '.show', $record));
            $this->assertSame(0, $dom->query('//a[@href="' . route($module . '.create') . '"]')->length);
            $this->assertSame(0, $dom->query('//a[@href="' . route($module . '.edit', $record) . '"]')->length);
            $this->assertSame(0, $dom->query('//*[contains(concat(" ", normalize-space(@class), " "), " rn-table-shell ")]//form[translate(@method, "post", "POST")="POST"]')->length);
            $this->exportFixture($module . '-reader.html', $response->getContent(), $module, 'compact_list_reader', [
                'records' => $response->viewData($module)->total(),
                'active_filters' => [],
            ]);
        }
    }

    private function records(): array
    {
        $organization = TestData::organization();
        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Compact list customer',
            'phone' => '9876543210',
            'city' => 'Bengaluru',
        ]);
        $product = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Compact list equipment',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'available_quantity' => 10,
            'total_quantity' => 10,
            'price_per_day' => 500,
            'rental_price' => 500,
            'sale_price' => 15000,
        ]);
        $rental = Rental::create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'customer_name' => $customer->name,
            'phone' => $customer->phone,
            'quantity' => 1,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
            'rental_amount' => 500,
            'deposit_amount' => 250,
            'transport_amount' => 0,
            'other_amount' => 0,
            'status' => 'active',
        ]);
        Delivery::create([
            'organization_id' => $organization->id,
            'rental_id' => $rental->id,
            'type' => 'delivery',
            'status' => 'completed',
            'scheduled_at' => now()->subDay(),
            'completed_at' => now()->subDay(),
        ]);
        $sale = Sale::create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 15000,
            'discount_amount' => 0,
            'shipping_charges' => 0,
            'tax_percentage' => 0,
            'tax_calculation_mode' => 'exclusive',
            'sale_date' => now()->toDateString(),
            'sale_amount' => 15000,
            'payment_status' => 'pending',
        ]);

        return [$organization, $customer, $rental, $sale];
    }

    private function dom(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);

        return new DOMXPath($document);
    }

    private function assertLink(DOMXPath $dom, string $url): void
    {
        $this->assertGreaterThan(0, $dom->query('//a[@href="' . $url . '"]')->length, $url);
    }

    private function assertPostForm(DOMXPath $dom, string $url, ?string $method = null): void
    {
        $selector = '//form[@action="' . $url . '" and translate(@method, "post", "POST")="POST"]';
        $this->assertGreaterThan(0, $dom->query($selector . '//input[@name="_token"]')->length, $url);
        if ($method !== null) {
            $this->assertGreaterThan(0, $dom->query($selector . '//input[@name="_method" and @value="' . $method . '"]')->length, $url);
        }
    }

    private function exportFixture(string $filename, string $html, string $page, string $role, array $metrics): void
    {
        $directory = getenv('PHOS_COMPACT_PREVIEW_DIR');
        if (! is_string($directory) || $directory === '') {
            return;
        }

        if (! is_dir($directory)) {
            $this->assertTrue(mkdir($directory, 0777, true));
        }
        $this->assertNotFalse(file_put_contents($directory . DIRECTORY_SEPARATOR . $filename, $html));
        $manifestPath = $directory . DIRECTORY_SEPARATOR . 'commercial-fixtures.json';
        $manifest = is_file($manifestPath) ? json_decode(file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR) : [];
        $manifest = array_values(array_filter($manifest, fn (array $entry) => $entry['html'] !== $filename));
        $manifest[] = array_merge([
            'synthetic' => true,
            'html' => $filename,
            'page' => $page,
            'role' => $role,
            'route' => $page . '.index',
        ], $metrics);
        $this->assertNotFalse(file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)));
    }
}
