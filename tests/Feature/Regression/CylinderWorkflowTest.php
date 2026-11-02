<?php

namespace Tests\Feature\Regression;

use App\Models\{Asset, Customer, Cylinder, CylinderEvent, CylinderJob, Invoice, Product, Rental, RentalAsset, RentalItem, User, Warehouse};
use App\Services\Cylinders\CylinderWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestData;
use Tests\TestCase;

class CylinderWorkflowTest extends TestCase
{
    use RefreshDatabase;
    private $owner, $warehouse, $product, $rental, $a, $b, $service;

    protected function setUp(): void
    {
        parent::setUp();
        $org = TestData::organization(['state'=>'Karnataka']);
        $this->owner = TestData::user($org); $this->actingAs($this->owner);
        $this->warehouse = Warehouse::create(['organization_id'=>$org->id,'name'=>'Synthetic cylinder warehouse','is_active'=>true]);
        $customer = Customer::create(['organization_id'=>$org->id,'name'=>'Synthetic cylinder customer','phone'=>'+919876543210','state'=>'Karnataka']);
        $this->product = Product::create(['organization_id'=>$org->id,'name'=>'Oxygen cylinder','stock_mode'=>'tracked_rental','product_type'=>'rentable','is_rentable'=>true,'price_per_day'=>10,'total_quantity'=>2,'available_quantity'=>1]);
        $this->rental = Rental::create(['organization_id'=>$org->id,'customer_id'=>$customer->id,'customer_name'=>$customer->name,'phone'=>$customer->phone,
            'product_id'=>$this->product->id,'status'=>'active','quantity'=>1,'start_date'=>today(),'end_date'=>today()->addDays(30),'deposit_amount'=>1000,'rental_amount'=>500,
            'created_by_user_id'=>$this->owner->id,'fulfilment_source'=>'in_house']);
        foreach (['A','B'] as $code) {
            $asset = Asset::create(['organization_id'=>$org->id,'warehouse_id'=>$this->warehouse->id,'product_id'=>$this->product->id,
                'serial_number'=>'UAT-OXY-'.$code,'asset_stage'=>'rental_stock','asset_status'=>$code==='A'?'rented':'available','condition_status'=>'good']);
            if ($code==='A') {
                RentalAsset::create(['organization_id'=>$org->id,'rental_id'=>$this->rental->id,'asset_id'=>$asset->id,'assigned_at'=>now()]);
                RentalItem::create(['organization_id'=>$org->id,'rental_id'=>$this->rental->id,'product_id'=>$this->product->id,'quantity'=>1,'ordered_quantity'=>1,'delivered_quantity'=>1,'returned_quantity'=>0,'asset_ids'=>[$asset->id],'line_total'=>500]);
            }
            $this->{strtolower($code)} = app(CylinderWorkflow::class)->register($this->owner,$asset->id,'full');
        }
        $this->service = app(CylinderWorkflow::class);
    }

    private function payload(string $mode='exchange'): array
    {
        return ['request_id'=>(string)Str::uuid(),'mode'=>$mode,'incoming_cylinder_id'=>$this->a->id,
            'outgoing_cylinder_id'=>$mode==='same'?null:$this->b->id,'assigned_user_id'=>$this->owner->id,
            'scheduled_at'=>now()->format('Y-m-d\TH:i'),'refill_charge'=>300,'transport_charge'=>50,'tax_rate'=>18,'tax_type'=>'cgst_sgst','notes'=>'Synthetic test only'];
    }

    private function act(CylinderJob $job,string $action,array $extra=[]): CylinderJob
    {
        $c = $action==='full_delivered' ? $job->outgoing : $job->incoming;
        return $this->service->act($this->owner,$job,['request_id'=>(string)Str::uuid(),'lock_version'=>$job->fresh()->lock_version,
            'action'=>$action,'scanned_code'=>$c->asset->serial_number,'signature_reason'=>'Synthetic test; customer unavailable',
            'confirm_empty'=>true,'inspection_confirmed'=>true,'warehouse_id'=>$this->warehouse->id]+$extra);
    }

    public function test_exchange_preserves_asset_history_quantity_and_deposit_and_is_safe_in_both_handover_orders(): void
    {
        $job = $this->service->create($this->owner,$this->rental,$this->payload());
        $this->assertSame('reserved',$this->b->asset->fresh()->asset_status);
        $this->assertFalse(Asset::whereKey($this->b->asset_id)->rentalReady()->exists());
        $job = $this->act($job,'full_delivered');
        $this->assertSame([$this->b->asset_id],$this->rental->rentalItems()->sole()->asset_ids);
        $this->assertSame(2,$this->rental->activeRentalAssets()->count());
        $job = $this->act($job,'empty_collected'); $job = $this->act($job,'warehouse_received');
        $this->assertSame('completed',$job->status);
        $this->assertNotNull(RentalAsset::where('asset_id',$this->a->asset_id)->sole()->returned_at);
        $this->assertSame(1,$this->rental->activeRentalAssets()->count());
        $this->assertSame('maintenance',$this->a->asset->fresh()->asset_status);
        $job = $this->act($job,'refill_sent'); $job = $this->act($job,'refill_received');
        $this->assertTrue(Asset::whereKey($this->a->asset_id)->rentalReady()->exists());
        $this->assertEquals(1000,$this->rental->fresh()->deposit_amount);
        $this->assertSame('active',$this->rental->fresh()->status);
        $this->assertDatabaseCount('assets',2); $this->assertDatabaseCount('rentals',1);
    }

    public function test_same_cylinder_refill_retains_assignment_and_returns_exact_original_serial(): void
    {
        $job = $this->service->create($this->owner,$this->rental,$this->payload('same'));
        foreach (['empty_collected','warehouse_received','refill_sent','refill_received','full_delivered'] as $action) $job=$this->act($job,$action);
        $this->assertSame('completed',$job->status);
        $this->assertSame('customer',$this->a->fresh()->custody);
        $this->assertSame('full',$this->a->fresh()->contents);
        $this->assertSame('rented',$this->a->asset->fresh()->asset_status);
        $this->assertSame([$this->a->asset_id],$this->rental->rentalItems()->sole()->asset_ids);
        $this->assertDatabaseCount('rental_assets',1);
    }

    public function test_exchange_collection_first_and_invoice_replay_do_not_duplicate_assets_or_billing(): void
    {
        $data=$this->payload(); $job=$this->service->create($this->owner,$this->rental,$data);
        $this->assertSame($job->id,$this->service->create($this->owner,$this->rental,$data)->id);
        foreach (['empty_collected','warehouse_received','full_delivered'] as $action) $job=$this->act($job,$action);
        $this->post(route('cylinder-services.invoice',$job))->assertSessionHasNoErrors()->assertRedirect();
        $this->post(route('cylinder-services.invoice',$job))->assertSessionHasNoErrors()->assertRedirect();
        $invoice=Invoice::sole();
        $this->assertEquals(350,$invoice->total_amount); $this->assertEquals(350,$invoice->balance_amount);
        $this->assertNull($invoice->rental_id); $this->assertEquals(0,$invoice->deposit_amount);
        $this->assertSame($invoice->id,$job->fresh()->invoice_id); $this->assertCount(2,$invoice->items);
        $this->assertDatabaseCount('payments',0);
    }

    public function test_wrong_scan_stale_version_and_changed_replay_are_rejected_without_movements(): void
    {
        $job=$this->service->create($this->owner,$this->rental,$this->payload());
        $base=['request_id'=>(string)Str::uuid(),'lock_version'=>0,'action'=>'empty_collected','scanned_code'=>'WRONG','signature_reason'=>'Unavailable','confirm_empty'=>true];
        $this->post(route('cylinder-services.action',$job),$base)->assertSessionHasErrors('cylinder');
        $this->assertNull($job->fresh()->collected_at);
        $base['scanned_code']=$this->a->asset->serial_number;
        $this->post(route('cylinder-services.action',$job),$base)->assertSessionHasNoErrors();
        $this->post(route('cylinder-services.action',$job),$base)->assertSessionHasNoErrors();
        $base['notes']='changed'; $this->post(route('cylinder-services.action',$job),$base)->assertStatus(409);
        $base['request_id']=(string)Str::uuid(); $base['action']='full_delivered'; $base['scanned_code']=$this->b->asset->serial_number;
        $this->post(route('cylinder-services.action',$job),$base)->assertStatus(409);
        $this->assertSame(1,$job->events()->where('action','empty_collected')->count());
    }

    public function test_empty_return_is_not_ready_until_refilled_and_inspected(): void
    {
        $this->rental->activeRentalAssets()->update(['returned_at'=>now()]);
        $this->service->recordOrdinaryReturn($this->owner->organization_id,[$this->a->asset_id]);
        $data=['request_id'=>(string)Str::uuid(),'action'=>'return_received','scanned_code'=>$this->a->asset->serial_number,'warehouse_id'=>$this->warehouse->id,'contents'=>'empty','inspection_confirmed'=>true];
        $this->service->stockAction($this->owner,$this->a,$data);
        $this->assertFalse(Asset::whereKey($this->a->asset_id)->rentalReady()->exists());
        $data['action']='sent_for_refill'; $data['request_id']=(string)Str::uuid(); $this->service->stockAction($this->owner,$this->a,$data);
        $data['action']='received_full'; $data['request_id']=(string)Str::uuid(); $this->service->stockAction($this->owner,$this->a,$data);
        $this->assertTrue(Asset::whereKey($this->a->asset_id)->rentalReady()->exists());
    }

    public function test_tenant_access_customer_signature_and_open_job_return_guards(): void
    {
        $job=$this->service->create($this->owner,$this->rental,$this->payload());
        $this->get(route('cylinder-services.show',$job))->assertOk()->assertSee('Name of person signing');
        $this->get(route('cylinder-services.index'))->assertOk(); $this->get(route('cylinder-services.stock'))->assertOk();
        $this->get(route('cylinder-services.create',$this->rental))->assertOk();
        $base=['request_id'=>(string)Str::uuid(),'lock_version'=>0,'action'=>'empty_collected','scanned_code'=>$this->a->asset->serial_number,'confirm_empty'=>true];
        $this->post(route('cylinder-services.action',$job),$base)->assertSessionHasErrors('cylinder');
        $other=TestData::user(); $this->actingAs($other)->get(route('cylinder-services.show',$job))->assertNotFound();
        $this->actingAs($this->owner);
        try { $this->service->guardOrdinaryReturn($this->rental,[$this->a->asset_id]); $this->fail('Open cylinder job allowed an ordinary pickup'); } catch (\Illuminate\Validation\ValidationException $expected) {}
        try { $this->a->asset->delete(); $this->fail('Cylinder history deleted'); } catch (\Illuminate\Validation\ValidationException $expected) {}
    }

    public function test_customer_signature_records_signer_and_remains_private_and_failed_steps_remove_new_files(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $job=$this->service->create($this->owner,$this->rental,$this->payload('same'));
        $image=imagecreatetruecolor(240,80); imagefill($image,0,0,imagecolorallocate($image,255,255,255));
        $ink=imagecolorallocate($image,20,30,40); imageline($image,20,55,95,20,$ink); imageline($image,95,20,215,60,$ink);
        ob_start(); imagepng($image); $signature='data:image/png;base64,'.base64_encode(ob_get_clean()); imagedestroy($image);
        $base=['request_id'=>(string)Str::uuid(),'lock_version'=>0,'action'=>'empty_collected','scanned_code'=>$this->a->asset->serial_number,
            'signature_data'=>$signature,'signer_name'=>'','confirm_empty'=>true];
        $this->post(route('cylinder-services.action',$job),$base)->assertSessionHasErrors('cylinder');
        $base['signer_name']='Synthetic customer representative';
        $base['confirm_empty']=false;
        $this->post(route('cylinder-services.action',$job),$base)->assertSessionHasErrors('cylinder');
        $this->assertSame([],\Illuminate\Support\Facades\Storage::disk('local')->allFiles());
        $base['confirm_empty']=true;
        $this->post(route('cylinder-services.action',$job),$base)->assertSessionHasNoErrors();
        $event=$job->events()->where('action','empty_collected')->sole();
        $this->assertSame('Synthetic customer representative',$event->details['signer_name']);
        $this->get(route('cylinder-services.signature',[$job,$event]))->assertOk()->assertHeader('Content-Type','image/png');
        $this->actingAs(TestData::user())->get(route('cylinder-services.signature',[$job,$event]))->assertNotFound();
        $this->assertCount(1,\Illuminate\Support\Facades\Storage::disk('local')->allFiles());
    }

    public function test_restricted_delivery_user_cannot_update_other_jobs_or_manage_warehouse_or_invoice(): void
    {
        $worker=TestData::user($this->owner->organization,['role'=>User::ROLE_DELIVERY]);
        $data=$this->payload();$data['assigned_user_id']=$worker->id;
        $job=$this->service->create($this->owner,$this->rental,$data);
        $this->actingAs($worker)->get(route('cylinder-services.show',$job))->assertOk();
        $this->get(route('cylinder-services.stock'))->assertForbidden();
        $this->post(route('cylinder-services.invoice',$job))->assertForbidden();
        $this->post(route('cylinder-services.action',$job),['request_id'=>(string)Str::uuid(),'lock_version'=>0,'action'=>'warehouse_received',
            'scanned_code'=>$this->a->asset->serial_number,'warehouse_id'=>$this->warehouse->id])->assertForbidden();
        $other=TestData::user($this->owner->organization,['role'=>User::ROLE_DELIVERY]);
        $this->actingAs($other)->get(route('cylinder-services.show',$job))->assertNotFound();
    }

    public function test_forecast_excludes_empty_or_customer_cylinders_and_cancellation_releases_only_replacement(): void
    {
        $job=$this->service->create($this->owner,$this->rental,$this->payload());
        $job=$this->act($job,'cancelled');
        $this->assertSame('customer',$this->a->fresh()->custody);
        $this->assertTrue(Asset::whereKey($this->b->asset_id)->rentalReady()->exists());
        $result=app(\App\Services\Availability\RentalAvailability::class)->check($this->owner,['start_date'=>today()->toDateString(),'end_date'=>today()->addDays(1)->toDateString(),
            'lines'=>[['product_id'=>$this->product->id,'quantity'=>2]]]);
        $this->assertSame(1,$result['rows'][0]['ready_now']); $this->assertSame(0,$result['rows'][0]['expected_back']);
        $this->assertSame(1,$result['rows'][0]['shortfall']);
    }

    public function test_mobile_fixture_pages_render_without_raw_template_code(): void
    {
        $job=$this->service->create($this->owner,$this->rental,$this->payload());
        $folder=getenv('PHOS_CYLINDER_RENDER_DIR');
        if ($folder && !is_dir($folder)) mkdir($folder,0700,true);
        foreach (['job'=>route('cylinder-services.show',$job),'create'=>route('cylinder-services.create',$this->rental),
            'stock'=>route('cylinder-services.stock'),'index'=>route('cylinder-services.index')] as $name=>$url) {
            $response=$this->get($url)->assertOk();
            $response->assertDontSee('@foreach')->assertDontSee('@if');
            if($folder) file_put_contents($folder.'/'.$name.'.html',$response->getContent());
        }
    }

    public function test_registration_distinguishes_reserved_warehouse_stock_from_delivered_customer_stock(): void
    {
        $asset=Asset::create(['organization_id'=>$this->owner->organization_id,'warehouse_id'=>$this->warehouse->id,'product_id'=>$this->product->id,
            'serial_number'=>'UAT-OXY-C','asset_stage'=>'rental_stock','asset_status'=>'reserved','condition_status'=>'good']);
        RentalAsset::create(['organization_id'=>$this->owner->organization_id,'rental_id'=>$this->rental->id,'asset_id'=>$asset->id,'assigned_at'=>now()]);
        $line=RentalItem::create(['organization_id'=>$this->owner->organization_id,'rental_id'=>$this->rental->id,'product_id'=>$this->product->id,
            'quantity'=>1,'ordered_quantity'=>1,'delivered_quantity'=>0,'returned_quantity'=>0,'asset_ids'=>[$asset->id],'line_total'=>500]);
        $c=$this->service->register($this->owner,$asset->id,'full');
        $this->assertSame('warehouse',$c->custody);$this->assertEquals($this->rental->id,$c->reserved_for_rental_id);
        $this->assertSame('reserved',$asset->fresh()->asset_status);
        $data=$this->payload();$data['incoming_cylinder_id']=$c->id;
        $this->post(route('cylinder-services.store',$this->rental),$data)->assertSessionHasErrors('cylinder');
        $line->update(['delivered_quantity'=>1]);
        app(\App\Services\Deliveries\DeliveryWorkflowService::class)->syncRentalProgressAssetStatuses($this->owner->organization_id,$this->rental);
        $this->assertSame('customer',$c->fresh()->custody);$this->assertSame('rented',$asset->fresh()->asset_status);
        $this->assertSame(1,$c->events()->where('action','rental_delivered')->count());
    }

    public function test_ordinary_pickup_keeps_empty_cylinder_unavailable_and_logs_one_return_event_on_retry(): void
    {
        $workflow=app(\App\Services\Deliveries\DeliveryWorkflowService::class);
        for($i=0;$i<2;$i++)$workflow->releaseSpecificRentalAssets($this->owner->organization_id,$this->rental,[$this->a->asset_id]);
        $this->assertSame('unknown',$this->a->fresh()->contents);$this->assertSame('in_transit',$this->a->fresh()->custody);
        $this->assertSame('awaiting_verification',$this->a->asset->fresh()->asset_status);
        $this->assertFalse(Asset::whereKey($this->a->asset_id)->rentalReady()->exists());
        $this->assertSame(1,$this->a->events()->where('action','rental_returned')->count());
    }

    public function test_unknown_warehouse_contents_can_be_inspected_without_creating_another_asset(): void
    {
        $asset=Asset::create(['organization_id'=>$this->owner->organization_id,'warehouse_id'=>$this->warehouse->id,'product_id'=>$this->product->id,
            'serial_number'=>'UAT-OXY-UNKNOWN','asset_stage'=>'rental_stock','asset_status'=>'maintenance','condition_status'=>'good']);
        $c=$this->service->register($this->owner,$asset->id,'unknown');
        $data=['request_id'=>(string)Str::uuid(),'action'=>'inspection_recorded','scanned_code'=>$asset->serial_number,
            'warehouse_id'=>$this->warehouse->id,'contents'=>'full','inspection_confirmed'=>true];
        $this->post(route('cylinder-services.stock-action',$c),$data)->assertSessionHasNoErrors();
        $this->post(route('cylinder-services.stock-action',$c),$data)->assertSessionHasNoErrors();
        $this->assertTrue(Asset::whereKey($asset->id)->rentalReady()->exists());
        $this->assertSame(1,$c->events()->where('action','inspection_recorded')->count());$this->assertDatabaseCount('assets',3);
    }

    public function test_accounts_role_can_invoice_completed_job_without_operational_or_consolidated_finance_access(): void
    {
        $job=$this->service->create($this->owner,$this->rental,$this->payload());
        foreach(['empty_collected','warehouse_received','full_delivered'] as $action)$job=$this->act($job,$action);
        $preset=\App\Models\Role::accessPresets()['finance'];
        $role=\App\Models\Role::create(['organization_id'=>$this->owner->organization_id,'name'=>'Accounts / Finance','slug'=>'finance','is_active'=>true,
            'permissions'=>$preset['modules']+['__special'=>$preset['special'],'__special_explicit'=>true]]);
        $staff=TestData::user($this->owner->organization,['role_id'=>$role->id,'role'=>'finance']);
        $this->assertFalse($staff->canViewFinance());$this->assertTrue($staff->canViewDues());
        $this->actingAs($staff)->get(route('cylinder-services.show',$job))->assertOk();
        $this->post(route('cylinder-services.invoice',$job))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertEquals(350,$job->fresh()->invoice->total_amount);$this->assertNull($job->fresh()->invoice->rental_id);
        $this->get(route('cylinder-services.stock'))->assertForbidden();
        $this->post(route('cylinder-services.action',$job),['request_id'=>(string)Str::uuid(),'lock_version'=>$job->fresh()->lock_version,
            'action'=>'refill_sent','scanned_code'=>$this->a->asset->serial_number])->assertForbidden();
    }

    public function test_schema_has_unique_requests_and_restricts_deleting_referenced_asset_history(): void
    {
        $expected=['cylinders'=>['organization_id'=>'organizations','asset_id'=>'assets','reserved_for_rental_id'=>'rentals'],
            'cylinder_jobs'=>['organization_id'=>'organizations','rental_id'=>'rentals','incoming_cylinder_id'=>'cylinders','outgoing_cylinder_id'=>'cylinders',
                'assigned_user_id'=>'users','created_by_user_id'=>'users','invoice_id'=>'invoices'],
            'cylinder_events'=>['organization_id'=>'organizations','cylinder_id'=>'cylinders','cylinder_job_id'=>'cylinder_jobs','user_id'=>'users']];
        $connection=\Illuminate\Support\Facades\DB::connection();
        $expectedSchema=$connection->getDriverName()==='sqlite' ? 'main' : $connection->getDatabaseName();
        foreach($expected as $table=>$wanted) {
            $found=[];
            foreach(\Illuminate\Support\Facades\Schema::getForeignKeys($table) as $key) {
                $this->assertSame(['id'],$key['foreign_columns']);
                $this->assertContains(strtolower($key['on_delete']),['restrict','no action']);
                if(isset($key['foreign_schema']))$this->assertSame($expectedSchema,$key['foreign_schema']);
                $found[$key['columns'][0]]=$key['foreign_table'];
            }
            ksort($found);ksort($wanted);$this->assertSame($wanted,$found);
            $sets=array_values(array_map(fn($index)=>$index['columns'],array_filter(\Illuminate\Support\Facades\Schema::getIndexes($table),fn($index)=>$index['unique'])));
            foreach(match($table){'cylinders'=>[['asset_id']],'cylinder_jobs'=>[['invoice_id'],['organization_id','request_id']],default=>[['organization_id','request_id'],['cylinder_job_id','action']]} as $set)$this->assertContains($set,$sets);
        }
    }

    public function test_replacement_delivery_refuses_a_rental_serial_changed_after_booking(): void
    {
        $job=$this->service->create($this->owner,$this->rental,$this->payload())->fresh();
        $this->rental->rentalItems()->sole()->update(['asset_ids'=>[]]);
        $this->post(route('cylinder-services.action',$job),['request_id'=>(string)Str::uuid(),'lock_version'=>$job->lock_version,
            'action'=>'full_delivered','scanned_code'=>$this->b->asset->serial_number,'signature_reason'=>'Synthetic test'])->assertSessionHasErrors('cylinder');
        $this->assertNull($job->fresh()->delivered_at);
        $this->assertFalse($this->b->asset->activeRentalAssignments()->exists());
        $this->assertSame('warehouse',$this->b->fresh()->custody);
    }
}
