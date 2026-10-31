<?php

namespace Tests\Feature\Regression;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Services\Metrics\SalesMetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestData;
use Tests\TestCase;

class SalesDuesPrivacyRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function fixture(string $profile = 'finance', bool $dues = true): array
    {
        $org = TestData::organization();
        $preset = Role::accessPresets()[$profile];
        $role = Role::create([
            'organization_id' => $org->id, 'name' => $preset['name'], 'slug' => $preset['slug'], 'is_active' => true,
            'permissions' => Role::normalizeExplicitPermissions([
                'sales' => ['read'], 'invoices' => ['read'],
                '__special' => $dues ? ['finance.view_dues'] : [],
            ]),
        ]);
        $user = TestData::user($org, ['role_id' => $role->id, 'role' => 'super_admin']);
        $customer = Customer::create(['organization_id' => $org->id, 'name' => 'Sales dues customer']);
        $product = Product::create(['organization_id' => $org->id, 'name' => 'Sales dues product',
            'product_type' => Product::TYPE_SELLABLE, 'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'is_sellable' => true, 'available_quantity' => 5, 'total_quantity' => 5, 'sale_price' => 100, 'price_per_day' => 0]);
        $sale = Sale::create(['organization_id' => $org->id, 'customer_id' => $customer->id,
            'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100, 'sale_amount' => 100,
            'sale_date' => now()->toDateString(), 'payment_status' => 'partial']);
        $invoice = Invoice::create(['organization_id' => $org->id, 'customer_id' => $customer->id,
            'sale_id' => $sale->id, 'invoice_number' => 'SALES-DUES-TEST', 'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDay()->toDateString(), 'bill_to_name' => $customer->name,
            'subtotal' => 100, 'total_amount' => 100, 'paid_amount' => 26.85, 'balance_amount' => 73.15,
            'status' => 'issued', 'payment_status' => 'partial']);
        // Two item links plus the direct sale link must still count this invoice once.
        foreach ([60, 40] as $amount) {
            $invoice->items()->create(['product_id' => $product->id, 'source_type' => 'sale', 'source_id' => $sale->id,
                'description' => 'Linked sale', 'quantity' => 1, 'rate' => $amount, 'taxable_amount' => $amount, 'line_total' => $amount]);
        }
        Sale::create(['organization_id' => $org->id, 'customer_id' => $customer->id,
            'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 600, 'sale_amount' => 600,
            'sale_date' => now()->toDateString(), 'payment_status' => 'pending']);

        return [$user, $sale, $invoice];
    }

    public static function duesProfiles(): array
    {
        return [['sales'], ['finance'], ['custom']];
    }

    #[DataProvider('duesProfiles')]
    public function test_permitted_sales_dues_are_real_without_unlocking_revenue(string $profile): void
    {
        [$user] = $this->fixture($profile);
        $response = $this->actingAs($user)->get(route('sales.index'))->assertOk();
        $this->assertEquals(73.15, $response->viewData('outstandingInvoiceAmount'));
        $this->assertSame(1, $response->viewData('outstandingInvoiceCount'));
        foreach (['totalSalesAmount', 'totalSaleValue', 'totalTransportationAmount', 'paidSalesAmount',
            'pendingSalesAmount', 'totalPendingSalesAmount', 'unbilledSalesAmount'] as $field) {
            $this->assertEquals(0, $response->viewData($field), $field);
        }
        $node = $this->dueNode($response->getContent());
        $this->assertStringContainsString('73.15', $node->textContent);
        $this->assertSame(route('invoices.index', ['status' => 'open']), $node->getAttribute('href'));
        $this->assertFalse($user->canViewFinanceDashboard());
    }

    public function test_denied_dues_are_restricted_instead_of_a_financial_zero(): void
    {
        [$user] = $this->fixture('finance', false);
        $response = $this->actingAs($user)->get(route('sales.index'))->assertOk();
        $this->assertEquals(0, $response->viewData('outstandingInvoiceAmount'));
        $node = $this->dueNode($response->getContent());
        $this->assertStringContainsString('Restricted', $node->textContent);
        $this->assertStringNotContainsString('0.00', $node->textContent);
        $this->assertFalse($node->hasAttribute('href'));
        $this->assertSame('true', $node->getAttribute('aria-disabled'));
    }

    public function test_a_permitted_zero_balance_is_displayed_as_zero(): void
    {
        [$user, , $invoice] = $this->fixture();
        $invoice->update(['paid_amount' => 100, 'balance_amount' => 0, 'payment_status' => 'paid']);
        $response = $this->actingAs($user)->get(route('sales.index'))->assertOk();
        $this->assertEquals(0, $response->viewData('outstandingInvoiceAmount'));
        $node = $this->dueNode($response->getContent());
        $this->assertStringContainsString('0.00', $node->textContent);
        $this->assertStringNotContainsString('Restricted', $node->textContent);
    }

    public function test_admin_revenue_access_does_not_show_a_false_zero_when_dues_are_denied(): void
    {
        [$user] = $this->fixture('admin', false);
        $role = $user->assignedRole;
        $role->update(['permissions' => array_merge($role->permissions, ['__special' => ['finance.view_revenue']])]);
        $response = $this->actingAs($user->fresh())->get(route('sales.index'))->assertOk();
        $this->assertEquals(700, $response->viewData('totalSalesAmount'));
        $this->assertEquals(26.85, $response->viewData('paidSalesAmount'));
        $this->assertEquals(0, $response->viewData('outstandingInvoiceAmount'));
        $this->assertStringContainsString('Restricted', $this->dueNode($response->getContent())->textContent);
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $desktop = (new \DOMXPath($document))->query('//a[span[normalize-space(.)="Outstanding Sales Invoices"]]/strong')->item(0);
        $this->assertNotNull($desktop);
        $this->assertStringContainsString('Restricted', $desktop->textContent);
    }

    public function test_metrics_keep_backwards_compatible_revenue_arithmetic_and_separate_dues(): void
    {
        [$user, $sale] = $this->fixture();
        $query = Sale::where('organization_id', $user->organization_id);
        $metrics = app(SalesMetricsService::class);
        $legacy = $metrics->summary(clone $query, $user->organization_id, true);
        $this->assertEquals(700, $legacy['totalSalesAmount']);
        $this->assertEquals(73.15, $legacy['outstandingInvoiceAmount']);
        $this->assertEquals(673.15, $legacy['totalPendingSalesAmount']);
        $this->assertEquals(26.85, $legacy['paidSalesAmount']);
        $withoutDues = $metrics->summary(clone $query, $user->organization_id, true, true, false);
        $this->assertEquals(0, $withoutDues['outstandingInvoiceAmount']);
        $this->assertEquals(673.15, $withoutDues['totalPendingSalesAmount']);
        $this->assertEquals(26.85, $withoutDues['paidSalesAmount']);
        $hidden = $metrics->summary(clone $query, $user->organization_id, true, false);
        $this->assertEquals(0, $hidden['outstandingInvoiceAmount']);
        $this->assertEquals(0, $hidden['totalPendingSalesAmount']);
        // Exercise item-only linkage and foreign-tenant exclusion too.
        $other = TestData::organization();
        Invoice::create(['organization_id' => $other->id, 'sale_id' => $sale->id,
            'invoice_number' => 'FOREIGN-SALES-DUES', 'invoice_date' => now()->toDateString(),
            'bill_to_name' => 'Foreign', 'total_amount' => 999, 'balance_amount' => 999,
            'payment_status' => 'unpaid']);
        foreach ([false, true] as $directSaleLink) {
            $duesOnly = $metrics->summary(clone $query, $user->organization_id, $directSaleLink, false, true);
            $this->assertEquals(73.15, $duesOnly['outstandingInvoiceAmount']);
            $this->assertSame(1, $duesOnly['outstandingInvoiceCount']);
            $this->assertEquals(0, $duesOnly['unbilledSalesAmount']);
        }
    }

    private function dueNode(string $html): \DOMElement
    {
        $document = new \DOMDocument;
        @$document->loadHTML($html);
        $node = (new \DOMXPath($document))->query('//*[@data-sales-dues-summary]')->item(0);
        $this->assertInstanceOf(\DOMElement::class, $node);

        return $node;
    }
}
