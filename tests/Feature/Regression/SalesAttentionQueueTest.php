<?php

namespace Tests\Feature\Regression;

use App\Models\{Enquiry,EnquiryFollowUp,Quotation,RentalAgreement,Role,User};
use App\Services\Sales\{RentalAgreements,SalesAttentionQueue,SalesNextAction,SalesQueueContext};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{DB,Mail};
use Tests\Support\TestData;
use Tests\TestCase;

class SalesAttentionQueueTest extends TestCase
{
    use RefreshDatabase;
    private User $sales;
    private array $fixtureManifest=[];
    protected function setUp(): void
    {
        parent::setUp();Mail::fake();config(['app.rental_agreements'=>true]);$this->travelTo(now('Asia/Kolkata')->setDate(2026,11,8)->setTime(11,0));
        $org=TestData::organization(['currency_code'=>'INR','state'=>'Karnataka','country_code'=>'IN']);
        $role=Role::create(['organization_id'=>$org->id,'name'=>'Sales','slug'=>'sales','is_active'=>true,'is_system'=>true,'permissions'=>Role::defaultSystemRoleTemplates()['sales']['permissions']]);
        $this->sales=TestData::user($org,['role'=>User::ROLE_SALES,'role_id'=>$role->id,'name'=>'Synthetic Sales Owner']);$this->actingAs($this->sales);
    }
    private function entry(string $name,?string $status=null,array $extra=[]): array
    {
        $e=Enquiry::create(['organization_id'=>$this->sales->organization_id,'owner_id'=>$this->sales->id,'created_by_user_id'=>$this->sales->id,
            'name'=>'Synthetic '.$name,'subject'=>'Synthetic '.$name,'phone'=>'+919000000001','source'=>'incoming_phone','intent'=>'rental','stage'=>'qualified']);
        $q=$status ? $e->quotations()->create(['organization_id'=>$e->organization_id,'revision'=>1,'kind'=>'rental','status'=>$status,'valid_until'=>'2026-11-20',
            'snapshot'=>['timezone'=>'Asia/Kolkata','currency'=>'INR','total'=>1000,'recipient'=>['name'=>$e->name,'phone'=>$e->phone]],'shared_at'=>in_array($status,['sent','accepted','declined'],true)?now():null]+$extra) : null;
        if($q && $extra)$q->forceFill($extra)->save();
        return [$e,$q];
    }
    private function due(Enquiry $e,string $time): void {EnquiryFollowUp::create(['enquiry_id'=>$e->id,'subject'=>'Synthetic contact','channel'=>'call','due_at'=>$time]);}
    private function agreement(Quotation $q,bool $signed=false,bool $exception=false): RentalAgreement
    {
        $agreements=app(RentalAgreements::class);$a=$agreements->issueForQuote($q);
        if($signed)$a->update(['signed_at'=>now()]);
        if($exception)$agreements->approveException($a,$this->sales,'Customer cannot use a digital signature; terms explained by telephone.',$q);
        return $a->fresh();
    }
    private function page(array $query=[],?string $fixture=null)
    {
        $response=$this->get(route('enquiries.index',$query))->assertOk();
        if($fixture && ($folder=getenv('PHOS_SALES_ATTENTION_RENDER_DIR'))){
            if(!is_dir($folder))mkdir($folder,0700,true);file_put_contents($folder.'/'.$fixture.'.html',$response->getContent());
            $this->fixtureManifest[$fixture]=['query'=>$query,'ids'=>$response->viewData('records')->getCollection()->pluck('id')->all()];
            file_put_contents($folder.'/manifest.json',json_encode(['fixtures'=>$this->fixtureManifest],JSON_PRETTY_PRINT));
        }
        return $response;
    }
    public function test_default_sales_queue_priorities_categories_latest_revisions_and_browser_fixtures(): void
    {
        [$ready,$q]=$this->entry('Ready accepted','accepted');$this->agreement($q,true);$this->due($ready,now('UTC')->subDays(2)->toIso8601String());
        [$overdue,$q]=$this->entry('Overdue call','accepted');$this->due($overdue,now('UTC')->subHour()->toIso8601String());
        [$review]=$this->entry('Declined response','declined');
        [$today]=$this->entry('Today call');$this->due($today,now('UTC')->addHour()->toIso8601String());
        [$terms,$q]=$this->entry('Terms pending','accepted');$this->agreement($q);
        [$share]=$this->entry('Share quotation','sent',['shared_at'=>null]);
        [$draft]=$this->entry('Draft quotation','draft');[$new]=$this->entry('New enquiry');
        [$plan]=$this->entry('Plan follow-up','sent',['kind'=>'sale']);
        [$waiting]=$this->entry('Waiting customer','sent',['kind'=>'sale']);$this->due($waiting,now('UTC')->addDays(2)->toIso8601String());
        [$signed,$q]=$this->entry('Signed needs acceptance','sent');$this->agreement($q,true);
        [$expired]=$this->entry('Expired quotation','accepted',['valid_until'=>'2026-11-01']);
        [$exception,$q]=$this->entry('Approved exception','accepted');$this->agreement($q,false,true);
        [$invalid,$q]=$this->entry('Changed exception','accepted');$this->agreement($q,false,true);$q->update(['snapshot'=>$q->snapshot+['changed_commercial_details'=>true]]);
        [$revised,$q]=$this->entry('Latest draft revision','accepted');$this->agreement($q,true);$q->update(['status'=>'superseded']);$q->replicate()->fill(['revision'=>2,'status'=>'draft'])->save();
        [$converted]=$this->entry('Converted excluded','converted');[$closed]=$this->entry('Closed excluded');$closed->update(['stage'=>'lost']);
        [$other]=$this->entry('Colleague enquiry');$other->update(['owner_id'=>TestData::user($this->sales->organization)->id]);
        [$foreign]=$this->entry('Other organization private');$foreign->update(['organization_id'=>TestData::organization()->id]);
        $before=[Enquiry::count(),Quotation::count(),RentalAgreement::count(),EnquiryFollowUp::count()];
        $response=$this->page([],'attention')->assertViewHas('mode','attention')->assertSee('Needs attention')->assertSee('Colleague enquiry')->assertDontSee('Other organization private');
        $records=$response->viewData('records')->getCollection();$this->assertSame($ready->id,$records->first()->id);$this->assertSame($exception->id,$records->get(1)->id);
        foreach($records as $record){$this->assertSame($record->attention_key,$record->attention_action['key']);$this->assertTrue($record->attention_action['attention']);}
        $this->assertNotContains($waiting->id,$records->pluck('id'));$this->assertNotContains($converted->id,$records->pluck('id'));$this->assertSame(15,$response->viewData('records')->total());
        foreach(SalesAttentionQueue::CATEGORIES as $category=>$label)if($category!=='all'){
            $page=$this->page(['view'=>'attention','attention'=>$category],'category-'.$category);
            $this->assertSame($page->viewData('attentionCounts')[$category],$page->viewData('records')->total());
            foreach($page->viewData('records') as $record)$this->assertSame($record->attention_key,$record->attention_action['key']);
        }
        $this->page(['view'=>'attention','q'=>'Synthetic Ready'],'search')->assertViewHas('records',fn($p)=>$p->total()===1 && $p->first()->id===$ready->id);
        $this->page(['view'=>'attention','q'=>'not-a-real-customer'],'empty')->assertSee('No work in this view');
        for($i=0;$i<24;$i++)$this->entry('Page continuation '.$i);
        $this->page(['view'=>'attention','page'=>2],'page2')->assertViewHas('records',fn($p)=>$p->currentPage()===2 && $p->firstItem()===21);
        $this->assertSame($before,[Enquiry::count()-24,Quotation::count(),RentalAgreement::count(),EnquiryFollowUp::count()]);Mail::assertNothingSent();
    }
    public function test_shared_filters_and_explicit_existing_views_are_preserved(): void
    {
        [$yes]=$this->entry('Filtered matching');[$no]=$this->entry('Different source');$no->update(['source'=>'website']);
        $query=['view'=>'attention','q'=>'Filtered','source'=>'incoming_phone','owner_id'=>$this->sales->id,'from_date'=>'2026-11-08','to_date'=>'2026-11-08'];
        $this->page($query)->assertViewHas('records',fn($p)=>$p->total()===1 && $p->first()->id===$yes->id)->assertSee('name="source"',false);
        foreach(['enquiries','followups','quotations'] as $mode)$this->page(['view'=>$mode])->assertViewHas('mode',$mode);
        $this->get(route('enquiries.index',['view'=>'attention','attention'=>'invalid']))->assertSessionHasErrors('attention');
        $this->actingAs(TestData::user($this->sales->organization,['role'=>User::ROLE_DELIVERY]))->get(route('enquiries.index',['view'=>'attention']))->assertForbidden();
    }
    public function test_no_quotation_access_exposes_no_quotation_state_or_ready_counts(): void
    {
        [$entry,$quote]=$this->entry('Visible contact hidden quotation','accepted');$this->agreement($quote,true);
        $role=$this->sales->assignedRole;$permissions=$role->permissions;$permissions['quotations']=[];$role->update(['permissions'=>$permissions]);$this->sales->unsetRelation('assignedRole');
        $page=$this->page(['view'=>'attention']);$this->assertSame(0,$page->viewData('attentionCounts')['ready']);
        $row=$page->viewData('records')->first();$this->assertSame('plan_followup',$row->attention_action['key']);$this->assertNull($row->attention_quote_id);
        $page->assertDontSee($quote->number())->assertDontSee('Quotation accepted.');
    }
    public function test_quote_scope_is_applied_per_row_even_with_all_enquiry_access(): void
    {
        [$other,$quote]=$this->entry('Other enquiry shared visibility','accepted');$other->update(['owner_id'=>TestData::user($this->sales->organization)->id]);$this->agreement($quote,true);
        $actor=new QueueScopedSales();$actor->setRawAttributes($this->sales->getAttributes(),true);$this->actingAs($actor);
        $page=$this->page(['view'=>'attention']);$row=$page->viewData('records')->first();$this->assertSame($other->id,$row->id);
        $this->assertSame('plan_followup',$row->attention_key);$this->assertSame($row->attention_key,$row->attention_action['key']);$this->assertNull($row->attention_quote_id);
    }
    public function test_expiry_uses_quotation_timezone_near_ist_midnight(): void
    {
        $this->travelTo(now('UTC')->setDate(2026,11,8)->setTime(19,0));
        [$expired,$q]=$this->entry('IST yesterday expired','accepted',['valid_until'=>'2026-11-08']);$this->agreement($q,true);
        [$valid,$q]=$this->entry('UTC today still valid','accepted',['valid_until'=>'2026-11-08','snapshot'=>['timezone'=>'UTC','currency'=>'INR','total'=>1000]]);$this->agreement($q,true);
        $page=$this->page(['view'=>'attention']);$rows=$page->viewData('records')->getCollection()->keyBy('id');
        $this->assertSame('draft_quote',$rows[$expired->id]->attention_key);$this->assertSame('ready_order',$rows[$valid->id]->attention_key);
        foreach($rows as $row)$this->assertSame($row->attention_key,$row->attention_action['key']);
    }
    public function test_page_query_count_does_not_grow_per_missing_agreement_or_followup(): void
    {
        $this->entry('First draft','draft');DB::enableQueryLog();$this->page(['view'=>'attention']);$small=count(DB::getQueryLog());DB::disableQueryLog();
        for($i=0;$i<19;$i++)$this->entry('More draft '.$i,'draft');DB::flushQueryLog();DB::enableQueryLog();
        $response=$this->page(['view'=>'attention']);$large=count(DB::getQueryLog());DB::disableQueryLog();
        $this->assertCount(20,$response->viewData('records'));$this->assertLessThanOrEqual($small+5,$large,'The twenty-row queue must not add per-row database reads.');
    }
    public function test_twenty_approved_exception_rows_keep_bounded_reads_and_matching_ready_actions(): void
    {
        [$first,$quote]=$this->entry('First exception','accepted');$this->agreement($quote,false,true);
        DB::enableQueryLog();$this->page(['view'=>'attention']);$small=count(DB::getQueryLog());DB::disableQueryLog();
        for($i=0;$i<19;$i++){[$entry,$q]=$this->entry('Exception row '.$i,'accepted');$this->agreement($q,false,true);}
        DB::flushQueryLog();DB::enableQueryLog();$response=$this->page(['view'=>'attention']);$large=count(DB::getQueryLog());DB::disableQueryLog();
        $this->assertCount(20,$response->viewData('records'));$this->assertSame(20,$response->viewData('attentionCounts')['ready']);
        foreach($response->viewData('records') as $row){$this->assertSame('ready_order',$row->attention_key);$this->assertSame('ready_order',$row->attention_action['key']);}
        $this->assertLessThanOrEqual($small+5,$large,'Encrypted approval classification and rendering must not add a database read for every row.');
    }
    public function test_queue_context_is_request_local_bound_to_actor_and_record_and_does_not_change_execution_guards(): void
    {
        [$first,$quote]=$this->entry('Approved context','accepted');$approval=$this->agreement($quote,false,true);
        $context=new SalesQueueContext((int)$first->organization_id,(int)$this->sales->id,(int)$first->id,(int)$quote->id,true);
        [$second,$otherQuote]=$this->entry('Unsigned other context','accepted');$unsigned=$this->agreement($otherQuote);
        $action=app(SalesNextAction::class)->for($this->sales,$second,$otherQuote,$unsigned,null,$context);
        $this->assertSame('terms_pending',$action['key']);
        $quote->update(['snapshot'=>$quote->snapshot+['changed_price'=>true]]);
        $this->assertFalse(app(RentalAgreements::class)->readyForQuote($quote->fresh(),$approval->fresh()));
        $wrongActor=new SalesQueueContext((int)$first->organization_id,(int)$this->sales->id+999,(int)$first->id,(int)$quote->id,true);
        $this->assertSame('terms_pending',app(SalesNextAction::class)->for($this->sales,$first,$quote->fresh(),$approval->fresh(),null,$wrongActor)['key']);
        $this->assertDatabaseCount('rentals',0);Mail::assertNothingSent();
    }
}

class QueueScopedSales extends User
{
    protected $table='users';
    public function scopeType(?string $module=null): string {return $module==='enquiries'?'all':($module==='quotations'?'self_created':parent::scopeType($module));}
}
