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
    public function test_primary_search_remains_available(string $routeName, array $query, string $placeholder, string $panelKey): void
    {
        $this->get(route($routeName, $query))
            ->assertOk()
            ->assertSee($placeholder)
            ->assertSee('data-filter-panel')
            ->assertSee('data-filter-panel-key="' . $panelKey . '"', false)
            ->assertSee('data-filter-clear="' . $panelKey . '"', false);
    }

    #[DataProvider('activeFilterProvider')]
    public function test_active_filters_remain_discoverable_without_forcing_compact_popovers_open(string $routeName, array $query, string $panelKey): void
    {
        $response = $this->get(route($routeName, $query));

        $response->assertOk()
            ->assertSee('data-filter-panel-key="' . $panelKey . '"', false)
            ->assertSee('data-filter-active="true"', false);

        if (in_array($routeName, ['rentals.index', 'sales.index', 'customers.index', 'deliveries.index'], true)) {
            $document = new \DOMDocument();
            $previous = libxml_use_internal_errors(true);
            $document->loadHTML('<?xml encoding="utf-8" ?>'.$response->getContent());
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
            $xpath = new \DOMXPath($document);
            $details = $xpath->query('//details[@data-phos-list-filter]');
            $this->assertCount(1, $details);
            $this->assertFalse($details->item(0)->hasAttribute('open'));
            $this->assertStringContainsString('filters active', $xpath->query('./summary', $details->item(0))->item(0)->getAttribute('aria-label'));
            return;
        }

        $this->assertMatchesRegularExpression(
            '/<details[^>]*data-filter-panel-key="' . preg_quote($panelKey, '/') . '"[^>]*open[^>]*>/',
            $response->getContent()
        );
    }

    public static function listPageProvider(): array
    {
        return [
            'products' => ['products.index', [], 'Search product name, brand, model, SKU, or product code', 'products-index'],
            'rentals' => ['rentals.index', [], 'Search rentals by ID, customer, product', 'rentals-index'],
            'sales' => ['sales.index', [], 'Search customer, phone, WhatsApp, product, notes', 'sales-index'],
            'customers' => ['customers.index', [], 'Search customer, phone, WhatsApp, city, state', 'customers-index'],
            'invoices' => ['invoices.index', [], 'Search invoice, customer, phone, GSTIN, rental, sale, or reference', 'invoices-index'],
            'deliveries' => ['deliveries.index', [], 'Search task, customer or product', 'deliveries-index'],
            'assets' => ['assets.index', [], 'Search by serial, barcode, customer, rental or product', 'assets-index'],
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
