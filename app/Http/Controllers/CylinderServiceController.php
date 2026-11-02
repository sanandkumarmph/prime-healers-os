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
        abort_unless($this->workflow->rentalHasCylinder($rental), 422, 'This rental has no oxygen-cylinder product.');
        $productIds = array_values(array_intersect($this->workflow->cylinderProductIds($user->organization_id), $this->workflow->rentalProductIds($rental)));
        $incoming = Cylinder::with('asset.product')->where('organization_id', $user->organization_id)->where('custody', 'customer')
            ->whereHas('asset', fn ($q) => $q->whereIn('product_id', $productIds))
            ->whereHas('asset.activeRentalAssignments', fn ($q) => $q->where('rental_id', $rental->id))->get();
        $outgoing = Cylinder::with('asset.product')->where('organization_id', $user->organization_id)
            ->where('contents', 'full')->where('custody', 'warehouse')->whereHas('asset', fn ($q) => $q->rentalReady()->whereIn('product_id', $productIds))->get();
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
            'tax_rate' => 'required|numeric|in:0,5,12,18,28', 'tax_type' => 'required|in:cgst_sgst,igst', 'notes' => 'nullable|string|max:2000'],
            ['outgoing_cylinder_id.required_if'=>'Choose a full, inspected replacement cylinder for an exchange. To refill the original cylinder, select Refill and return the same cylinder.'],
            ['outgoing_cylinder_id'=>'replacement cylinder','incoming_cylinder_id'=>'customer cylinder']);
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
        $rental = $this->registrationRental($request);
        $productIds = $this->workflow->cylinderProductIds($org);
        if ($rental) $productIds = array_values(array_intersect($productIds, $this->workflow->rentalProductIds($rental)));
        $cylinders = Cylinder::with(['asset.product', 'events' => fn ($q) => $q->latest('id')])->where('organization_id', $org)->latest('id')->paginate(20);
        $assets = Asset::with('product')->where('organization_id', $org)->where('asset_stage', Asset::STAGE_RENTAL_STOCK)
            ->where('asset_status', '!=', Asset::STATUS_RETIRED)->whereHas('product', fn ($q) => $q->whereIn('stock_mode', ['tracked_rental','tracked_both']))
            ->whereIn('product_id', $productIds)->whereDoesntHave('cylinder')
            ->when($rental, fn ($q) => $q->whereDoesntHave('activeRentalAssignments', fn ($assigned) => $assigned->where('rental_id','!=',$rental->id)))
            ->orderBy('serial_number')->get()
            ->filter(fn ($asset) => filled($asset->serial_number) && !str_starts_with($asset->serial_number, Asset::SERIAL_PENDING_PREFIX))->values();
        $registered = $request->filled('registered') ? Cylinder::with(['asset.product','reservedRental'])->where('organization_id', $org)->findOrFail($request->integer('registered')) : null;
        $warehouses = Warehouse::where('organization_id', $org)->where('is_active', true)->get();
        return view('cylinder-services.stock', compact('cylinders', 'assets', 'warehouses', 'rental', 'registered'));
    }

    private function registrationRental(Request $request): ?Rental
    {
        if (!$request->filled('rental_id')) return null;
        $request->validate(['rental_id'=>'required|integer|min:1']);
        $rental = Rental::where('organization_id', $request->user()->organization_id)->findOrFail($request->integer('rental_id'));
        Gate::authorize('view', $rental);
        abort_unless($this->workflow->rentalHasCylinder($rental), 422, 'This rental has no oxygen-cylinder product.');
        return $rental;
    }

    public function register(Request $request)
    {
        $this->workflow->authorizeStock($request->user());
        $data = $request->validate(['asset_id' => 'required|integer', 'contents' => 'required|in:full,empty,unknown']);
        $rental = $this->registrationRental($request);
        if ($rental) {
            $asset = Asset::where('organization_id', $request->user()->organization_id)->findOrFail($data['asset_id']);
            if (!in_array((int) $asset->product_id, $this->workflow->rentalProductIds($rental), true)) {
                throw \Illuminate\Validation\ValidationException::withMessages(['asset_id'=>'Choose an oxygen cylinder belonging to a product on this rental.']);
            }
            if ($asset->activeRentalAssignments()->where('rental_id','!=',$rental->id)->exists()) {
                throw \Illuminate\Validation\ValidationException::withMessages(['asset_id'=>'This cylinder is assigned to another rental. Choose this customer\'s cylinder or unassigned stock.']);
            }
        }
        $cylinder = $this->workflow->register($request->user(), $data['asset_id'], $data['contents']);
        $message = 'Cylinder '.$cylinder->asset->serial_number.' is registered. '.ucfirst($cylinder->contents).' · '.str_replace('_',' ', $cylinder->custody).'.';
        if ($rental && $cylinder->custody === 'customer' && (int) $cylinder->reserved_for_rental_id === (int) $rental->id
            && $request->user()->canAccessModule('deliveries','create') && $request->user()->canAccessModule('rentals','update')) {
            return redirect()->route('cylinder-services.create', $rental)->with('success', $message.' You can now book its refill or exchange.');
        }
        return redirect()->to(route('cylinder-services.stock', array_filter(['registered'=>$cylinder->id,'rental_id'=>$rental?->id])).'#cylinder-'.$cylinder->id)
            ->with('success', $message.' See its status and next step below.');
    }

    public function stockAction(Request $request, Cylinder $cylinder)
    {
        $data = $request->validate(['request_id' => 'required|uuid', 'action' => 'required|in:ready_confirmed,inspection_recorded,return_received,sent_for_refill,received_full',
            'scanned_code' => 'required|string|max:255', 'warehouse_id' => 'required|integer', 'contents' => 'nullable|in:full,empty',
            'inspection_confirmed' => 'nullable|boolean', 'notes' => 'nullable|string|max:2000']);
        $this->workflow->stockAction($request->user(), $cylinder, $data);
        return back()->with('success', 'Cylinder stock step recorded.');
    }
}
