<?php

namespace Tests\Feature\Regression;

use App\Models\Asset;
use App\Models\Customer;
use App\Models\DashboardWidget;
use App\Models\Delivery;
use App\Models\FollowUp;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Rental;
use App\Models\Role;
use App\Models\RoleDashboardWidget;
use App\Models\User;
use App\Models\Warehouse;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Testing\TestResponse;
use Tests\Support\TestData;
use Tests\TestCase;

class TabbedExecutiveDashboardRegressionTest extends TestCase
{
    use RefreshDatabase;

    private const EXECUTIVE_TABS = ['overview', 'team', 'operations', 'revenue', 'inventory', 'activity'];

    public function test_superadmin_has_real_tab_links_and_preserved_executive_sections(): void
    {
        $this->actingAs(TestData::user());

        $response = $this->get(route('dashboard'))->assertOk();
        $xpath = $this->xpath($response);

        $this->assertSame(self::EXECUTIVE_TABS, $this->tabNames($xpath));
        $this->assertActivePanel($xpath, 'overview');

        foreach (['Team Performance', 'Rental Operations Pipeline', 'Revenue Center', 'Inventory Center', 'Activity & Communication Center'] as $section) {
            $response->assertSeeText($section);
        }

        $columns = [];
        foreach ($xpath->query('//*[@id="team-performance"]//thead/tr/th') as $column) {
            $columns[] = strtolower(trim($column->textContent));
        }
        $this->assertCount(13, $columns, 'The full existing team table must survive the tab layout.');
        foreach (['orders', 'rentals', 'sales', 'delivery assigned', 'delivery completed', 'renewal reminders', 'renewals completed', 'invoices generated', 'rental amount', 'sales amount', 'total amount'] as $column) {
            $this->assertContains($column, $columns);
        }
    }

    public function test_server_tab_selection_and_links_preserve_global_and_team_filters(): void
    {
        $this->actingAs(TestData::user());
        $filters = [
            'dashboard_tab' => 'team',
            'from_date' => now()->startOfMonth()->toDateString(),
            'to_date' => now()->toDateString(),
            'city' => 'Bengaluru',
            'team_performance_period' => 'custom_range',
            'team_performance_from_date' => now()->startOfMonth()->toDateString(),
            'team_performance_to_date' => now()->toDateString(),
            'team_performance_city' => 'Bengaluru',
            'team_performance_role' => User::ROLE_SALES,
        ];

        $response = $this->get(route('dashboard', $filters))->assertOk();
        $xpath = $this->xpath($response);
        $this->assertActivePanel($xpath, 'team');

        foreach ($xpath->query('//a[@data-dashboard-tab]') as $link) {
            $query = [];
            parse_str((string) parse_url($link->getAttribute('href'), PHP_URL_QUERY), $query);
            $this->assertSame(parse_url(route('dashboard'), PHP_URL_PATH), parse_url($link->getAttribute('href'), PHP_URL_PATH));
            $this->assertSame($link->getAttribute('data-dashboard-tab'), $query['dashboard_tab'] ?? null);
            foreach ($filters as $key => $value) {
                if ($key !== 'dashboard_tab') {
                    $this->assertSame($value, $query[$key] ?? null, "Tab links must retain {$key} for a normal server navigation.");
                }
            }
        }

        $resetLinks = $xpath->query('//*[@id="team-performance"]//a[normalize-space()="Reset"]');
        $this->assertCount(1, $resetLinks);
        $resetUrl = $resetLinks->item(0)->getAttribute('href');
        $resetFilters = [];
        parse_str((string) parse_url($resetUrl, PHP_URL_QUERY), $resetFilters);
        $this->assertSame('team-performance', parse_url($resetUrl, PHP_URL_FRAGMENT));
        $this->assertSame(array_filter($filters, fn ($key) => !str_starts_with($key, 'team_performance_'), ARRAY_FILTER_USE_KEY), $resetFilters);
    }

    public function test_switching_server_tabs_preserves_filtered_metrics_tenant_scope_and_business_records(): void
    {
        $organization = TestData::organization();
        $owner = TestData::user($organization, ['name' => 'Synthetic Executive Owner']);
        $this->actingAs($owner);
        $rental = $this->rental($organization->id, 'Bengaluru', 'Selected city rental', 1000);
        $this->rental($organization->id, 'Mysuru', 'Other city rental', 9000);
        $otherOrganization = TestData::organization(['name' => 'Unrelated tenant']);
        $this->rental($otherOrganization->id, 'Bengaluru', 'Unrelated tenant customer', 90000);

        $invoice = Invoice::create([
            'organization_id' => $organization->id,
            'customer_id' => $rental->customer_id,
            'rental_id' => $rental->id,
            'invoice_number' => 'TABS-SCOPE-001',
            'invoice_date' => now()->toDateString(),
            'due_date' => now()->subDay()->toDateString(),
            'bill_to_name' => 'Selected city rental',
            'status' => 'unpaid',
            'payment_status' => 'partial',
            'subtotal' => 1000,
            'total_amount' => 1000,
            'paid_amount' => 400,
            'balance_amount' => 600,
        ]);
        Payment::create([
            'organization_id' => $organization->id,
            'customer_id' => $rental->customer_id,
            'rental_id' => $rental->id,
            'invoice_id' => $invoice->id,
            'payment_date' => now()->toDateString(),
            'amount' => 400,
            'payment_method' => 'upi',
        ]);

        $filters = ['city' => 'Bengaluru', 'from_date' => now()->startOfMonth()->toDateString(), 'to_date' => now()->toDateString()];
        $baseline = $this->get(route('dashboard', $filters))->assertOk();
        $keys = ['totalRentals', 'activeRentals', 'pendingDeliveryCount', 'pendingPickupCount', 'paymentsReceivedThisMonth', 'outstandingDueAmount', 'unbilledRenewalCount', 'unpaidRenewalCount'];
        $this->assertSame(1, (int) $baseline->viewData('totalRentals'));
        $this->assertSame(1, (int) $baseline->viewData('activeRentals'));
        $this->assertSame(400.0, (float) $baseline->viewData('paymentsReceivedThisMonth'));
        $before = [
            'rentals' => DB::table('rentals')->count(),
            'invoices' => DB::table('invoices')->count(),
            'payments' => DB::table('payments')->count(),
            'invoice' => $invoice->fresh()->only(['total_amount', 'paid_amount', 'balance_amount', 'status', 'payment_status']),
        ];

        foreach (self::EXECUTIVE_TABS as $tab) {
            $response = $this->get(route('dashboard', $filters + ['dashboard_tab' => $tab]))->assertOk();
            $this->assertActivePanel($this->xpath($response), $tab);
            $this->assertOverviewQueuesMatchMetrics($response);
            foreach ($keys as $key) {
                $this->assertEquals($baseline->viewData($key), $response->viewData($key), "Selecting {$tab} changed {$key}.");
            }
            $response->assertDontSeeText('Unrelated tenant customer');
            $this->exportFixture('dashboard-'.$tab, $response, [
                'role' => 'super_admin',
                'active_tab' => $tab,
                'filters' => $filters,
                'metrics' => collect($keys)->mapWithKeys(fn ($key) => [$key => $response->viewData($key)])->all(),
            ]);
        }

        $this->assertSame($before, [
            'rentals' => DB::table('rentals')->count(),
            'invoices' => DB::table('invoices')->count(),
            'payments' => DB::table('payments')->count(),
            'invoice' => $invoice->fresh()->only(['total_amount', 'paid_amount', 'balance_amount', 'status', 'payment_status']),
        ]);
    }

    public function test_finance_user_cannot_select_or_receive_superadmin_team_content(): void
    {
        $organization = TestData::organization();
        $finance = $this->roleUser($organization, User::ROLE_FINANCE, [
            'invoices' => ['read', 'update'],
            'payments' => ['read', 'create', 'update'],
        ]);
        $response = $this->actingAs($finance)->get(route('dashboard', ['dashboard_tab' => 'team']))->assertOk();
        $xpath = $this->xpath($response);

        $this->assertContains('revenue', $this->tabNames($xpath));
        $this->assertNotContains('team', $this->tabNames($xpath));
        $this->assertSame(0, $xpath->query('//*[@data-dashboard-panel="team"]')->length);
        $response->assertDontSee('id="team-performance"', false);
        $this->assertActivePanel($xpath, 'overview');
        $this->exportFixture('dashboard-finance', $response, ['role' => 'finance', 'active_tab' => 'overview', 'unavailable_tabs' => ['team']]);
    }

    public function test_warehouse_user_has_inventory_but_no_finance_or_team_tab_data(): void
    {
        $organization = TestData::organization();
        $warehouse = $this->roleUser($organization, 'warehouse_user', [
            'assets' => ['read'], 'warehouses' => ['read'], 'products' => ['read'],
        ]);
        $response = $this->actingAs($warehouse)->get(route('dashboard', ['dashboard_tab' => 'revenue']))->assertOk();
        $xpath = $this->xpath($response);

        $this->assertContains('inventory', $this->tabNames($xpath));
        $this->assertNotContains('revenue', $this->tabNames($xpath));
        $this->assertNotContains('team', $this->tabNames($xpath));
        $this->assertSame(0, $xpath->query('//*[@data-dashboard-panel="revenue" or @data-dashboard-panel="team"]')->length);
        $this->assertActivePanel($xpath, 'overview');
        $response->assertDontSeeText('Collections This Month')->assertDontSeeText('Team Performance');
        $this->exportFixture('dashboard-warehouse', $response, ['role' => 'warehouse_user', 'active_tab' => 'overview', 'unavailable_tabs' => ['team', 'revenue']]);
    }

    public function test_delivery_dashboard_remains_task_first_without_executive_tabs(): void
    {
        $organization = TestData::organization();
        $delivery = $this->roleUser($organization, User::ROLE_DELIVERY, [
            'deliveries' => ['read', 'update'], 'rentals' => ['read'],
        ]);
        $response = $this->actingAs($delivery)->get(route('dashboard', ['dashboard_tab' => 'team']))->assertOk();
        $xpath = $this->xpath($response);

        $this->assertSame([], $this->tabNames($xpath));
        $this->assertSame(0, $xpath->query('//*[@data-dashboard-panel]')->length);
        $response->assertSeeText('My Assigned Tasks')->assertSeeText('My Deliveries Today')
            ->assertDontSeeText('Team Performance')->assertDontSeeText('Revenue Center');
        $this->exportFixture('dashboard-delivery', $response, ['role' => 'delivery', 'active_tab' => null, 'unavailable_tabs' => self::EXECUTIVE_TABS]);
    }

    public function test_disabled_role_widget_stays_disabled_after_dashboard_consolidation(): void
    {
        $organization = TestData::organization();
        $operator = $this->roleUser($organization, 'admin_operations', Role::defaultSystemRoleTemplates()['admin_operations']['permissions']);
        foreach (['kpi_active_rentals', 'widget_today_followups'] as $key) {
            $widget = DashboardWidget::query()->where('widget_key', $key)->firstOrFail();
            RoleDashboardWidget::create([
                'organization_id' => $organization->id,
                'role_id' => $operator->role_id,
                'dashboard_widget_id' => $widget->id,
                'is_enabled' => false,
                'sort_order' => 1,
            ]);
        }

        $response = $this->actingAs($operator)->get(route('dashboard'))->assertOk()
            ->assertViewHas('dashboardVisibility', fn (array $visibility) => ! in_array('kpi_active_rentals', $visibility['widget_keys'], true));
        $this->assertActivePanel($this->xpath($response), 'overview');
        $this->assertTrue($operator->canAccessModule('rentals', 'read'), 'The fixture must otherwise be eligible for the follow-up queue.');
        foreach ($this->tabNames($this->xpath($response)) as $tab) {
            $selected = $this->get(route('dashboard', ['dashboard_tab' => $tab]))->assertOk();
            $xpath = $this->xpath($selected);
            $this->assertActivePanel($xpath, $tab);
            $this->assertSame(0, $xpath->query('//*[@data-overview-queue="widget_today_followups"]')->length, "The disabled follow-up queue reappeared while selecting {$tab}.");
            $this->assertSame(1, $xpath->query('//*[@data-overview-queue="primary_overdue_rentals"]')->length, 'Disabling one queue must not remove other permitted attention queues.');
        }
        $this->exportFixture('dashboard-disabled-widgets', $response, ['role' => 'admin_operations', 'active_tab' => 'overview', 'disabled_widgets' => ['kpi_active_rentals', 'widget_today_followups']]);
    }

    public function test_former_business_overview_attention_queues_keep_their_distinct_nonzero_counts(): void
    {
        $organization = TestData::organization();
        $owner = TestData::user($organization);
        $this->actingAs($owner);
        $overdue = $this->rental($organization->id, 'Bengaluru', 'Attention overdue rental', 1000);
        $overdue->update(['start_date' => now()->subDays(7)->toDateString(), 'end_date' => now()->subDays(2)->toDateString()]);
        foreach (range(1, 2) as $number) {
            $due = $this->rental($organization->id, 'Bengaluru', 'Attention renewal '.$number, 1000);
            $due->update(['end_date' => now()->toDateString()]);
        }
        $warehouse = Warehouse::create([
            'organization_id' => $organization->id, 'name' => 'Attention verification warehouse',
            'code' => 'ATTENTION-VERIFY', 'is_active' => true,
        ]);
        foreach (range(1, 3) as $number) {
            Asset::create([
                'organization_id' => $organization->id, 'product_id' => $overdue->product_id,
                'warehouse_id' => $warehouse->id, 'asset_name' => 'Attention returned asset '.$number,
                'serial_number' => 'ATTENTION-RETURN-'.$number, 'barcode_value' => 'ATTENTION-RETURN-'.$number,
                'asset_stage' => Asset::STAGE_RENTAL_STOCK, 'condition_status' => Asset::CONDITION_STATUS_GOOD,
                'asset_status' => Asset::STATUS_AWAITING_VERIFICATION,
            ]);
        }
        foreach (range(1, 4) as $number) {
            FollowUp::create([
                'organization_id' => $organization->id, 'customer_id' => $overdue->customer_id,
                'rental_id' => $overdue->id, 'assigned_user_id' => $owner->id,
                'followup_type' => FollowUp::TYPE_GENERAL, 'title' => 'Attention callback '.$number,
                'due_at' => now()->startOfDay()->addHours(12), 'status' => FollowUp::STATUS_PENDING,
                'priority' => FollowUp::PRIORITY_MEDIUM,
            ]);
        }

        $response = $this->get(route('dashboard'))->assertOk();
        foreach (['overdueCount' => 1, 'renewalsDueTodayCount' => 2, 'awaitingReturnVerificationCount' => 3, 'followUpsDueTodayCount' => 4] as $metric => $expected) {
            $this->assertSame($expected, (int) $response->viewData($metric), "Synthetic fixture should exercise a distinct {$metric} count.");
        }
        $this->assertOverviewQueuesMatchMetrics($response);
    }

    public function test_unknown_tab_falls_back_to_overview_and_legacy_overview_redirect_retains_filters(): void
    {
        $this->actingAs(TestData::user());
        $response = $this->get(route('dashboard', ['dashboard_tab' => 'not-a-panel']))->assertOk();
        $this->assertActivePanel($this->xpath($response), 'overview');

        $filters = ['city' => 'Bengaluru', 'from_date' => '2026-10-01', 'to_date' => '2026-10-29'];
        $legacy = $this->get(route('dashboard.compact', $filters))->assertRedirect();
        $query = [];
        parse_str((string) parse_url($legacy->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame(parse_url(route('dashboard'), PHP_URL_PATH), parse_url($legacy->headers->get('Location'), PHP_URL_PATH));
        $this->assertEquals($filters + ['dashboard_tab' => 'overview'], $query);

        $legacy = $this->get(route('dashboard.compact', $filters + ['dashboard_tab' => 'inventory']))->assertRedirect();
        parse_str((string) parse_url($legacy->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('inventory', $query['dashboard_tab'] ?? null);
    }

    public function test_sidebar_prioritizes_daily_work_preserves_badges_and_keeps_other_destinations(): void
    {
        $owner = TestData::user();
        $this->actingAs($owner);
        // Console HTTP tests intentionally suppress the live sidebar count composer.
        // Supply an existing badge value to exercise its unchanged rendering/key.
        View::composer('layouts.app', fn ($view) => $view->with('sidebarPendingCounts', ['rentals' => 1]));
        $response = $this->get(route('dashboard'))->assertOk();
        $xpath = $this->xpath($response);
        $this->assertSame([
            'Dashboard', 'Rentals', 'Sales', 'Sales workspace', 'Customers',
            'Business Partners', 'Collections & cash', 'Rental availability',
        ], $this->sidebarLabels($xpath, 'main'));
        $this->assertSame(['Knowledge Hub'], $this->sidebarLabels($xpath, 'help'));
        $groups = [];
        foreach ($xpath->query('//*[@data-sidebar-group]') as $group) {
            $groups[] = $group->getAttribute('data-sidebar-group');
        }
        $this->assertSame(['main', 'operations', 'inventory', 'finance', 'team', 'analytics', 'more', 'help', 'organization-settings'], $groups);
        $rentalBadge = $xpath->query('//*[@data-sidebar-group="main"]//a[@aria-label="Rentals"]//span[contains(concat(" ", normalize-space(@class), " "), " sidebar-link-badge ")]');
        $this->assertSame(1, $rentalBadge->length);
        $this->assertSame('1', trim($rentalBadge->item(0)->textContent), 'The overdue rental badge must follow its existing destination after reordering.');
        $links = [];
        foreach ($xpath->query('//a[contains(concat(" ", normalize-space(@class), " "), " sidebar-link ")]') as $link) {
            $links[] = $link->getAttribute('href');
        }
        $this->assertSame(1, count(array_filter($links, fn ($href) => $href === route('dashboard'))));
        $this->assertNotContains(route('dashboard.compact'), $links);
        foreach (['customers.index', 'business-partners.index', 'cash-handovers.index', 'enquiries.index', 'availability.index', 'knowledge.index', 'rentals.index', 'sales.index', 'renewal-center.index', 'pickup-center.index', 'communication-center.index', 'deliveries.index', 'inventory.dashboard', 'products.index', 'assets.index', 'warehouses.index', 'stock-history.index', 'ledger.index', 'invoices.index', 'reports.index', 'users.index', 'roles.index'] as $route) {
            $this->assertContains(route($route), $links, "Existing sidebar destination {$route} was removed.");
        }
    }

    public function test_reordered_main_and_bottom_help_preserve_restricted_role_visibility(): void
    {
        $organization = TestData::organization();
        $customerReader = $this->roleUser($organization, 'customers_only', ['customers' => ['read']]);
        $response = $this->actingAs($customerReader)->get(route('profile.edit'))->assertOk();
        $xpath = $this->xpath($response);
        $this->assertSame(['Dashboard', 'Customers', 'Business Partners'], $this->sidebarLabels($xpath, 'main'));
        $this->assertSame(['Knowledge Hub'], $this->sidebarLabels($xpath, 'help'));
        foreach (['rentals.index', 'sales.index', 'enquiries.index', 'cash-handovers.index', 'availability.index'] as $route) {
            $this->assertSame(0, $xpath->query('//*[@data-sidebar-group="main"]//a[@href="'.route($route).'"]')->length, "Reordering must not expose {$route} to a customer-only reader.");
        }

        $delivery = $this->roleUser($organization, User::ROLE_DELIVERY, [
            'deliveries' => ['read', 'update'], 'rentals' => ['read'],
        ]);
        $response = $this->actingAs($delivery)->get(route('profile.edit'))->assertOk();
        $xpath = $this->xpath($response);
        $this->assertSame(['Dashboard', 'Collections & cash'], $this->sidebarLabels($xpath, 'main'));
        $this->assertSame(['Pickup Center', 'Tasks Board'], $this->sidebarLabels($xpath, 'operations'));
        $this->assertSame(['Knowledge Hub'], $this->sidebarLabels($xpath, 'help'));
    }

    public function test_knowledge_hub_help_destination_remains_active_for_legacy_workflow_guides(): void
    {
        $this->actingAs(TestData::user());
        $response = $this->get(route('workflow-guides.index'))->assertOk();
        $xpath = $this->xpath($response);
        $help = $xpath->query('//*[@data-sidebar-group="help"]');
        $this->assertSame(1, $help->length);
        $this->assertSame('true', $help->item(0)->getAttribute('data-has-active'));
        $this->assertSame('true', $help->item(0)->getAttribute('data-default-open'));
        $active = $xpath->query('//*[@data-sidebar-group="help"]//a[@href="'.route('knowledge.index').'" and contains(concat(" ", normalize-space(@class), " "), " is-active ")]');
        $this->assertSame(1, $active->length, 'Knowledge Hub must stay active for existing workflow-guide bookmarks.');
        $this->assertSame(0, $xpath->query('//*[@data-sidebar-group="help"]//a[@href="'.route('workflow-guides.index').'"]')->length);
    }

    public function test_shared_navigation_appearance_is_present_outside_dashboard_without_dashboard_assets(): void
    {
        $this->actingAs(TestData::user());
        foreach (['profile.edit', 'rentals.index', 'customers.index', 'workflow-guides.index'] as $route) {
            $response = $this->get(route($route))->assertOk();
            $xpath = $this->xpath($response);
            $bodyClasses = $xpath->query('//body')->item(0)->getAttribute('class');
            $this->assertContains('phos-navigation-refresh', preg_split('/\s+/', trim($bodyClasses)), "{$route} must use the shared navigation presentation.");
            $this->assertNotContains('phos-executive-page', preg_split('/\s+/', trim($bodyClasses)), "{$route} must retain its own content presentation.");
            $this->assertSame(1, $xpath->query('//style[@id="phos-global-navigation-styles"]')->length);
            $this->assertSame(0, $xpath->query('//link[contains(@href, "/css/phos-executive-dashboard.css")]')->length, 'Shared navigation must not depend on the dashboard-only stylesheet.');
            $this->assertSame(0, $xpath->query('//script[contains(@src, "/js/phos-executive-dashboard.js")]')->length);
            $this->assertSame(['Knowledge Hub'], $this->sidebarLabels($xpath, 'help'));
            if ($route === 'profile.edit') {
                $this->exportFixture('non-dashboard-profile', $response, ['role' => 'super_admin', 'active_tab' => null, 'route' => $route]);
            }
        }
    }

    private function sidebarLabels(DOMXPath $xpath, string $group): array
    {
        $labels = [];
        foreach ($xpath->query('//*[@data-sidebar-group="'.$group.'"]//a[@data-sidebar-link]') as $link) {
            $labels[] = $link->getAttribute('aria-label');
        }

        return $labels;
    }

    private function xpath(TestResponse $response): DOMXPath
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="utf-8" ?>'.$response->getContent(), LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }

    private function exportFixture(string $name, TestResponse $response, array $metadata): void
    {
        $directory = getenv('PHOS_DASHBOARD_PREVIEW_DIR');
        if (! is_string($directory) || $directory === '') {
            return;
        }
        $this->assertTrue(app()->environment('testing'), 'Browser fixtures must only be generated by the test application.');
        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Cannot create the synthetic dashboard fixture directory.');
        }
        file_put_contents($directory.'/'.$name.'.html', $response->getContent());
        $manifestPath = $directory.'/dashboard-fixtures.json';
        $manifest = is_file($manifestPath) ? json_decode(file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR) : [];
        $manifest[$name] = ['synthetic' => true, 'html' => $name.'.html', 'available_tabs' => $this->tabNames($this->xpath($response))] + $metadata;
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    private function tabNames(DOMXPath $xpath): array
    {
        $names = [];
        foreach ($xpath->query('//a[@data-dashboard-tab]') as $link) {
            $names[] = $link->getAttribute('data-dashboard-tab');
            $this->assertSame('tab', $link->getAttribute('role'));
            $this->assertNotSame('', $link->getAttribute('href'), 'Every tab must work as a server link without JavaScript.');
        }

        return $names;
    }

    private function assertOverviewQueuesMatchMetrics(TestResponse $response): void
    {
        $xpath = $this->xpath($response);
        $queues = [
            'primary_overdue_rentals' => ['overdueCount', 'renewal-center.index'],
            'widget_today_renewals' => ['renewalsDueTodayCount', 'renewal-center.index'],
            'snapshot_asset_alerts' => ['awaitingReturnVerificationCount', 'assets.pending-verification'],
            'widget_today_followups' => ['followUpsDueTodayCount', 'communication-center.index'],
        ];
        $this->assertSame(4, $xpath->query('//*[@data-overview-queue]')->length);
        foreach ($queues as $key => [$metric, $route]) {
            $nodes = $xpath->query('//*[@data-overview-queue="'.$key.'"]');
            $this->assertSame(1, $nodes->length, "Former compact attention queue {$key} must remain available.");
            $link = $nodes->item(0);
            $count = trim($xpath->query('./span[last()]', $link)->item(0)->textContent);
            $this->assertSame(number_format((int) $response->viewData($metric)), $count, "Queue {$key} no longer matches its original metric.");
            $this->assertSame(parse_url(route($route), PHP_URL_PATH), parse_url($link->getAttribute('href'), PHP_URL_PATH));
        }
    }

    private function assertActivePanel(DOMXPath $xpath, string $active): void
    {
        $selected = $xpath->query('//a[@data-dashboard-tab and @aria-selected="true"]');
        $this->assertSame(1, $selected->length, 'Exactly one available tab should be selected on the server.');
        $this->assertSame($active, $selected->item(0)->getAttribute('data-dashboard-tab'));
        $visible = [];
        foreach ($xpath->query('//*[@data-dashboard-panel]') as $panel) {
            $this->assertInstanceOf(DOMElement::class, $panel);
            if (! $panel->hasAttribute('hidden')) {
                $visible[] = $panel->getAttribute('data-dashboard-panel');
            }
        }
        $this->assertSame([$active], $visible, 'Server rendering should expose only the selected, authorized panel.');
    }

    private function roleUser($organization, string $slug, array $permissions): User
    {
        $role = Role::create([
            'organization_id' => $organization->id,
            'name' => ucfirst(str_replace('_', ' ', $slug)),
            'slug' => $slug,
            'permissions' => Role::normalizePermissions($permissions),
            'is_active' => true,
        ]);

        return TestData::user($organization, ['role_id' => $role->id, 'role' => $slug]);
    }

    private function rental(int $organizationId, string $city, string $customerName, int $amount): Rental
    {
        $customer = Customer::create([
            'organization_id' => $organizationId, 'name' => $customerName,
            'phone' => '9000000000', 'city' => $city,
        ]);
        $product = Product::create([
            'organization_id' => $organizationId, 'name' => $customerName.' product',
            'product_type' => Product::TYPE_RENTABLE, 'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'total_quantity' => 10, 'available_quantity' => 10, 'rental_price' => $amount, 'price_per_day' => $amount,
        ]);

        $rental = Rental::create([
            'organization_id' => $organizationId, 'customer_id' => $customer->id,
            'customer_name' => $customerName, 'phone' => $customer->phone,
            'product_id' => $product->id, 'quantity' => 1,
            'start_date' => now()->subDay()->toDateString(), 'end_date' => now()->addDays(5)->toDateString(),
            'status' => 'active', 'rental_amount' => $amount, 'deposit_amount' => 250,
            'transport_amount' => 0, 'other_amount' => 0,
        ]);
        Delivery::create([
            'organization_id' => $organizationId,
            'rental_id' => $rental->id,
            'type' => 'delivery',
            'status' => 'completed',
            'scheduled_at' => now()->subDay(),
            'completed_at' => now()->subDay(),
        ]);

        return $rental;
    }
}
