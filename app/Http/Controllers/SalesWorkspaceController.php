<?php

namespace App\Http\Controllers;

use App\Models\{Customer, Enquiry, EnquiryFollowUp, Product, Quotation};
use App\Services\Sales\SalesWorkspace;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\{Rule, ValidationException};

class SalesWorkspaceController extends Controller
{
    public function __construct(private SalesWorkspace $workspace) {}

    public function index(Request $request)
    {
        $data = $request->validate(['view' => 'nullable|in:enquiries,followups,quotations', 'queue' => 'nullable|in:all,today,overdue',
            'stage' => ['nullable', Rule::in(array_keys(Enquiry::STAGES))], 'q' => 'nullable|string|max:100', 'mine' => 'nullable|boolean']);
        $mode = $data['view'] ?? 'enquiries';
        $base = $this->workspace->query($request->user());
        $tz = $this->workspace->timezone(); $today = now($tz)->startOfDay()->utc(); $tomorrow = $today->copy()->timezone($tz)->addDay()->utc();
        $openIds = (clone $base)->whereNotIn('stage', ['won', 'lost'])->select('id');
        $due = EnquiryFollowUp::whereIn('enquiry_id', $openIds)->whereNull('completed_at');
        $counts = ['open' => (clone $openIds)->count(), 'today' => (clone $due)->where('due_at', '>=', $today)->where('due_at', '<', $tomorrow)->count(),
            'overdue' => (clone $due)->where('due_at', '<', now())->count()];
        $query = (clone $base)->when($request->boolean('mine'), fn ($q) => $q->where('owner_id', $request->user()->id))
            ->when($data['stage'] ?? null, fn ($q, $stage) => $q->where('stage', $stage))
            ->when($data['q'] ?? null, fn ($q, $term) => $q->where(fn ($q) => $q->where('subject', 'like', '%'.$term.'%')->orWhere('name', 'like', '%'.$term.'%')->orWhere('phone', 'like', '%'.$term.'%')));
        if ($mode === 'followups') {
            $records = EnquiryFollowUp::whereIn('enquiry_id', (clone $query)->whereNotIn('stage', ['won', 'lost'])->select('id'))->whereNull('completed_at')
                ->when(($data['queue'] ?? '') === 'today', fn ($q) => $q->where('due_at', '>=', $today)->where('due_at', '<', $tomorrow))
                ->when(($data['queue'] ?? '') === 'overdue', fn ($q) => $q->where('due_at', '<', now()))
                ->with('enquiry.owner')->orderBy('due_at')->orderBy('id')->paginate(20)->withQueryString();
        } elseif ($mode === 'quotations') {
            abort_unless($request->user()->canAccessModule('quotations', 'read'), 403);
            if ($request->user()->scopeType('quotations') !== 'all') $query->where('owner_id', $request->user()->id);
            $records = Quotation::whereIn('enquiry_id', $query->select('id'))->with('enquiry.owner')->latest('id')->paginate(20)->withQueryString();
        } else {
            $records = $query->with('owner')->withMin(['followUps as next_follow_up' => fn ($q) => $q->whereNull('completed_at')], 'due_at')->latest('updated_at')->paginate(20)->withQueryString();
        }
        return view('sales-workspace.index', compact('records', 'mode', 'counts', 'tz'));
    }

    private function form(?Enquiry $enquiry = null)
    {
        $owners = $this->workspace->owners();
        $customers = $this->workspace->customers();
        return view('sales-workspace.enquiry-form', compact('enquiry', 'owners', 'customers'));
    }

    public function create() { abort_unless(auth()->user()->canAccessModule('enquiries', 'create'), 403); return $this->form(); }
    public function edit(int $enquiry) { return $this->form($this->workspace->enquiry($enquiry, 'update')); }

    private function input(Request $request): array
    {
        $org = $request->user()->organization_id;
        $data = $request->validate(['name' => 'required|string|max:255', 'subject' => 'required|string|max:255',
            'phone' => 'nullable|string|max:40', 'email' => 'nullable|email|max:255', 'requirements' => 'nullable|string|max:5000',
            'intent' => 'nullable|in:rental,sale',
            'source' => 'nullable|string|max:80', 'owner_id' => ['required', 'integer', Rule::in($this->workspace->owners()->pluck('id')->all())],
            'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->where('organization_id', $org)]]);
        if ($request->user()->scopeType('enquiries') !== 'all' && (int) $data['owner_id'] !== $request->user()->id) abort(403);
        return $data;
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->canAccessModule('enquiries', 'create'), 403);
        $data = $this->input($request);
        $request->validate(['next' => 'nullable|in:enquiry,quotation']);
        if ($request->input('next') === 'quotation') {
            abort_unless($request->user()->canAccessModule('quotations', 'create') && $request->user()->canAccessModule('enquiries', 'update'), 403);
            abort_if($request->user()->scopeType('quotations') !== 'all' && (int) $data['owner_id'] !== (int) $request->user()->id, 403);
        }
        $enquiry = $this->workspace->atomic($request->user()->organization_id, function () use ($request, $data) {
            $enquiry = Enquiry::create($data + ['organization_id' => $request->user()->organization_id, 'created_by_user_id' => $request->user()->id]);
            $this->workspace->event($enquiry, 'enquiry.created', 'Enquiry captured and assigned to '.$enquiry->owner->name.'.');
            return $enquiry;
        });
        if ($request->input('next') === 'quotation') return redirect()->route('quotations.create', $enquiry);
        return redirect()->route('enquiries.show', $enquiry)->with('success', 'Enquiry created. Set the next follow-up or prepare a quotation.');
    }

    public function update(Request $request, int $enquiry)
    {
        $enquiry = $this->workspace->enquiry($enquiry, 'update'); $data = $this->input($request);
        $this->workspace->atomic($enquiry->organization_id, function () use ($enquiry, $request, $data) {
            $e = $this->workspace->locked($enquiry); $this->workspace->version($e, $request);
            abort_if($e->closed(), 409, 'Reopen this enquiry before editing.');
            $oldOwner = $e->owner?->name ?? 'Unassigned';
            $e->update($data + ['lock_version' => $e->lock_version + 1]);
            $e->unsetRelation('owner');
            $this->workspace->event($e, 'enquiry.updated', 'Enquiry details updated. Owner: '.$oldOwner.' → '.($e->owner?->name ?? 'Unassigned').'. Existing quotations retain their original recipient.');
        });
        return redirect()->route('enquiries.show', $enquiry)->with('success', 'Enquiry updated.');
    }

    public function show(int $enquiry)
    {
        $enquiry = $this->workspace->enquiry($enquiry)->load('owner', 'customer');
        $followUps = $enquiry->followUps()->whereNull('completed_at')->orderBy('due_at')->get();
        $quotes = auth()->user()->canAccessModule('quotations', 'read') && (auth()->user()->scopeType('quotations') === 'all' || (int) $enquiry->owner_id === (int) auth()->id()) ? $enquiry->quotations()->latest('revision')->get() : collect();
        $activities = $enquiry->activities()->with('user')->latest('id')->paginate(30);
        $tz = $this->workspace->timezone();
        return view('sales-workspace.show', compact('enquiry', 'followUps', 'quotes', 'activities', 'tz'));
    }

    public function stage(Request $request, int $enquiry)
    {
        $e = $this->workspace->enquiry($enquiry, 'update');
        $data = $request->validate(['stage' => 'required|in:new,qualified,lost', 'reason' => 'required_if:stage,lost|nullable|string|max:2000']);
        $this->workspace->atomic($e->organization_id, function () use ($e, $request, $data) {
            $e = $this->workspace->locked($e); $this->workspace->version($e, $request);
            abort_if($e->stage === 'won', 409, 'An order has already been created.');
            if ($e->quotations()->whereIn('status', ['sent', 'accepted'])->exists()) {
                throw ValidationException::withMessages(['stage' => 'Withdraw the current quotation before changing this enquiry stage.']);
            }
            $e->update(['stage' => $data['stage'], 'lost_reason' => $data['stage'] === 'lost' ? $data['reason'] : null, 'lock_version' => $e->lock_version + 1]);
            if ($data['stage'] === 'lost') $e->followUps()->whereNull('completed_at')->update(['completed_at' => now(), 'outcome' => 'cancelled', 'completion_note' => 'Enquiry closed as lost.']);
            $this->workspace->event($e, 'enquiry.stage', 'Stage changed to '.Enquiry::STAGES[$e->stage].'. '.($data['reason'] ?? ''));
        });
        return back()->with('success', 'Stage updated.');
    }

    public function note(Request $request, int $enquiry)
    {
        $e = $this->workspace->enquiry($enquiry, 'update'); $data = $request->validate(['note' => 'required|string|max:5000']);
        $this->workspace->atomic($e->organization_id, fn () => $this->workspace->event($this->workspace->locked($e), 'note', $data['note']));
        return back()->with('success', 'Note added.');
    }

    public function followUp(Request $request, int $enquiry)
    {
        $e = $this->workspace->enquiry($enquiry, 'update');
        $data = $request->validate(['channel' => 'required|in:call,email,whatsapp,meeting', 'subject' => 'required|string|max:255', 'due_at' => 'required|date_format:Y-m-d\TH:i']);
        $due = Carbon::createFromFormat('Y-m-d\TH:i', $data['due_at'], $this->workspace->timezone())->seconds(0)->utc();
        if ($due->isPast()) throw ValidationException::withMessages(['due_at' => 'Choose a future follow-up time.']);
        $this->workspace->atomic($e->organization_id, function () use ($e, $data, $due) {
            $e = $this->workspace->locked($e); abort_if($e->closed(), 409, 'This enquiry is closed.');
            $e->followUps()->create(['channel' => $data['channel'], 'subject' => $data['subject'], 'due_at' => $due]);
            $this->workspace->event($e, 'followup.scheduled', ucfirst($data['channel']).' scheduled: '.$data['subject'].' · '.$due->copy()->timezone($this->workspace->timezone())->format('d M Y, H:i T'));
        });
        return back()->with('success', 'Follow-up scheduled.');
    }

    public function completeFollowUp(Request $request, int $enquiry, int $followUp)
    {
        $e = $this->workspace->enquiry($enquiry, 'update');
        $data = $request->validate(['outcome' => 'required|in:connected,no_response,requested_revision,cancelled', 'completion_note' => 'required|string|max:3000']);
        $this->workspace->atomic($e->organization_id, function () use ($e, $followUp, $data) {
            $e = $this->workspace->locked($e); $task = $e->followUps()->lockForUpdate()->findOrFail($followUp);
            if ($task->completed_at) return;
            $task->update($data + ['completed_at' => now()]);
            $this->workspace->event($e, 'followup.completed', $task->subject.' · '.str_replace('_', ' ', $data['outcome']).'. '.$data['completion_note']);
        });
        return back()->with('success', 'Follow-up completed. Schedule the next action if needed.');
    }
}
