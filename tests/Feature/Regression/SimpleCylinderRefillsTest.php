<?php
namespace Tests\Feature\Regression;
use App\Models\{Asset,Customer,Cylinder,CylinderRefill,CylinderRefillEvent,Invoice,Product,Rental,RentalAsset,RentalItem,Staff,User,Vendor,Warehouse};
use App\Services\Cylinders\{SimpleCylinderRefills,RefillDeliveryAssignments};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\TestData;
use Tests\TestCase;

class SimpleCylinderRefillsTest extends TestCase
{
    use RefreshDatabase;
    private $owner,$driver,$warehouse,$product,$rental,$incoming,$outgoing,$service;
    protected function setUp(): void
    {
        parent::setUp();$org=TestData::organization(['state'=>'Karnataka']);$this->owner=TestData::user($org);$this->actingAs($this->owner);
        $this->driver=TestData::user($org,['role'=>User::ROLE_DELIVERY,'name'=>'Synthetic delivery driver']);
        $this->warehouse=Warehouse::create(['organization_id'=>$org->id,'name'=>'Synthetic refill warehouse','is_active'=>true]);
        $customer=Customer::create(['organization_id'=>$org->id,'name'=>'Synthetic refill customer','phone'=>'+919876543210','state'=>'Karnataka']);
        $this->product=Product::create(['organization_id'=>$org->id,'name'=>'Oxygen cylinder 4.5 litres','product_type'=>'rentable','stock_mode'=>'tracked_rental','total_quantity'=>2,'available_quantity'=>1,'price_per_day'=>10]);
        $this->rental=Rental::create(['organization_id'=>$org->id,'customer_id'=>$customer->id,'product_id'=>$this->product->id,'customer_name'=>$customer->name,'phone'=>$customer->phone,
            'status'=>'active','quantity'=>1,'start_date'=>today(),'end_date'=>today()->addDays(30),'deposit_amount'=>1000,'rental_amount'=>500,'created_by_user_id'=>$this->owner->id,'fulfilment_source'=>'in_house']);
        foreach(['incoming'=>'rented','outgoing'=>'available']as$key=>$status){
            $this->$key=Asset::create(['organization_id'=>$org->id,'product_id'=>$this->product->id,'warehouse_id'=>$this->warehouse->id,'serial_number'=>'PENDING-SYNTHETIC-'.strtoupper($key),'asset_stage'=>'rental_stock','asset_status'=>$status,'condition_status'=>'new']);
        }
        RentalAsset::create(['organization_id'=>$org->id,'rental_id'=>$this->rental->id,'asset_id'=>$this->incoming->id,'assigned_at'=>now()]);
        RentalItem::create(['organization_id'=>$org->id,'rental_id'=>$this->rental->id,'product_id'=>$this->product->id,'quantity'=>1,'ordered_quantity'=>1,'delivered_quantity'=>1,'returned_quantity'=>0,'asset_ids'=>[$this->incoming->id],'line_total'=>500]);
        $this->service=app(SimpleCylinderRefills::class);
    }
    private function data(string $mode='exchange'): array {return ['request_id'=>(string)Str::uuid(),'product_id'=>$this->product->id,'quantity'=>1,'mode'=>$mode,'assigned_user_id'=>$this->driver->id,'scheduled_at'=>now()->format('Y-m-d\TH:i'),'refill_charge'=>600,'transport_charge'=>100,'tax_rate'=>5];}
    private function change(CylinderRefill $refill,string $action,int $qty=1,?array $extra=null)
    {
        return $this->service->change($this->owner,$refill,['request_id'=>(string)Str::uuid(),'lock_version'=>$refill->fresh()->lock_version,'action'=>$action,'quantity'=>$qty,'warehouse_id'=>$this->warehouse->id]+($extra??[]));
    }
    private function render(string $name,$response): void
    {
        if($folder=getenv('PHOS_SIMPLE_REFILL_RENDER_DIR')){if(!is_dir($folder))mkdir($folder,0700,true);file_put_contents($folder.'/'.$name.'.html',$response->getContent());}
    }
    public function test_popup_booking_needs_no_serial_registration_or_inspection_and_preserves_rental_terms(): void
    {
        $this->getJson(route('cylinder-refills.popup',$this->rental))->assertOk()->assertJsonStructure(['html']);
        $before=[$this->rental->fresh()->getRawOriginal(),Asset::count()];$data=$this->data();
        $this->postJson(route('cylinder-refills.store',$this->rental),$data)->assertOk()->assertSee('Ensure the empty cylinder');
        $refill=CylinderRefill::sole();
        $this->assertSame([$this->incoming->id],$refill->incoming_asset_ids);$this->assertSame([$this->outgoing->id],$refill->outgoing_asset_ids);
        $this->assertNull($refill->serial_reference);$this->assertSame('reserved',$this->outgoing->fresh()->asset_status);
        $this->assertSame('new',$this->outgoing->fresh()->condition_status);$this->assertSame($before,[$this->rental->fresh()->getRawOriginal(),Asset::count()]);
        $this->postJson(route('cylinder-refills.store',$this->rental),$data)->assertOk();$this->assertDatabaseCount('cylinder_refills',1);
        $data['transport_charge']=200;$this->postJson(route('cylinder-refills.store',$this->rental),$data)->assertStatus(409);
    }
    public static function handoverOrders(): array {return [[['deliver','collect_empty','receive_empty']],[['collect_empty','receive_empty','deliver']]];}
    #[\PHPUnit\Framework\Attributes\DataProvider('handoverOrders')]
    public function test_exchange_delivery_and_empty_receipt_in_either_order_leave_correct_assets_and_reminder(array $actions): void
    {
            $refill=$this->service->book($this->owner,$this->rental,$this->data())->fresh();
            foreach($actions as$action){$refill=$this->change($refill,$action);if($action==='deliver'&&$refill->received_quantity===0)$this->assertSame(1,$refill->pending_empty_quantity);}
            $this->assertSame('completed',$refill->status);$this->assertSame(0,$refill->pending_empty_quantity);
            $this->assertSame([$this->outgoing->id],$this->rental->rentalItems()->sole()->asset_ids);
            $this->assertSame(1,$this->rental->activeRentalAssets()->count());$this->assertSame('maintenance',$this->incoming->fresh()->asset_status);
            $this->assertSame('empty',$this->incoming->cylinder->contents);$this->assertSame('warehouse',$this->incoming->cylinder->custody);
    }
    public function test_same_cylinder_refill_returns_original_unit_without_replacement_selection(): void
    {
        $refill=$this->service->book($this->owner,$this->rental,$this->data('same'))->fresh();
        foreach(['collect_empty','receive_empty','receive_refilled','deliver']as$action)$refill=$this->change($refill,$action);
        $this->assertSame('completed',$refill->status);$this->assertSame([$this->incoming->id],$this->rental->rentalItems()->sole()->asset_ids);
        $this->assertSame('rented',$this->incoming->fresh()->asset_status);$this->assertSame('available',$this->outgoing->fresh()->asset_status);
        $this->assertDatabaseCount('assets',2);$this->assertDatabaseCount('rental_assets',1);
    }
    public function test_quantity_stock_works_without_any_asset_serial_and_empty_return_does_not_inflate_full_stock(): void
    {
        RentalAsset::query()->delete();$this->rental->rentalItems()->sole()->update(['asset_ids'=>[]]);Asset::query()->delete();
        $this->product->update(['stock_mode'=>'untracked','available_quantity'=>2,'total_quantity'=>3]);
        $refill=$this->service->book($this->owner,$this->rental,$this->data())->fresh();$this->assertSame('quantity',$refill->stock_mode);
        $this->assertSame(1,(int)$this->product->fresh()->available_quantity);
        foreach(['deliver','collect_empty','receive_empty']as$action)$refill=$this->change($refill,$action);
        $this->assertSame(1,(int)$this->product->fresh()->available_quantity);$this->assertDatabaseCount('assets',0);
        $data=['request_id'=>(string)Str::uuid(),'lock_version'=>$refill->lock_version,'action'=>'receive_refilled','quantity'=>1,'warehouse_id'=>$this->warehouse->id];
        $this->service->change($this->owner,$refill,$data);$this->service->change($this->owner,$refill,$data);
        $this->assertSame(2,(int)$this->product->fresh()->available_quantity);
    }
    public function test_driver_sees_only_own_refill_and_pickup_actions_without_financial_numbers(): void
    {
        $refill=$this->service->book($this->owner,$this->rental,$this->data())->fresh();
        $this->actingAs($this->driver)->get(route('cylinder-refills.tasks'))->assertOk()->assertSeeText('Refill delivered')->assertSeeText('Empty cylinder picked up')->assertDontSee('name="refill_charge"',false)->assertDontSeeText('Total ₹');
        $this->get(route('dashboard'))->assertOk()->assertSeeText('My cylinder refills')->assertSeeText('Empty cylinder picked up');
        $other=TestData::user($this->owner->organization,['role'=>User::ROLE_DELIVERY]);$this->assertSame(0,$this->service->query($other)->count());
        $payload=['request_id'=>(string)Str::uuid(),'lock_version'=>0,'action'=>'deliver','quantity'=>1];
        $this->actingAs($other)->postJson(route('cylinder-refills.change',$refill),$payload)->assertNotFound();
        $this->actingAs($this->driver)->postJson(route('cylinder-refills.change',$refill),$payload+['task_view'=>1])->assertOk();
        $this->postJson(route('cylinder-refills.change',$refill),['request_id'=>(string)Str::uuid(),'lock_version'=>1,'action'=>'receive_empty','quantity'=>1,'warehouse_id'=>$this->warehouse->id])->assertForbidden();
    }
    public function test_duplicates_stale_submissions_and_early_empty_receipt_cannot_change_counts(): void
    {
        $refill=$this->service->book($this->owner,$this->rental,$this->data())->fresh();
        $payload=['request_id'=>(string)Str::uuid(),'lock_version'=>0,'action'=>'deliver','quantity'=>1];
        $this->postJson(route('cylinder-refills.change',$refill),$payload)->assertOk();$this->postJson(route('cylinder-refills.change',$refill),$payload)->assertOk();
        $this->assertSame(1,$refill->events()->where('action','deliver')->count());$payload['request_id']=(string)Str::uuid();$payload['action']='collect_empty';
        $this->postJson(route('cylinder-refills.change',$refill),$payload)->assertStatus(409);
        $payload['lock_version']=1;$payload['action']='receive_empty';$payload['warehouse_id']=$this->warehouse->id;
        $this->postJson(route('cylinder-refills.change',$refill),$payload)->assertUnprocessable();$this->assertSame(0,$refill->fresh()->received_quantity);
    }
    public function test_cancel_restores_reserved_stock_and_reassignment_updates_the_delivery_login(): void
    {
        $refill=$this->service->book($this->owner,$this->rental,$this->data())->fresh();$other=TestData::user($this->owner->organization,['role'=>User::ROLE_DELIVERY]);
        $refill=$this->change($refill,'reassign',0,['assigned_user_id'=>$other->id]);$this->assertSame($other->id,$refill->assigned_user_id);
        $this->assertSame(0,$this->service->query($this->driver)->count());$this->change($refill,'cancel',0);
        $this->assertTrue(Asset::whereKey($this->outgoing->id)->rentalReady()->exists());$this->assertSame('new',$this->outgoing->fresh()->condition_status);
    }
    public function test_service_invoice_is_separate_from_rental_and_is_created_only_once(): void
    {
        $refill=$this->service->book($this->owner,$this->rental,$this->data())->fresh();$this->change($refill,'deliver');
        $this->postJson(route('cylinder-refills.invoice',$refill))->assertOk();$this->postJson(route('cylinder-refills.invoice',$refill))->assertOk();
        $invoice=$refill->fresh()->invoice;$this->assertNotNull($invoice);$this->assertNull($invoice->rental_id);$this->assertEquals(700,$invoice->total_amount);
        $this->assertDatabaseCount('invoices',1);$this->assertEquals(1000,$this->rental->fresh()->deposit_amount);
    }
    public function test_rendered_popup_and_task_fixtures_contain_all_controls_without_serial_requirement(): void
    {
        Vendor::create(['organization_id'=>$this->owner->organization_id,'name'=>'Synthetic medical supply vendor','is_active'=>true]);
        Staff::create(['organization_id'=>$this->owner->organization_id,'name'=>'Synthetic external transporter','role'=>'third_party','assignment_role'=>'third_party','is_assignment_enabled'=>true,'status'=>'active']);
        $this->render('rental',$this->get(route('rentals.show',$this->rental))->assertOk());
        $this->render('popup',$this->get(route('cylinder-refills.popup',$this->rental))->assertOk());
        $folder=getenv('PHOS_SIMPLE_REFILL_RENDER_DIR');if($folder)file_put_contents($folder.'/popup-fragment.html',$this->getJson(route('cylinder-refills.popup',$this->rental))->json('html'));
        $refill=$this->service->book($this->owner,$this->rental,$this->data())->fresh();
        $this->render('booked',$this->get(route('cylinder-refills.popup',$this->rental))->assertOk());
        if($folder)file_put_contents($folder.'/booked-fragment.html',$this->getJson(route('cylinder-refills.popup',$this->rental))->json('html'));
        $this->actingAs($this->driver);$this->render('tasks',$this->get(route('cylinder-refills.tasks'))->assertOk());
        $this->actingAs($this->owner);
        foreach ([
            'deliver' => ['delivered', 'Delivery recorded'],
            'collect_empty' => ['picked-up', 'Empty cylinder pickup recorded'],
            'receive_empty' => ['received', 'Empty cylinder return recorded'],
        ] as $action => [$name, $confirmation]) {
            $response = $this->postJson(route('cylinder-refills.change', $refill), [
                'request_id' => (string) Str::uuid(), 'lock_version' => $refill->fresh()->lock_version,
                'action' => $action, 'quantity' => 1, 'warehouse_id' => $this->warehouse->id,
            ])->assertOk();
            $this->assertStringContainsString($confirmation.' for refill #'.$refill->id.'.', $response->json('html'));
            if ($folder) file_put_contents($folder.'/'.$name.'-fragment.html', $response->json('html'));
        }
        $this->assertEquals(1, $refill->fresh()->delivered_quantity);
        $this->assertEquals(1, $refill->fresh()->collected_quantity);
        $this->assertSame('completed', $refill->fresh()->status);
    }
    public function test_partial_delivery_and_returns_cannot_exceed_the_requested_cylinder_count(): void
    {
        $in=$this->incoming->replicate();$in->serial_number='PENDING-IN-2';$in->save();
        $out=$this->outgoing->replicate();$out->serial_number='PENDING-OUT-2';$out->save();
        RentalAsset::create(['organization_id'=>$this->owner->organization_id,'rental_id'=>$this->rental->id,'asset_id'=>$in->id,'assigned_at'=>now()]);
        $this->rental->update(['quantity'=>2]);$this->product->update(['total_quantity'=>4,'available_quantity'=>2]);
        $this->rental->rentalItems()->sole()->update(['quantity'=>2,'ordered_quantity'=>2,'delivered_quantity'=>2,'asset_ids'=>[$this->incoming->id,$in->id]]);
        $data=$this->data();$data['quantity']=2;$refill=$this->service->book($this->owner,$this->rental,$data)->fresh();
        foreach(['deliver','collect_empty','receive_empty']as$action)$refill=$this->change($refill,$action);
        $this->assertSame('open',$refill->status);$this->assertSame(1,$refill->pending_empty_quantity);
        $this->postJson(route('cylinder-refills.change',$refill),['request_id'=>(string)Str::uuid(),'lock_version'=>$refill->lock_version,'action'=>'collect_empty','quantity'=>2])->assertUnprocessable();
        foreach(['deliver','collect_empty','receive_empty']as$action)$refill=$this->change($refill,$action);
        $this->assertSame('completed',$refill->status);$this->assertSame(2,$this->rental->activeRentalAssets()->count());
        $this->assertSame(2,$this->rental->rentalItems()->sole()->quantity);$this->assertDatabaseCount('assets',4);
    }
    public function test_wrong_capacity_damaged_stock_foreign_rentals_and_closing_early_are_rejected(): void
    {
        $data=$this->data();$data['product_id']=999999;$this->postJson(route('cylinder-refills.store',$this->rental),$data)->assertUnprocessable();
        $this->outgoing->update(['condition_status'=>'damaged']);$this->postJson(route('cylinder-refills.store',$this->rental),$this->data())->assertUnprocessable();
        $this->assertDatabaseCount('cylinder_refills',0);$this->assertDatabaseCount('cylinders',0);
        $this->outgoing->update(['condition_status'=>'new']);$refill=$this->service->book($this->owner,$this->rental,$this->data());
        $foreign=TestData::user();$this->actingAs($foreign)->getJson(route('cylinder-refills.popup',$this->rental))->assertForbidden();
        $this->postJson(route('cylinder-refills.change',$refill),['request_id'=>(string)Str::uuid(),'lock_version'=>0,'action'=>'deliver','quantity'=>1])->assertNotFound();
        $this->actingAs($this->owner);
        try{$this->rental->update(['status'=>'closed']);$this->fail('Rental closed before its empty return.');}catch(\Illuminate\Validation\ValidationException $error){$this->assertArrayHasKey('status',$error->errors());}
        $this->assertSame('active',$this->rental->fresh()->status);
    }
    public function test_popup_requires_login_and_new_history_has_restricted_foreign_keys_and_retry_keys(): void
    {
        auth()->logout();$this->get(route('cylinder-refills.popup',$this->rental))->assertRedirect(route('login'));
        $this->postJson(route('cylinder-refills.store',$this->rental),$this->data())->assertUnauthorized();
        foreach(['cylinder_refills','cylinder_refill_events']as$table){
            foreach(\Illuminate\Support\Facades\Schema::getForeignKeys($table)as$key)$this->assertContains(strtolower($key['on_delete']),['restrict','no action']);
            $indexes=\Illuminate\Support\Facades\Schema::getIndexes($table);
            $this->assertTrue(collect($indexes)->contains(fn($key)=>$key['unique']&&$key['columns']===['organization_id','request_id']));
        }
        $this->assertTrue(collect(\Illuminate\Support\Facades\Schema::getColumns('cylinder_refills'))->firstWhere('name','serial_reference')['nullable']);
    }
    public function test_assignment_choices_exclude_admins_and_sales_and_group_only_active_delivery_people(): void
    {
        TestData::user($this->owner->organization,['role'=>User::ROLE_SALES]);
        TestData::user($this->owner->organization,['role'=>User::ROLE_DELIVERY,'is_active'=>false]);
        $vendor=TestData::user($this->owner->organization,['role'=>User::ROLE_VENDOR]);
        $third=TestData::user($this->owner->organization,['role'=>User::ROLE_THIRD_PARTY]);
        $options=collect(app(RefillDeliveryAssignments::class)->options($this->owner));
        $this->assertSame(['user:'.$this->driver->id],$options->where('type','delivery_team')->pluck('value')->all());
        $this->assertSame('vendor',$options->firstWhere('value','user:'.$vendor->id)['type']);
        $this->assertSame('third_party',$options->firstWhere('value','user:'.$third->id)['type']);
        $this->assertNotContains('user:'.$this->owner->id,$options->pluck('value'));
        $this->getJson(route('cylinder-refills.popup',$this->rental))->assertOk()->assertSee('Internal team')->assertSee('Third party')->assertSee('Vendor');
    }
    public static function externalLogins(): array {return [['vendor'],['third_party']];}
    #[\PHPUnit\Framework\Attributes\DataProvider('externalLogins')]
    public function test_external_login_receives_own_refill_task_and_no_other_delivery_user_can_access_it(string $type): void
    {
        $user=TestData::user($this->owner->organization,['role'=>$type]);$data=$this->data();unset($data['assigned_user_id']);
        $data+=['assignment_type'=>$type,'assignment_target'=>'user:'.$user->id];
        $this->postJson(route('cylinder-refills.store',$this->rental),$data)->assertOk();$refill=CylinderRefill::sole();
        $this->assertSame($user->id,$refill->assigned_user_id);$this->assertSame($type,$refill->assignment_type);
        $this->assertSame(0,$this->service->query($this->driver)->count());
        $this->actingAs($user)->get(route('cylinder-refills.tasks'))->assertOk()->assertSeeText('Refill delivered')->assertDontSeeText('Total ₹');
        $this->postJson(route('cylinder-refills.change',$refill),['request_id'=>(string)Str::uuid(),'lock_version'=>0,'action'=>'deliver','quantity'=>1,'task_view'=>1])->assertOk();
        $notification=$user->notifications()->where('data->type','cylinder_refill.assigned')->sole();
        $this->assertSame(route('cylinder-refills.tasks'),$notification->data['action_url']);
    }
    public static function externalRecords(): array {return [['staff'],['vendor'],['manual']];}
    #[\PHPUnit\Framework\Attributes\DataProvider('externalRecords')]
    public function test_external_provider_without_login_keeps_stock_progress_and_snapshot_without_fake_user(string $kind): void
    {
        $data=$this->data();unset($data['assigned_user_id']);$users=User::count();
        if($kind==='staff'){$record=Staff::create(['organization_id'=>$this->owner->organization_id,'name'=>'External vendor staff','role'=>'vendor','assignment_role'=>'vendor','is_assignment_enabled'=>true,'status'=>'active']);$data+=['assignment_type'=>'vendor','assignment_target'=>'staff:'.$record->id];}
        elseif($kind==='vendor'){$record=Vendor::create(['organization_id'=>$this->owner->organization_id,'name'=>'External vendor master','is_active'=>true]);$data+=['assignment_type'=>'vendor','assignment_target'=>'vendor:'.$record->id];}
        else $data+=['assignment_type'=>'third_party','assignment_target'=>'manual','third_party_name'=>'Independent transporter','third_party_phone'=>'+919876543210'];
        $this->postJson(route('cylinder-refills.store',$this->rental),$data)->assertOk()->assertSee('no assigned PHOS login');
        $this->postJson(route('cylinder-refills.store',$this->rental),$data)->assertOk();$refill=CylinderRefill::sole();
        $this->assertNull($refill->assigned_user_id);$this->assertSame($users,User::count());$this->assertSame(0,$this->service->query($this->driver)->count());
        $this->assertSame($kind==='staff'?$record->id:null,$refill->assigned_staff_id);
        $this->assertSame($kind==='vendor'?$record->id:null,$refill->assigned_vendor_id);
        $snapshot=$refill->delivery_assignee_name;if(isset($record))$record->update(['name'=>'Renamed provider']);
        $this->assertSame($snapshot,$refill->fresh()->delivery_assignee_name);
        foreach(['deliver','collect_empty','receive_empty']as$action)$refill=$this->change($refill,$action);
        $this->assertSame('completed',$refill->status);$this->assertSame(0,$refill->pending_empty_quantity);
        $this->assertSame('empty',$this->incoming->fresh()->cylinder->contents);
        $this->assertSame($snapshot,$refill->events()->where('action','booked')->sole()->details['assignment']['assignment_name']);
    }
    public function test_wrong_type_foreign_inactive_and_disabled_provider_assignments_are_refused_before_stock_reservation(): void
    {
        $foreign=Vendor::create(['organization_id'=>TestData::organization()->id,'name'=>'Foreign vendor','is_active'=>true]);
        $inactive=Vendor::create(['organization_id'=>$this->owner->organization_id,'name'=>'Inactive vendor','is_active'=>false]);
        $staff=Staff::create(['organization_id'=>$this->owner->organization_id,'name'=>'Disabled vendor','role'=>'vendor','assignment_role'=>'vendor','is_assignment_enabled'=>false,'status'=>'active']);
        foreach([['delivery_team','user:'.$this->owner->id],['vendor','user:'.$this->driver->id],['vendor','vendor:'.$foreign->id],['vendor','vendor:'.$inactive->id],['vendor','staff:'.$staff->id],['third_party','manual']]as[$type,$target]){
            $data=$this->data();unset($data['assigned_user_id']);$data+=['assignment_type'=>$type,'assignment_target'=>$target];
            $this->postJson(route('cylinder-refills.store',$this->rental),$data)->assertUnprocessable();
        }
        $this->assertDatabaseCount('cylinder_refills',0);$this->assertSame('available',$this->outgoing->fresh()->asset_status);
    }
    public function test_reassignment_switches_provider_types_without_moving_stock_and_preserves_previous_assignment(): void
    {
        $refill=$this->service->book($this->owner,$this->rental,$this->data())->fresh();
        $data=['request_id'=>(string)Str::uuid(),'lock_version'=>0,'action'=>'reassign','assignment_type'=>'third_party','assignment_target'=>'manual','third_party_name'=>'Outside transporter'];
        $this->postJson(route('cylinder-refills.change',$refill),$data)->assertOk();$this->postJson(route('cylinder-refills.change',$refill),$data)->assertOk();
        $this->assertSame(0,$this->service->query($this->driver)->count());$this->assertSame('reserved',$this->outgoing->fresh()->asset_status);
        $this->assertSame($this->driver->id,$refill->events()->where('action','reassign')->sole()->details['previous_assignment']['assigned_user_id']);
        $refill=$this->service->change($this->owner,$refill->fresh(),['request_id'=>(string)Str::uuid(),'lock_version'=>1,'action'=>'reassign','assignment_type'=>'delivery_team','assignment_target'=>'user:'.$this->driver->id]);
        $this->assertSame($this->driver->id,$refill->assigned_user_id);$this->assertNull($refill->assigned_vendor_id);$this->assertNull($refill->assigned_staff_id);$this->assertNull($refill->assignment_phone);
        $this->assertSame(1,$this->service->query($this->driver)->count());
    }
    public function test_assignment_migration_preserves_existing_refills_and_blocks_removal_after_new_assignments(): void
    {
        $refill=$this->service->book($this->owner,$this->rental,$this->data())->fresh();
        $migration=require database_path('migrations/2026_11_02_220000_add_cylinder_refill_delivery_assignment.php');
        try{$migration->down();$this->fail('Assignment history must block schema removal.');}catch(\RuntimeException $error){$this->assertStringContainsString('in use',$error->getMessage());}
        \Illuminate\Support\Facades\DB::table('cylinder_refills')->where('id',$refill->id)->update(['assignment_type'=>null,'assignment_name'=>null,'assignment_phone'=>null,'assignment_contact'=>null]);
        $before=$refill->fresh()->only(['id','assigned_user_id','quantity','status','refill_charge','lock_version','created_at','updated_at']);$events=CylinderRefillEvent::count();
        if(\Illuminate\Support\Facades\DB::getDriverName()==='sqlite')\Illuminate\Support\Facades\DB::statement('PRAGMA defer_foreign_keys=ON');
        $migration->down();$migration->up();
        if(\Illuminate\Support\Facades\DB::getDriverName()==='sqlite')$this->assertSame([],\Illuminate\Support\Facades\DB::select('PRAGMA foreign_key_check'));
        $this->assertEquals($before,$refill->fresh()->only(array_keys($before)));$this->assertSame($events,CylinderRefillEvent::count());
        $this->assertSame($this->driver->name,$refill->fresh()->delivery_assignee_name);$this->assertNull($refill->fresh()->assignment_type);
    }
}
