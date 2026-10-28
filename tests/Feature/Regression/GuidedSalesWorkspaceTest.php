<?php

namespace Tests\Feature\Regression;

use App\Models\{Customer, Enquiry, EnquiryActivity, Product, Quotation, Role};
use App\Services\Sales\SalesWorkspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\TestData;
use Tests\TestCase;

class GuidedSalesWorkspaceTest extends TestCase
{
    use RefreshDatabase;
    private $owner;
    private $customer;
    private $product;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 25)->setTime(8, 0));
        $org = TestData::organization(['name'=>'Meridian Rentals', 'currency_code'=>'INR', 'timezone'=>'Asia/Kolkata', 'state'=>'Karnataka', 'country_code'=>'IN']);
        $this->owner = TestData::user($org);
        $this->customer = Customer::create(['organization_id'=>$org->id, 'name'=>'Asha Rao', 'phone'=>'+919876543210', 'email'=>'asha@example.test']);
        $this->product = Product::create(['organization_id'=>$org->id, 'name'=>'Hospital bed', 'product_type'=>'both', 'stock_mode'=>'untracked', 'is_rentable'=>true, 'is_sellable'=>true, 'total_quantity'=>0, 'available_quantity'=>0, 'price_per_day'=>118, 'rental_price'=>118, 'sale_price'=>590, 'gst_rate'=>18, 'gst_calculation_mode'=>'inclusive']);
        $this->actingAs($this->owner);
    }

    private function enquiry(array $extra=[]): Enquiry
    {
        $this->post(route('enquiries.store'), $extra + ['name'=>'Asha Rao', 'phone'=>'9876543210', 'email'=>'asha@example.test', 'subject'=>'Bed for recovery', 'owner_id'=>$this->owner->id, 'intent'=>'rental'])->assertSessionHasNoErrors();
        return Enquiry::latest('id')->firstOrFail();
    }

    private function payload(Enquiry $e, array $extra=[]): array
    {
        return $extra + ['kind'=>'rental', 'pricing_kind'=>'rental', 'customer_id'=>$this->customer->id, 'enquiry_lock_version'=>$e->fresh()->lock_version,
            'valid_until'=>'2026-11-01', 'start_date'=>'2026-10-26', 'end_date'=>'2026-11-24', 'tax_type'=>'cgst_sgst', 'transport'=>25, 'deposit'=>500,
            'lines'=>[['product_id'=>$this->product->id,'quantity'=>2,'rate'=>75.25,'tax_rate'=>18,'tax_mode'=>'inclusive']]];
    }

    private function quote(Enquiry $e): Quotation
    {
        $this->post(route('quotations.store',$e),$this->payload($e))->assertSessionHasNoErrors();
        return $e->quotations()->sole();
    }

    private function fixture(string $name, $response): void
    {
        if ($dir=getenv('GETTING_STARTED_PREVIEW_DIR')) {
            if (!is_dir($dir)) mkdir($dir,0777,true);
            file_put_contents($dir.'/'.$name.'.html',$response->getContent());
        }
    }

    public function test_enquiry_shortcut_persists_intent_and_opens_quote_without_qualifying(): void
    {
        $e=$this->enquiry(['intent'=>'sale','next'=>'quotation']);
        $this->assertSame('new',$e->stage);
        $this->assertSame('sale',$e->intent);
        $this->get(route('quotations.create',$e))->assertOk()->assertSee('data-original-kind="sale"',false)->assertSee('Select or add customer');
        $this->assertDatabaseCount('quotations',0);
    }

    public function test_shortcut_cannot_bypass_quotation_permissions_or_create_an_orphan_enquiry(): void
    {
        $role=Role::create(['organization_id'=>$this->owner->organization_id,'name'=>'Capture only','slug'=>'capture_only','scope_type'=>'all','is_active'=>true,'permissions'=>['enquiries'=>['read','create','update']]]);
        $user=TestData::user($this->owner->organization,['role_id'=>$role->id]);
        $this->actingAs($user)->post(route('enquiries.store'),['name'=>'Test','subject'=>'Test','owner_id'=>$user->id,'next'=>'quotation'])->assertForbidden();
        $this->assertDatabaseCount('enquiries',0);
    }

    public function test_inline_recipient_link_and_snapshot_are_saved_together_then_review_opens(): void
    {
        $e=$this->enquiry(['name'=>'Family contact']);
        $this->post(route('quotations.store',$e),$this->payload($e,['next'=>'review']))->assertSessionHasNoErrors()->assertSessionHas('review_ready',true);
        $q=$e->quotations()->sole();
        $this->assertEquals($this->customer->id,$e->fresh()->customer_id);
        $this->assertSame('Family contact',$q->snapshot['contact']['name']);
        $this->assertSame('Asha Rao',$q->snapshot['recipient']['name']);
        $this->fixture('sw-guided-review',$this->get(route('quotations.show',$q))->assertOk()->assertSee('id="quotation-review"',false));
        $this->assertSame('draft',$q->fresh()->status);
    }

    public function test_unlinked_customer_is_suggested_and_prefilled_but_never_auto_attached(): void
    {
        $e=$this->enquiry(['email'=>null]);
        $this->fixture('sw-guided-unlinked',$this->get(route('quotations.create',$e))->assertOk()->assertSee('value="Asha Rao"',false));
        $this->assertNull($e->fresh()->customer_id);
        $this->post(route('quotations.store',$e),$this->payload($e,['customer_id'=>null,'next'=>'review']))->assertSessionHasErrors('customer_id');
        $this->assertDatabaseCount('quotations',0);
        $this->flushSession();
        $this->post(route('quotations.store',$e),$this->payload($e,['customer_id'=>null,'next'=>'save']))->assertSessionHasNoErrors();
        $this->assertNull($e->quotations()->sole()->snapshot['customer_id']);
    }

    public function test_invalid_quote_rolls_back_customer_link_and_activity(): void
    {
        $e=$this->enquiry();
        $this->post(route('quotations.store',$e),$this->payload($e,['lines'=>[]]))->assertSessionHasErrors('lines');
        $this->assertNull($e->fresh()->customer_id);
        $this->assertSame(1,$e->fresh()->lock_version);
        $this->assertDatabaseMissing('enquiry_activities',['enquiry_id'=>$e->id,'type'=>'enquiry.customer']);
    }

    public function test_foreign_or_stale_customer_selection_is_rejected(): void
    {
        $e=$this->enquiry();
        $other=Customer::create(['organization_id'=>TestData::organization()->id,'name'=>'Foreign','phone'=>'9000000000']);
        $this->post(route('quotations.store',$e),$this->payload($e,['customer_id'=>$other->id]))->assertSessionHasErrors('customer_id');
        $this->post(route('quotations.store',$e),$this->payload($e,['enquiry_lock_version'=>999]))->assertSessionHasErrors('workspace');
        $this->assertNull($e->fresh()->customer_id);
        $this->assertDatabaseCount('quotations',0);
    }

    public function test_type_change_requires_explicit_price_confirmation_and_preserves_zero_rate(): void
    {
        $e=$this->enquiry(); $q=$this->quote($e);
        $p=$this->payload($e,['kind'=>'sale','pricing_kind'=>'sale','lock_version'=>$q->lock_version]);
        $p['lines'][0]['rate']=0;
        $this->put(route('quotations.update',$q),$p)->assertSessionHasErrors('kind');
        $this->assertSame('rental',$q->fresh()->kind);
        $this->flushSession();
        $this->put(route('quotations.update',$q),$p+['kind_confirmed'=>1])->assertSessionHasNoErrors();
        $this->assertSame('sale',$q->fresh()->kind);
        $this->assertEquals(0,$q->fresh()->snapshot['lines'][0]['rate']);
        $this->assertEquals(25,$q->fresh()->snapshot['total']);
        $this->assertEquals(0,$q->fresh()->snapshot['deposit']);
    }

    public function test_type_confirmation_does_not_allow_an_ineligible_product(): void
    {
        $this->product->update(['product_type'=>Product::TYPE_RENTABLE,'is_sellable'=>false]);
        $e=$this->enquiry();
        $this->post(route('quotations.store',$e),$this->payload($e,['kind'=>'sale','pricing_kind'=>'sale','kind_confirmed'=>1]))->assertSessionHasErrors();
        $this->assertDatabaseCount('quotations',0);
    }

    public function test_sent_revision_remains_immutable_when_recipient_changes(): void
    {
        $e=$this->enquiry();$q=$this->quote($e);$snapshot=$q->snapshot;
        $this->post(route('quotations.action',$q),['action'=>'send','lock_version'=>$q->lock_version])->assertSessionHasNoErrors();
        $this->put(route('quotations.update',$q),$this->payload($e,['customer_id'=>null,'lock_version'=>$q->fresh()->lock_version]))->assertStatus(409);
        $this->assertSame($snapshot,$q->fresh()->snapshot);
        $this->assertEquals($this->customer->id,$e->fresh()->customer_id);
    }

    public function test_finalising_and_opening_link_do_not_claim_message_was_sent(): void
    {
        Mail::fake();$e=$this->enquiry();$q=$this->quote($e);
        $this->post(route('quotations.action',$q),['action'=>'send','lock_version'=>$q->lock_version])->assertSessionHasNoErrors();
        $this->assertSame('Ready to share',$q->fresh()->statusLabel());
        $url=app(SalesWorkspace::class)->shareUrl($q->fresh());
        $this->get($url)->assertOk();
        $this->assertNull($q->fresh()->shared_at);
        $this->get(route('quotations.show',$q))->assertOk()->assertSee('I have sent this quotation')->assertSee('Remind me tomorrow');
        $version=$q->fresh()->lock_version;
        $this->post(route('quotations.action',$q),['action'=>'mark_shared','shared_channel'=>'whatsapp','lock_version'=>$version])->assertSessionHasNoErrors();
        $this->assertSame('Awaiting response',$q->fresh()->statusLabel());
        $this->assertSame('whatsapp',$q->fresh()->shared_channel);
        $this->post(route('quotations.action',$q),['action'=>'mark_shared','shared_channel'=>'email','lock_version'=>$version])->assertSessionHasErrors('workspace');
        $this->assertEquals(1,EnquiryActivity::where('enquiry_id',$e->id)->where('type','quotation.shared')->count());
        Mail::assertNothingSent();Mail::assertNothingQueued();
    }

    public function test_draft_cannot_be_marked_shared_and_read_only_users_cannot_use_editor(): void
    {
        $e=$this->enquiry();$q=$this->quote($e);
        $this->post(route('quotations.action',$q),['action'=>'mark_shared','shared_channel'=>'email','lock_version'=>$q->lock_version])->assertStatus(409);
        $role=Role::create(['organization_id'=>$this->owner->organization_id,'name'=>'Read sales','slug'=>'read_sales','scope_type'=>'all','is_active'=>true,'permissions'=>['enquiries'=>['read'],'quotations'=>['read']]]);
        $this->actingAs(TestData::user($this->owner->organization,['role_id'=>$role->id]))->get(route('quotations.show',$q))->assertOk()->assertDontSee('id="quotation-form"',false)->assertDontSee('Create sharing link');
    }

    public function test_quote_document_includes_frozen_logo_date_and_contact_without_zero_tax_added_label(): void
    {
        $logo='data:image/png;base64,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';
        $logoName=uniqid('guided-quote-logo-',true).'.png';
        $logoPath=storage_path('app/public/'.$logoName);
        \Illuminate\Support\Facades\File::ensureDirectoryExists(dirname($logoPath));
        file_put_contents($logoPath,base64_decode(substr($logo,strpos($logo,',')+1)));
        try {
            $this->owner->organization->update(['logo'=>$logoName]);
            $e=$this->enquiry(['name'=>'Family contact']);$p=$this->payload($e);$p['lines'][0]['tax_rate']=0;
            $this->post(route('quotations.store',$e),$p)->assertSessionHasNoErrors();$q=$e->quotations()->sole();
        } finally { @unlink($logoPath); }
        // The document must retain its image even after the original upload is removed.
        $html=view('sales-workspace.quote-pdf',compact('q')+['quote'=>$q])->render();
        $this->assertStringContainsString('Issued 25 Oct 2026',$html);
        $this->assertStringContainsString('Enquiry contact: Family contact',$html);
        $this->assertStringNotContainsString('Added',$html);
        $this->assertSame($logo,$q->snapshot['issuer']['logo']);
        $this->owner->organization->update(['logo'=>null]);
        $this->assertSame($logo,$q->fresh()->snapshot['issuer']['logo']);
        $response=$this->get(route('quotations.pdf',$q))->assertOk()->assertHeader('Content-Type','application/pdf');
        if($dir=getenv('GETTING_STARTED_PREVIEW_DIR')) file_put_contents($dir.'/quotation-guided.pdf',$response->getContent());
        $snapshot=$q->snapshot;unset($snapshot['contact'],$snapshot['issuer']['logo']);$q->update(['snapshot'=>$snapshot]);
        $this->get(route('quotations.pdf',$q))->assertOk();
    }

    public function test_guided_draft_browser_fixture_keeps_negotiated_price_on_load(): void
    {
        $e=$this->enquiry();$q=$this->quote($e);
        $rentalOnly=$this->product->replicate();$rentalOnly->name='Rental only';$rentalOnly->product_type=Product::TYPE_RENTABLE;$rentalOnly->save();
        $this->fixture('sw-guided-draft',$this->get(route('quotations.show',$q))->assertOk());
    }
}
