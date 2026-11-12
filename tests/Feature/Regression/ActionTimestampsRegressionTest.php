<?php

namespace Tests\Feature\Regression;

use App\Models\{ActivityLog, BusinessPartner, Customer, Delivery, Enquiry, Invoice, Payment, Product, Rental, Role, Sale, Warehouse};
use App\Support\ActionTimestamp;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestData;
use Tests\TestCase;

class ActionTimestampsRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_utc_action_crossing_midnight_is_shown_in_ist_without_mutating_the_source(): void
    {
        $source = Carbon::parse('2026-10-31T18:45:27Z');
        $this->assertSame('01 Nov 2026, 12:15:27 AM IST', ActionTimestamp::label($source));
        $this->assertSame('Z', $source->timezoneName);
        $this->assertSame('2026-10-31T18:45:27+00:00', $source->toIso8601String());
        $this->assertSame('01 Nov 2026, 12:15:27 AM IST', ActionTimestamp::label('2026-10-31T18:45:27.000000Z'));
        $this->assertSame('01 Nov 2026, 12:15:27 AM IST', ActionTimestamp::label('2026-11-01 00:15:27'));
        $html = view('partials.action-time', ['actionAt' => $source])->render();
        $this->assertStringContainsString('datetime="2026-11-01T00:15:27+05:30"', $html);
        $this->assertStringContainsString('01 Nov 2026, 12:15:27 AM IST', $html);
    }

    public function test_missing_and_date_only_values_never_invent_an_action_clock(): void
    {
        foreach ([null, '', '2026-11-01', 'yesterday', 1793463327, '2026-02-30 12:00:00', '2026-11-01 25:15:00', '<script>alert(1)</script>'] as $value) {
            $this->assertNull(ActionTimestamp::ist($value));
            $this->assertSame('Time not recorded', ActionTimestamp::label($value));
            $html = view('partials.action-time', ['actionAt' => $value])->render();
            $this->assertStringContainsString('Time not recorded', $html);
            $this->assertStringNotContainsString('<time ', $html);
            $this->assertStringNotContainsString('<script>', $html);
        }
    }

    public function test_record_metadata_uses_only_existing_attributes_and_does_not_infer_completion(): void
    {
        $record = new Delivery;
        $record->setRawAttributes(['created_at' => '2026-11-01 09:10:11', 'updated_at' => '2026-11-01 17:18:19', 'completed_at' => null]);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $before = $record->getAttributes();
        $html = view('partials.record-timestamps', ['timestampRecord' => $record, 'timestampFields' => ['created_at' => 'Created', 'updated_at' => 'Last updated', 'completed_at' => 'Completed']])->render();
        $this->assertStringContainsString('09:10:11 AM IST', $html);
        $this->assertStringContainsString('05:18:19 PM IST', $html);
        $this->assertStringNotContainsString('<dt style="font-size:12px; color:#64748b; font-weight:600;">Completed', $html);
        $this->assertSame($before, $record->getAttributes());
        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_shared_history_groups_by_ist_day_and_shows_exact_action_times(): void
    {
        $owner = TestData::user();
        $this->actingAs($owner);
        $first = new ActivityLog(['action' => 'customer.updated']);
        $first->setRawAttributes(['action' => 'customer.updated', 'created_at' => '2026-10-31T18:45:27Z']);
        $first->setRelation('user', $owner);
        $second = new ActivityLog(['action' => 'customer.created']);
        $second->setRawAttributes(['action' => 'customer.created', 'created_at' => '2026-10-31T18:15:11Z']);
        $second->setRelation('user', $owner);
        $html = view('partials.activity-timeline', ['timeline' => collect([$first, $second]), 'errors' => new \Illuminate\Support\ViewErrorBag])->render();
        $this->assertStringContainsString('01 Nov 2026', $html);
        $this->assertStringContainsString('31 Oct 2026', $html);
        $this->assertStringContainsString('12:15:27 AM IST', $html);
        $this->assertStringContainsString('11:45:11 PM IST', $html);
    }

    public function test_authorized_detail_pages_show_record_clocks_without_changing_records(): void
    {
        $this->travelTo(Carbon::parse('2026-11-01 17:18:19', 'Asia/Kolkata'));
        $organization = TestData::organization();
        $owner = TestData::user($organization);
        $this->actingAs($owner);
        $customer = Customer::create(['organization_id' => $organization->id, 'name' => 'Timestamp test customer', 'phone' => '9876543210']);
        $product = Product::create(['organization_id' => $organization->id, 'name' => 'Timestamp test product', 'product_type' => Product::TYPE_RENTABLE, 'stock_mode' => Product::STOCK_MODE_UNTRACKED, 'available_quantity' => 5, 'total_quantity' => 5, 'price_per_day' => 100]);
        $rental = Rental::create(['organization_id' => $organization->id, 'customer_id' => $customer->id, 'product_id' => $product->id, 'quantity' => 1, 'customer_name' => $customer->name, 'phone' => $customer->phone, 'start_date' => '2026-10-01', 'end_date' => '2026-11-04', 'rental_amount' => 100, 'status' => 'active']);
        $sale = Sale::create(['organization_id' => $organization->id, 'customer_id' => $customer->id, 'product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100, 'sale_amount' => 100, 'sale_date' => '2026-10-01', 'payment_status' => 'pending']);
        $delivery = Delivery::create(['organization_id' => $organization->id, 'rental_id' => $rental->id, 'type' => 'delivery', 'status' => 'completed', 'scheduled_at' => '2026-10-01 10:00:00', 'completed_at' => '2026-11-01 13:14:15', 'assigned_user_id' => $owner->id]);
        $invoice = Invoice::create(['organization_id' => $organization->id, 'customer_id' => $customer->id, 'rental_id' => $rental->id, 'invoice_number' => 'IST-TIMESTAMP-1', 'invoice_date' => '2026-10-01', 'due_date' => '2026-11-04', 'status' => 'unpaid', 'total' => 100]);
        $payment = Payment::create(['organization_id' => $organization->id, 'customer_id' => $customer->id, 'rental_id' => $rental->id, 'invoice_id' => $invoice->id, 'payment_date' => '2026-10-02', 'amount' => 10, 'payment_method' => 'cash']);
        $partner = BusinessPartner::create(['organization_id' => $organization->id, 'business_name' => 'Timestamp partner', 'status' => 'active']);
        $warehouse = Warehouse::create(['organization_id' => $organization->id, 'name' => 'Timestamp warehouse']);
        $enquiry = Enquiry::create(['organization_id' => $organization->id, 'owner_id' => $owner->id, 'created_by_user_id' => $owner->id, 'name' => 'Timestamp enquiry', 'subject' => 'Equipment', 'stage' => 'new']);
        $role = Role::create(['organization_id' => $organization->id, 'name' => 'Timestamp role', 'slug' => 'timestamp-role', 'permissions' => [], 'is_active' => true]);
        $pages = ['customers' => $customer, 'products' => $product, 'rentals' => $rental, 'sales' => $sale, 'deliveries' => $delivery, 'invoices' => $invoice, 'business-partners' => $partner, 'warehouses' => $warehouse, 'enquiries' => $enquiry, 'roles' => $role, 'users' => $owner];
        $folder = getenv('PHOS_ACTION_TIMESTAMPS_RENDER_DIR');
        if ($folder && !is_dir($folder)) mkdir($folder, 0777, true);
        $manifest = [];
        foreach ($pages as $module => $record) {
            $before = $record->fresh()->only(['created_at', 'updated_at']);
            $url = route($module . '.show', $record);
            $response = $this->get($url)->assertOk()->assertSee('data-record-timestamps', false)->assertSee('05:18:19 PM IST');
            if ($module === 'deliveries') $response->assertSee('01:14:15 PM IST');
            if ($module === 'invoices') $response->assertSee('02 Oct 2026')->assertSee('Recorded');
            $this->assertEquals($before, $record->fresh()->only(['created_at', 'updated_at']));
            if ($folder) {
                $file = $module . '.html';
                file_put_contents($folder . '/' . $file, $response->getContent());
                $manifest[] = compact('module', 'url', 'file');
            }
        }
        if ($folder) file_put_contents($folder . '/action-fixtures.json', json_encode($manifest, JSON_THROW_ON_ERROR));
        $foreign = Customer::create(['organization_id' => TestData::organization()->id, 'name' => 'Different tenant']);
        $this->get(route('customers.show', $foreign))->assertNotFound();
        $this->travelBack();
    }
}
