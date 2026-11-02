<?php
namespace App\Services\Cylinders;

use App\Models\{Asset,Cylinder,CylinderEvent,CylinderJob,CylinderRefill,CylinderRefillEvent,Product,Rental,RentalAsset,User,Warehouse};
use App\Services\Inventory\StockMovementRecorder;
use App\Services\NotificationCenterService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SimpleCylinderRefills
{
    private function fail(string $text): never {throw ValidationException::withMessages(['refill'=>$text]);}
    private function workflow(): CylinderWorkflow {return app(CylinderWorkflow::class);}
    private function fingerprint(array $data): string {unset($data['request_id'],$data['lock_version']);ksort($data);return hash('sha256',json_encode($data,JSON_THROW_ON_ERROR));}
    public function driver(User $user): bool {return in_array($user->effective_role,[User::ROLE_DELIVERY,User::ROLE_DELIVERY_EXECUTIVE,User::ROLE_VENDOR,User::ROLE_THIRD_PARTY],true);}
    public function query(User $user)
    {
        $module=$user->canAccessModule('deliveries','read')?'deliveries':'invoices';
        $this->workflow()->authorize($user,'read');
        return CylinderRefill::where('organization_id',$user->organization_id)
            ->when($this->driver($user),fn($q)=>$q->where('assigned_user_id',$user->id))
            ->when(!$this->driver($user)&&$user->scopeType($module)!=='all',fn($q)=>$q->where(fn($a)=>$a->where('assigned_user_id',$user->id)->orWhere('created_by_user_id',$user->id)));
    }
    public function authorize(User $user,CylinderRefill $refill,string $action='read'): void
    {
        abort_unless((int)$refill->organization_id===(int)$user->organization_id,404);
        abort_unless($this->query($user)->whereKey($refill->id)->exists(),404);
        if($action!=='read')abort_unless($user->canAccessModule('deliveries',$action),403);
    }
    public function capacities(Rental $rental)
    {
        $rental->loadMissing(['product.categoryMaster','rentalItems.product.categoryMaster']);
        if($rental->isVendorSupplied())return collect();
        if($rental->rentalItems->isNotEmpty())return $rental->rentalItems->filter(fn($line)=>$this->workflow()->isCylinderProduct($line->product))
            ->groupBy('product_id')->map(fn($lines)=>['product'=>$lines->first()->product,'issued'=>$lines->sum(fn($line)=>max(0,$line->delivered_quantity_value-$line->returned_quantity_value))])->values();
        return $this->workflow()->isCylinderProduct($rental->product)?collect([['product'=>$rental->product,'issued'=>max(0,(int)$rental->quantity)]]):collect();
    }
    public function book(User $user,Rental $rental,array $data): CylinderRefill
    {
        $this->workflow()->authorize($user,'create');$this->workflow()->authorize($user,'read');Gate::forUser($user)->authorize('update',$rental);
        abort_unless((int)$rental->organization_id===(int)$user->organization_id,404);
        return $this->workflow()->atomic($user,function()use($user,$rental,$data){
            $rental=Rental::lockForUpdate()->findOrFail($rental->id);
            if($rental->status!=='active'||$rental->isVendorSupplied())$this->fail('Choose an active PHOS-owned cylinder rental.');
            $fingerprint=$this->fingerprint($data+['rental_id'=>$rental->id]);
            if($old=CylinderRefill::where('organization_id',$user->organization_id)->where('request_id',$data['request_id'])->first()){
                $this->authorize($user,$old);abort_unless(hash_equals($old->request_fingerprint,$fingerprint),409,'This request was already used with different details.');return $old;
            }
            $capacity=$this->capacities($rental)->first(fn($row)=>(int)$row['product']->id===(int)$data['product_id']);
            if(!$capacity||$data['quantity']>$capacity['issued'])$this->fail('Choose a cylinder size and quantity already issued on this rental.');
            $product=Product::where('organization_id',$user->organization_id)->lockForUpdate()->findOrFail($data['product_id']);
            if(CylinderRefill::where('rental_id',$rental->id)->where('product_id',$product->id)->where('status','open')->exists())$this->fail('A refill for this size is already open. Update it below and record the empty return.');
            if(CylinderJob::where('rental_id',$rental->id)->where('status','open')->exists())$this->fail('Complete or cancel the earlier cylinder service before booking another refill.');
            $assignee=User::where('organization_id',$user->organization_id)->findOrFail($data['assigned_user_id']);
            if(!$assignee->canAccessModule('deliveries','update'))$this->fail('Choose active delivery staff with task-update access.');
            if($user->scopeType('deliveries')!=='all'&&(int)$assignee->id!==(int)$user->id)abort(403);
            $incoming=[];$outgoing=[];$tracked=$product->tracksRentalStock();$qty=(int)$data['quantity'];
            if($tracked){
                $lineIds=$rental->rentalItems->where('product_id',$product->id)->flatMap(fn($line)=>$line->asset_ids??[])->map(fn($id)=>(int)$id)->all();
                $units=Asset::where('organization_id',$user->organization_id)->where('product_id',$product->id)->where('asset_status','rented')
                    ->whereHas('activeRentalAssignments',fn($q)=>$q->where('rental_id',$rental->id))
                    ->when($rental->rentalItems->isNotEmpty(),fn($q)=>$q->whereIn('id',$lineIds))->orderBy('id')->lockForUpdate()->limit($qty)->get();
                if($units->count()!==$qty)$this->fail('The initial delivery has not recorded enough cylinder units for this rental. Complete that delivery first.');
                $incoming=$units->modelKeys();foreach($units as $unit)$this->unit($unit,'customer','unknown',$rental->id);
                if($data['mode']==='exchange'){
                    $replacement=Asset::where('organization_id',$user->organization_id)->where('product_id',$product->id)->where('asset_stage','rental_stock')
                        ->whereIn('condition_status',['new','good'])->whereDoesntHave('activeRentalAssignments')
                        ->where(fn($q)=>$q->where(fn($a)=>$a->where('asset_status','available')->whereDoesntHave('cylinder'))
                            ->orWhere(fn($a)=>$a->whereIn('asset_status',['available','maintenance'])->whereHas('cylinder',fn($c)=>$c->where('contents','full')->where('custody','warehouse')->whereNull('reserved_for_rental_id'))))
                        ->orderBy('id')->lockForUpdate()->limit($qty)->get();
                    if($replacement->count()!==$qty)$this->fail('There are not enough full cylinders of this size in stock. Choose same-cylinder refill or arrange full stock first.');
                    $outgoing=$replacement->modelKeys();foreach($replacement as $unit){$this->unit($unit,'warehouse','full',$rental->id);$unit->update(['asset_status'=>'reserved']);}
                }else $outgoing=$incoming;
                $product->syncLegacyStockFields();
            }elseif($data['mode']==='exchange'){
                if((int)$product->available_quantity<$qty)$this->fail('There are not enough full cylinders of this size in stock. Choose same-cylinder refill or arrange full stock first.');
                $product->decrement('available_quantity',$qty);
            }
            $refill=CylinderRefill::create(['organization_id'=>$user->organization_id,'rental_id'=>$rental->id,'product_id'=>$product->id,'capacity_label'=>$product->name,
                'assigned_user_id'=>$assignee->id,'created_by_user_id'=>$user->id,'request_id'=>$data['request_id'],'request_fingerprint'=>$fingerprint,
                'mode'=>$data['mode'],'stock_mode'=>$tracked?'asset':'quantity','quantity'=>$qty,'incoming_asset_ids'=>$incoming,'outgoing_asset_ids'=>$outgoing,
                'serial_reference'=>$data['serial_reference']??null,'scheduled_at'=>$data['scheduled_at'],'refill_charge'=>$data['refill_charge'],
                'transport_charge'=>$data['transport_charge'],'tax_rate'=>$data['tax_rate'],'tax_type'=>'cgst_sgst','notes'=>$data['notes']??null]);
            $this->event($user,$refill,'booked',$qty,(string)Str::uuid(),['serial_reference'=>$data['serial_reference']??null]);
            $this->notify($refill,$assignee);
            return $refill;
        });
    }
    private function unit(Asset $asset,string $custody,string $contents,?int $rentalId): Cylinder
    {
        $c=Cylinder::firstOrCreate(['asset_id'=>$asset->id],['organization_id'=>$asset->organization_id,'custody'=>$custody,'contents'=>$contents,'reserved_for_rental_id'=>$rentalId]);
        if((int)$c->organization_id!==(int)$asset->organization_id)$this->fail('Cylinder ownership needs review.');
        if($custody==='customer'&&($c->custody!=='customer'||((int)$c->reserved_for_rental_id!==$rentalId&&$c->reserved_for_rental_id)))$this->fail('This cylinder is recorded elsewhere. Review the original delivery.');
        $c->update(['reserved_for_rental_id'=>$rentalId]);return $c;
    }
    private function notify(CylinderRefill $refill,User $user): void
    {
        app(NotificationCenterService::class)->notifyUser($user,['title'=>'Cylinder refill assigned','message'=>'Deliver '.$refill->quantity.' cylinder(s) and collect the empty cylinder(s).',
            'url'=>route('cylinder-refills.tasks'),'event_type'=>'cylinder_refill.assigned','priority'=>'medium']);
    }
    private function event(User $user,CylinderRefill $refill,string $action,int $qty,string $request,array $details=[],?string $fingerprint=null): void
    {
        CylinderRefillEvent::create(['organization_id'=>$user->organization_id,'cylinder_refill_id'=>$refill->id,'user_id'=>$user->id,
            'request_id'=>$request,'request_fingerprint'=>$fingerprint??$this->fingerprint($details),'action'=>$action,'quantity'=>$qty,'details'=>$details]);
    }
    public function change(User $user,CylinderRefill $refill,array $data): CylinderRefill
    {
        $this->authorize($user,$refill,'update');
        $action=$data['action'];
        if(in_array($action,['receive_empty','receive_refilled'],true))$this->workflow()->authorizeStock($user);
        if(in_array($action,['cancel','reassign'],true)){$this->workflow()->authorize($user,'create');Gate::forUser($user)->authorize('update',$refill->rental);}
        return $this->workflow()->atomic($user,function()use($user,$refill,$data,$action){
            $refill=CylinderRefill::lockForUpdate()->findOrFail($refill->id);$this->authorize($user,$refill,'update');
            $fingerprint=$this->fingerprint($data+['refill_id'=>$refill->id]);
            if($old=CylinderRefillEvent::where('organization_id',$user->organization_id)->where('request_id',$data['request_id'])->first()){
                abort_unless((int)$old->cylinder_refill_id===(int)$refill->id&&hash_equals($old->request_fingerprint,$fingerprint),409,'This action was already used with different details.');return $refill;
            }
            abort_unless((int)$refill->lock_version===(int)$data['lock_version'],409,'This refill changed. Refresh it before continuing.');
            if($refill->status==='cancelled'||($refill->status==='completed'&&$action!=='receive_refilled'))$this->fail('This refill is no longer open for that action.');
            $product=Product::where('organization_id',$user->organization_id)->lockForUpdate()->findOrFail($refill->product_id);
            if(($refill->stock_mode==='asset')!==$product->tracksRentalStock())$this->fail('Stock tracking changed after booking. Review this refill before updating its stock.');
            $rental=Rental::lockForUpdate()->findOrFail($refill->rental_id);
            $qty=(int)($data['quantity']??0);$assets=[];$movement='manual_adjustment';$warehouse=null;
            if(in_array($action,['receive_empty','receive_refilled'],true))$warehouse=Warehouse::where('organization_id',$user->organization_id)->where('is_active',true)->findOrFail($data['warehouse_id']??0);
            if(in_array($action,['deliver','collect_empty','receive_empty','receive_refilled'],true)&&$qty<1)$this->fail('Enter the number of cylinders for this step.');
            if($action==='deliver'){
                if($rental->status!=='active'||$qty>$refill->quantity-$refill->delivered_quantity||($refill->mode==='same'&&$qty>$refill->refilled_quantity-$refill->delivered_quantity))$this->fail('This delivery quantity is not ready or exceeds the refill request.');
                $ids=array_slice($refill->outgoing_asset_ids??[],$refill->delivered_quantity,$qty);
                $oldIds=array_slice($refill->incoming_asset_ids??[],$refill->delivered_quantity,$qty);
                foreach($ids as $index=>$id){
                    $asset=$this->asset($user,$id);$c=Cylinder::where('asset_id',$id)->lockForUpdate()->firstOrFail();
                    if($c->custody!=='warehouse'||$c->contents!=='full'||(int)$c->reserved_for_rental_id!==(int)$rental->id||!in_array($asset->condition_status,['new','good'],true))$this->fail('The allocated cylinder is no longer available for this delivery. Refresh the refill.');
                    $c->update(['custody'=>'customer']);$asset->update(['asset_status'=>'rented']);
                    if($refill->mode==='same'&&!$rental->activeRentalAssets()->where('asset_id',$id)->exists())$this->fail('The original cylinder assignment changed. Review the rental before returning it.');
                    if($refill->mode==='exchange'){
                        if($asset->activeRentalAssignments()->exists())$this->fail('This cylinder has another rental assignment.');
                        $lines=$rental->rentalItems()->where('product_id',$product->id)->lockForUpdate()->get();
                        $matches=$lines->filter(fn($line)=>in_array((int)$oldIds[$index],array_map('intval',$line->asset_ids??[]),true));
                        if($lines->isNotEmpty()&&$matches->count()!==1)$this->fail('The rental cylinder allocation changed. Refresh the refill before delivery.');
                        RentalAsset::create(['organization_id'=>$user->organization_id,'rental_id'=>$rental->id,'asset_id'=>$id,'assigned_at'=>now(),'notes'=>'Refill #'.$refill->id]);
                        foreach($matches as $line)$line->update(['asset_ids'=>array_map(fn($old)=>(int)$old===(int)$oldIds[$index]?(int)$id:(int)$old,$line->asset_ids)]);
                    }
                    $assets[]=$asset;
                }
                $refill->delivered_quantity+=$qty;$movement='rental_out';
            }elseif($action==='collect_empty'){
                if($qty>$refill->quantity-$refill->collected_quantity)$this->fail('The empty pickup exceeds the outstanding quantity.');
                foreach(array_slice($refill->incoming_asset_ids??[],$refill->collected_quantity,$qty)as$id){
                    $asset=$this->asset($user,$id);$c=Cylinder::where('asset_id',$id)->lockForUpdate()->firstOrFail();
                    if($c->custody!=='customer'||!$rental->activeRentalAssets()->where('asset_id',$id)->exists())$this->fail('This customer cylinder assignment changed. Refresh before recording pickup.');
                    if($refill->mode==='exchange')$rental->activeRentalAssets()->where('asset_id',$id)->update(['returned_at'=>now(),'return_condition'=>$asset->condition_status]);
                    $c->update(['contents'=>'empty','custody'=>'in_transit','reserved_for_rental_id'=>$refill->mode==='same'?$rental->id:null]);$asset->update(['asset_status'=>'maintenance']);$assets[]=$asset;
                }
                $refill->collected_quantity+=$qty;$movement='pickup_return';
            }elseif($action==='receive_empty'){
                if($qty>$refill->collected_quantity-$refill->received_quantity)$this->fail('Only empties already picked up can be received back.');
                foreach(array_slice($refill->incoming_asset_ids??[],$refill->received_quantity,$qty)as$id){
                    $asset=$this->asset($user,$id);$c=Cylinder::where('asset_id',$id)->lockForUpdate()->firstOrFail();
                    if($c->custody!=='in_transit'||$c->contents!=='empty')$this->fail('The empty cylinder is not recorded in transit.');
                    $c->update(['custody'=>'warehouse']);$asset->update(['warehouse_id'=>$warehouse->id,'asset_status'=>'maintenance']);$assets[]=$asset;
                }
                $refill->received_quantity+=$qty;$movement='return_verification';
            }elseif($action==='receive_refilled'){
                if($qty>$refill->received_quantity-$refill->refilled_quantity)$this->fail('Receive the empty cylinder before recording the refilled stock.');
                foreach(array_slice($refill->incoming_asset_ids??[],$refill->refilled_quantity,$qty)as$id){
                    $asset=$this->asset($user,$id);$c=Cylinder::where('asset_id',$id)->lockForUpdate()->firstOrFail();
                    if($c->custody!=='warehouse'||$c->contents!=='empty'||!in_array($asset->condition_status,['new','good'],true))$this->fail('This empty cylinder is not ready to be recorded as refilled.');
                    $c->update(['contents'=>'full','reserved_for_rental_id'=>$refill->mode==='same'?$rental->id:null]);$asset->update(['warehouse_id'=>$warehouse->id,'asset_status'=>$refill->mode==='same'?'reserved':'available']);$assets[]=$asset;
                }
                if($refill->stock_mode==='quantity'&&$refill->mode==='exchange'){
                    if((int)$product->available_quantity+$qty>(int)$product->total_quantity)$this->fail('Full stock would exceed the recorded cylinder total. Reconcile the stock count first.');
                    $product->increment('available_quantity',$qty);
                }
                $refill->refilled_quantity+=$qty;
            }elseif($action==='cancel'){
                if($refill->delivered_quantity||$refill->collected_quantity)$this->fail('A started refill cannot be cancelled. Record its delivery and empty return.');
                if($refill->mode==='exchange'){
                    foreach($refill->outgoing_asset_ids??[]as$id){$asset=$this->asset($user,$id);$c=Cylinder::where('asset_id',$id)->lockForUpdate()->firstOrFail();
                        if($asset->asset_status!=='reserved'||$c->custody!=='warehouse'||(int)$c->reserved_for_rental_id!==(int)$rental->id)$this->fail('The reserved stock changed. Review the refill before cancelling.');
                        $c->update(['reserved_for_rental_id'=>null]);$ready=$c->contents==='full'&&$c->custody==='warehouse'&&in_array($asset->condition_status,['new','good'],true);$asset->update(['asset_status'=>$ready?'available':'maintenance']);}
                    if($refill->stock_mode==='quantity'){
                        if((int)$product->available_quantity+$refill->quantity>(int)$product->total_quantity)$this->fail('Stock changed after reservation. Review the refill before cancelling.');
                        $product->increment('available_quantity',$refill->quantity);
                    }
                }
                $refill->status='cancelled';$qty=0;
            }elseif($action==='reassign'){
                $assignee=User::where('organization_id',$user->organization_id)->findOrFail($data['assigned_user_id']??0);
                if(!$assignee->canAccessModule('deliveries','update'))$this->fail('Choose active delivery staff.');
                if($user->scopeType('deliveries')!=='all'&&(int)$assignee->id!==(int)$user->id)abort(403);
                $refill->assigned_user_id=$assignee->id;$this->notify($refill,$assignee);$qty=0;
            }else $this->fail('Choose a valid refill action.');
            if($refill->status!=='cancelled'&&$refill->delivered_quantity===$refill->quantity&&$refill->received_quantity===$refill->quantity){$refill->status='completed';$refill->completed_at??=now();}
            $refill->lock_version++;$refill->save();
            if($refill->stock_mode==='asset')$product->syncLegacyStockFields();
            $details=['notes'=>$data['notes']??null,'serial_reference'=>$data['serial_reference']??null,'warehouse_id'=>$warehouse?->id,'assigned_user_id'=>$refill->assigned_user_id,
                'delivered'=>$refill->delivered_quantity,'picked_up'=>$refill->collected_quantity,'received_back'=>$refill->received_quantity,'refilled'=>$refill->refilled_quantity];
            $this->event($user,$refill,$action,$qty,$data['request_id'],$details,$fingerprint);
            $recorder=app(StockMovementRecorder::class);
            foreach($assets as$asset){$recorder->recordForAsset($asset,$movement,1,['rental_id'=>$rental->id,'performed_by_user_id'=>$user->id,'notes'=>'Cylinder refill #'.$refill->id.': '.$action]);
                CylinderEvent::create(['organization_id'=>$user->organization_id,'cylinder_id'=>$asset->cylinder->id,'user_id'=>$user->id,'request_id'=>(string)Str::uuid(),'request_fingerprint'=>$fingerprint,'action'=>'refill_'.$action,'details'=>$details+['refill_id'=>$refill->id,'serial_entry_optional'=>true]]);}
            if($refill->stock_mode==='quantity'&&$qty)$recorder->recordForProduct($product,$movement,$qty,['rental_id'=>$rental->id,'performed_by_user_id'=>$user->id,'notes'=>'Cylinder refill #'.$refill->id.': '.$action]);
            return $refill;
        });
    }
    private function asset(User $user,int $id): Asset {return Asset::where('organization_id',$user->organization_id)->lockForUpdate()->findOrFail($id);}
}
