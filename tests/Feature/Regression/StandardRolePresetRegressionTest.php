<?php

namespace Tests\Feature\Regression;

use App\Models\Asset;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\DashboardWidgetRegistryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

class StandardRolePresetRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function worker(string $preset, array $special = []): User
    {
        $organization = TestData::organization();
        $configuration = Role::accessPresets()[$preset];
        $role = Role::create([
            'organization_id' => $organization->id, 'name' => $configuration['name'],
            'slug' => $configuration['slug'], 'is_active' => true,
            'permissions' => Role::normalizeExplicitPermissions($configuration['modules'] + [
                '__special' => $special ?: $configuration['special'], '__dashboard' => 'allow',
            ]),
        ]);

        return TestData::user($organization, ['role_id' => $role->id, 'role' => 'super_admin']);
    }

    public function test_seven_presets_are_visible_without_creating_or_changing_roles(): void
    {
        $owner = TestData::user(TestData::organization());
        $before = Role::count();
        $response = $this->actingAs($owner)->get(route('roles.create'))->assertOk()
            ->assertSeeText('Standard roles')->assertSeeText('Accounts / Finance')
            ->assertSee('id="standard-role-preset"', false)
            ->assertSee('data-template-button="service"', false)
            ->assertSee('data-template-button="delivery"', false);
        if ($folder = getenv('PHOS_ROLE_PRESET_RENDER_DIR')) {
            $this->assertTrue(is_dir($folder));
            file_put_contents($folder . '/role-preset-create.html', $response->getContent());
        }
        $this->assertSame($before, Role::count());
        $this->assertSame(['super_admin', 'admin', 'sales', 'service', 'delivery', 'finance', 'custom'], array_keys(Role::accessPresets()));
    }

    public function test_every_non_admin_profile_rejects_income_profit_and_consolidated_reports_even_if_explicitly_granted(): void
    {
        foreach (['sales', 'service', 'delivery', 'custom'] as $preset) {
            $user = $this->worker($preset, array_keys(Role::SPECIAL_PERMISSIONS));
            $role = $user->assignedRole;
            $role->update(['permissions' => array_merge($role->permissions, ['reports' => ['read']])]);
            $user = $user->fresh();
            foreach (['finance.view_revenue', 'finance.view_profit', 'finance.view_payments', 'finance.view_deposits',
                'finance.view_reports', 'dashboard.finance.full', 'vendor_costs.view', 'vendor_reports.view'] as $permission) {
                $this->assertFalse($user->hasPermission($permission), $preset . ': ' . $permission);
            }
            $this->assertFalse($user->canViewFinanceDashboard());
            $this->assertFalse($user->canViewProfitability());
            $this->actingAs($user)->get(route('reports.index'))->assertForbidden();
            $this->get(route('reports.export.csv'))->assertForbidden();
        }
    }

    public function test_finance_can_read_payments_invoices_and_reports_without_writing_or_exporting(): void
    {
        $user = $this->worker('finance');
        $this->assertTrue($user->canViewRecordFinance());
        $this->assertTrue($user->canViewFinance('finance.view_revenue'));
        $this->assertTrue($user->canAccessModule('payments', 'read'));
        $this->assertFalse($user->canAccessModule('payments', 'create'));
        $this->assertFalse($user->hasPermission('payments.create'));
        $this->assertFalse($user->canAccessModule('invoices', 'update'));
        $this->assertFalse($user->canViewProfitability());
        $this->assertTrue($user->canViewDues());
        $this->assertFalse($user->canSeeFinanceSummary());
        $keys = app(DashboardWidgetRegistryService::class)->widgetKeysForUser($user);
        foreach (['kpi_collections_this_month', 'snapshot_collections_today', 'section_finance_summary', 'section_sales_overview'] as $key) {
            $this->assertNotContains($key, $keys);
        }
        $this->actingAs($user)->get(route('reports.index', ['tab' => 'trends']))->assertOk()
            ->assertSee('Finance reports')->assertDontSee('Product Trend')->assertDontSee('Staff incentives');
        $this->get(route('reports.export.csv'))->assertForbidden();
        $this->get(route('reports.staff-incentives', ['download' => 'xlsx']))->assertForbidden();
        $this->get(route('communication-center.index'))->assertForbidden();
        $this->postJson(route('invoices.store'))->assertForbidden();
        $this->get(route('ledger.export.csv'))->assertForbidden();
    }

    public static function duesProfiles(): array
    {
        return [['service']];
    }

    #[DataProvider('duesProfiles')]
    public function test_dashboard_dues_do_not_unlock_billed_collected_or_profit_amounts_on_any_tab(string $preset): void
    {
        $user = $this->worker($preset);
        $customer = Customer::create(['organization_id' => $user->organization_id, 'name' => 'Dues only customer']);
        Invoice::create([
            'organization_id' => $user->organization_id, 'customer_id' => $customer->id,
            'invoice_number' => 'PRESET-DUES', 'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDay()->toDateString(), 'bill_to_name' => $customer->name,
            'subtotal' => 9437.29, 'total_amount' => 9437.29, 'paid_amount' => 1234.56,
            'balance_amount' => 8202.73, 'status' => 'issued', 'payment_status' => 'partial',
        ]);
        $ledger = $this->actingAs($user)->get(route('invoices.index'))->assertOk();
        $stats = $ledger->viewData('invoiceStats');
        $this->assertEquals(8202.73, $stats['outstandingAmount']);
        foreach (['totalBilled', 'collectedToday', 'collectedThisMonth'] as $field) {
            $this->assertEquals(0, $stats[$field], $field);
        }
        $ledger->assertSee('8,202.73');
        $this->assertMatchesRegularExpression('/Largest Invoice<\/span><strong>\s*Restricted\s*<\/strong>/', $ledger->getContent());

        foreach (['overview', 'revenue', 'team', 'operations', 'inventory', 'activity'] as $tab) {
            $response = $this->actingAs($user)->get(route('dashboard', ['dashboard_tab' => $tab]))->assertOk();
            $this->assertEquals(8202.73, $response->viewData('outstandingDueAmount'));
            foreach (['totalBilledAmount', 'grossBilledAmount', 'paymentsReceivedToday', 'paymentsReceivedThisMonth', 'totalRentalValue', 'totalSalesAmount'] as $field) {
                $this->assertEquals(0, $response->viewData($field), $field . ' on ' . $tab);
            }
            $response->assertDontSee('data-dashboard-tab="revenue"', false);
            $this->assertFalse($response->viewData('dashboardVisibility')['finance_widgets']);
        }
    }

    public function test_super_admin_remains_above_admin_and_only_owner_can_grant_it(): void
    {
        $owner = $this->worker('super_admin');
        $this->assertTrue($owner->isSuperAdmin());
        foreach (array_keys(Role::SPECIAL_PERMISSIONS) as $permission) {
            $this->assertTrue($owner->hasPermission($permission), $permission);
        }
        $admin = $this->worker('admin');
        $this->assertFalse($admin->isSuperAdmin());
        $this->actingAs($admin)->get(route('roles.create'))->assertOk()
            ->assertDontSee('data-template-button="super_admin"', false);
        $this->post(route('roles.store'), [
            'name' => 'Forged owner', 'slug' => 'super_admin', 'is_active' => true,
            'permissions' => Role::accessPresets()['super_admin']['modules'],
        ])->assertForbidden();
    }

    public function test_admin_keeps_financial_access_and_delivery_keeps_assigned_work_scope(): void
    {
        $admin = $this->worker('admin');
        $this->assertTrue($admin->canViewFinanceDashboard());
        $this->assertTrue($admin->hasPermission('finance.view_profit'));
        $this->assertTrue($admin->canAccessGeneralReports());
        $this->actingAs($admin)->get(route('reports.index', ['tab' => 'trends']))->assertOk()
            ->assertDontSee('Product Trend')->assertDontSee('EBITDA')
            ->assertViewHas('reportGroups', fn ($groups) => !isset($groups['product_trends']) && !isset($groups['profitability_reports']['estimated_ebitda']));
        $owner = $this->worker('super_admin');
        $this->actingAs($owner)->get(route('reports.index', ['tab' => 'trends']))->assertOk()
            ->assertSee('Product Trends')->assertSee('Estimated EBITDA');
        $delivery = $this->worker('delivery');
        $this->assertSame('assigned', $delivery->scopeType('deliveries'));
        $this->assertFalse($delivery->canAccessModule('users', 'update'));
        $this->assertFalse($delivery->canViewDues());
        $this->actingAs($delivery)->get(route('deliveries.assets.create'))->assertOk()->assertSee('Add an asset');
    }

    public function test_delivery_asset_entry_reuses_stock_creation_and_restricts_sensitive_fields(): void
    {
        $delivery = $this->worker('delivery');
        $product = Product::create([
            'organization_id' => $delivery->organization_id, 'name' => 'Delivery stock',
            'product_type' => Product::TYPE_RENTABLE, 'stock_mode' => Product::STOCK_MODE_TRACKED_RENTAL,
            'total_quantity' => 0, 'available_quantity' => 0, 'price_per_day' => 0, 'rental_price' => 0, 'sale_price' => 0,
        ]);
        $warehouse = Warehouse::create([
            'organization_id' => $delivery->organization_id, 'name' => 'Delivery warehouse', 'code' => 'DLV', 'is_active' => true,
        ]);
        $this->actingAs($delivery)->post(route('deliveries.assets.store'), [
            'warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'asset_stage' => Asset::STAGE_RENTAL_STOCK,
            'serial_number' => 'DLV-123', 'purchase_cost' => 200, 'asset_status' => 'rented',
        ])->assertSessionHasNoErrors()->assertRedirect(route('deliveries.assets.create'));
        $this->assertDatabaseHas('assets', [
            'product_id' => $product->id, 'warehouse_id' => $warehouse->id, 'serial_number' => 'DLV-123',
            'asset_status' => Asset::STATUS_AVAILABLE, 'purchase_cost' => null,
        ]);
        $this->getJson(route('assets.edit', Asset::sole()))->assertForbidden();
    }
}
