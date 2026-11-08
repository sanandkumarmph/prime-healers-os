<?php

namespace Tests\Feature\Regression;

use App\Models\{Customer, Enquiry, Product, Quotation, RentalAgreement, Role};
use App\Services\Sales\{QuotationSharing, RentalAgreements};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Mail, Schema};
use Tests\Support\TestData;
use Tests\TestCase;

class EnquiryQuotationUsabilityTest extends TestCase
{
    use RefreshDatabase;
    private $owner, $product;

    protected function setUp(): void
    {
        parent::setUp(); Mail::fake();config(['app.rental_agreements'=>true]);
        $this->travelTo(now('Asia/Kolkata')->setDate(2026,11,8)->setTime(10,0));
        $org=TestData::organization(['currency_code'=>'INR','country_code'=>'IN','state'=>'Karnataka']);
        $this->owner=TestData::user($org);$this->actingAs($this->owner);
        $this->product=Product::create(['organization_id'=>$org->id,'name'=>'Synthetic concentrator','product_type'=>'both','stock_mode'=>'untracked',
            'is_rentable'=>true,'is_sellable'=>true,'total_quantity'=>10,'available_quantity'=>10,'rental_price'=>3500,'sale_price'=>35000,'price_per_day'=>100]);
    }

    private function enquiry(array $extra=[]): Enquiry
    {
        $this->post(route('enquiries.store'),$extra+['name'=>'Helen Rao','salutation'=>'Ms.','phone'=>'+919876543210','email'=>'helen@example.test',
            'subject'=>'Concentrator for a month','source'=>'incoming_phone','owner_id'=>$this->owner->id,'intent'=>'rental'])->assertSessionHasNoErrors();
        return Enquiry::latest('id')->firstOrFail();
    }

    private function draft(Enquiry $enquiry): Quotation
    {
        $this->post(route('quotations.store',$enquiry),['kind'=>'rental','valid_until'=>'2026-11-20','start_date'=>'2026-11-09','end_date'=>'2026-12-08',
            'transport'=>600,'deposit'=>2000,'customer_id'=>null,'enquiry_lock_version'=>$enquiry->fresh()->lock_version,'tax_type'=>'cgst_sgst',
            'lines'=>[['product_id'=>$this->product->id,'quantity'=>1,'rate'=>3500,'tax_rate'=>5,'tax_mode'=>'inclusive']]])->assertSessionHasNoErrors();
        return $enquiry->quotations()->sole();
    }

    private function action(Quotation $quote,string $action)
    {
        return $this->post(route('quotations.action',$quote),['lock_version'=>$quote->fresh()->lock_version,'action'=>$action,
            'contact_name'=>'Helen Rao','note'=>'Customer confirmed by phone.']);
    }

    private function fixture(string $name,$response): void
    {
        if ($folder=getenv('PHOS_ENQUIRY_QUOTE_USABILITY_RENDER_DIR')) {
            if (!is_dir($folder)) mkdir($folder,0700,true);
            file_put_contents($folder.'/'.$name.'.html',$response->getContent());
        }
    }

    public function test_salutation_is_saved_separately_and_recipient_snapshot_is_compatible(): void
    {
        $this->assertTrue(Schema::hasColumn('enquiries','salutation'));
        $enquiry=$this->enquiry();$this->assertSame('Ms.',$enquiry->salutation);$this->assertSame('Helen Rao',$enquiry->name);
        $this->assertSame('Ms. Helen Rao',$enquiry->display_name);$this->assertSame('Ms. Helen Rao',Enquiry::contactName('Ms. Helen Rao','Ms.'));
        $quote=$this->draft($enquiry);
        $this->assertSame('Ms.',$quote->snapshot['recipient']['salutation']);$this->assertSame('helen@example.test',$quote->snapshot['recipient']['email']);
        $this->assertSame('Helen Rao',$quote->snapshot['recipient']['first_name']);
        $this->assertStringContainsString('Hi Ms. Helen Rao,',app(QuotationSharing::class)->message($quote,'https://example.test/quote'));
        $legacy=$quote->snapshot;unset($legacy['contact']['salutation'],$legacy['recipient']['salutation']);$quote->update(['snapshot'=>$legacy]);
        $enquiry->update(['salutation'=>null]);
        $this->action($quote,'send')->assertSessionHasNoErrors();
        $this->assertSame('sent',$quote->fresh()->status);
    }

    public function test_invalid_salutation_and_contact_changes_are_guarded(): void
    {
        $this->post(route('enquiries.store'),['name'=>'Test','subject'=>'Test','source'=>'incoming_phone','owner_id'=>$this->owner->id,'salutation'=>'<script>'])
            ->assertSessionHasErrors('salutation');$this->assertDatabaseCount('enquiries',0);
        $enquiry=$this->enquiry();$quote=$this->draft($enquiry);$enquiry->update(['salutation'=>'Dr.']);
        $this->action($quote,'send')->assertSessionHasErrors('workspace');$this->assertSame('draft',$quote->fresh()->status);
    }

    public function test_single_customer_picker_is_tenant_scoped_and_exports_browser_fixtures(): void
    {
        Customer::create(['organization_id'=>$this->owner->organization_id,'name'=>'Alice Existing','phone'=>'+919000000001','email'=>'alice@example.test','salutation'=>'Dr.']);
        Customer::create(['organization_id'=>$this->owner->organization_id,'name'=>'Bob Existing','phone'=>'+919000000002','email'=>'bob@example.test']);
        Customer::create(['organization_id'=>TestData::organization()->id,'name'=>'Foreign hidden customer','phone'=>'+919000000003','email'=>'foreign@example.test']);
        $page=$this->get(route('enquiries.create'))->assertOk()->assertSee('Existing customer (optional)')->assertDontSee('Find a customer')
            ->assertSee('alice@example.test')->assertDontSee('Foreign hidden customer')->assertSee('id="salutation"',false);
        $this->fixture('enquiry-capture',$page);
        $enquiry=$this->enquiry();$quote=$this->draft($enquiry);
        $this->fixture('quotation-draft',$this->get(route('quotations.edit',$quote))->assertOk()->assertDontSee('Find a customer'));
        $this->action($quote,'send')->assertSessionHasNoErrors();
        $page=$this->get(route('quotations.show',$quote))->assertOk()->assertSeeInOrder(['data-share-step="quotation"','data-share-step="terms"','data-share-step="order"'],false)
            ->assertSee('Copy quotation message')->assertSee('Copy rental terms message')->assertSee('data-edit-quotation',false);
        $this->fixture('quotation-sharing',$page);
    }

    public function test_editing_accepted_quote_creates_guarded_revision_preserving_signed_history(): void
    {
        $enquiry=$this->enquiry();$quote=$this->draft($enquiry);$this->action($quote,'send')->assertSessionHasNoErrors();
        $this->action($quote,'accepted')->assertSessionHasNoErrors();
        $service=app(RentalAgreements::class);$agreement=$service->quotation($quote->fresh());
        $image=imagecreatetruecolor(100,50);imagefill($image,0,0,0xffffff);imageline($image,5,40,80,10,0x172b4d);ob_start();imagepng($image);$signature=base64_encode(ob_get_clean());imagedestroy($image);
        $agreement->update(['signed_at'=>now(),'terms'=>'Immutable accepted terms','proof'=>['name'=>'Synthetic signer','signature'=>$signature,'id_reference'=>null]]);
        $before=$quote->fresh()->snapshot;
        $this->get(route('quotations.show',$quote))->assertOk()->assertSee('Edit quotation')
            ->assertSee('data-action-key="ready_order"',false)
            ->assertSee('href="'.route('rentals.create',['quotation_id'=>$quote->id]).'"',false);
        $this->action($quote,'revise')->assertSessionHasNoErrors()->assertRedirect(route('quotations.edit',$quote->id+1));
        $revision=$enquiry->quotations()->latest('revision')->first();
        $this->assertSame('draft',$revision->status);$this->assertNull($revision->accepted_at);$this->assertSame($before,$revision->snapshot);
        $this->assertSame('superseded',$quote->fresh()->status);$this->assertSame('Immutable accepted terms',$agreement->fresh()->terms);
        $this->assertNull($service->quotation($revision));$this->assertDatabaseCount('rentals',0);Mail::assertNothingSent();
        $this->get(route('quotations.show',$quote))->assertOk()->assertDontSee('data-edit-quotation',false);
    }

    public function test_revision_edit_button_and_action_require_create_permission(): void
    {
        $enquiry=$this->enquiry();$quote=$this->draft($enquiry);$this->action($quote,'send')->assertSessionHasNoErrors();
        $role=Role::create(['organization_id'=>$this->owner->organization_id,'name'=>'Quotation updater','slug'=>'quote_updater','scope_type'=>'all','is_active'=>true,
            'permissions'=>['enquiries'=>['read','update'],'quotations'=>['read','update']]]);
        $user=TestData::user($this->owner->organization,['role_id'=>$role->id]);$this->actingAs($user);
        $this->get(route('quotations.show',$quote))->assertOk()->assertDontSee('data-edit-quotation',false);
        $this->action($quote,'revise')->assertForbidden();$this->assertDatabaseCount('quotations',1);
    }
}
