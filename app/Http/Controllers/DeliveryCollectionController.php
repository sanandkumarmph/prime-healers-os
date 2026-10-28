<?php

namespace App\Http\Controllers;

use App\Models\{DeliveryCashHandover, DeliveryCollection, DeliveryProof};
use App\Services\Deliveries\{DeliveryCashHandoverService, DeliveryCollectionService};
use Illuminate\Http\Request;

class DeliveryCollectionController extends Controller
{
    public function proof(Request $request, int $collection, DeliveryCollectionService $service)
    {
        $entry = DeliveryCollection::where('organization_id', $request->user()->organization_id)->findOrFail($collection);
        $service->task($request->user(), $entry->delivery_id);
        $proof = DeliveryProof::where('organization_id', $entry->organization_id)->where('delivery_id', $entry->delivery_id)->findOrFail($entry->proof_id);
        return app(\App\Services\Deliveries\DeliveryProofService::class)->streamInline($proof);
    }

    public function index(Request $request, DeliveryCollectionService $service)
    {
        abort_unless($service->canReceive($request->user()), 403);
        $entries = DeliveryCollection::where('organization_id', $request->user()->organization_id)->where('status','pending_review')
            ->with('collector')->oldest('id')->paginate(25);
        return response()->view('deliveries.collection-review', compact('entries'))->header('Cache-Control','private, no-store');
    }

    public function show(Request $request, int $delivery, DeliveryCollectionService $service)
    {
        $task = $service->task($request->user(), $delivery);
        $invoice = $service->invoice($task); $invoice->syncFinancialStatus();
        $version = $service->version($task, $invoice);
        $entries = DeliveryCollection::where('organization_id', $request->user()->organization_id)->where('delivery_id', $task->id)
            ->with(['collector','reviewer','handovers','proof'])->latest('id')->get();
        $proofs = DeliveryProof::where('organization_id', $request->user()->organization_id)->where('delivery_id', $task->id)
            ->where('proof_type', DeliveryProof::TYPE_COLLECTION)->latest('id')->get();
        $canReceive = $service->canReceive($request->user());
        $canRecord = $request->user()->can('update', $task) && $task->collection_required && in_array($task->status, ['in_progress','completed'], true);
        $reported = $entries->whereNotIn('status',['rejected','reversed'])->sum('amount');
        $available = max(0, min((float) $task->collection_amount_to_collect - $reported, (float) $invoice->balance_amount));
        return response()->view('deliveries.collections', compact('task','version','entries','proofs','canReceive','canRecord','available'))
            ->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, int $delivery, DeliveryCollectionService $service)
    {
        $entry = $service->record($request->user(), $delivery, $request->all());
        return redirect()->route('delivery-collections.show', $delivery)->with('success', 'Saved collection #'.$entry->id.': '.str_replace('_',' ',$entry->status).'. Check its receipt and review history.');
    }

    public function review(Request $request, int $collection, DeliveryCollectionService $service)
    {
        $entry = $service->review($request->user(), $collection, $request->all());
        return redirect()->route('delivery-collections.show', $entry->delivery_id)->with('success', 'Collection review saved.');
    }

    public function reverse(Request $request, int $collection, DeliveryCollectionService $service)
    {
        $entry = $service->reverse($request->user(), $collection, $request->all());
        return redirect()->route('delivery-collections.show', $entry->delivery_id)->with('success', 'Collection corrected. Original history retained; no customer refund recorded.');
    }

    public function cash(Request $request, DeliveryCollectionService $service)
    {
        abort_unless($service->canViewCash($request->user()), 403);
        $canReceive = $service->canReceive($request->user());
        $entries = DeliveryCollection::where('organization_id', $request->user()->organization_id)->where('method','cash')
            ->when(!$canReceive, fn ($q) => $q->where('collector_user_id', $request->user()->id))
            ->with(['collector','handovers.receiver'])->latest('id')->paginate(25);
        return response()->view('deliveries.cash', compact('entries','canReceive'))->header('Cache-Control','private, no-store');
    }

    public function submitCash(Request $request, int $collection, DeliveryCashHandoverService $service)
    {
        $service->submit($request->user(), $collection, $request->all());
        return redirect()->route('cash-handovers.index')->with('success','Handover submitted. Cash remains outstanding until Accounts confirms receipt.');
    }

    public function receiveCash(Request $request, int $handover, DeliveryCashHandoverService $service)
    {
        $service->confirm($request->user(), $handover, $request->all());
        return redirect()->route('cash-handovers.index')->with('success','Actual cash receipt confirmed. The customer invoice was not credited again.');
    }

    public function correctCash(Request $request, int $handover, DeliveryCashHandoverService $service)
    {
        $service->correct($request->user(), $handover, $request->all());
        return redirect()->route('cash-handovers.index')->with('success','Cash accountability correction saved. Original amounts and history retained.');
    }
}
