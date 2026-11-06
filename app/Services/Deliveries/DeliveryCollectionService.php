<?php

namespace App\Services\Deliveries;

use App\Models\{ActivityLog, Delivery, DeliveryCollection, DeliveryProof, Invoice, Payment, Rental, Sale, User};
use App\Services\Finance\{OrganizationFinancialTransaction, PaymentSyncService};
use Illuminate\Support\Facades\{DB, Gate, Validator};
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DeliveryCollectionService
{
    public static function cents(mixed $amount): int { return (int) round((float) $amount * 100); }

    public function canReceive(User $user): bool
    {
        return $user->is_active && $user->organization()->where('is_active', true)->exists()
            && ($user->isSuperAdmin() || ((in_array($user->effective_role, [User::ROLE_ADMIN_OPERATIONS, User::ROLE_FINANCE, 'admin'], true)
                || $user->hasPermission('cash.receive')) && $user->canAccessModule('payments', 'create') && $user->scopeType('payments') === 'all'));
    }

    public function canViewCash(User $user): bool
    {
        return $user->is_active && $user->organization()->where('is_active', true)->exists()
            && ($this->canReceive($user) || $user->canAccessModule('deliveries', 'update'));
    }

    public function task(User $user, int $id, bool $write = false): Delivery
    {
        abort_unless($user->is_active && $user->organization()->where('is_active', true)->exists(), 403);
        $task = Delivery::where('organization_id', $user->organization_id)->findOrFail($id);
        if ($write || !$this->canReceive($user)) Gate::forUser($user)->authorize($write ? 'update' : 'view', $task);
        if (!$this->canReceive($user)) {
            // Cash accountability belongs to the actual assigned operator.
            abort_unless((int) $task->assigned_user_id === (int) $user->id, 403);
        }
        return $task;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['collection' => $message]);
    }

    public function invoice(Delivery $task): Invoice
    {
        if ((!$task->rental_id && !$task->sale_id) || ($task->rental_id && $task->sale_id)) {
            $this->fail('Reconcile this task with one rental or sale order before collecting.');
        }
        $query = Invoice::where('organization_id', $task->organization_id);
        if ($task->rental_id) {
            $query->where('rental_id', $task->rental_id)->whereNull('sale_id')
                ->whereNotIn('id', DB::table('rental_renewals')->where('organization_id', $task->organization_id)->whereNotNull('invoice_id')->select('invoice_id'));
        } else {
            $query->where('sale_id', $task->sale_id);
        }
        $ids = $query->pluck('id');
        if ($ids->count() !== 1) $this->fail('This task needs one directly linked invoice. Accounts must reconcile missing or ambiguous invoice links first.');

        return Invoice::where('organization_id', $task->organization_id)->whereKey($ids->first())->lockForUpdate()->firstOrFail();
    }

    public function version(Delivery $task, Invoice $invoice): string
    {
        return hash('sha256', json_encode([$task->id, $task->rental_id, $task->sale_id,
            $task->assigned_user_id, $task->status, $task->collection_required, $task->collection_amount_to_collect, $task->collection_allowed_methods,
            app(VendorCollectionAttribution::class)->vendor($task)['key'] ?? null,
            $invoice->id, $invoice->status, $invoice->payment_status, $invoice->paid_amount, $invoice->balance_amount,
            DeliveryCollection::where('organization_id', $task->organization_id)->where('invoice_id', $invoice->id)->orderBy('id')->get(['id','status','amount'])->toArray()], JSON_THROW_ON_ERROR));
    }

    private function sync(Invoice $invoice): void
    {
        app(PaymentSyncService::class)->refreshInvoiceAfterPaymentDeletion((int) $invoice->organization_id, $invoice);
    }

    public function audit(User $actor, string $action, object $record, array $properties): void
    {
        ActivityLog::create(['organization_id' => $actor->organization_id, 'user_id' => $actor->id,
            'action' => $action, 'subject_type' => $record::class, 'subject_id' => $record->id,
            'description' => str_replace('.', ' ', $action), 'properties' => $properties]);
    }

    private function post(User $actor, DeliveryCollection $entry, Invoice $invoice): void
    {
        $payment = app(PaymentSyncService::class)->createInvoicePayment((int) $actor->organization_id, $invoice,
            ['amount' => $entry->amount, 'payment_date' => today()->toDateString(),
                'payment_method' => $entry->method, 'notes' => 'Delivery collection #'.$entry->id.'. '.$entry->reference,
                'collection_recipient' => $entry->collection_recipient, 'collection_vendor_key' => $entry->collection_vendor_key,
                'collection_vendor_name' => $entry->collection_vendor_name],
            $invoice->rental_id ? (int) $invoice->rental_id : null, true);
        $entry->update(['payment_id' => $payment->id]);
        $this->sync($invoice);
    }

    public function canRecord(User $actor, Delivery $task): bool
    {
        return $actor->can('update', $task) && $task->collection_required
            && in_array($task->status, ['in_progress', 'completed'], true)
            && (!$task->hasOrderCollection() || ($task->type === 'delivery' && $task->assignment_type === 'delivery_team'
                && (int) $task->assigned_user_id === (int) $actor->id
                && app(OrderCollectionSetup::class)->eligibleOperator($actor)));
    }

    public function availableCents(Delivery $task, Invoice $invoice): int
    {
        $reported = DeliveryCollection::where('organization_id', $task->organization_id)->where('delivery_id', $task->id)
            ->whereNotIn('status', ['rejected', 'reversed'])->sum('amount');
        $pending = DeliveryCollection::where('organization_id', $task->organization_id)->where('invoice_id', $invoice->id)
            ->where('status', 'pending_review')->sum('amount');

        return max(0, min(self::cents($task->collection_amount_to_collect) - self::cents($reported),
            self::cents($invoice->balance_amount) - self::cents($pending)));
    }

    public function record(User $snapshot, int $taskId, array $input): DeliveryCollection
    {
        $proofService = app(DeliveryProofService::class);
        $data = Validator::make($input, [
            'submission_token' => 'required|uuid', 'version' => 'required|string|size:64',
            'outcome' => 'nullable|in:collected,partial,none',
            'amount' => 'required_unless:outcome,none|nullable|numeric|decimal:0,2|min:0.01|max:99999999.99',
            'method' => 'required_unless:outcome,none|nullable|in:cash,upi,bank_transfer,card,split',
            'cash_amount' => 'required_if:method,split|nullable|numeric|decimal:0,2|min:0.01|max:99999999.99',
            'online_method' => 'required_if:method,split|nullable|in:upi,bank_transfer,card',
            'reference' => 'nullable|string|max:255',
            'collection_recipient' => 'nullable|in:company,vendor',
            'proof_id' => 'nullable|integer|prohibits:payment_screenshot',
            'payment_screenshot' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:'.$proofService->maxKilobytes()],
            'note' => 'required_if:outcome,partial,none|nullable|string|max:1000',
            'confirmed' => 'accepted',
        ])->validate();

        return app(OrganizationFinancialTransaction::class)->run((int) $snapshot->organization_id, function () use ($snapshot, $taskId, $data, $proofService) {
            $actor = $snapshot->fresh();
            $task = $this->task($actor, $taskId, true);
            if ($task->hasOrderCollection()) {
                abort_unless((int) $task->assigned_user_id === (int) $actor->id
                    && app(OrderCollectionSetup::class)->eligibleOperator($actor), 403);
            }

            $token = strtolower($data['submission_token']);
            $outcome = $data['outcome'] ?? null;
            $amount = self::cents($data['amount'] ?? 0);
            $method = $data['method'] ?? null;
            $note = trim($data['note'] ?? '');
            $reference = trim($data['reference'] ?? '');
            $screenshot = $data['payment_screenshot'] ?? null;
            $requestValues = [$taskId, $actor->id, $amount, $method, $reference, (int) ($data['proof_id'] ?? 0), $note];
            if (!empty($data['collection_recipient'])) $requestValues[] = $data['collection_recipient'];
            if ($outcome !== null || $method === 'split' || $screenshot) {
                $requestValues[] = [$outcome, $data['cash_amount'] ?? null, $data['online_method'] ?? null,
                    $screenshot ? hash_file('sha256', $screenshot->getPathname()) : null];
            }
            $hash = hash('sha256', json_encode($requestValues, JSON_THROW_ON_ERROR));
            $prior = DeliveryCollection::where('organization_id', $actor->organization_id)->where('submission_token', $token)->first();
            if ($prior) {
                if (!hash_equals($prior->request_hash, $hash)) $this->fail('That submission token already belongs to a different report. Check saved history.');
                return $prior;
            }
            if (!$this->canRecord($actor, $task)) $this->fail('Start an authorised collection task before recording its outcome.');
            if ($task->hasOrderCollection() && $outcome === null) $this->fail('Choose the collection outcome.');
            if (!$task->hasOrderCollection() && ($outcome === 'none' || $method === 'split')) $this->fail('Use the existing single-payment workflow for this task.');

            $invoice = $this->invoice($task);
            $invoice->syncFinancialStatus();
            if (!hash_equals($this->version($task, $invoice), $data['version'])) $this->fail('The task or invoice changed. Refresh and review its saved history before collecting.');

            $proof = !empty($data['proof_id']) ? DeliveryProof::where('organization_id', $actor->organization_id)->where('delivery_id', $taskId)
                ->where('proof_type', DeliveryProof::TYPE_COLLECTION)->findOrFail($data['proof_id']) : null;
            if ($proof && DeliveryCollection::where('organization_id', $actor->organization_id)->where('proof_id', $proof->id)->exists()) {
                $this->fail('This payment proof already belongs to a saved collection report.');
            }

            if ($outcome === 'none') {
                if ($amount || $method || $proof || $screenshot || $note === '') $this->fail('Enter a reason and leave payment details blank when nothing was collected.');
                $parts = ['none' => 0];
            } else {
                app(PaymentSyncService::class)->assertPayable($invoice);
                $available = $this->availableCents($task, $invoice);
                if ($amount <= 0 || $amount > $available) $this->fail('This amount exceeds the remaining collection or unpaid invoice balance.');
                if ($outcome === 'collected' && $amount !== $available) $this->fail('Choose partial collection when less than the available amount was received.');
                if ($outcome === 'partial' && ($amount >= $available || $note === '')) $this->fail('Enter a partial amount and the reason the full amount was not collected.');

                $cash = self::cents($data['cash_amount'] ?? 0);
                $parts = $method === 'split' ? ['cash' => $cash, $data['online_method'] => $amount - $cash] : [$method => $amount];
                if (min($parts) <= 0 || array_diff(array_keys($parts), $task->allowedCollectionMethods())) {
                    $this->fail('Use authorised methods and positive amounts for each payment portion.');
                }
                $hasOnline = count($parts) > 1 || !isset($parts['cash']);
                if ($hasOnline && $reference === '' && !$proof && !$screenshot) {
                    $this->fail('Provide a transaction reference or a private payment screenshot for Accounts verification.');
                }
                if (!$hasOnline && $screenshot) $this->fail('A payment screenshot is only needed for an online payment.');
                if ($screenshot) {
                    [$proof] = $proofService->storeUploadedFiles($task, $actor, 'delivery', DeliveryProof::MOMENT_COMPLETE,
                        DeliveryProof::TYPE_COLLECTION, [$screenshot]);
                }
            }

            $attribution = app(VendorCollectionAttribution::class)->attributes($task, $data['collection_recipient'] ?? null, $amount > 0);
            $first = null;
            foreach ($parts as $portionMethod => $portion) {
                $entry = DeliveryCollection::create([
                    'organization_id' => $actor->organization_id, 'delivery_id' => $taskId, 'invoice_id' => $invoice->id,
                    'collector_user_id' => $actor->id, 'submission_token' => $first ? (string) Str::uuid() : $token,
                    'request_hash' => $hash, 'amount' => $portion / 100, 'method' => $portionMethod, 'outcome' => $outcome,
                    'status' => match ($portionMethod) {
                        'cash' => 'cash_received',
                        'none' => 'not_collected',
                        default => 'pending_review',
                    },
                    'reference' => $portionMethod !== 'cash' || count($parts) === 1 ? $reference : null,
                    'proof_id' => $portionMethod !== 'cash' || count($parts) === 1 ? $proof?->id : null,
                    'note' => $note, 'received_at' => now(),
                ] + $attribution);
                if ($portionMethod === 'cash') $this->post($actor, $entry, $invoice);
                $this->audit($actor, 'delivery.collection_recorded', $entry, ['amount' => $entry->amount, 'method' => $entry->method,
                    'outcome' => $outcome, 'payment_id' => $entry->payment_id] + $attribution);
                $first ??= $entry;
            }

            return $first;
        });
    }

    public function review(User $snapshot, int $id, array $input): DeliveryCollection
    {
        $data = Validator::make($input, ['action' => 'required|in:verify,reject', 'reason' => 'required|string|min:5|max:1000', 'confirmed' => 'accepted'])->validate();
        return app(OrganizationFinancialTransaction::class)->run((int) $snapshot->organization_id, function () use ($snapshot, $id, $data) {
            $actor = $snapshot->fresh(); abort_unless($this->canReceive($actor), 403);
            $entry = DeliveryCollection::where('organization_id', $actor->organization_id)->lockForUpdate()->findOrFail($id);
            $next = $data['action'] === 'verify' ? 'verified' : 'rejected';
            if ($entry->status === $next && (int) $entry->reviewed_by_user_id === (int) $actor->id && $entry->review_reason === $data['reason']) return $entry;
            if ($entry->status !== 'pending_review') $this->fail('This online report has already been reviewed. Check its history.');
            if ($data['action'] === 'verify') {
                $task = Delivery::where('organization_id', $actor->organization_id)->findOrFail($entry->delivery_id);
                $invoice = $this->invoice($task);
                if ((int) $invoice->id !== (int) $entry->invoice_id) $this->fail('The task invoice changed. Reconcile before verification.');
                $this->post($actor, $entry, $invoice);
            }
            $entry->update(['status' => $next, 'reviewed_by_user_id' => $actor->id, 'reviewed_at' => now(), 'review_reason' => $data['reason']]);
            $this->audit($actor, 'delivery.collection_'.$next, $entry, ['reason' => $data['reason'], 'payment_id' => $entry->payment_id]);
            return $entry;
        });
    }

    public function reverse(User $snapshot, int $id, array $input): DeliveryCollection
    {
        $data = Validator::make($input, ['reason' => 'required|string|min:5|max:1000', 'confirmed' => 'accepted'])->validate();
        return app(OrganizationFinancialTransaction::class)->run((int) $snapshot->organization_id, function () use ($snapshot, $id, $data) {
            $actor = $snapshot->fresh(); abort_unless($this->canReceive($actor) && $actor->canAccessModule('payments', 'delete'), 403);
            $entry = DeliveryCollection::where('organization_id', $actor->organization_id)->lockForUpdate()->findOrFail($id);
            if ($entry->status === 'reversed') return $entry;
            if (!in_array($entry->status, ['cash_received','verified'], true) || !$entry->payment_id) $this->fail('Only a posted collection can be reversed. Reject an unverified report instead.');
            if ($entry->handovers()->whereIn('status', ['pending','confirmed'])->exists()) $this->fail('Cancel pending handovers and reverse erroneous cash receipts before correcting the collection. Reconcile actual cash separately.');
            $payment = Payment::where('organization_id', $actor->organization_id)->whereKey($entry->payment_id)->lockForUpdate()->firstOrFail();
            if (self::cents($payment->amount) !== self::cents($entry->amount) || $payment->reversal()->exists()) $this->fail('The payment history needs reconciliation before this correction.');
            $reversal = Payment::create(['organization_id' => $actor->organization_id, 'invoice_id' => $payment->invoice_id,
                'rental_id' => $payment->rental_id, 'customer_id' => $payment->customer_id, 'payment_date' => today(),
                'amount' => -(float) $payment->amount, 'payment_method' => $payment->payment_method,
                'reversal_of_payment_id' => $payment->id, 'notes' => 'Delivery collection correction. No refund recorded. '.$data['reason']]);
            $invoice = Invoice::where('organization_id', $actor->organization_id)->findOrFail($payment->invoice_id); $this->sync($invoice);
            $entry->update(['status' => 'reversed', 'reversal_payment_id' => $reversal->id,
                'reversed_by_user_id' => $actor->id, 'reversed_at' => now(), 'reversal_reason' => $data['reason']]);
            $this->audit($actor, 'delivery.collection_reversed', $entry, ['reason' => $data['reason'], 'original_payment_id' => $payment->id, 'reversal_payment_id' => $reversal->id]);
            return $entry;
        });
    }
}
