<?php

namespace Tests\Feature\Regression;

use App\Models\{Customer, Enquiry, Product, Quotation, Rental, Sale, Warehouse};
use App\Services\Sales\{QuotationConversion, SalesWorkspace};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\TestData;
use Tests\TestCase;

class ProspectQuotationFlowTest extends TestCase
{
    use RefreshDatabase;
    private $owner, $product;

    protected function setUp(): void
    {
        parent::setUp(); Mail::fake();
        $this->travelTo(now()->setDate(2026,11,2)->setTime(10,0));
        $org=TestData::organization(['country_code'=>'IN','state'=>'Karnataka','currency_code'=>'INR']);
        $this->owner=TestData::user($org);$this->actingAs($this->owner);
        Warehouse::create(['organization_id'=>$org->id,'name'=>'Synthetic warehouse','is_active'=>true]);
        $this->product=Product::create(['organization_id'=>$org->id,'name'=>'Synthetic oxygen concentrator','product_type'=>'both',
            'stock_mode'=>'untracked','is_rentable'=>true,'is_sellable'=>true,'total_quantity'=>20,'available_quantity'=>20,'price_per_day'=>100,'rental_price'=>700,'sale_price'=>700]);
    }

    private function enquiry(): Enquiry
    {
        $this->post(route('enquiries.store'),['name'=>'Synthetic prospect','phone'=>'+919876543210','email'=>'prospect@example.test',
            'subject'=>'Oxygen concentrator for a week','requirements'=>'Call before preparing quotation','source'=>'Phone','owner_id'=>$this->owner->id,'next'=>'quotation'])
            ->assertSessionHasNoErrors()->assertRedirect(route('enquiries.show',1));
        return Enquiry::sole();
    }

    private function draft(string $kind='rental'): Quotation
    {
        $enquiry=$this->enquiry();
        $this->post(route('quotations.store',$enquiry),['kind'=>$kind,'valid_until'=>'2026-11-20','start_date'=>'2026-11-03','end_date'=>'2026-11-09',
            'transport'=>50,'deposit'=>500,'tax_type'=>'cgst_sgst','customer_id'=>null,'enquiry_lock_version'=>$enquiry->lock_version,'next'=>'review',
            'lines'=>[['product_id'=>$this->product->id,'quantity'=>1,'rate'=>700,'tax_rate'=>18,'tax_mode'=>'inclusive']]])
            ->assertSessionHasNoErrors()->assertSessionHas('review_ready',true);
        return Quotation::sole();
    }

    private function action(Quotation $q,string $action): Quotation
    {
        $this->post(route('quotations.action',$q),['action'=>$action,'lock_version'=>$q->fresh()->lock_version,'contact_name'=>'Synthetic prospect','note'=>'Confirmed by telephone'])
            ->assertSessionHasNoErrors();
        return $q->fresh();
    }

    private function fixture(string $name,$response): void
    {
        if($folder=getenv('PHOS_PROSPECT_RENDER_DIR')) {
            if(!is_dir($folder))mkdir($folder,0700,true);
            file_put_contents($folder.'/'.$name.'.html',$response->getContent());
        }
    }

    public function test_enquiry_save_shows_filled_record_and_optional_followup_and_quote_without_creating_customer(): void
    {
        $this->fixture('capture',$this->get(route('enquiries.create'))->assertOk()->assertSee('Save enquiry')->assertDontSee('Save &amp; create quotation',false)->assertDontSee('salesQuickCustomerForm'));
        $e=$this->enquiry();
        $this->fixture('enquiry',$this->get(route('enquiries.show',$e))->assertOk()->assertSee('Create quotation')->assertSee('Follow-ups')->assertSee('Call before preparing quotation')->assertSee('Synthetic prospect'));
        $this->post(route('enquiries.follow-up',$e),['channel'=>'call','subject'=>'Discuss requirements','due_at'=>'2026-11-03T17:00'])->assertSessionHasNoErrors();
        $this->assertSame(1,$e->followUps()->count());$this->assertDatabaseCount('quotations',0);$this->assertDatabaseCount('customers',0);
    }

    public function test_prospect_can_review_share_pdf_and_accept_without_customer_or_full_address(): void
    {
        $q=$this->draft();
        $this->assertNull($q->snapshot['customer_id']);$this->assertSame('',$q->snapshot['recipient']['address']);
        $this->fixture('review',$this->get(route('quotations.show',$q))->assertOk()->assertSee('id="quotation-review"',false)->assertDontSee('salesQuickCustomerForm'));
        $q=$this->action($q,'send');$snapshot=$q->snapshot;
        $this->fixture('shared',$this->get(route('quotations.show',$q))->assertOk()->assertSee('Synthetic prospect')->assertSee('wa.me/919876543210'));
        $this->get(route('quotations.pdf',$q))->assertOk()->assertHeader('Content-Type','application/pdf');
        $this->get(app(SalesWorkspace::class)->shareUrl($q))->assertOk()->assertSee('Synthetic prospect');
        $q=$this->action($q,'accepted');
        $this->assertSame('accepted',$q->status);$this->assertSame($snapshot,$q->snapshot);
        $this->assertNull($q->enquiry->customer_id);$this->assertDatabaseCount('customers',0);$this->assertDatabaseCount('rentals',0);$this->assertDatabaseCount('sales',0);
        Mail::assertNothingSent();Mail::assertNothingQueued();
    }

    public function test_new_customer_is_linked_only_when_accepted_rental_is_created_and_retries_are_safe(): void
    {
        $q=$this->action($this->action($this->draft(),'send'),'accepted');
        $this->fixture('order',$this->get(route('rentals.create',['quotation_id'=>$q->id]))->assertOk()->assertSee('quotation_recipient_confirmed')->assertSee('value="Synthetic prospect"',false));
        $this->assertDatabaseCount('customers',0);
        $customer=Customer::create(['organization_id'=>$this->owner->organization_id,'name'=>'Confirmed billing contact','phone'=>'+919876543210','address'=>'Confirmed delivery address','state'=>'Karnataka']);
        $data=array_replace(app(QuotationConversion::class)->prefill($q),['customer_id'=>$customer->id,'delivery_assignment_type'=>'customer_pickup']);
        $this->post(route('rentals.store'),$data)->assertSessionHasErrors('quotation_recipient_confirmed');
        $this->assertNull($q->enquiry->fresh()->customer_id);$this->assertDatabaseCount('rentals',0);
        $data['quotation_recipient_confirmed']=1;
        $this->post(route('rentals.store'),$data)->assertSessionHasNoErrors();
        $rental=Rental::sole();$this->assertEquals($customer->id,$rental->customer_id);
        $this->assertEquals($customer->id,$q->enquiry->fresh()->customer_id);
        $this->assertNull($q->fresh()->snapshot['customer_id']);$this->assertSame('Synthetic prospect',$q->fresh()->snapshot['recipient']['name']);
        $this->post(route('rentals.store'),$data)->assertRedirect(route('rentals.show',$rental));
        $this->assertDatabaseCount('rentals',1);$this->assertDatabaseCount('customers',1);
    }

    public function test_sale_conversion_requires_explicit_customer_confirmation_and_rejects_foreign_customer(): void
    {
        $q=$this->action($this->action($this->draft('sale'),'send'),'accepted');
        $customer=Customer::create(['organization_id'=>$this->owner->organization_id,'name'=>'Confirmed customer','phone'=>'+919876543210','state'=>'Karnataka']);
        $other=Customer::create(['organization_id'=>TestData::organization()->id,'name'=>'Foreign','phone'=>'+919876543211']);
        $data=array_replace(app(QuotationConversion::class)->prefill($q),['customer_id'=>$other->id,'quotation_recipient_confirmed'=>1]);
        $this->post(route('sales.store'),$data)->assertSessionHasErrors('customer_id');$this->assertDatabaseCount('sales',0);
        $data['customer_id']=$customer->id;
        $this->post(route('sales.store'),$data)->assertSessionHasNoErrors();
        $this->assertSame('converted',$q->fresh()->status);$this->assertEquals($customer->id,Sale::sole()->customer_id);
        $this->assertEquals($customer->id,$q->enquiry->fresh()->customer_id);
    }

    public function test_changed_enquiry_contact_must_be_reviewed_before_finalising_a_prospect_quote(): void
    {
        $q=$this->draft();$q->enquiry->update(['phone'=>'+919999999999']);
        $this->post(route('quotations.action',$q),['action'=>'send','lock_version'=>$q->lock_version])->assertSessionHasErrors('workspace');
        $this->assertSame('draft',$q->fresh()->status);$this->assertDatabaseCount('customers',0);
    }
}
