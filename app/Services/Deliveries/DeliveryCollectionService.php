<?php

namespace App\Services\Deliveries;

use App\Models\{ActivityLog, Delivery, DeliveryCollection, DeliveryProof, Invoice, Payment, Rental, Sale, User};
use App\Services\Finance\{OrganizationFinancialTransaction, PaymentSyncService};
use Illuminate\Support\Facades\{DB, Gate, Validator};
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
            $task->assigned_user_id, $task->status, $task->collection_required, $task->collection_amount_to_collect,
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
                'payment_method' => $entry->method, 'notes' => 'Delivery collection #'.$entry->id.'. '.$entry->reference],
            $invoice->rental_id ? (int) $invoice->rental_id : null, true);
        $entry->update(['payment_id' => $payment->id]);
        $this->sync($invoice);
    }

    public function record(User $snapshot, int $taskId, array $input): DeliveryCollection
    {
        $data = Validator::make($input, ['submission_token' => 'required|uuid', 'version' => 'required|string|size:64',
            'amount' => 'required|numeric|decimal:0,2|min:0.01|max:99999999.99',
            'method' => 'required|in:cash,upi,bank_transfer,card', 'reference' => 'nullable|string|max:255',
            'proof_id' => 'nullable|integer', 'note' => 'nullable|string|max:1000', 'confirmed' => 'accepted'])->validate();

        return app(OrganizationFinancialTransaction::class)->run((int) $snapshot->organization_id, function () use ($snapshot, $taskId, $data) {
            $actor = $snapshot->fresh();
            $task = $this->task($actor, $taskId, true);
            $token = strtolower($data['submission_token']);
            $hash = hash('sha256', json_encode([$taskId, $actor->id, self::cents($data['amount']), $data['method'],
                trim($data['reference'] ?? ''), (int) ($data['proof_id'] ?? 0), trim($data['note'] ?? '')], JSON_THROW_ON_ERROR));
            $prior = DeliveryCollection::where('organization_id', $actor->organization_id)->where('submission_token', $token)->first();
            if ($prior) {
                if (!hash_equals($prior->request_hash, $hash)) $this->fail('That submission token already belongs to a different report. Check saved history.');
                return $prior;
            }
            if (!$task->collection_required || !in_array($task->status, ['in_progress', 'completed'], true)) $this->fail('Start an authorised collection task before recording a receipt.');
            $invoice = $this->invoice($task);
            $invoice->syncFinancialStatus();
            if (!hash_equals($this->version($task, $invoice), $data['version'])) $this->fail('The task or invoice changed. Refresh and review its saved history before collecting.');
            app(PaymentSyncService::class)->assertPayable($invoice);
            $entries = DeliveryCollection::where('organization_id', $actor->organization_id)->where('delivery_id', $taskId)->whereNotIn('status', ['rejected','reversed'])->get();
            $authorized = self::cents($task->collection_amount_to_collect);
            if (self::cents($data['amount']) + $entries->sum(fn ($row) => self::cents($row->amount)) > $authorized) $this->fail('This amount exceeds the remaining collection authorised for the task.');
            $reserved = DeliveryCollection::where('organization_id', $actor->organization_id)->where('invoice_id', $invoice->id)->where('status', 'pending_review')->sum('amount');
            if (self::cents($data['amount']) > self::cents($invoice->balance_amount) - self::cents($reserved)) $this->fail('This amount exceeds the invoice balance after pending online reports.');
            $proof = !empty($data['proof_id']) ? DeliveryProof::where('organization_id', $actor->organization_id)->where('delivery_id', $taskId)
                ->where('proof_type', DeliveryProof::TYPE_COLLECTION)->findOrFail($data['proof_id']) : null;
            if ($proof && DeliveryCollection::where('organization_id', $actor->organization_id)->where('proof_id', $proof->id)->exists()) {
                $this->fail('This payment proof already belongs to a saved collection report. Review that report before recording another receipt.');
            }
            if ($data['method'] !== 'cash' && trim($data['reference'] ?? '') === '' && !$proof) $this->fail('Provide a transaction reference or choose an existing private payment proof. Accounts must verify online reports.');
            $entry = DeliveryCollection::create(['organization_id' => $actor->organization_id, 'delivery_id' => $taskId,
                'invoice_id' => $invoice->id, 'collector_user_id' => $actor->id, 'submission_token' => $token, 'request_hash' => $hash,
                'amount' => $data['amount'], 'method' => $data['method'], 'status' => $data['method'] === 'cash' ? 'cash_received' : 'pending_review',
                'reference' => trim($data['reference'] ?? ''), 'proof_id' => $proof?->id, 'note' => trim($data['note'] ?? ''), 'received_at' => now()]);
            if ($entry->method === 'cash') $this->post($actor, $entry, $invoice);
            $this->audit($actor, 'delivery.collection_recorded', $entry, ['amount' => $entry->amount, 'method' => $entry->method, 'payment_id' => $entry->payment_id]);
            return $entry;
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
