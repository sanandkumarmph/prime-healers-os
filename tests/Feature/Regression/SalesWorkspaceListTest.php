<?php

namespace Tests\Feature\Regression;

use App\Models\{Enquiry, EnquiryFollowUp, Quotation, Role, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class SalesWorkspaceListTest extends TestCase
{
    use RefreshDatabase;
    private User $admin;
    private User $sales;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('Asia/Kolkata')->setDate(2026, 11, 7)->setTime(10, 0));
        $org = TestData::organization();
        $this->admin = TestData::user($org, ['name'=>'Workspace Admin']);
        $role = Role::create(['organization_id'=>$org->id,'slug'=>'sales','name'=>'Sales','is_active'=>true,'is_system'=>true,
            'permissions'=>Role::defaultSystemRoleTemplates()['sales']['permissions']]);
        $this->sales = TestData::user($org, ['role'=>User::ROLE_SALES,'role_id'=>$role->id,'name'=>'Synthetic Sales']);
        $this->actingAs($this->admin);
    }

    private function enquiry(array $extra = []): Enquiry
    {
        return Enquiry::create($extra + ['organization_id'=>$this->admin->organization_id,'created_by_user_id'=>$this->admin->id,
            'owner_id'=>$this->sales->id,'name'=>'Synthetic contact','phone'=>'+919000000001','subject'=>'Oxygen concentrator rental',
            'source'=>'incoming_phone','stage'=>'new','created_at'=>'2026-11-07 09:00:00','updated_at'=>'2026-11-07 09:00:00']);
    }

    private function followUp(Enquiry $enquiry, array $extra = []): EnquiryFollowUp
    {
        return EnquiryFollowUp::create($extra + ['enquiry_id'=>$enquiry->id,'subject'=>'Confirm delivery timing','channel'=>'call',
            'due_at'=>'2026-11-07T07:00:00Z','created_at'=>'2026-11-07 09:15:00']);
    }

    private function quote(Enquiry $enquiry, array $extra = []): Quotation
    {
        return Quotation::create($extra + ['organization_id'=>$enquiry->organization_id,'enquiry_id'=>$enquiry->id,'revision'=>1,
            'kind'=>'rental','status'=>'sent','valid_until'=>'2026-11-14','snapshot'=>['currency'=>'INR','total'=>3500,'timezone'=>'Asia/Kolkata'],
            'created_at'=>'2026-11-07 09:30:00']);
    }

    private function ids(array $filters = []): array
    {
        return $this->get(route('enquiries.index', $filters))->assertOk()->viewData('records')->pluck('id')->all();
    }

    public function test_open_default_history_and_missing_follow_up_focus_are_distinct(): void
    {
        $open = $this->enquiry(); $planned = $this->enquiry(); $done = $this->enquiry(['stage'=>'won']);
        $lost = $this->enquiry(['stage'=>'lost']);
        $this->followUp($planned); $this->followUp($open, ['completed_at'=>now()]);
        $this->assertSame([$planned->id,$open->id], $this->ids());
        $this->assertSame([$lost->id,$done->id,$planned->id,$open->id], $this->ids(['focus'=>'all']));
        $this->assertSame([$done->id], $this->ids(['stage'=>'won']));
        $this->assertSame([$open->id], $this->ids(['focus'=>'no_followup']));
    }

    public function test_combined_filters_search_requirements_and_do_not_modify_records(): void
    {
        $match = $this->enquiry(['requirements'=>'Need portable unit for stairs','stage'=>'qualified']);
        $this->enquiry(['owner_id'=>$this->admin->id,'requirements'=>'Portable unit','stage'=>'qualified']);
        $this->enquiry(['source'=>'website','requirements'=>'Portable unit','stage'=>'qualified']);
        $before = Enquiry::all()->toJson();
        $this->assertSame([$match->id], $this->ids(['owner_id'=>$this->sales->id,'source'=>'incoming_phone','q'=>'portable',
            'stage'=>'qualified','from_date'=>'2026-11-07','to_date'=>'2026-11-07']));
        $this->assertSame($before, Enquiry::all()->toJson());
    }

    public function test_creation_range_includes_the_full_ist_day_and_supports_single_bounds(): void
    {
        $previous = $this->enquiry(['created_at'=>'2026-11-06 23:59:59']);
        $first = $this->enquiry(['created_at'=>'2026-11-07 00:00:00']);
        $last = $this->enquiry(['created_at'=>'2026-11-07 23:59:59']);
        $next = $this->enquiry(['created_at'=>'2026-11-08 00:00:00']);
        $this->assertSame([$last->id,$first->id], $this->ids(['from_date'=>'2026-11-07','to_date'=>'2026-11-07']));
        $this->assertSame([$next->id,$last->id,$first->id], $this->ids(['from_date'=>'2026-11-07']));
        $this->assertSame([$last->id,$first->id,$previous->id], $this->ids(['to_date'=>'2026-11-07']));
    }

    public function test_each_tab_filters_its_own_creation_date_and_due_times_remain_ist(): void
    {
        $enquiry = $this->enquiry(['created_at'=>'2026-11-01 12:00:00']);
        $follow = $this->followUp($enquiry); $quote = $this->quote($enquiry);
        foreach (['followups'=>$follow,'quotations'=>$quote] as $view=>$record) {
            $this->assertSame([$record->id], $this->ids(['view'=>$view,'from_date'=>'2026-11-07','to_date'=>'2026-11-07']));
            $this->assertSame([], $this->ids(['view'=>$view,'to_date'=>'2026-11-06']));
        }
        $this->get(route('enquiries.index',['view'=>'followups']))->assertSee('07 Nov, 12:30')->assertSee('09:15 AM')->assertSee('IST');
    }

    public function test_invalid_ranges_foreign_owner_and_unknown_source_are_rejected(): void
    {
        $foreign = TestData::user();
        foreach ([['from_date'=>'2026-11-08','to_date'=>'2026-11-07'],['from_date'=>'07/11/2026'],['owner_id'=>$foreign->id],['source'=>'fabricated']] as $filter) {
            $this->getJson(route('enquiries.index',$filter))->assertUnprocessable();
        }
    }

    public function test_filters_cannot_widen_staff_scope_and_foreign_owner_names_are_not_listed(): void
    {
        $own = $this->enquiry(); $other = $this->enquiry(['owner_id'=>$this->admin->id]);
        $foreign = TestData::user(null,['name'=>'Foreign Owner Hidden']);
        $this->enquiry(['organization_id'=>$foreign->organization_id,'owner_id'=>$foreign->id]);
        $this->actingAs($this->sales);
        $this->assertSame([$own->id],$this->ids(['focus'=>'all','source'=>'incoming_phone']));
        $this->assertSame([],$this->ids(['owner_id'=>$this->admin->id,'focus'=>'all']));
        $this->get(route('enquiries.index'))->assertDontSee('Foreign Owner Hidden')->assertDontSee('Business Direction');
        $this->get(route('enquiries.show',$other))->assertNotFound();
        $this->assertSame([$other->id], $this->actingAs($this->admin)->ids(['mine'=>1]));
        $this->assertSame([$own->id], $this->ids(['mine'=>1,'owner_id'=>$this->sales->id]));
    }

    public function test_sorting_serials_and_pagination_keep_filters(): void
    {
        foreach (range(1,23) as $n) $this->enquiry(['subject'=>'Synthetic enquiry '.$n]);
        $response = $this->get(route('enquiries.index',['source'=>'incoming_phone','sort'=>'oldest','page'=>2]))->assertOk();
        $this->assertSame([21,22,23],$response->viewData('records')->pluck('id')->all());
        $response->assertSee('data-label="#">21</td>',false)->assertSee('source=incoming_phone',false)->assertSee('sort=oldest',false);
    }

    public function test_quotation_permission_still_controls_tab_and_direct_access(): void
    {
        $role = $this->sales->assignedRole;
        $permissions = $role->permissions; $permissions['quotations'] = [];
        $role->update(['permissions'=>$permissions]);
        $this->actingAs($this->sales->fresh());
        $this->get(route('enquiries.index'))->assertOk()->assertDontSee('>Quotations</a>',false);
        $this->get(route('enquiries.index',['view'=>'quotations']))->assertForbidden();
    }

    public function test_render_compact_lists_with_dates_next_actions_and_safe_empty_state(): void
    {
        $first = $this->enquiry(['subject'=>'Oxygen concentrator 5 LPM','name'=>'Synthetic customer One']);
        $this->followUp($first,['due_at'=>'2026-11-06T09:00:00Z']); $this->quote($first);
        $this->enquiry(['subject'=>'Hospital bed for home recovery','stage'=>'qualified','name'=>'Synthetic customer Two','source'=>'facebook']);
        $this->enquiry(['subject'=>'Wheelchair for 30 days','stage'=>'quoted','name'=>'Synthetic customer Three','source'=>'indiamart']);
        $this->enquiry(['subject'=>'Portable oxygen cylinder','stage'=>'accepted','name'=>'Synthetic customer Four']);
        $before = Enquiry::count();
        foreach (['enquiries'=>[], 'followups'=>['view'=>'followups'],'quotations'=>['view'=>'quotations'],'empty'=>['q'=>'nomatchfound'],
            'filtered'=>['source'=>'incoming_phone','from_date'=>'2026-11-07','to_date'=>'2026-11-07','owner_id'=>$this->sales->id]] as $fixture=>$filters) {
            $response=$this->get(route('enquiries.index',$filters))->assertOk()->assertSee('Sales workspace')->assertSee('Created (IST)');
            if ($fixture==='enquiries') $response->assertSee('Plan follow-up')->assertSee('Continue to order')->assertSee('Review quotation')->assertSee('07 Nov 2026');
            if ($folder=getenv('PHOS_SALES_LIST_RENDER_DIR')) {
                if (!is_dir($folder)) mkdir($folder,0777,true);
                file_put_contents($folder.'/'.$fixture.'.html',$response->getContent());
            }
        }
        $this->actingAs($this->sales);
        $response=$this->get(route('enquiries.index'))->assertOk()->assertSee('Oxygen concentrator 5 LPM');
        if ($folder=getenv('PHOS_SALES_LIST_RENDER_DIR')) file_put_contents($folder.'/sales.html',$response->getContent());
        $this->actingAs($this->admin);
        $this->assertSame($before,Enquiry::count());
        $this->get(route('enquiries.show',$first))->assertOk()->assertSee('id="enquiry-followups"',false);
    }
}
