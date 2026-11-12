<?php

namespace Tests\Feature\Regression;

use App\Models\{Enquiry, EnquiryFollowUp, Quotation, RentalAgreement, Role, User};
use App\Services\Sales\{RentalAgreements, SalesNextAction};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class SalesNextActionTest extends TestCase
{
    use RefreshDatabase;
    private User $sales;
    private Enquiry $enquiry;
    protected function setUp(): void
    {
        parent::setUp();config(['app.rental_agreements'=>true]);$this->travelTo(now('Asia/Kolkata')->setDate(2026,11,8)->setTime(12,0));
        $organization=TestData::organization();$role=Role::create(['organization_id'=>$organization->id,'name'=>'Sales','slug'=>'sales','is_active'=>true,'is_system'=>true,'permissions'=>Role::defaultSystemRoleTemplates()['sales']['permissions']]);
        $this->sales=TestData::user($organization,['role'=>User::ROLE_SALES,'role_id'=>$role->id]);$this->actingAs($this->sales);
        $this->enquiry=Enquiry::create(['organization_id'=>$organization->id,'owner_id'=>$this->sales->id,'name'=>'Synthetic customer','subject'=>'Synthetic next action','intent'=>'rental','source'=>'incoming_phone','stage'=>'qualified']);
    }
    private function quote(string $status='sent'): Quotation
    {
        return $this->enquiry->quotations()->create(['organization_id'=>$this->enquiry->organization_id,'revision'=>1,'kind'=>'rental','status'=>$status,'valid_until'=>'2026-11-20','snapshot'=>['timezone'=>'Asia/Kolkata']]);
    }
    private function action(?Quotation $quote=null,?RentalAgreement $agreement=null): array {return app(SalesNextAction::class)->for($this->sales,$this->enquiry->fresh(),$quote,$agreement);}
    public function test_each_stage_has_one_honest_recommendation_and_order_needs_acceptance_and_terms(): void
    {
        $this->assertSame('new_enquiry',$this->action()['key']);$quote=$this->quote('draft');$this->assertSame('draft_quote',$this->action($quote)['key']);
        $quote->update(['status'=>'sent']);$this->assertSame('share_quote',$this->action($quote)['key']);
        $quote->update(['shared_at'=>now()]);$this->assertSame('terms_pending',$this->action($quote)['key']);
        $agreement=app(RentalAgreements::class)->issueForQuote($quote);$agreement->update(['signed_at'=>now()]);
        $signed=$this->action($quote,$agreement);$this->assertSame('review_response',$signed['key']);$this->assertSame('Record quotation response',$signed['label']);
        $this->assertStringNotContainsString('/rentals/create',$signed['url']);
        $quote->update(['status'=>'accepted']);$ready=$this->action($quote,$agreement);$this->assertSame('ready_order',$ready['key']);$this->assertStringContainsString('/rentals/create?quotation_id=',$ready['url']);
        $this->assertDatabaseCount('rentals',0);$this->assertDatabaseCount('customers',0);
    }
    public function test_due_followup_precedes_pending_work_but_ready_order_stays_first(): void
    {
        $quote=$this->quote();$quote->update(['shared_at'=>now()]);
        EnquiryFollowUp::create(['enquiry_id'=>$this->enquiry->id,'subject'=>'Synthetic call','channel'=>'call','due_at'=>now('UTC')->subHour()]);
        $this->assertSame('overdue_followup',$this->action($quote)['key']);
        $agreement=app(RentalAgreements::class)->issueForQuote($quote);$agreement->update(['signed_at'=>now()]);$quote->update(['status'=>'accepted']);
        $this->assertSame('ready_order',$this->action($quote,$agreement)['key']);
    }
    public function test_waiting_customer_is_not_claimed_overdue_and_expired_or_declined_quotes_are_not_ready(): void
    {
        $quote=$this->quote();$quote->update(['kind'=>'sale','shared_at'=>now()]);
        EnquiryFollowUp::create(['enquiry_id'=>$this->enquiry->id,'subject'=>'Future call','channel'=>'call','due_at'=>now('UTC')->addDay()]);
        $waiting=$this->action($quote);$this->assertSame('awaiting_response',$waiting['key']);$this->assertFalse($waiting['attention']);
        $quote->update(['valid_until'=>'2026-11-06']);$this->assertSame('draft_quote',$this->action($quote)['key']);
        $quote->update(['status'=>'declined']);$this->assertSame('review_response',$this->action($quote)['key']);
    }
    public function test_closed_converted_and_unscoped_records_never_enter_attention(): void
    {
        $quote=$this->quote('converted');$this->assertFalse($this->action($quote)['attention']);
        $this->enquiry->update(['stage'=>'lost']);$this->assertFalse($this->action($quote)['attention']);
        $other=TestData::user($this->sales->organization,['role'=>User::ROLE_SALES,'role_id'=>$this->sales->role_id]);
        $this->assertNull(app(SalesNextAction::class)->for($other,$this->enquiry,$quote)['url']);
        $foreign=TestData::user();$this->assertFalse(app(SalesNextAction::class)->for($foreign,$this->enquiry,$quote)['attention']);
    }
    public function test_read_only_actor_gets_status_without_a_mutation_action(): void
    {
        $quote=$this->quote();$role=Role::create(['organization_id'=>$this->sales->organization_id,'name'=>'Reader','slug'=>'reader','is_active'=>true,'permissions'=>['enquiries'=>['read'],'quotations'=>['read']]]);
        $reader=TestData::user($this->sales->organization,['role_id'=>$role->id]);
        $result=app(SalesNextAction::class)->for($reader,$this->enquiry,$quote);$this->assertFalse($result['attention']);$this->assertNull($result['url']);
    }
}
