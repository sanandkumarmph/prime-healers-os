<?php

namespace App\Http\Controllers;

use App\Models\{Asset, Cylinder, CylinderEvent, CylinderJob, Rental, User, Warehouse};
use App\Services\Cylinders\CylinderWorkflow;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Gate, Storage};

class CylinderServiceController extends Controller
{
    public function __construct(private CylinderWorkflow $workflow) {}

    public function index(Request $request)
    {
        $user = $request->user(); $this->workflow->authorize($user, 'read');
        abort_unless(Cylinder::hasTable(), 503, 'Cylinder services are awaiting database setup.');
        $module = $user->canAccessModule('deliveries','read') ? 'deliveries' : 'invoices';
        if ($request->filled('rental_id')) Gate::authorize('view', Rental::where('organization_id',$user->organization_id)->findOrFail($request->integer('rental_id')));
        $jobs = CylinderJob::with(['rental', 'incoming.asset', 'outgoing.asset', 'assignee'])
            ->where('organization_id', $user->organization_id)
            ->when($request->filled('rental_id'), fn ($q) => $q->where('rental_id',$request->integer('rental_id')))
            ->when($user->scopeType($module) !== 'all', fn ($q) => $q->where(fn ($a) => $a->where('assigned_user_id', $user->id)->orWhere('created_by_user_id', $user->id)))
            ->orderByRaw("case when status = 'open' then 0 else 1 end")->latest('id')->paginate(20);
        return view('cylinder-services.index', compact('jobs'));
    }

    public function create(Request $request, Rental $rental)
    {
        $user = $request->user(); $this->workflow->authorize($user, 'create'); Gate::authorize('update', $rental);
        abort_unless((int) $rental->organization_id === (int) $user->organization_id, 404);
        $incoming = Cylinder::with('asset.product')->where('organization_id', $user->organization_id)->where('custody', 'customer')
            ->whereHas('asset.activeRentalAssignments', fn ($q) => $q->where('rental_id', $rental->id))->get();
        $outgoing = Cylinder::with('asset.product')->where('organization_id', $user->organization_id)
            ->where('contents', 'full')->where('custody', 'warehouse')->whereHas('asset', fn ($q) => $q->rentalReady())->get();
        $staff = User::where('organization_id', $user->organization_id)->where('is_active', true)
            ->when($user->scopeType('deliveries') !== 'all', fn ($q) => $q->whereKey($user->id))->get()
            ->filter(fn ($u) => $u->canAccessModule('deliveries', 'update'));
        return view('cylinder-services.create', compact('rental', 'incoming', 'outgoing', 'staff'));
    }

    public function store(Request $request, Rental $rental)
    {
        $data = $request->validate(['request_id' => 'required|uuid', 'mode' => 'required|in:exchange,same',
            'incoming_cylinder_id' => 'required|integer', 'outgoing_cylinder_id' => 'nullable|required_if:mode,exchange|integer',
            'assigned_user_id' => 'required|integer', 'scheduled_at' => 'required|date_format:Y-m-d\TH:i',
            'refill_charge' => 'required|numeric|min:0|max:999999.99', 'transport_charge' => 'required|numeric|min:0|max:999999.99',
            'tax_rate' => 'required|numeric|in:0,5,12,18,28', 'tax_type' => 'required|in:cgst_sgst,igst', 'notes' => 'nullable|string|max:2000']);
        $job = $this->workflow->create($request->user(), $rental, $data);
        return redirect()->route('cylinder-services.show', $job)->with('success', 'Cylinder service booked. Rental quantity and deposit are unchanged.');
    }

    public function show(Request $request, CylinderJob $job)
    {
        $this->workflow->authorize($request->user(), 'read', $job);
        $job->load(['rental', 'incoming.asset.product', 'outgoing.asset', 'assignee', 'events.user', 'invoice']);
        $warehouses = Warehouse::where('organization_id', $request->user()->organization_id)->where('is_active', true)->get();
        return view('cylinder-services.show', compact('job', 'warehouses'));
    }

    public function action(Request $request, CylinderJob $job)
    {
        $data = $request->validate(['request_id' => 'required|uuid', 'lock_version' => 'required|integer|min:0',
            'action' => 'required|in:empty_collected,warehouse_received,refill_sent,refill_received,full_delivered,cancelled',
            'scanned_code' => 'nullable|required_unless:action,cancelled|string|max:255', 'warehouse_id' => 'nullable|integer',
            'signer_name' => 'nullable|string|max:150', 'signature_data' => 'nullable|string|max:750000',
            'signature_reason' => 'nullable|string|max:500', 'confirm_empty' => 'nullable|boolean',
            'inspection_confirmed' => 'nullable|boolean', 'notes' => 'nullable|string|max:2000']);
        $this->workflow->act($request->user(), $job, $data);
        return back()->with('success', 'Cylinder step recorded.');
    }

    public function signature(Request $request, CylinderJob $job, CylinderEvent $event)
    {
        $this->workflow->authorize($request->user(), 'read', $job);
        abort_unless((int) $event->cylinder_job_id === (int) $job->id && (int) $event->organization_id === (int) $job->organization_id, 404);
        $path = $event->details['signature_path'] ?? '';
        abort_unless(preg_match('#^cylinder-signatures/'.(int) $job->organization_id.'/[a-f0-9-]+\.png$#D', $path) && Storage::disk('local')->exists($path), 404);
        return response()->file(Storage::disk('local')->path($path), ['Content-Type' => 'image/png', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    public function stock(Request $request)
    {
        $this->workflow->authorizeStock($request->user());
        $org = $request->user()->organization_id;
        $cylinders = Cylinder::with(['asset.product', 'events' => fn ($q) => $q->latest('id')])->where('organization_id', $org)->latest('id')->paginate(20);
        $assets = Asset::with('product')->where('organization_id', $org)->where('asset_stage', Asset::STAGE_RENTAL_STOCK)
            ->where('asset_status', '!=', Asset::STATUS_RETIRED)->whereHas('product', fn ($q) => $q->whereIn('stock_mode', ['tracked_rental','tracked_both']))
            ->whereDoesntHave('cylinder')->orderBy('serial_number')->get();
        $warehouses = Warehouse::where('organization_id', $org)->where('is_active', true)->get();
        return view('cylinder-services.stock', compact('cylinders', 'assets', 'warehouses'));
    }

    public function register(Request $request)
    {
        $data = $request->validate(['asset_id' => 'required|integer', 'contents' => 'required|in:full,empty,unknown']);
        $this->workflow->register($request->user(), $data['asset_id'], $data['contents']);
        return back()->with('success', 'Existing PHOS asset registered as a cylinder. No new asset was created.');
    }

    public function stockAction(Request $request, Cylinder $cylinder)
    {
        $data = $request->validate(['request_id' => 'required|uuid', 'action' => 'required|in:inspection_recorded,return_received,sent_for_refill,received_full',
            'scanned_code' => 'required|string|max:255', 'warehouse_id' => 'required|integer', 'contents' => 'nullable|in:full,empty',
            'inspection_confirmed' => 'nullable|boolean', 'notes' => 'nullable|string|max:2000']);
        $this->workflow->stockAction($request->user(), $cylinder, $data);
        return back()->with('success', 'Cylinder stock step recorded.');
    }
}
