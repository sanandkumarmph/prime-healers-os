<?php

namespace Tests\Feature\Regression;

use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestData;
use Tests\TestCase;

class ListFilterPanelUxRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $organization = TestData::organization();
        $this->actingAs(TestData::user($organization));
    }

    #[DataProvider('listPageProvider')]
    public function test_primary_search_is_visible_by_default(string $routeName, array $query, string $placeholder, string $panelKey): void
    {
        $this->get(route($routeName, $query))
            ->assertOk()
            ->assertSee($placeholder)
            ->assertSee('data-filter-panel')
            ->assertSee('data-filter-panel-key="' . $panelKey . '"', false)
            ->assertSee('data-filter-clear="' . $panelKey . '"', false);
    }

    #[DataProvider('activeFilterProvider')]
    public function test_filter_panel_stays_open_when_filters_are_active(string $routeName, array $query, string $panelKey): void
    {
        $response = $this->get(route($routeName, $query));

        $response->assertOk()
            ->assertSee('data-filter-panel-key="' . $panelKey . '"', false)
            ->assertSee('data-filter-active="true"', false)
            ->assertSee('Filters Active');

        $this->assertMatchesRegularExpression(
            '/<details[^>]*data-filter-panel-key="' . preg_quote($panelKey, '/') . '"[^>]*open[^>]*>/',
            $response->getContent()
        );
    }

    public static function listPageProvider(): array
    {
        return [
            'products' => ['products.index', [], 'Search product name, brand, model, SKU, or product code', 'products-index'],
            'rentals' => ['rentals.index', [], 'Search customer, phone, product, asset serial', 'rentals-index'],
            'sales' => ['sales.index', [], 'Search customer, phone, WhatsApp, product, notes', 'sales-index'],
            'customers' => ['customers.index', [], 'Search customer, phone, WhatsApp, city, state', 'customers-index'],
            'invoices' => ['invoices.index', [], 'Search invoice, customer, phone, GSTIN, or reference', 'invoices-index'],
            'deliveries' => ['deliveries.index', [], 'Search customer, mobile, product, sale order', 'deliveries-index'],
            'assets' => ['assets.index', [], 'Search serial number, barcode, or product', 'assets-index'],
        ];
    }

    public static function activeFilterProvider(): array
    {
        return [
            'products_search' => ['products.index', ['search' => 'oxygen'], 'products-index'],
            'rentals_status' => ['rentals.index', ['status' => 'active'], 'rentals-index'],
            'sales_search' => ['sales.index', ['search' => 'amit'], 'sales-index'],
            'customers_city' => ['customers.index', ['city' => 'Bengaluru'], 'customers-index'],
            'invoices_status' => ['invoices.index', ['status' => 'unpaid'], 'invoices-index'],
            'deliveries_status' => ['deliveries.index', ['status' => 'pending'], 'deliveries-index'],
            'assets_stage' => ['assets.index', ['asset_stage' => 'rental_stock'], 'assets-index'],
        ];
    }
}
