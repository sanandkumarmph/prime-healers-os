<?php

namespace Tests\Feature\Regression;

use App\Models\Customer;
use App\Models\Delivery;
use App\Models\FollowUp;
use App\Models\Organization;
use App\Models\Product;
use App\Models\Rental;
use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\TestData;
use Tests\TestCase;

class OperationsCompactListFiltersRegressionTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->organization = TestData::organization(['state' => 'Karnataka']);
        $this->admin = TestData::user($this->organization, ['name' => 'Compact preview administrator']);
        $this->actingAs($this->admin);
    }

    #[DataProvider('pageFilters')]
    public function test_header_filter_preserves_get_fields_tab_and_reset_isolation(string $page, string $route, array $query, array $fields, array $values): void
    {
        $response = $this->get(route($route, $query + ['page' => 7, 'unrelated_filter' => 'do-not-copy']));
        $response->assertOk()->assertSee('data-phos-compact-page="'.$page.'"', false);
        $xpath = $this->xpath($response->getContent());
        $filter = $xpath->query('//*[@id="'.$page.'-list-filters" and @data-phos-list-filter]')->item(0);
        $this->assertInstanceOf(DOMElement::class, $filter);
        $this->assertSame(1, $xpath->query('ancestor::*[contains(concat(" ", normalize-space(@class), " "), " phos-page-header ")]', $filter)->length);
        $this->assertFalse($filter->hasAttribute('open'));
        $forms = $xpath->query('.//form', $filter);
        $this->assertSame(1, $forms->length);
        $form = $forms->item(0);
        $this->assertSame('GET', strtoupper($form->getAttribute('method')));
        $this->assertSame(route($route), $form->getAttribute('action'));
        $names = [];
        foreach ($xpath->query('.//*[@name]', $form) as $input) {
            $names[] = $input->getAttribute('name');
        }
        sort($names);
        sort($fields);
        $this->assertSame($fields, $names);
        foreach ($values + ['tab' => $query['tab']] as $name => $expected) {
            $input = $xpath->query('.//*[@name="'.$name.'"]', $form)->item(0);
            $actual = $input->tagName === 'select'
                ? $xpath->query('.//option[@selected]', $input)->item(0)?->getAttribute('value')
                : $input->getAttribute('value');
            $this->assertSame($expected, $actual, $page.' '.$name);
        }
        $this->assertSame(0, $xpath->query('.//*[@name="page" or @name="unrelated_filter" or @name="_token"]', $form)->length);
        $reset = null;
        foreach ($xpath->query('.//a', $filter) as $link) {
            if (in_array(trim($link->textContent), ['Clear', 'Clear Filters', 'Reset'], true)) {
                $reset = $link;
            }
        }
        $this->assertInstanceOf(DOMElement::class, $reset);
        parse_str((string) parse_url($reset->getAttribute('href'), PHP_URL_QUERY), $resetQuery);
        $this->assertSame($page === 'deliveries' ? [] : ['tab' => $query['tab']], $resetQuery);
    }

    public static function pageFilters(): array
    {
        return [
            'renewals' => ['renewals', 'renewal-center.index', ['tab' => 'overdue', 'search' => 'oxygen', 'city' => 'Bengaluru', 'sort_by' => 'renewal_date', 'sort_dir' => 'desc'], ['tab', 'search', 'sort_by', 'sort_dir', 'city', 'product_id', 'staff', 'overdue_days', 'payment_status', 'pickup_status'], ['search' => 'oxygen', 'city' => 'Bengaluru', 'sort_by' => 'renewal_date', 'sort_dir' => 'desc']],
            'pickups' => ['pickups', 'pickup-center.index', ['tab' => 'overdue', 'search' => 'oxygen', 'city' => 'Bengaluru', 'product' => 'Concentrator', 'sort_by' => 'schedule_date', 'sort_dir' => 'desc'], ['tab', 'sort_by', 'sort_dir', 'search', 'city', 'product', 'staff', 'pickup_status'], ['search' => 'oxygen', 'city' => 'Bengaluru', 'product' => 'Concentrator', 'sort_by' => 'schedule_date', 'sort_dir' => 'desc']],
            'communications' => ['communications', 'communication-center.index', ['tab' => 'payments', 'search' => 'oxygen', 'priority' => 'high', 'type' => 'payment', 'staff' => 'me', 'sort' => 'latest'], ['tab', 'search', 'priority', 'type', 'staff', 'sort'], ['search' => 'oxygen', 'priority' => 'high', 'type' => 'payment', 'staff' => 'me', 'sort' => 'latest']],
            'deliveries' => ['deliveries', 'deliveries.index', ['tab' => 'pickups', 'ownership' => 'my', 'search' => 'oxygen', 'task_type' => 'pickup', 'staff' => 'unassigned', 'status' => 'pending', 'workflow' => 'failed', 'sort_by' => 'schedule_date', 'sort_dir' => 'desc'], ['ownership', 'search', 'date', 'task_type', 'staff', 'area', 'status', 'workflow', 'tab', 'sort_by', 'sort_dir'], ['ownership' => 'my', 'search' => 'oxygen', 'task_type' => 'pickup', 'staff' => 'unassigned', 'status' => 'pending', 'workflow' => 'failed', 'sort_by' => 'schedule_date', 'sort_dir' => 'desc']],
        ];
    }

    public function test_post_workflows_and_task_selection_remain_outside_filter_forms(): void
    {
        $this->seedOperations();
        foreach (['renewal-center.index', 'pickup-center.index', 'communication-center.index', 'deliveries.index'] as $route) {
            $response = $this->get(route($route, ['tab' => 'all']));
            $response->assertOk();
            $xpath = $this->xpath($response->getContent());
            $this->assertSame(0, $xpath->query('//*[@data-phos-list-filter]//form[translate(@method,"post","POST")="POST"]')->length);
            $this->assertGreaterThan(0, $xpath->query('//form[translate(@method,"post","POST")="POST"]')->length, $route);
            if ($route === 'deliveries.index') {
                $response->assertSee('id="deliverySelectAll"', false)
                    ->assertSee('data-mobile-filter-sheet', false)
                    ->assertSee('data-phos-legacy-filter', false)
                    ->assertSee('data-taskboard-auto-search', false);
            }
        }
    }

    public function test_delivery_role_keeps_assigned_task_first_scope(): void
    {
        $fixtures = $this->seedOperations();
        $response = $this->actingAs($fixtures['agent'])->get(route('deliveries.index'));
        $response->assertOk()->assertSee('id="deliveries-list-filters"', false);
        $this->assertSame('my', $response->viewData('ownershipFilter'));
        $ids = $response->viewData('tasks')->pluck('id');
        $this->assertTrue($ids->contains($fixtures['assigned_task']->id));
        $this->assertFalse($ids->contains($fixtures['unassigned_task']->id));
        $xpath = $this->xpath($response->getContent());
        $this->assertSame('my', $xpath->query('//*[@id="deliveries-list-filters"]//input[@name="ownership"]')->item(0)?->getAttribute('value'));
    }

    public function test_nonempty_operations_pages_render_and_optionally_export_browser_fixtures(): void
    {
        $fixtures = $this->seedOperations();
        $pages = [
            'renewals' => ['renewal-center.index', 'rentals', ['tab' => 'all']],
            'pickups' => ['pickup-center.index', 'pickups', ['tab' => 'all']],
            'communications' => ['communication-center.index', 'followUps', ['tab' => 'all']],
            'deliveries' => ['deliveries.index', 'tasks', []],
        ];
        $manifest = ['schema' => 1, 'group' => 'operations', 'synthetic' => true, 'pages' => []];
        $directory = $this->previewDirectory();
        foreach ($pages as $page => [$route, $rowsKey, $query]) {
            $response = $this->actingAs($this->admin)->get(route($route, $query));
            $response->assertOk()->assertSee('id="'.$page.'-list-filters"', false);
            $rows = $response->viewData($rowsKey);
            $this->assertGreaterThan(0, $rows->count(), $page);
            $manifest['pages'][$page] = ['file' => $page.'.html', 'route' => $route, 'query' => $query, 'rows' => $rows->count(), 'role' => 'super_admin'];
            if ($directory) {
                file_put_contents($directory.'/'.$page.'.html', $response->getContent());
            }
        }
        $response = $this->actingAs($fixtures['agent'])->get(route('deliveries.index'));
        $response->assertOk();
        $this->assertSame('my', $response->viewData('ownershipFilter'));
        $this->assertGreaterThan(0, $response->viewData('tasks')->count());
        $manifest['delivery_role_variant'] = ['file' => 'deliveries-delivery.html', 'role' => 'delivery', 'scope' => 'assigned', 'rows' => $response->viewData('tasks')->count()];
        if ($directory) {
            file_put_contents($directory.'/deliveries-delivery.html', $response->getContent());
            file_put_contents($directory.'/operations-fixtures.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        }
    }

    private function xpath(string $html): DOMXPath
    {
        $dom = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($dom);
    }

    private function previewDirectory(): ?string
    {
        $directory = getenv('PHOS_COMPACT_PREVIEW_DIR');
        if (!$directory) {
            return null;
        }
        $workspace = str_replace('\\', '/', (string) realpath(base_path('..')));
        $resolved = str_replace('\\', '/', (string) realpath($directory));
        $this->assertNotSame('', $resolved, 'Create PHOS_COMPACT_PREVIEW_DIR before fixture export.');
        $this->assertStringStartsWith(rtrim($workspace, '/').'/', $resolved);

        return rtrim($resolved, '/');
    }

    private function seedOperations(): array
    {
        $agent = TestData::user($this->organization, ['name' => 'Compact fixture field agent', 'role' => User::ROLE_DELIVERY]);
        $product = Product::create(['organization_id' => $this->organization->id, 'name' => 'Oxygen concentrator fixture', 'product_type' => Product::TYPE_RENTABLE, 'stock_mode' => Product::STOCK_MODE_UNTRACKED, 'available_quantity' => 12, 'total_quantity' => 12, 'price_per_day' => 300, 'rental_price' => 1500, 'sale_price' => 0]);
        $rentals = [];
        foreach (['due today' => 0, 'overdue' => -4, 'upcoming' => 5, 'awaiting dispatch' => 7] as $label => $days) {
            $customer = Customer::create(['organization_id' => $this->organization->id, 'name' => 'Compact fixture '.$label, 'phone' => '900001000'.count($rentals), 'whatsapp_number' => '900001000'.count($rentals), 'address' => '12 Synthetic Preview Street', 'city' => 'Bengaluru', 'state' => 'Karnataka', 'pincode' => '560001']);
            $rental = Rental::create(['organization_id' => $this->organization->id, 'created_by_user_id' => $this->admin->id, 'customer_id' => $customer->id, 'customer_name' => $customer->name, 'phone' => $customer->phone, 'product_id' => $product->id, 'quantity' => 1, 'start_date' => now()->subDays(10)->toDateString(), 'end_date' => now()->addDays($days)->toDateString(), 'status' => 'active', 'rental_amount' => 1500, 'deposit_amount' => 300, 'transport_amount' => 0, 'other_amount' => 0]);
            if ($label !== 'awaiting dispatch') {
                Delivery::create(['organization_id' => $this->organization->id, 'rental_id' => $rental->id, 'type' => 'delivery', 'status' => 'completed', 'scheduled_at' => now()->subDays(9), 'completed_at' => now()->subDays(9), 'assigned_user_id' => $agent->id]);
            }
            $rentals[$label] = $rental;
        }
        foreach (['due today' => 0, 'overdue' => -2] as $label => $days) {
            Delivery::create(['organization_id' => $this->organization->id, 'rental_id' => $rentals[$label]->id, 'type' => 'pickup', 'status' => 'pending', 'pickup_status' => 'assigned', 'scheduled_at' => now()->addDays($days)->setTime(11, 30), 'assignment_type' => 'delivery_team', 'assigned_user_id' => $agent->id]);
        }
        $assigned = Delivery::create(['organization_id' => $this->organization->id, 'rental_id' => $rentals['awaiting dispatch']->id, 'type' => 'delivery', 'status' => 'pending', 'scheduled_at' => now()->setTime(14, 0), 'assigned_user_id' => $agent->id, 'notes' => 'Assigned fixture task']);
        $unassigned = Delivery::create(['organization_id' => $this->organization->id, 'type' => 'delivery', 'status' => 'pending', 'scheduled_at' => now(), 'notes' => 'Unassigned fixture task']);
        foreach (['Priority fixture callback' => -1, 'Upcoming fixture service check' => 1] as $title => $days) {
            FollowUp::create(['organization_id' => $this->organization->id, 'customer_id' => $rentals['due today']->customer_id, 'rental_id' => $rentals['due today']->id, 'followup_type' => FollowUp::TYPE_CALLBACK, 'title' => $title, 'due_at' => now()->addDays($days), 'status' => FollowUp::STATUS_PENDING, 'priority' => FollowUp::PRIORITY_HIGH, 'assigned_user_id' => $agent->id, 'created_by_user_id' => $this->admin->id]);
        }

        return ['agent' => $agent, 'assigned_task' => $assigned, 'unassigned_task' => $unassigned];
    }
}
