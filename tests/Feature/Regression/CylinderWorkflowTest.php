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
        $booking=$this->get(route('cylinder-services.create',$this->rental))->assertOk();
        $job=$this->service->create($this->owner,$this->rental,$this->payload());
        $folder=getenv('PHOS_CYLINDER_RENDER_DIR');
        if ($folder && !is_dir($folder)) mkdir($folder,0700,true);
        foreach (['job'=>route('cylinder-services.show',$job),'create'=>route('cylinder-services.create',$this->rental),
            'stock'=>route('cylinder-services.stock'),'index'=>route('cylinder-services.index'),
            'rental'=>route('rentals.show',$this->rental),'stock-register'=>route('cylinder-services.stock',['register'=>1])] as $name=>$url) {
            $response=$name==='create' ? $booking : $this->get($url)->assertOk();
            $response->assertDontSee('@foreach')->assertDontSee('@if');
            if($folder) file_put_contents($folder.'/'.$name.'.html',$response->getContent());
        }
    }

    public function test_empty_booking_explains_serial_registration_and_provides_authorized_next_steps_without_writes(): void
    {
        $this->a->update(['custody'=>'warehouse']);
        $before=[$this->a->fresh()->getRawOriginal(),CylinderEvent::count(),CylinderJob::count(),Asset::count()];
        $response=$this->get(route('cylinder-services.create',$this->rental))->assertOk()
            ->assertSeeText("Link the customer's cylinder before booking",false)
            ->assertSeeText('Register the existing cylinder')->assertSeeText('Confirm assignment and delivery')
            ->assertSee('href="'.e(route('cylinder-services.stock',['register'=>1,'rental_id'=>$this->rental->id])).'#cylinder-registration"',false)
            ->assertSee('href="'.route('rentals.show',$this->rental).'#rental-products-section"',false)
            ->assertDontSee('name="incoming_cylinder_id"',false);
        $folder=getenv('PHOS_CYLINDER_RENDER_DIR');
        if($folder){if(!is_dir($folder))mkdir($folder,0700,true);file_put_contents($folder.'/create-empty.html',$response->getContent());}
        $this->assertSame($before,[$this->a->fresh()->getRawOriginal(),CylinderEvent::count(),CylinderJob::count(),Asset::count()]);
        $role=\App\Models\Role::create(['organization_id'=>$this->owner->organization_id,'name'=>'Synthetic service booking','slug'=>'synthetic-service-booking','is_active'=>true,
            'permissions'=>['rentals'=>['read','update'],'deliveries'=>['read','create'],'__special'=>['scope.all'],'__special_explicit'=>true]]);
        $staff=TestData::user($this->owner->organization,['role_id'=>$role->id,'role'=>'custom']);
        $this->actingAs($staff)->withSession(['password_hash_web'=>$staff->getAuthPassword()])->get(route('cylinder-services.create',$this->rental))->assertOk()
            ->assertSeeText('Ask your asset manager')->assertDontSeeText('Register / view cylinders');
    }

    public function test_rental_actions_are_available_in_both_layouts_and_respect_booking_access(): void
    {
        $response=$this->get(route('rentals.show',$this->rental))->assertOk();
        $this->assertSame(2,substr_count($response->getContent(),'aria-label="Cylinder refill and exchange"'));
        $response->assertSeeText('Book refill / exchange')->assertSeeText('View cylinder history');
        $role=\App\Models\Role::create(['organization_id'=>$this->owner->organization_id,'name'=>'Synthetic rental read only','slug'=>'synthetic-rental-read','is_active'=>true,
            'permissions'=>['rentals'=>['read'],'__special'=>['scope.all'],'__special_explicit'=>true]]);
        $reader=TestData::user($this->owner->organization,['role_id'=>$role->id,'role'=>'custom']);
        $this->actingAs($reader)->withSession(['password_hash_web'=>$reader->getAuthPassword()])->get(route('rentals.show',$this->rental))->assertOk()
            ->assertDontSeeText('Book refill / exchange')->assertDontSeeText('View cylinder history');
        $this->get(route('cylinder-services.create',$this->rental))->assertForbidden();
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

    private function unregisteredAsset(Product $product, string $serial, string $status='available'): Asset
    {
        return Asset::create(['organization_id'=>$product->organization_id,'warehouse_id'=>$this->warehouse->id,'product_id'=>$product->id,
            'serial_number'=>$serial,'asset_stage'=>'rental_stock','asset_status'=>$status,'condition_status'=>'good']);
    }

    public function test_cylinder_product_recognition_excludes_other_equipment_and_accessories(): void
    {
        foreach(['4.5LRTS OXYGEN CYLINDER','Oxygen Cylinder 10 lpm','Medical O2 Cylinder'] as $name){
            $product=new Product(['name'=>$name]);$product->setRelation('categoryMaster',null);
            $this->assertTrue($this->service->isCylinderProduct($product),$name);
        }
        foreach(['Oxygen concentrator','Hospital bed','Oxygen cylinder regulator','Oxygen cylinder trolley','Nasal cannula','Oxygen cylinder refill service'] as $name){
            $product=new Product(['name'=>$name]);$product->setRelation('categoryMaster',null);
            $this->assertFalse($this->service->isCylinderProduct($product),$name);
        }
        $product=new Product(['name'=>'4.5L unit','category'=>'Oxygen Cylinders']);$product->setRelation('categoryMaster',null);
        $this->assertTrue($this->service->isCylinderProduct($product));
    }

    public function test_only_cylinder_rentals_show_actions_including_mixed_and_legacy_rentals(): void
    {
        $other=$this->product->replicate();$other->name='Synthetic oxygen concentrator';$other->save();
        $this->rental->rentalItems()->sole()->update(['product_id'=>$other->id]);
        $this->get(route('rentals.show',$this->rental))->assertOk()->assertDontSeeText('Book refill / exchange')->assertDontSeeText('View cylinder history');
        $this->get(route('cylinder-services.create',$this->rental))->assertStatus(422);
        RentalItem::create(['organization_id'=>$this->owner->organization_id,'rental_id'=>$this->rental->id,'product_id'=>$this->product->id,'quantity'=>1,'asset_ids'=>[$this->a->asset_id]]);
        $this->get(route('rentals.show',$this->rental))->assertOk()->assertSeeText('Book refill / exchange');
        $legacy=$this->rental->replicate();$legacy->save();
        $this->get(route('rentals.show',$legacy))->assertOk()->assertSeeText('Book refill / exchange');
    }

    public function test_registration_picker_and_server_validation_exclude_non_cylinders_and_invalid_serials(): void
    {
        $allowed=$this->unregisteredAsset($this->product,'UAT-ELIGIBLE-OXY');
        $bed=$this->product->replicate();$bed->name='Synthetic hospital bed';$bed->save();
        $wrong=$this->unregisteredAsset($bed,'UAT-NON-CYLINDER');
        $pending=$this->unregisteredAsset($this->product,Asset::SERIAL_PENDING_PREFIX.'SYNTHETIC');
        $this->get(route('cylinder-services.stock'))->assertOk()->assertViewHas('assets',fn($assets)=>$assets->modelKeys()===[$allowed->id]);
        $before=[Cylinder::count(),CylinderEvent::count(),$wrong->fresh()->getRawOriginal()];
        $this->post(route('cylinder-services.register'),['asset_id'=>$wrong->id,'contents'=>'full'])->assertSessionHasErrors('cylinder');
        $this->assertSame($before,[Cylinder::count(),CylinderEvent::count(),$wrong->fresh()->getRawOriginal()]);
    }

    public function test_registration_context_filters_rental_products_and_rejects_forged_context(): void
    {
        $allowed=$this->unregisteredAsset($this->product,'UAT-SCOPED-OXY');
        $otherProduct=$this->product->replicate();$otherProduct->name='Oxygen cylinder other size';$otherProduct->save();
        $wrong=$this->unregisteredAsset($otherProduct,'UAT-OTHER-SIZE');
        $otherRental=$this->rental->replicate();$otherRental->save();
        $otherAsset=$this->unregisteredAsset($this->product,'UAT-OTHER-CUSTOMER','rented');
        RentalAsset::create(['organization_id'=>$this->owner->organization_id,'rental_id'=>$otherRental->id,'asset_id'=>$otherAsset->id,'assigned_at'=>now()]);
        $this->get(route('cylinder-services.stock',['rental_id'=>$this->rental->id]))->assertOk()->assertViewHas('assets',fn($assets)=>$assets->modelKeys()===[$allowed->id]);
        $this->post(route('cylinder-services.register'),['rental_id'=>$this->rental->id,'asset_id'=>$wrong->id,'contents'=>'full'])->assertSessionHasErrors('asset_id');
        $this->assertDatabaseMissing('cylinders',['asset_id'=>$wrong->id]);
        $this->post(route('cylinder-services.register'),['rental_id'=>$this->rental->id,'asset_id'=>$otherAsset->id,'contents'=>'full'])->assertSessionHasErrors('asset_id');
        $this->assertDatabaseMissing('cylinders',['asset_id'=>$otherAsset->id]);
        $foreign=$this->rental->replicate();$foreign->organization_id=TestData::organization()->id;$foreign->save();
        $this->get(route('cylinder-services.stock',['rental_id'=>$foreign->id]))->assertNotFound();
        $this->post(route('cylinder-services.register'),['rental_id'=>$foreign->id,'asset_id'=>$allowed->id,'contents'=>'full'])->assertNotFound();
    }

    public function test_warehouse_registration_shows_serial_status_and_next_step_and_is_retry_safe(): void
    {
        $asset=$this->unregisteredAsset($this->product,'UAT-REGISTERED-WAREHOUSE');
        $data=['asset_id'=>$asset->id,'contents'=>'full'];
        $this->post(route('cylinder-services.register'),$data)->assertRedirect();
        $c=Cylinder::where('asset_id',$asset->id)->sole();
        $this->post(route('cylinder-services.register'),$data)->assertRedirect(route('cylinder-services.stock',['registered'=>$c->id]).'#cylinder-'.$c->id)
            ->assertSessionHas('success',fn($message)=>str_contains($message,$asset->serial_number));
        $response=$this->get(route('cylinder-services.stock',['registered'=>$c->id]))->assertOk()->assertSeeText($asset->serial_number)
            ->assertSeeText('This cylinder is still at the warehouse.')->assertViewHas('registered',fn($registered)=>$registered->id===$c->id);
        $this->assertSame(1,$c->events()->where('action','registered')->count());
        $folder=getenv('PHOS_CYLINDER_RENDER_DIR');if($folder){if(!is_dir($folder))mkdir($folder,0700,true);file_put_contents($folder.'/registered.html',$response->getContent());}
        $this->actingAs(TestData::user())->get(route('cylinder-services.stock',['registered'=>$c->id]))->assertNotFound();
    }

    public function test_customer_registration_returns_to_booking_and_can_create_refill_without_duplicating_assets(): void
    {
        $rental=$this->rental->replicate();$rental->save();
        $asset=$this->unregisteredAsset($this->product,'UAT-CUSTOMER-CYLINDER','rented');
        RentalAsset::create(['organization_id'=>$this->owner->organization_id,'rental_id'=>$rental->id,'asset_id'=>$asset->id,'assigned_at'=>now()]);
        RentalItem::create(['organization_id'=>$this->owner->organization_id,'rental_id'=>$rental->id,'product_id'=>$this->product->id,'quantity'=>1,'asset_ids'=>[$asset->id]]);
        $counts=[Asset::count(),Rental::count()];
        $this->post(route('cylinder-services.register'),['rental_id'=>$rental->id,'asset_id'=>$asset->id,'contents'=>'full'])
            ->assertRedirect(route('cylinder-services.create',$rental))->assertSessionHas('success',fn($message)=>str_contains($message,$asset->serial_number));
        $c=Cylinder::where('asset_id',$asset->id)->sole();
        $this->get(route('cylinder-services.create',$rental))->assertOk()->assertSeeText($asset->serial_number)->assertViewHas('incoming',fn($items)=>$items->modelKeys()===[$c->id]);
        $data=$this->payload('same');$data['incoming_cylinder_id']=$c->id;
        $this->post(route('cylinder-services.store',$rental),$data)->assertSessionHasNoErrors()->assertRedirect();
        $this->assertDatabaseHas('cylinder_jobs',['rental_id'=>$rental->id,'incoming_cylinder_id'=>$c->id,'mode'=>'same']);
        $this->assertSame($counts,[Asset::count(),Rental::count()]);
    }

    public function test_full_new_warehouse_cylinder_becomes_a_replacement_only_after_explicit_inspection(): void
    {
        $this->b->asset->update(['asset_status'=>'maintenance','condition_status'=>'new']);
        $page=$this->get(route('cylinder-services.create',$this->rental))->assertOk()->assertViewHas('outgoing',fn($items)=>$items->isEmpty())
            ->assertSeeText('No replacement cylinder is ready for exchange.');
        $stock=$this->get(route('cylinder-services.stock',['rental_id'=>$this->rental->id]))->assertOk()->assertSeeText('Inspect and mark ready for exchange')->assertSeeText('Not ready for exchange');
        $folder=getenv('PHOS_CYLINDER_RENDER_DIR');if($folder){if(!is_dir($folder))mkdir($folder,0700,true);file_put_contents($folder.'/create-no-ready.html',$page->getContent());file_put_contents($folder.'/stock-readiness.html',$stock->getContent());}
        $data=['request_id'=>(string)Str::uuid(),'action'=>'ready_confirmed','scanned_code'=>$this->b->asset->serial_number,'warehouse_id'=>$this->warehouse->id];
        $this->post(route('cylinder-services.stock-action',$this->b),$data)->assertSessionHasErrors('cylinder');
        $this->assertSame('maintenance',$this->b->asset->fresh()->asset_status);
        $this->assertSame('new',$this->b->asset->fresh()->condition_status);
        $data['inspection_confirmed']=true;
        $this->post(route('cylinder-services.stock-action',$this->b),$data)->assertSessionHasNoErrors();
        $this->post(route('cylinder-services.stock-action',$this->b),$data)->assertSessionHasNoErrors();
        $this->assertTrue(Asset::whereKey($this->b->asset_id)->rentalReady()->exists());
        $event=$this->b->events()->where('action','ready_confirmed')->sole();
        $this->assertSame('new',$event->details['condition_before']);$this->assertSame('good',$event->details['condition_after']);
        $this->get(route('cylinder-services.create',$this->rental))->assertOk()->assertViewHas('outgoing',fn($items)=>$items->modelKeys()===[$this->b->id]);
        $this->post(route('cylinder-services.store',$this->rental),$this->payload())->assertSessionHasNoErrors()->assertRedirect();
    }

    public function test_readiness_does_not_release_damaged_or_reserved_cylinders_and_requires_stock_access(): void
    {
        $data=['request_id'=>(string)Str::uuid(),'action'=>'ready_confirmed','scanned_code'=>$this->b->asset->serial_number,'warehouse_id'=>$this->warehouse->id,'inspection_confirmed'=>true];
        foreach(['damaged','needs_repair','fair','retired'] as $condition){
            $this->b->asset->update(['asset_status'=>'maintenance','condition_status'=>$condition]);
            $this->post(route('cylinder-services.stock-action',$this->b),$data)->assertSessionHasErrors('cylinder');
            $this->assertSame($condition,$this->b->asset->fresh()->condition_status);
        }
        $this->b->asset->update(['asset_status'=>'maintenance','condition_status'=>'new']);
        $this->b->update(['reserved_for_rental_id'=>$this->rental->id]);
        $this->post(route('cylinder-services.stock-action',$this->b),$data)->assertSessionHasErrors('cylinder');
        $this->assertSame(0,$this->b->events()->where('action','ready_confirmed')->count());
        $worker=TestData::user($this->owner->organization,['role'=>User::ROLE_DELIVERY]);
        $this->actingAs($worker)->post(route('cylinder-services.stock-action',$this->b),$data)->assertForbidden();
    }

    public function test_missing_exchange_replacement_uses_a_clear_validation_message_and_same_refill_needs_none(): void
    {
        $data=$this->payload();$data['outgoing_cylinder_id']=null;
        $this->post(route('cylinder-services.store',$this->rental),$data)->assertSessionHasErrors(['outgoing_cylinder_id'=>'Choose a full, inspected replacement cylinder for an exchange. To refill the original cylinder, select Refill and return the same cylinder.']);
        $data['mode']='same';
        $this->post(route('cylinder-services.store',$this->rental),$data)->assertSessionHasNoErrors()->assertRedirect();
    }
}
