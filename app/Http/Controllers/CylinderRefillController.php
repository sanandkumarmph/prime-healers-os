<?php
namespace App\Http\Controllers;
use App\Models\{CylinderRefill,Rental,User,Warehouse};
use App\Services\Cylinders\SimpleCylinderRefills;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class CylinderRefillController extends Controller
{
    public function __construct(private SimpleCylinderRefills $service){}
    public function renderForRental(Request $request,Rental $rental,?string $message=null)
    {
        Gate::authorize('view',$rental);abort_unless((int)$rental->organization_id===(int)$request->user()->organization_id,404);
        abort_unless(CylinderRefill::available(),503,'The refill update is awaiting database setup.');
        $refills=$this->service->query($request->user())->where('rental_id',$rental->id)->with(['product','assignee','events.user','invoice'])->latest('id')->limit(40)->get();
        $capacities=$this->service->capacities($rental);
        $staff=User::where('organization_id',$request->user()->organization_id)->where('is_active',true)->get()
            ->filter(fn($user)=>$user->canAccessModule('deliveries','update')&&($request->user()->scopeType('deliveries')==='all'||$user->id===$request->user()->id));
        $warehouses=Warehouse::where('organization_id',$request->user()->organization_id)->where('is_active',true)->get();
        $data=compact('rental','refills','capacities','staff','warehouses','message');
        $pendingEmpty=(int)$this->service->query($request->user())->where('rental_id',$rental->id)->where('status','open')->sum(\Illuminate\Support\Facades\DB::raw('quantity - received_quantity'));
        if($request->expectsJson())return response()->json(['html'=>view('cylinder-refills.content',$data)->render(),'pending_empty'=>$pendingEmpty]);
        return view('cylinder-refills.page',$data);
    }
    public function store(Request $request,Rental $rental)
    {
        $data=$request->validate(['request_id'=>'required|uuid','product_id'=>'required|integer','quantity'=>'required|integer|min:1|max:100',
            'mode'=>'required|in:exchange,same','assigned_user_id'=>'required|integer','scheduled_at'=>'required|date_format:Y-m-d\TH:i',
            'refill_charge'=>'required|numeric|min:0|max:999999.99','transport_charge'=>'required|numeric|min:0|max:999999.99',
            'tax_rate'=>'required|numeric|in:0,5,12,18,28','serial_reference'=>'nullable|string|max:500','notes'=>'nullable|string|max:2000'],[],
            ['product_id'=>'cylinder size','assigned_user_id'=>'delivery staff','scheduled_at'=>'scheduled date and time']);
        $refill=$this->service->book($request->user(),$rental,$data);
        return $this->renderForRental($request,$rental,'Refill #'.$refill->id.' assigned. Ensure the empty cylinder is picked up and record it when received back.');
    }
    public function change(Request $request,CylinderRefill $refill)
    {
        $data=$request->validate(['request_id'=>'required|uuid','lock_version'=>'required|integer|min:0','action'=>'required|in:deliver,collect_empty,receive_empty,receive_refilled,cancel,reassign',
            'quantity'=>'nullable|integer|min:1|max:100','warehouse_id'=>'nullable|integer','assigned_user_id'=>'nullable|integer',
            'serial_reference'=>'nullable|string|max:500','notes'=>'nullable|string|max:2000']);
        $refill=$this->service->change($request->user(),$refill,$data);
        if($request->boolean('task_view'))return $this->taskResponse($request,'Refill progress saved. Remember to collect any outstanding empty cylinders.');
        return $this->renderForRental($request,$refill->rental,'Refill progress saved.');
    }
    public function tasks(Request $request){return $this->taskResponse($request);}
    private function taskResponse(Request $request,?string $message=null)
    {
        abort_unless(CylinderRefill::available(),503);
        $refillTasks=$this->service->query($request->user())->where('status','open')->with(['product','rental.customer','assignee'])
            ->where(fn($q)=>$q->whereColumn('delivered_quantity','<','quantity')->orWhereColumn('collected_quantity','<','quantity'))->orderBy('scheduled_at')->paginate(20);
        $data=compact('refillTasks','message');
        if($request->expectsJson())return response()->json(['html'=>view('cylinder-refills.tasks-content',$data)->render()]);
        return view('cylinder-refills.tasks',$data);
    }
}
