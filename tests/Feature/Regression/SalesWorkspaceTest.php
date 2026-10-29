<?php

namespace Tests\Feature\Regression;

use App\Models\{Customer, Enquiry, EnquiryActivity, EnquiryFollowUp, Organization, Product, Quotation, Rental, Role, Sale, User, Warehouse};
use App\Services\Sales\{QuotationConversion, SalesWorkspace};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB, URL};
use Tests\Support\TestData;
use Tests\TestCase;

class SalesWorkspaceTest extends TestCase
{
    use RefreshDatabase;
    private User $owner;
    private Customer $customer;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 24)->setTime(8, 0));
        $org = TestData::organization(['name' => 'Meridian Rentals', 'currency_code' => 'INR', 'timezone' => 'Asia/Kolkata', 'state' => 'Karnataka', 'country_code' => 'IN']);
        $this->owner = TestData::user($org);
        $this->customer = Customer::create(['organization_id' => $org->id, 'name' => 'Asha Rao', 'phone' => '9876543210', 'state' => 'Karnataka', 'email' => 'asha@example.test']);
        $this->product = Product::create(['organization_id' => $org->id, 'name' => 'Electric hospital bed', 'product_type' => 'both', 'stock_mode' => 'untracked',
            'is_rentable' => true, 'is_sellable' => true, 'total_quantity' => 50, 'available_quantity' => 50, 'price_per_day' => 118, 'sale_price' => 118, 'rental_price' => 118, 'gst_tax_type' => 'cgst_sgst', 'cgst_rate' => 9, 'sgst_rate' => 9, 'gst_calculation_mode' => 'inclusive']);
        Warehouse::create(['organization_id' => $org->id, 'name' => 'Central warehouse', 'is_active' => true]);
        $this->actingAs($this->owner);
    }

    private function systemRole(string $slug): Role
    {
        return Role::firstOrCreate(['organization_id' => $this->owner->organization_id, 'slug' => $slug],
            ['name' => ucfirst($slug), 'is_active' => true, 'is_system' => true,
                'permissions' => Role::defaultSystemRoleTemplates()[$slug]['permissions']]);
    }

    private function enquiry(array $extra = []): Enquiry
    {
        $this->post(route('enquiries.store'), array_replace(['name' => 'Asha Rao', 'subject' => 'Bed rental for recovery', 'owner_id' => $this->owner->id,
            'phone' => '9876543210', 'customer_id' => $this->customer->id, 'source' => 'Referral', 'requirements' => 'Internal: ask about access to the building.'], $extra))->assertSessionHasNoErrors()->assertRedirect();
        return Enquiry::latest('id')->firstOrFail();
    }

    private function payload(string $kind = 'rental'): array
    {
        return ['kind' => $kind, 'valid_until' => '2026-11-01', 'start_date' => '2026-10-26', 'end_date' => '2026-11-24', 'tax_type' => 'cgst_sgst',
            'transport' => 25, 'deposit' => $kind === 'rental' ? 500 : 0, 'terms' => 'Please confirm access and delivery timing.',
            'lines' => [['product_id' => $this->product->id, 'quantity' => 2, 'rate' => 118, 'tax_rate' => 18, 'tax_mode' => 'inclusive']]];
    }

    private function quote(string $kind = 'rental'): Quotation
    {
        $e = $this->enquiry();
        $this->post(route('quotations.store', $e), $this->payload($kind))->assertSessionHasNoErrors()->assertRedirect();
        return $e->quotations()->sole();
    }

    private function transition(Quotation $q, string $action, array $extra = []): Quotation
    {
        $q->refresh();
        $this->post(route('quotations.action', $q), ['lock_version' => $q->lock_version, 'action' => $action, 'contact_name' => 'Asha Rao', 'note' => 'Confirmed by telephone.'] + $extra)->assertSessionHasNoErrors()->assertRedirect();
        return $q->fresh();
    }

    private function accepted(string $kind = 'rental'): Quotation { return $this->transition($this->transition($this->quote($kind), 'send'), 'accepted'); }

    private function fixture(string $name, $response): void
    {
        if ($dir = getenv('GETTING_STARTED_PREVIEW_DIR')) {
            if (!is_dir($dir)) mkdir($dir, 0777, true);
            file_put_contents($dir.'/'.$name.'.html', $response->getContent());
        }
    }

    public function test_workflow_pages_render_and_customer_view_excludes_internal_information(): void
    {
        $e = $this->enquiry();
        $this->fixture('sw-enquiry-form', $this->get(route('enquiries.create'))->assertOk());
        $this->fixture('sw-enquiry', $this->get(route('enquiries.show', $e))->assertOk());
        $this->fixture('sw-quote-form', $this->get(route('quotations.create', $e))->assertOk());
        $this->post(route('enquiries.follow-up', $e), ['subject' => 'Confirm delivery date', 'channel' => 'call', 'due_at' => '2026-10-24T17:00'])->assertSessionHasNoErrors();
        $this->fixture('sw-index', $this->get(route('enquiries.index'))->assertOk());
        $this->fixture('sw-followups', $this->get(route('enquiries.index', ['view' => 'followups']))->assertOk()->assertSee('Confirm delivery date'));
        $q = $this->transition($this->quote(), 'send');
        $this->fixture('sw-quote', $this->get(route('quotations.show', $q))->assertOk());
        $url = app(SalesWorkspace::class)->shareUrl($q);
        auth()->logout();
        $this->fixture('sw-public', $this->get($url)->assertOk()->assertDontSee('Internal:')->assertDontSee('Record a response')->assertSee('Respond to this quotation'));
        $this->assertNotNull($q->fresh()->viewed_at);
    }

    public function test_enquiry_can_be_captured_without_customer_but_sharing_requires_one(): void
    {
        $e = $this->enquiry(['customer_id' => null]);
        $this->post(route('quotations.store', $e), $this->payload())->assertSessionHasNoErrors();
        $q = $e->quotations()->sole();
        $this->post(route('quotations.action', $q), ['action' => 'send', 'lock_version' => 1])->assertSessionHasErrors('workspace');
        $this->assertSame('draft', $q->fresh()->status);
    }

    public function test_server_calculates_prices_and_ignores_forged_totals(): void
    {
        $e = $this->enquiry(); $payload = $this->payload();
        $payload['total'] = 1; $payload['lines'][] = ['product_id' => $this->product->id, 'quantity' => 1, 'rate' => 100, 'tax_rate' => 5, 'tax_mode' => 'exclusive'];
        $this->post(route('quotations.store', $e), $payload)->assertSessionHasNoErrors();
        $s = $e->quotations()->sole()->snapshot;
        $this->assertEquals(300, $s['subtotal']); $this->assertEquals(41, $s['tax']); $this->assertEquals(366, $s['total']); $this->assertEquals(866, $s['payable_with_deposit']);
        $this->assertDatabaseCount('rentals', 0); $this->assertDatabaseCount('invoices', 0); $this->assertEquals(50, $this->product->fresh()->available_quantity);
    }

    public function test_foreign_customer_product_owner_and_unconfigured_tax_are_rejected(): void
    {
        $other = TestData::user();
        $customer = Customer::create(['organization_id' => $other->organization_id, 'name' => 'Foreign', 'phone' => '9000000001']);
        $this->post(route('enquiries.store'), ['name' => 'Attempt', 'subject' => 'Test', 'owner_id' => $other->id, 'customer_id' => $customer->id])->assertSessionHasErrors(['owner_id', 'customer_id']);
        $e = $this->enquiry(); $p = $this->payload(); $p['lines'][0]['tax_rate'] = 13;
        $this->post(route('quotations.store', $e), $p)->assertSessionHasErrors('lines.0.tax_rate');
        $foreign = $this->product->replicate(); $foreign->organization_id = $other->organization_id; $foreign->save();
        $p['lines'][0]['product_id'] = $foreign->id;
        $this->post(route('quotations.store', $e), $p)->assertSessionHasErrors('lines.0.product_id');
    }

    public function test_assignment_and_role_scope_apply_to_lists_details_mutations_and_pdf(): void
    {
        $q = $this->quote(); $e = $q->enquiry;
        $salesRole = $this->systemRole('sales');
        $sales = TestData::user($this->owner->organization, ['role_id' => $salesRole->id, 'role' => 'sales']);
        $this->actingAs($sales)->get(route('enquiries.index'))->assertOk()->assertDontSee($e->subject);
        $this->get(route('enquiries.show', $e))->assertNotFound(); $this->get(route('quotations.pdf', $q))->assertNotFound();
        $this->post(route('enquiries.note', $e), ['note' => 'intrusion'])->assertNotFound();
        $e->update(['owner_id' => $sales->id]);
        $this->get(route('enquiries.show', $e))->assertOk();
        $this->post(route('enquiries.note', $e), ['note' => 'Called customer'])->assertSessionHasNoErrors();
        $this->actingAs(TestData::user())->get(route('quotations.show', $q))->assertNotFound();
        $delivery = $this->systemRole('delivery');
        $this->actingAs(TestData::user($this->owner->organization, ['role_id' => $delivery->id, 'role' => 'delivery']))->get(route('enquiries.index'))->assertForbidden();
    }

    public function test_custom_quote_scope_is_enforced_even_with_company_enquiry_access(): void
    {
        // One role scope applies to both modules; an explicit all-scope reader still cannot mutate quotations.
        $q = $this->quote();
        $role = Role::create(['organization_id' => $this->owner->organization_id, 'name' => 'Read only sales', 'slug' => 'read_only_sales', 'scope_type' => 'all', 'is_active' => true,
            'permissions' => ['enquiries' => ['read'], 'quotations' => ['read']]]);
        $this->actingAs(TestData::user($this->owner->organization, ['role_id' => $role->id]))->get(route('quotations.show', $q))->assertOk()->assertDontSee('id="quotation-form"', false)->assertDontSee('Create sharing link');
        $this->post(route('quotations.action', $q), ['action' => 'send', 'lock_version' => 1])->assertForbidden();
    }

    public function test_stale_edits_are_rejected_and_sent_snapshots_are_immutable(): void
    {
        $q = $this->quote(); $original = $q->snapshot;
        $this->put(route('quotations.update', $q), $this->payload() + ['lock_version' => 999])->assertSessionHasErrors('workspace');
        $q = $this->transition($q, 'send');
        $this->put(route('quotations.update', $q), $this->payload() + ['lock_version' => $q->lock_version])->assertStatus(409);
        $this->customer->update(['name' => 'Renamed contact']); $this->product->update(['name' => 'Renamed product', 'sale_price' => 999]);
        $this->assertSame($original, $q->fresh()->snapshot);
    }

    public function test_revision_preserves_snapshot_and_disables_old_link_and_acceptance(): void
    {
        $q = $this->accepted(); $url = app(SalesWorkspace::class)->shareUrl($q); $original = $q->snapshot;
        $this->transition($q, 'revise');
        $next = $q->enquiry->quotations()->latest('revision')->first();
        $this->assertEquals(2, $next->revision); $this->assertSame('draft', $next->status); $this->assertSame('superseded', $q->fresh()->status); $this->assertSame($original, $q->fresh()->snapshot);
        auth()->logout(); $this->get($url)->assertStatus(410); $this->post($url, ['decision' => 'accepted', 'contact_name' => 'Asha', 'acknowledge' => 1])->assertStatus(410);
        $this->actingAs($this->owner)->get(route('rentals.create', ['quotation_id' => $q->id]))->assertStatus(409);
    }

    public function test_customer_responses_require_valid_signature_and_are_recorded_once(): void
    {
        $q = $this->transition($this->quote(), 'send'); $url = app(SalesWorkspace::class)->shareUrl($q); auth()->logout();
        $this->get(route('quotation-share.show', $q))->assertForbidden();
        $this->post($url, ['decision' => 'accepted', 'contact_name' => 'Asha'])->assertSessionHasErrors('acknowledge');
        $this->post($url, ['decision' => 'accepted', 'contact_name' => 'Asha', 'acknowledge' => 1])->assertSessionHasNoErrors()->assertRedirect($url);
        $this->assertSame('accepted', $q->fresh()->status); $this->assertSame('accepted', $q->enquiry->fresh()->stage);
        $this->post($url, ['decision' => 'declined', 'contact_name' => 'Asha', 'acknowledge' => 1])->assertSessionHasErrors('workspace');
        $this->assertSame(1, EnquiryActivity::where('enquiry_id', $q->enquiry_id)->where('type', 'quotation.accepted')->whereNull('user_id')->count());
    }

    public function test_expired_withdrawn_and_inactive_organisation_links_cannot_accept(): void
    {
        $q = $this->transition($this->quote(), 'send'); $url = app(SalesWorkspace::class)->shareUrl($q);
        $this->transition($q, 'withdraw'); auth()->logout(); $this->get($url)->assertStatus(410);
        $this->actingAs($this->owner); $q2 = $this->transition($this->quote(), 'send'); $url2 = app(SalesWorkspace::class)->shareUrl($q2);
        $this->owner->organization->update(['is_active' => false]); auth()->logout(); $this->get($url2)->assertNotFound();
        $this->owner->organization->update(['is_active' => true]); $this->travelTo(now()->setDate(2026, 11, 3)); $this->get($url2)->assertForbidden();
    }

    public function test_follow_ups_use_organisation_timezone_and_completion_is_idempotent(): void
    {
        $e = $this->enquiry();
        $this->post(route('enquiries.follow-up', $e), ['subject' => 'Call Asha', 'channel' => 'call', 'due_at' => '2026-10-24T17:00'])->assertSessionHasNoErrors();
        $task = $e->followUps()->sole();
        $this->assertSame('2026-10-24 11:30:00', $task->getRawOriginal('due_at'));
        $this->assertSame('UTC', $task->due_at->timezoneName);
        $this->assertSame('2026-10-24 17:00', $task->due_at->timezone('Asia/Kolkata')->format('Y-m-d H:i'));
        $this->travelTo(now()->setTime(12, 0));
        $this->assertFalse($task->due_at->isPast());
        $this->get(route('enquiries.show', $e))->assertOk()->assertSee('24 Oct 2026, 17:00');
        $this->get(route('enquiries.index'))->assertOk()->assertSee('Next: 24 Oct, 17:00')->assertViewHas('counts', fn ($c) => $c['overdue'] === 0 && $c['today'] === 1);
        $this->get(route('enquiries.index', ['view' => 'followups', 'queue' => 'overdue']))->assertOk()->assertDontSee('Call Asha');
        $this->get(route('enquiries.index', ['view' => 'followups', 'queue' => 'today']))->assertOk()->assertSee('Call Asha')->assertSee('24 Oct, 17:00');
        $this->travelTo(now()->setTime(17, 1));
        $this->assertTrue($task->due_at->isPast());
        $this->get(route('enquiries.index', ['view' => 'followups', 'queue' => 'overdue']))->assertOk()->assertSee('Call Asha')->assertViewHas('counts', fn ($c) => $c['overdue'] === 1);
        for ($i=0; $i<2; $i++) $this->post(route('enquiries.follow-up.complete', [$e, $task]), ['outcome' => 'connected', 'completion_note' => 'Requested quotation'])->assertSessionHasNoErrors();
        $this->assertSame(1, $e->activities()->where('type', 'followup.completed')->count());
        $this->get(route('enquiries.index', ['view' => 'followups']))->assertDontSee('Call Asha');
    }

    public function test_follow_up_today_queue_uses_local_midnight_with_existing_utc_rows(): void
    {
        $e = $this->enquiry();
        $this->post(route('enquiries.follow-up', $e), ['subject'=>'Midnight call','channel'=>'call','due_at'=>'2026-10-25T00:15'])->assertSessionHasNoErrors();
        $task=$e->followUps()->sole();
        $this->assertSame('2026-10-24 18:45:00', $task->getRawOriginal('due_at'));
        $this->get(route('enquiries.index', ['view'=>'followups','queue'=>'today']))->assertOk()->assertDontSee('Midnight call');
        $this->travelTo(now()->addDay()->startOfDay());
        $this->get(route('enquiries.index', ['view'=>'followups','queue'=>'today']))->assertOk()->assertSee('Midnight call')->assertSee('25 Oct, 00:15');
        $this->get(route('enquiries.index', ['view'=>'followups','queue'=>'overdue']))->assertOk()->assertDontSee('Midnight call');
        $this->travelTo(now()->setTime(0,16));
        $this->get(route('enquiries.index', ['view'=>'followups','queue'=>'overdue']))->assertOk()->assertSee('Midnight call');
    }

    public function test_product_tax_options_include_standard_and_saved_custom_rates_and_save_them(): void
    {
        $products=[];
        foreach ([0,5,12,18,28,7.5] as $rate) {
            $product=$this->product->replicate();
            $product->fill(['name'=>'Tax test '.$rate,'cgst_rate'=>$rate/2,'sgst_rate'=>$rate/2,'gst_calculation_mode'=>'exclusive','rental_price'=>100]);
            $product->save();$products[]=$product;
        }
        $e=$this->enquiry();
        $response=$this->get(route('quotations.create',$e))->assertOk();
        foreach ([0,5,12,18,28,7.5] as $rate) $response->assertSee('value="'.number_format($rate,2,'.','').'"',false);
        $this->fixture('sw-tax-product-selection',$response);
        foreach ($products as $i=>$product) {
            $enquiry=$i === 0 ? $e : $this->enquiry();
            $payload=$this->payload();$payload['lines']=[['product_id'=>$product->id,'quantity'=>1,'rate'=>100,'tax_rate'=>number_format($product->defaultGstRate(),2,'.',''),'tax_mode'=>'exclusive']];
            $this->post(route('quotations.store',$enquiry),$payload)->assertSessionHasNoErrors();
            $this->assertEquals($product->defaultGstRate(),$enquiry->quotations()->sole()->snapshot['tax']);
        }
    }

    public function test_lost_reason_is_required_and_active_quote_cannot_be_bypassed(): void
    {
        $q = $this->transition($this->quote(), 'send'); $e = $q->enquiry;
        $this->post(route('enquiries.stage', $e), ['stage' => 'lost', 'lock_version' => $e->lock_version])->assertSessionHasErrors('reason');
        $this->post(route('enquiries.stage', $e), ['stage' => 'lost', 'reason' => 'Budget', 'lock_version' => $e->lock_version])->assertSessionHasErrors('stage');
        $this->transition($q, 'withdraw'); $e->refresh();
        $this->post(route('enquiries.stage', $e), ['stage' => 'lost', 'reason' => 'Budget', 'lock_version' => $e->lock_version])->assertSessionHasNoErrors();
        $this->assertSame('lost', $e->fresh()->stage);
    }

    public function test_accepted_sale_converts_once_through_existing_stock_and_invoice_flow(): void
    {
        $q = $this->accepted('sale'); $payload = app(QuotationConversion::class)->prefill($q);
        $this->get(route('sales.create', ['quotation_id' => $q->id]))->assertOk()->assertSee('Creating an order from');
        $this->post(route('sales.store'), $payload)->assertSessionHasNoErrors()->assertRedirect(route('sales.index', ['sort_by' => 'latest']));
        $sale = Sale::sole(); $this->assertSame($q->snapshot['terms'], $sale->invoice()->firstOrFail()->terms_conditions); $this->assertEquals($q->snapshot['total'], $sale->sale_amount); $this->assertEquals(236, $sale->saleItems()->sole()->line_total);
        $this->assertSame('converted', $q->fresh()->status); $this->assertEquals($sale->id, $q->fresh()->order_id); $this->assertSame('won', $q->enquiry->fresh()->stage);
        $this->delete(route('sales.destroy', $sale))->assertSessionHas('error');
        $this->assertDatabaseHas('sales', ['id'=>$sale->id]);
        $this->post(route('sales.store'), $payload)->assertRedirect(route('sales.show', $sale));
        $this->assertDatabaseCount('sales', 1); $this->assertDatabaseCount('invoices', 1);
    }

    public function test_accepted_rental_converts_once_with_quoted_commercials(): void
    {
        $q = $this->accepted(); $payload = app(QuotationConversion::class)->prefill($q) + ['delivery_assignment_type' => 'customer_pickup'];
        $this->fixture('sw-rental-conversion', $this->get(route('rentals.create', ['quotation_id' => $q->id]))->assertOk());
        $this->post(route('rentals.store'), $payload)->assertSessionHasNoErrors()->assertRedirect(route('rentals.index', ['sort_by' => 'latest']));
        $rental = Rental::sole(); $this->assertSame($q->snapshot['terms'], \App\Models\Invoice::where('rental_id', $rental->id)->firstOrFail()->terms_conditions); $this->assertEquals(236, $rental->rentalItems()->sole()->line_total); $this->assertEquals(500, $rental->deposit_amount);
        $this->assertSame('converted', $q->fresh()->status);
        $this->delete(route('rentals.destroy', $rental))->assertSessionHas('error');
        $this->assertDatabaseHas('rentals', ['id'=>$rental->id]);
        $this->post(route('rentals.store'), $payload)->assertRedirect(route('rentals.show', $rental)); $this->assertDatabaseCount('rentals', 1);
    }

    public static function negotiatedRentalPrices(): array
    {
        return [
            'inclusive' => ['inclusive', 75.25, false],
            'exclusive' => ['exclusive', 75.25, true],
            'complimentary' => ['inclusive', 0.0, false],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('negotiatedRentalPrices')]
    public function test_rental_conversion_form_preserves_negotiated_primary_amount(string $mode, float $rate, bool $additionalLine): void
    {
        $enquiry = $this->enquiry();
        $quoteInput = $this->payload();
        $quoteInput['lines'][0]['rate'] = $rate;
        $quoteInput['lines'][0]['tax_mode'] = $mode;
        if ($additionalLine) {
            $second = $this->product->replicate();
            $second->name = 'Walking aid';
            $second->save();
            $quoteInput['lines'][] = ['product_id' => $second->id, 'quantity' => 3, 'rate' => 40.25, 'tax_rate' => 18, 'tax_mode' => 'inclusive'];
        }
        $this->post(route('quotations.store', $enquiry), $quoteInput)->assertSessionHasNoErrors();
        $quote = $this->transition($this->transition($enquiry->quotations()->sole(), 'send'), 'accepted');
        $payload = app(QuotationConversion::class)->prefill($quote) + ['delivery_assignment_type' => 'customer_pickup'];
        // The main field is only the primary quantity × quoted rate, before exclusive tax or charges.
        $this->assertEquals(round(2 * $rate, 2), $payload['rental_amount']);
        $fixture = $rate === 0.0 ? 'complimentary' : $mode;
        $this->fixture('sw-rental-conversion-'.$fixture, $this->get(route('rentals.create', ['quotation_id' => $quote->id]))->assertOk());
        $this->post(route('rentals.store'), $payload)->assertSessionHasNoErrors()->assertRedirect(route('rentals.index', ['sort_by' => 'latest']));
        $items = Rental::findOrFail($quote->fresh()->order_id)->rentalItems()->reorder('id')->get();
        $this->assertCount($additionalLine ? 2 : 1, $items);
        $this->assertEquals($rate, $items[0]->unit_rental_amount);
        $this->assertSame($mode, $items[0]->gst_mode);
        if ($additionalLine) $this->assertEquals(40.25, $items[1]->unit_rental_amount);
    }

    public function test_conversion_rejects_commercial_tampering_and_rolls_back_failed_stock(): void
    {
        $q = $this->accepted(); $payload = app(QuotationConversion::class)->prefill($q) + ['delivery_assignment_type' => 'customer_pickup'];
        $bad = $payload; $bad['rental_amount'] = 1;
        $this->post(route('rentals.store'), $bad)->assertSessionHasErrors('quotation_id');
        $bad = $payload; $bad['end_date'] = $bad['start_date'];
        $this->post(route('rentals.store'), $bad)->assertSessionHasErrors('quotation_id');
        $bad = $payload; $bad['other_amount'] = 100;
        $this->post(route('rentals.store'), $bad)->assertSessionHasErrors('quotation_id');
        $this->product->update(['available_quantity' => 0]);
        $this->post(route('rentals.store'), $payload)->assertSessionHasErrors();
        $this->assertDatabaseCount('rentals', 0); $this->assertDatabaseCount('invoices', 0); $this->assertSame('accepted', $q->fresh()->status);
    }

    public function test_quote_pdf_is_pdf_and_handles_long_terms(): void
    {
        $q = $this->quote(); $s = $q->snapshot; $s['terms'] = str_repeat('Terms with clear delivery, payment and return requirements. ', 100); $q->update(['snapshot' => $s]);
        $response = $this->get(route('quotations.pdf', $q))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $response->getContent());
        if ($dir = getenv('GETTING_STARTED_PREVIEW_DIR')) { if (!is_dir($dir)) mkdir($dir, 0777, true); file_put_contents($dir.'/quotation-long-terms.pdf', $response->getContent()); }
    }

    public function test_customer_pdf_requires_its_own_signature_and_current_quotation(): void
    {
        $q = $this->transition($this->quote(), 'send');
        $url = URL::temporarySignedRoute('quotation-share.pdf', $q->expiresAt(), ['quotation' => $q->id]);
        auth()->logout();
        $this->get(route('quotation-share.pdf', $q))->assertForbidden();
        $pdf = $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $this->actingAs($this->owner); $this->transition($q, 'revise'); auth()->logout();
        $this->get($url)->assertStatus(410);
    }

    public function test_region_changes_require_a_fresh_quotation_before_conversion(): void
    {
        $q = $this->accepted('sale'); $payload = app(QuotationConversion::class)->prefill($q);
        $this->owner->organization->update(['state' => 'Maharashtra']);
        $this->post(route('sales.store'), $payload)->assertSessionHasErrors('quotation_id');
        $this->assertDatabaseCount('sales', 0);
        $this->assertSame('accepted', $q->fresh()->status);
    }

    public function test_staff_response_requires_evidence_and_reassignment_removes_previous_owner_access(): void
    {
        $q = $this->transition($this->quote(), 'send');
        $this->post(route('quotations.action', $q), ['action' => 'accepted', 'lock_version' => $q->lock_version])->assertSessionHasErrors(['contact_name', 'note']);
        $role = $this->systemRole('sales');
        $a = TestData::user($this->owner->organization, ['role_id' => $role->id, 'role' => 'sales']);
        $b = TestData::user($this->owner->organization, ['role_id' => $role->id, 'role' => 'sales']);
        $q->enquiry->update(['owner_id' => $a->id]);
        $this->actingAs($a)->get(route('quotations.show', $q))->assertOk();
        $q->enquiry->update(['owner_id' => $b->id]);
        $this->get(route('quotations.show', $q))->assertNotFound();
        $this->post(route('quotations.action', $q), ['action' => 'accepted', 'lock_version' => $q->lock_version, 'contact_name' => 'Asha', 'note' => 'Telephone'])->assertNotFound();
    }

    public function test_quoted_customer_and_product_cannot_be_deleted_even_after_enquiry_contact_changes(): void
    {
        $q = $this->accepted('sale');
        $q->enquiry->update(['customer_id' => null]);
        $this->delete(route('customers.destroy', $this->customer))->assertRedirect()->assertSessionHas('error', fn ($s) => str_contains($s, 'enquiries or quotations'));
        $this->delete(route('products.destroy', $this->product))->assertRedirect()->assertSessionHas('error', fn ($s) => str_contains($s, 'quotations'));
        $this->delete(route('customers.bulk.delete'), ['customer_ids' => [$this->customer->id]])->assertRedirect()->assertSessionHas('error', fn ($s) => str_contains($s, 'enquiries or quotations'));
        $this->assertModelExists($this->customer); $this->assertModelExists($this->product);
        $this->assertSame('accepted', $q->fresh()->status);
        $this->post(route('sales.store'), app(QuotationConversion::class)->prefill($q))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('sales', 1);
    }
}
