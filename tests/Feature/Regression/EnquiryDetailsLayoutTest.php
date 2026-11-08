<?php

namespace Tests\Feature\Regression;

use App\Models\{Enquiry, EnquiryFollowUp, Product, Quotation, RentalAgreement, Role};
use App\Services\Sales\RentalAgreements;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\TestData;
use Tests\TestCase;

class EnquiryDetailsLayoutTest extends TestCase
{
    use RefreshDatabase;
    private $owner;
    private Enquiry $enquiry;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp(); Mail::fake();
        config(['app.rental_agreements'=>true]);
        $this->travelTo(now('Asia/Kolkata')->setDate(2026,11,7)->setTime(10,15));
        $org=TestData::organization(['country_code'=>'IN','state'=>'Karnataka','currency_code'=>'INR']);
        $this->owner=TestData::user($org,['name'=>'Synthetic Sales Owner']);
        $this->actingAs($this->owner);
        $this->enquiry=Enquiry::create(['organization_id'=>$org->id,'created_by_user_id'=>$this->owner->id,'owner_id'=>$this->owner->id,
            'name'=>'Synthetic enquiry customer','phone'=>'+919000000001','email'=>'synthetic@example.test',
            'subject'=>'Oxygen concentrator rental for home recovery','requirements'=>'Confirm the delivery time. Customer needs a portable concentrator and nasal cannula.',
            'intent'=>'rental','source'=>'incoming_phone','stage'=>'qualified']);
        $this->product=Product::create(['organization_id'=>$org->id,'name'=>'Synthetic oxygen concentrator 5 LPM','product_type'=>'both',
            'stock_mode'=>'untracked','is_rentable'=>true,'is_sellable'=>true,'total_quantity'=>20,'available_quantity'=>20,
            'price_per_day'=>100,'rental_price'=>3500,'sale_price'=>35000]);
    }

    private function draft(): Quotation
    {
        $this->post(route('quotations.store',$this->enquiry),['kind'=>'rental','valid_until'=>'2026-11-20','start_date'=>'2026-11-08','end_date'=>'2026-12-07',
            'transport'=>600,'deposit'=>2000,'tax_type'=>'cgst_sgst','customer_id'=>null,'enquiry_lock_version'=>$this->enquiry->fresh()->lock_version,
            'lines'=>[['product_id'=>$this->product->id,'quantity'=>1,'rate'=>3500,'tax_rate'=>5,'tax_mode'=>'inclusive']]])
            ->assertSessionHasNoErrors();
        return Quotation::sole();
    }

    private function transition(Quotation $quote,string $action): Quotation
    {
        $this->post(route('quotations.action',$quote),['action'=>$action,'lock_version'=>$quote->fresh()->lock_version,
            'contact_name'=>'Synthetic enquiry customer','note'=>'Confirmed during phone call'])->assertSessionHasNoErrors();
        return $quote->fresh();
    }

    private function sign(Quotation $quote): RentalAgreement
    {
        $agreements=app(RentalAgreements::class);$agreement=$agreements->quotation($quote);
        $image=imagecreatetruecolor(760,280);imagefill($image,0,0,0xffffff);imagesetthickness($image,5);
        imageline($image,70,210,230,80,0x172b4d);imageline($image,230,80,470,190,0x172b4d);
        ob_start();imagepng($image);$signature=base64_encode(ob_get_clean());imagedestroy($image);
        $agreements->sign($agreement,['name'=>'Synthetic signer','signature'=>$signature,'id_reference'=>null],$quote);
        return $agreement->fresh();
    }

    private function page(string $fixture)
    {
        $response=$this->get(route('enquiries.show',$this->enquiry))->assertOk()->assertSee('sw-detail',false);
        foreach (['enquiry-details','enquiry-next','enquiry-response','enquiry-followups','enquiry-quotations','enquiry-activity'] as $anchor) {
            $response->assertSee('id="'.$anchor.'"',false);
        }
        $dom=new \DOMDocument();@$dom->loadHTML($response->getContent());$xpath=new \DOMXPath($dom);
        $this->assertCount(4,$xpath->query('//nav[@aria-label="Sales progress"]//a'));
        $this->assertCount(1,$xpath->query('//nav[@aria-label="Sales progress"]//a[@aria-current="step"]'));
        if ($folder=getenv('PHOS_ENQUIRY_DETAILS_RENDER_DIR')) {
            if (!is_dir($folder)) mkdir($folder,0700,true);
            file_put_contents($folder.'/'.$fixture.'.html',$response->getContent());
        }
        return $response;
    }

    public function test_no_quotation_details_keep_contact_next_step_followups_and_activity_together(): void
    {
        EnquiryFollowUp::create(['enquiry_id'=>$this->enquiry->id,'subject'=>'Confirm equipment availability','channel'=>'call',
            'due_at'=>'2026-11-08T04:30:00Z']);
        $response=$this->page('noquote')->assertSee('Create quotation')->assertSee('Synthetic enquiry customer')
            ->assertSee('Incoming phone call')->assertSee('Confirm equipment availability')->assertSee('Confirm the delivery time.');
        $response->assertDontSee('Create Rental');
        $this->assertDatabaseCount('quotations',0);$this->assertDatabaseCount('rental_agreements',0);
    }

    public function test_draft_and_sent_unsigned_states_do_not_offer_order_creation(): void
    {
        $quote=$this->draft();
        $this->page('draft')->assertSee('Complete the quotation')->assertDontSee(route('rentals.create',['quotation_id'=>$quote->id]));
        $quote=$this->transition($quote,'send');
        $this->page('unsigned')->assertSee('Awaiting signature')->assertSee('Send rental terms on WhatsApp')
            ->assertDontSee(route('rentals.create',['quotation_id'=>$quote->id]));
        $this->assertDatabaseCount('rentals',0);Mail::assertNothingSent();
    }

    public function test_signing_alone_keeps_acceptance_step_and_signed_accepted_state_opens_rental_creation(): void
    {
        $quote=$this->transition($this->draft(),'send');$this->sign($quote);
        $this->page('signed')->assertSee('Record quotation response')->assertSee('Download signed PDF')
            ->assertDontSee(route('rentals.create',['quotation_id'=>$quote->id]));
        $this->assertSame('sent',$quote->fresh()->status);
        $quote=$this->transition($quote,'accepted');
        $this->page('ready')->assertSee('Create Rental')->assertSee(route('rentals.create',['quotation_id'=>$quote->id]));
        $this->assertDatabaseCount('rentals',0);$this->assertDatabaseCount('customers',0);
    }

    public function test_lost_record_keeps_readable_history_without_followup_or_order_mutations(): void
    {
        $this->enquiry->update(['stage'=>'lost','lost_reason'=>'Customer postponed the requirement.']);
        $response=$this->page('lost')->assertSee('Customer postponed the requirement.')->assertDontSee('Create quotation')->assertDontSee('Create Rental');
        $response->assertDontSee(route('enquiries.follow-up',$this->enquiry));
    }

    public function test_read_only_staff_cannot_see_mutation_controls_or_reach_other_organizations(): void
    {
        $quote=$this->transition($this->draft(),'send');$this->sign($quote);$this->transition($quote,'accepted');
        $role=Role::create(['organization_id'=>$this->owner->organization_id,'name'=>'Read only enquiries','slug'=>'read_only_enquiries','scope_type'=>'all','is_active'=>true,
            'permissions'=>['enquiries'=>['read'],'quotations'=>['read']]]);
        $reader=TestData::user($this->owner->organization,['role_id'=>$role->id]);$this->actingAs($reader);
        $this->page('readonly')->assertDontSee('Edit details')->assertDontSee('Create Rental')->assertDontSee('Save quotation response')
            ->assertDontSee('Remind me tomorrow')->assertDontSee('Add note')->assertDontSee('Update stage / close enquiry');
        $this->post(route('enquiries.note',$this->enquiry),['note'=>'Unauthorized note'])->assertForbidden();
        $this->actingAs(TestData::user())->get(route('enquiries.show',$this->enquiry))->assertNotFound();
    }

    public function test_success_notice_and_error_forms_survive_compact_sections(): void
    {
        $quote=$this->transition($this->draft(),'send');
        $this->get(route('enquiries.show',$this->enquiry))->assertOk();
        $this->withSession(['success'=>'Follow-up completed successfully.'])->get(route('enquiries.show',$this->enquiry))
            ->assertOk()->assertSee('Follow-up completed successfully.');
        $this->from(route('enquiries.show',$this->enquiry))->post(route('quotations.action',$quote),[
            'action'=>'accepted','lock_version'=>$quote->lock_version,'return_to_enquiry'=>1])->assertSessionHasErrors('note');
        $response=$this->get(route('enquiries.show',$this->enquiry))->assertOk();
        $dom=new \DOMDocument();@$dom->loadHTML($response->getContent());$xpath=new \DOMXPath($dom);
        $this->assertCount(1,$xpath->query('//*[@id="enquiry-response"]//details[@open]'));
    }
}
