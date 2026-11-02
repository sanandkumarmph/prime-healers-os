<?php

namespace App\Services\Cylinders;

use App\Models\{Asset, Cylinder, CylinderEvent, CylinderJob, Organization, Rental, RentalAsset, User, Warehouse};
use App\Services\Deliveries\SignatureCapture;
use App\Services\Inventory\StockMovementRecorder;
use Illuminate\Support\Facades\{DB, Gate, Storage};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CylinderWorkflow
{
    private function fail(string $message): never { throw ValidationException::withMessages(['cylinder' => $message]); }

    public function authorize(User $user, string $action, ?CylinderJob $job = null): void
    {
        $module = $action === 'read' && !$user->canAccessModule('deliveries', 'read')
            && $user->canViewDues() && $user->canAccessModule('rentals', 'read') ? 'invoices' : 'deliveries';
        abort_unless($user->canAccessModule($module, $action), 403);
        if ($job) {
            abort_unless((int) $job->organization_id === (int) $user->organization_id, 404);
            abort_unless($user->scopeType($module) === 'all' || in_array((int) $user->id,
                [(int) $job->assigned_user_id, (int) $job->created_by_user_id], true), 404);
        }
    }

    public function authorizeStock(User $user): void
    {
        abort_unless($user->canAccessModule('assets', 'update') && $user->scopeType('assets') === 'all', 403);
    }

    public function atomic(User $user, callable $callback): mixed
    {
        return DB::transaction(function () use ($user, $callback) {
            $organization = Organization::whereKey($user->organization_id)->lockForUpdate()->firstOrFail();
            abort_unless($organization->is_active, 403);
            return $callback();
        }, 3);
    }

    public function register(User $user, int $assetId, string $contents): Cylinder
    {
        $this->authorizeStock($user);
        return $this->atomic($user, function () use ($user, $assetId, $contents) {
            $asset = Asset::where('organization_id', $user->organization_id)->lockForUpdate()->findOrFail($assetId);
            if (!$asset->isRentalStock() || !$asset->product?->tracksRentalStock() || $asset->asset_status === Asset::STATUS_RETIRED || str_starts_with($asset->serial_number ?? '', Asset::SERIAL_PENDING_PREFIX)
                || blank($asset->serial_number)) $this->fail('Register a PHOS-owned rental asset with its actual cylinder serial number first.');
            if (!in_array($contents, ['full', 'empty', 'unknown'], true)) $this->fail('Choose the cylinder contents.');
            if ($existing = Cylinder::where('asset_id', $assetId)->first()) return $existing;
            $assignment = $asset->activeRentalAssignments()->first();
            if ($asset->activeRentalAssignments()->count() > 1 || $assignment?->rental?->isVendorSupplied()) $this->fail('Reconcile this PHOS-owned cylinder assignment before registration.');
            if (!$assignment && in_array($asset->asset_status, ['reserved', 'rented'], true)) $this->fail('Reconcile this asset assignment before registering the cylinder.');
            if ($assignment && !in_array($asset->asset_status, ['reserved', 'rented'], true)) $this->fail('Reconcile this assigned asset status before registering the cylinder.');
            if ($assignment && $asset->asset_status === Asset::STATUS_RESERVED && $contents !== 'full') $this->fail('Confirm the reserved cylinder is full before registering it for delivery.');
            $cylinder = Cylinder::create(['organization_id' => $user->organization_id, 'asset_id' => $assetId,
                'contents' => $contents, 'custody' => $assignment && $asset->asset_status === Asset::STATUS_RENTED ? 'customer' : 'warehouse',
                'reserved_for_rental_id' => $assignment?->rental_id]);
            if (!$assignment) $asset->update(['asset_status' => $contents === 'full' && $asset->condition_status === 'good' ? Asset::STATUS_AVAILABLE : Asset::STATUS_MAINTENANCE]);
            $this->event($user, $cylinder, null, 'registered', ['contents' => $contents, 'custody' => $cylinder->custody], (string) Str::uuid());
            $asset->product?->syncLegacyStockFields();
            return $cylinder;
        });
    }

    private function fingerprint(array $data): string
    {
        unset($data['request_id'], $data['lock_version']);
        ksort($data);
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function create(User $user, Rental $rental, array $data): CylinderJob
    {
        $this->authorize($user, 'create'); Gate::forUser($user)->authorize('update', $rental);
        return $this->atomic($user, function () use ($user, $rental, $data) {
            $rental = Rental::where('organization_id', $user->organization_id)->lockForUpdate()->findOrFail($rental->id);
            if ($rental->status !== 'active') $this->fail('Cylinder service requires an active rental.');
            if ($rental->isVendorSupplied()) $this->fail('Cylinder services support PHOS-owned rental cylinders only.');
            $fingerprint = $this->fingerprint($data + ['rental_id' => $rental->id]);
            if ($existing = CylinderJob::where('organization_id', $user->organization_id)->where('request_id', $data['request_id'])->first()) {
                $this->authorize($user, 'read', $existing);
                abort_unless(hash_equals($existing->request_fingerprint, $fingerprint), 409, 'This request was already used with different details.');
                return $existing;
            }
            $incoming = Cylinder::with('asset')->where('organization_id', $user->organization_id)->lockForUpdate()->findOrFail($data['incoming_cylinder_id']);
            if (!$rental->activeRentalAssets()->where('asset_id', $incoming->asset_id)->exists()) $this->fail('The empty cylinder must be assigned to this rental.');
            $line = $rental->rentalItems()->where('product_id', $incoming->asset->product_id)->get()
                ->first(fn ($item) => in_array((int) $incoming->asset_id, array_map('intval', $item->asset_ids ?? []), true));
            if (!$line) $this->fail('Reconcile this rental product line with its current cylinder serial before creating a service job.');
            if ($incoming->custody !== 'customer') $this->fail('The cylinder is not currently recorded with the customer.');
            $outgoingId = $data['mode'] === 'same' ? $incoming->id : $data['outgoing_cylinder_id'];
            if ($data['mode'] === 'same' && !empty($data['outgoing_cylinder_id']) && (int) $data['outgoing_cylinder_id'] !== (int) $incoming->id) $this->fail('A same-cylinder refill must return the original cylinder.');
            $outgoing = Cylinder::with('asset')->where('organization_id', $user->organization_id)->lockForUpdate()->findOrFail($outgoingId);
            $ids = array_unique([$incoming->id, $outgoing->id]);
            $busy = CylinderJob::where('organization_id', $user->organization_id)->where('status', 'open')
                ->where(fn ($q) => $q->whereIn('incoming_cylinder_id', $ids)->orWhereIn('outgoing_cylinder_id', $ids))->exists();
            if ($busy) $this->fail('One of these cylinders already has an open service job.');
            $assignee = User::where('organization_id', $user->organization_id)->findOrFail($data['assigned_user_id']);
            if (!$assignee->canAccessModule('deliveries', 'update')) $this->fail('Choose an active staff member with delivery-update access.');
            if ($user->scopeType('deliveries') !== 'all' && (int) $assignee->id !== (int) $user->id) abort(403);
            if ($data['mode'] === 'exchange') {
                if ($incoming->id === $outgoing->id || $incoming->asset->product_id !== $outgoing->asset->product_id) $this->fail('Choose a different full cylinder of the same product.');
                if ($outgoing->contents !== 'full' || $outgoing->custody !== 'warehouse'
                    || !Asset::whereKey($outgoing->asset_id)->rentalReady()->exists()) $this->fail('The replacement cylinder must be full, inspected and ready in the warehouse.');
                $outgoing->update(['reserved_for_rental_id' => $rental->id]);
                $outgoing->asset->update(['asset_status' => Asset::STATUS_RESERVED]);
                $outgoing->asset->product?->syncLegacyStockFields();
            }
            $job = CylinderJob::create(['organization_id' => $user->organization_id, 'rental_id' => $rental->id,
                'incoming_cylinder_id' => $incoming->id, 'outgoing_cylinder_id' => $outgoing->id,
                'assigned_user_id' => $assignee->id, 'created_by_user_id' => $user->id, 'mode' => $data['mode'],
                'scheduled_at' => $data['scheduled_at'], 'refill_charge' => $data['refill_charge'], 'transport_charge' => $data['transport_charge'],
                'tax_rate' => $data['tax_rate'], 'tax_type' => $data['tax_type'], 'notes' => $data['notes'] ?? null,
                'request_id' => $data['request_id'], 'request_fingerprint' => $fingerprint]);
            $this->event($user, $incoming, $job, 'created', ['mode' => $data['mode']], (string) Str::uuid());
            return $job;
        });
    }

    private function event(User $user, Cylinder $cylinder, ?CylinderJob $job, string $action, array $details, string $requestId, ?string $fingerprint = null): CylinderEvent
    {
        return CylinderEvent::create(['organization_id' => $user->organization_id, 'cylinder_id' => $cylinder->id,
            'cylinder_job_id' => $job?->id, 'user_id' => $user->id, 'action' => $action, 'details' => $details,
            'request_id' => $requestId, 'request_fingerprint' => $fingerprint ?? $this->fingerprint($details)]);
    }

    public function act(User $user, CylinderJob $job, array $data): CylinderJob
    {
        $this->authorize($user, 'update', $job);
        $stockActions = ['warehouse_received', 'refill_sent', 'refill_received'];
        if (in_array($data['action'], $stockActions, true)) $this->authorizeStock($user);
        $written = [];
        try {
            return $this->atomic($user, function () use ($user, $job, $data, &$written) {
                // A database deadlock may retry this transaction after a signature
                // was written. Remove only files made by that rolled-back attempt.
                foreach ($written as $path) Storage::disk('local')->delete($path);
                $written = [];
                $job = CylinderJob::where('organization_id', $user->organization_id)->lockForUpdate()->findOrFail($job->id);
                $this->authorize($user, 'update', $job);
                $fingerprint = $this->fingerprint($data);
                $existing = CylinderEvent::where('organization_id', $user->organization_id)->where(function ($q) use ($job, $data) {
                    $q->where('request_id', $data['request_id'])->orWhere(fn ($stage) => $stage->where('cylinder_job_id', $job->id)->where('action', $data['action']));
                })->first();
                if ($existing) {
                    abort_unless((int) $existing->cylinder_job_id === (int) $job->id && hash_equals($existing->request_fingerprint, $fingerprint), 409, 'This action was already recorded with different details.');
                    return $job;
                }
                abort_unless((int) $job->lock_version === (int) $data['lock_version'], 409, 'This job changed. Refresh before continuing.');
                if ($job->status === 'cancelled') $this->fail('This job is cancelled.');
                $incoming = Cylinder::with('asset')->lockForUpdate()->findOrFail($job->incoming_cylinder_id);
                $outgoing = $job->incoming_cylinder_id === $job->outgoing_cylinder_id ? $incoming : Cylinder::with('asset')->lockForUpdate()->findOrFail($job->outgoing_cylinder_id);
                if ($job->status === 'completed' && CylinderJob::where('organization_id', $user->organization_id)->where('status', 'open')->whereKeyNot($job->id)
                    ->where(fn ($q) => $q->whereIn('incoming_cylinder_id', [$incoming->id, $outgoing->id])->orWhereIn('outgoing_cylinder_id', [$incoming->id, $outgoing->id]))->exists()) {
                    $this->fail('This cylinder belongs to a newer service job. Continue from that job.');
                }
                $cylinder = $data['action'] === 'full_delivered' ? $outgoing : $incoming;
                Asset::whereIn('id', array_unique([$incoming->asset_id, $outgoing->asset_id]))->orderBy('id')->lockForUpdate()->get();
                if ($data['action'] !== 'cancelled' && !in_array(trim($data['scanned_code'] ?? ''), array_filter([$cylinder->asset->serial_number, $cylinder->asset->barcode_value]), true)) $this->fail('The scanned serial/barcode does not match the cylinder for this step.');
                $details = ['notes' => $data['notes'] ?? '', 'serial_number' => $cylinder->asset->serial_number];
                $warehouse = null;
                if (in_array($data['action'], ['warehouse_received', 'refill_received'], true)) {
                    $warehouse = Warehouse::where('organization_id', $user->organization_id)->where('is_active', true)->findOrFail($data['warehouse_id'] ?? 0);
                }
                if (in_array($data['action'], ['empty_collected', 'full_delivered'], true)) {
                    $signature = trim($data['signature_data'] ?? '');
                    $name = trim($data['signer_name'] ?? '');
                    $reason = trim($data['signature_reason'] ?? '');
                    if ($signature === '' && $reason === '') $this->fail('Capture customer signature or record why signing was not possible.');
                    if ($signature !== '' && $name === '') $this->fail('Enter the name of the person signing.');
                    $details += ['signer_name' => $name, 'signature_reason' => $reason];
                    if ($signature !== '') {
                        $bytes = SignatureCapture::clean($signature, 512 * 1024);
                        $path = 'cylinder-signatures/'.$user->organization_id.'/'.Str::uuid().'.png';
                        if (!Storage::disk('local')->put($path, $bytes)) throw new \RuntimeException('Unable to save customer signature.');
                        $written[] = $path; $details['signature_path'] = $path;
                    }
                }
                $before = $cylinder->asset->asset_status;
                switch ($data['action']) {
                    case 'empty_collected':
                        if ($incoming->custody !== 'customer' || $job->collected_at) $this->fail('The empty cylinder has already been collected or is not with this customer.');
                        if (empty($data['confirm_empty'])) $this->fail('Confirm the collected cylinder is empty.');
                        $incoming->update(['contents' => 'empty', 'custody' => 'in_transit', 'reserved_for_rental_id' => $job->mode === 'same' ? $job->rental_id : null]);
                        if ($job->mode === 'exchange') RentalAsset::where('rental_id', $job->rental_id)->where('asset_id', $incoming->asset_id)->whereNull('returned_at')->update(['returned_at' => now(), 'return_condition' => $incoming->asset->condition_status]);
                        $incoming->asset->update(['asset_status' => Asset::STATUS_MAINTENANCE]);
                        $job->collected_at = now();
                        break;
                    case 'warehouse_received':
                        if (!$job->collected_at || $incoming->custody !== 'in_transit') $this->fail('Collect the empty cylinder before confirming warehouse receipt.');
                        $incoming->update(['custody' => 'warehouse']);
                        $incoming->asset->update(['warehouse_id' => $warehouse->id, 'asset_status' => Asset::STATUS_MAINTENANCE]);
                        $details['warehouse_id'] = $warehouse->id; $job->warehouse_received_at = now();
                        break;
                    case 'refill_sent':
                        if ($incoming->custody !== 'warehouse' || $incoming->contents !== 'empty' || $incoming->asset->condition_status !== 'good') $this->fail('The empty cylinder must be received and its condition confirmed before sending for refill.');
                        $incoming->update(['contents' => 'refilling', 'custody' => 'refiller']);
                        $incoming->asset->update(['asset_status' => Asset::STATUS_MAINTENANCE]);
                        $job->refill_sent_at = now();
                        break;
                    case 'refill_received':
                        if (!$job->refill_sent_at || $incoming->custody !== 'refiller' || $incoming->contents !== 'refilling' || empty($data['inspection_confirmed']) || $incoming->asset->condition_status !== 'good') $this->fail('Receive the refilled cylinder and confirm its condition before making it ready.');
                        $incoming->update(['contents' => 'full', 'custody' => 'warehouse']);
                        $incoming->asset->update(['warehouse_id' => $warehouse->id, 'asset_status' => $job->mode === 'same' ? Asset::STATUS_RESERVED : Asset::STATUS_AVAILABLE]);
                        $details['warehouse_id'] = $warehouse->id; $job->refilled_at = now();
                        break;
                    case 'full_delivered':
                        $rental = Rental::where('organization_id', $user->organization_id)->lockForUpdate()->findOrFail($job->rental_id);
                        if ($rental->status !== 'active' || $job->delivered_at || $outgoing->contents !== 'full' || $outgoing->custody !== 'warehouse'
                            || (int) $outgoing->reserved_for_rental_id !== (int) $job->rental_id || $outgoing->asset->condition_status !== 'good') $this->fail('This cylinder is not full, inspected and reserved for this active rental.');
                        if ($job->mode === 'same' && !$job->refilled_at) $this->fail('Complete this cylinder refill before returning it to the customer.');
                        if ($job->mode === 'exchange') {
                            if ($outgoing->asset->activeRentalAssignments()->exists()) $this->fail('The replacement cylinder has another active assignment.');
                            RentalAsset::create(['organization_id' => $user->organization_id, 'rental_id' => $job->rental_id, 'asset_id' => $outgoing->asset_id, 'assigned_at' => now(), 'notes' => 'Cylinder exchange job #'.$job->id]);
                            foreach ($rental->rentalItems()->where('product_id', $incoming->asset->product_id)->get() as $item) {
                                $ids = array_map('intval', $item->asset_ids ?? []);
                                if (in_array((int) $incoming->asset_id, $ids, true)) $item->update(['asset_ids' => array_map(fn ($id) => $id === (int) $incoming->asset_id ? (int) $outgoing->asset_id : $id, $ids)]);
                            }
                        }
                        $outgoing->update(['custody' => 'customer']);
                        $outgoing->asset->update(['asset_status' => Asset::STATUS_RENTED]);
                        $job->delivered_at = now();
                        break;
                    case 'cancelled':
                        if ($job->collected_at || $job->delivered_at) $this->fail('A job with recorded physical movements cannot be cancelled. Complete or reconcile its outstanding steps.');
                        if ($job->mode === 'exchange') {
                            $outgoing->update(['reserved_for_rental_id' => null]);
                            $ready = $outgoing->contents === 'full' && $outgoing->custody === 'warehouse' && $outgoing->asset->condition_status === 'good';
                            $outgoing->asset->update(['asset_status' => $ready ? Asset::STATUS_AVAILABLE : Asset::STATUS_MAINTENANCE]);
                        }
                        $job->status = 'cancelled'; $job->completed_at = now();
                        break;
                    default: $this->fail('Unknown cylinder workflow step.');
                }
                $details += ['from_status' => $before, 'to_status' => $cylinder->asset->fresh()->asset_status, 'contents' => $cylinder->fresh()->contents, 'custody' => $cylinder->fresh()->custody];
                $this->event($user, $cylinder, $job, $data['action'], $details, $data['request_id'], $fingerprint);
                $movement = match ($data['action']) { 'empty_collected' => 'pickup_return', 'full_delivered' => 'rental_out', 'warehouse_received' => 'return_verification', default => 'manual_adjustment' };
                if ($data['action'] !== 'cancelled') app(StockMovementRecorder::class)->recordForAsset($cylinder->asset->fresh(), $movement, 1,
                    ['rental_id' => $job->rental_id, 'from_status' => $before, 'to_status' => $details['to_status'], 'performed_by_user_id' => $user->id, 'notes' => 'Cylinder job #'.$job->id.': '.$data['action']]);
                if ($job->status !== 'cancelled' && $job->delivered_at && $job->warehouse_received_at) {
                    $job->status = 'completed'; $job->completed_at ??= now();
                }
                $job->lock_version++; $job->save();
                foreach (array_unique([$incoming->asset->product_id, $outgoing->asset->product_id]) as $id) \App\Models\Product::find($id)?->syncLegacyStockFields();
                return $job;
            });
        } catch (\Throwable $error) {
            foreach ($written as $path) Storage::disk('local')->delete($path);
            throw $error;
        }
    }

    public function syncOrdinaryAssignment(int $organizationId, Rental $rental, array $rented, array $reserved): void
    {
        if (!Cylinder::hasTable()) return;
        foreach (Cylinder::with('asset')->where('organization_id', $organizationId)->whereIn('asset_id', array_unique([...$rented, ...$reserved]))->get() as $cylinder) {
            if (CylinderJob::where('status', 'open')->where(fn ($q) => $q->where('incoming_cylinder_id', $cylinder->id)->orWhere('outgoing_cylinder_id', $cylinder->id))->exists()) continue;
            if ($cylinder->contents !== 'full') continue;
            if (!$rental->activeRentalAssets()->where('asset_id', $cylinder->asset_id)->exists()) continue;
            $delivered = in_array((int) $cylinder->asset_id, array_map('intval', $rented), true);
            $custody = $delivered ? 'customer' : 'warehouse';
            $changed = $cylinder->custody !== $custody || (int) $cylinder->reserved_for_rental_id !== (int) $rental->id;
            $cylinder->update(['custody' => $custody, 'reserved_for_rental_id' => $rental->id]);
            $cylinder->asset->update(['asset_status' => $delivered ? Asset::STATUS_RENTED : Asset::STATUS_RESERVED]);
            if ($changed && ($actor = auth()->user()) && (int) $actor->organization_id === $organizationId) {
                $this->event($actor, $cylinder, null, $delivered ? 'rental_delivered' : 'rental_reserved',
                    ['rental_id' => $rental->id, 'contents' => $cylinder->contents, 'custody' => $custody], (string) Str::uuid());
            }
        }
    }

    public function guardOrdinaryReturn(Rental $rental, array $assetIds): void
    {
        if (!Cylinder::hasTable()) return;
        if (CylinderJob::where('rental_id', $rental->id)->where('status', 'open')->where(fn ($q) => $q
            ->whereHas('incoming', fn ($c) => $c->whereIn('asset_id', $assetIds))
            ->orWhereHas('outgoing', fn ($c) => $c->whereIn('asset_id', $assetIds)))->exists()) {
            $this->fail('Complete the open cylinder service steps before recording an ordinary pickup for these cylinders.');
        }
    }

    public function recordOrdinaryReturn(int $organizationId, array $assetIds): void
    {
        if (!Cylinder::hasTable()) return;
        foreach (Cylinder::where('organization_id', $organizationId)->whereIn('asset_id', $assetIds)->get() as $cylinder) {
            if ($cylinder->contents === 'unknown' && $cylinder->custody === 'in_transit' && !$cylinder->reserved_for_rental_id) continue;
            $rentalId = $cylinder->reserved_for_rental_id;
            $cylinder->update(['contents' => 'unknown', 'custody' => 'in_transit', 'reserved_for_rental_id' => null]);
            if (($actor = auth()->user()) && (int) $actor->organization_id === $organizationId) {
                $this->event($actor, $cylinder, null, 'rental_returned', ['rental_id' => $rentalId,
                    'contents' => 'unknown', 'custody' => 'in_transit'], (string) Str::uuid());
            }
        }
    }

    public function stockAction(User $user, Cylinder $cylinder, array $data): Cylinder
    {
        $this->authorizeStock($user);
        abort_unless((int) $cylinder->organization_id === (int) $user->organization_id, 404);
        return $this->atomic($user, function () use ($user, $cylinder, $data) {
            $cylinder = Cylinder::with('asset')->lockForUpdate()->findOrFail($cylinder->id);
            $fingerprint = $this->fingerprint($data + ['cylinder_id' => $cylinder->id]);
            $old = CylinderEvent::where('organization_id', $user->organization_id)->where('request_id', $data['request_id'])->first();
            if ($old) {
                abort_unless(hash_equals($old->request_fingerprint, $fingerprint), 409);
                return $cylinder;
            }
            if ($cylinder->reserved_for_rental_id || $cylinder->asset->activeRentalAssignments()->exists()
                || CylinderJob::where('status', 'open')->where(fn ($q) => $q->where('incoming_cylinder_id', $cylinder->id)->orWhere('outgoing_cylinder_id', $cylinder->id))->exists()) {
                $this->fail('This cylinder is assigned or reserved. Continue from its rental service job.');
            }
            if (!in_array(trim($data['scanned_code']), array_filter([$cylinder->asset->serial_number, $cylinder->asset->barcode_value]), true)) $this->fail('The serial/barcode does not match this cylinder.');
            $warehouse = Warehouse::where('organization_id', $user->organization_id)->where('is_active', true)->findOrFail($data['warehouse_id']);
            $before = $cylinder->asset->asset_status;
            switch ($data['action']) {
                case 'inspection_recorded':
                    if ($cylinder->custody !== 'warehouse' || $cylinder->contents !== 'unknown' || empty($data['inspection_confirmed'])) $this->fail('Inspect this warehouse cylinder before confirming its contents.');
                    if (!in_array($data['contents'] ?? '', ['empty', 'full'], true)) $this->fail('Confirm whether the cylinder is full or empty.');
                    $cylinder->fill(['contents' => $data['contents'], 'custody' => 'warehouse']);
                    break;
                case 'return_received':
                    if ($cylinder->custody !== 'in_transit' || $cylinder->contents !== 'unknown' || empty($data['inspection_confirmed'])) $this->fail('Inspect the returned cylinder before recording warehouse receipt.');
                    if (!in_array($data['contents'] ?? '', ['empty', 'full'], true)) $this->fail('Confirm whether the returned cylinder is full or empty.');
                    $cylinder->fill(['contents' => $data['contents'], 'custody' => 'warehouse']);
                    break;
                case 'sent_for_refill':
                    if ($cylinder->custody !== 'warehouse' || $cylinder->contents !== 'empty' || $cylinder->asset->condition_status !== 'good') $this->fail('Only an inspected empty warehouse cylinder can be sent for refill.');
                    $cylinder->fill(['contents' => 'refilling', 'custody' => 'refiller']);
                    break;
                case 'received_full':
                    if ($cylinder->custody !== 'refiller' || $cylinder->contents !== 'refilling' || empty($data['inspection_confirmed'])) $this->fail('Confirm inspection of the refilled cylinder.');
                    $cylinder->fill(['contents' => 'full', 'custody' => 'warehouse']);
                    break;
                default: $this->fail('Unknown warehouse step.');
            }
            $cylinder->save();
            $ready = $cylinder->contents === 'full' && $cylinder->custody === 'warehouse' && $cylinder->asset->condition_status === 'good';
            $cylinder->asset->update(['warehouse_id' => $warehouse->id, 'asset_status' => $ready ? Asset::STATUS_AVAILABLE : Asset::STATUS_MAINTENANCE]);
            $this->event($user, $cylinder, null, $data['action'], ['contents' => $cylinder->contents, 'custody' => $cylinder->custody,
                'warehouse_id' => $warehouse->id, 'notes' => $data['notes'] ?? ''], $data['request_id'], $fingerprint);
            app(StockMovementRecorder::class)->recordForAsset($cylinder->asset, 'manual_adjustment', 1,
                ['from_status' => $before, 'to_status' => $cylinder->asset->asset_status, 'performed_by_user_id' => $user->id, 'notes' => 'Cylinder: '.$data['action']]);
            $cylinder->asset->product?->syncLegacyStockFields();
            return $cylinder;
        });
    }
}
