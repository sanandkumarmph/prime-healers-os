<?php

namespace App\Services\Deliveries;

use App\Models\{DeliveryCashHandover, DeliveryCollection, User};
use App\Services\Finance\OrganizationFinancialTransaction;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class DeliveryCashHandoverService
{
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['handover' => $message]);
    }

    private function locked(User $actor, int $id): DeliveryCollection
    {
        abort_unless(app(DeliveryCollectionService::class)->canViewCash($actor), 403);
        $collection = DeliveryCollection::where('organization_id', $actor->organization_id)->lockForUpdate()->findOrFail($id);
        if ($collection->status !== 'cash_received') $this->fail('Only a posted cash collection can be handed over.');
        $collection->setRelation('handovers', $collection->handovers()->orderBy('id')->lockForUpdate()->get());
        return $collection;
    }

    public function submit(User $snapshot, int $collectionId, array $input): DeliveryCashHandover
    {
        $data = Validator::make($input, ['submission_token' => 'required|uuid',
            'amount' => 'required|numeric|decimal:0,2|min:0.01|max:99999999.99', 'confirmed' => 'accepted'])->validate();
        return app(OrganizationFinancialTransaction::class)->run((int) $snapshot->organization_id, function () use ($snapshot, $collectionId, $data) {
            $actor = $snapshot->fresh(); $collection = $this->locked($actor, $collectionId);
            abort_unless((int) $collection->collector_user_id === (int) $actor->id, 403);
            $prior = DeliveryCashHandover::where('organization_id', $actor->organization_id)->where('submission_token', strtolower($data['submission_token']))->first();
            $amount = DeliveryCollectionService::cents($data['amount']);
            if ($prior) {
                if ((int) $prior->collection_id !== $collectionId || (int) $prior->collector_user_id !== (int) $actor->id
                    || DeliveryCollectionService::cents($prior->submitted_amount) !== $amount || !in_array($prior->status, ['pending','confirmed'], true)) {
                    $this->fail('This token belongs to a different or corrected handover. Check its history.');
                }
                return $prior;
            }
            if ($amount > $collection->cashAmounts()['available']) $this->fail('Amount exceeds cash available for handover. Pending submissions reserve their amount.');
            $handover = DeliveryCashHandover::create(['organization_id' => $actor->organization_id, 'collection_id' => $collectionId,
                'collector_user_id' => $actor->id, 'submission_token' => strtolower($data['submission_token']),
                'submitted_amount' => $amount / 100, 'submitted_at' => now(), 'status' => 'pending']);
            app(DeliveryCollectionService::class)->audit($actor, 'cash.handover_submitted', $handover, ['submitted_amount' => $handover->submitted_amount]);
            return $handover;
        });
    }

    public function confirm(User $snapshot, int $id, array $input): DeliveryCashHandover
    {
        $data = Validator::make($input, ['confirmation_token' => 'required|uuid', 'received_amount' => 'required|numeric|decimal:0,2|min:0|max:99999999.99',
            'reason' => 'nullable|string|max:1000', 'confirmed' => 'accepted'])->validate();
        return app(OrganizationFinancialTransaction::class)->run((int) $snapshot->organization_id, function () use ($snapshot, $id, $data) {
            $actor = $snapshot->fresh(); $service = app(DeliveryCollectionService::class);
            abort_unless($service->canReceive($actor), 403);
            $handover = DeliveryCashHandover::where('organization_id', $actor->organization_id)->findOrFail($id);
            $collection = $this->locked($actor, $handover->collection_id);
            $handover = $collection->handovers->firstWhere('id', $id);
            abort_unless((int) $handover->collector_user_id !== (int) $actor->id, 403);
            $amount = DeliveryCollectionService::cents($data['received_amount']);
            $submitted = DeliveryCollectionService::cents($handover->submitted_amount);
            $reason = $amount === $submitted ? null : trim($data['reason'] ?? '');
            if ($handover->status === 'confirmed') {
                if ($handover->confirmation_token !== strtolower($data['confirmation_token']) || (int) $handover->receiver_user_id !== (int) $actor->id
                    || DeliveryCollectionService::cents($handover->received_amount) !== $amount || $handover->discrepancy_reason !== $reason) $this->fail('This handover is already confirmed. Check its saved receipt.');
                return $handover;
            }
            if ($handover->status !== 'pending') $this->fail('This handover was corrected and cannot be confirmed.');
            if ($amount > $submitted) $this->fail('Confirm only the cash actually received, up to the submitted amount.');
            if ($amount < $submitted && mb_strlen($reason) < 5) $this->fail('Explain the difference between submitted and received cash.');
            if (DeliveryCashHandover::where('organization_id', $actor->organization_id)->where('confirmation_token', strtolower($data['confirmation_token']))->exists()) $this->fail('This confirmation token has already been used.');
            $handover->update(['confirmation_token' => strtolower($data['confirmation_token']), 'received_amount' => $amount / 100,
                'receiver_user_id' => $actor->id, 'confirmed_at' => now(), 'discrepancy_reason' => $reason, 'status' => 'confirmed']);
            $service->audit($actor, 'cash.handover_received', $handover, ['submitted_amount' => $handover->submitted_amount,
                'received_amount' => $handover->received_amount, 'reason' => $reason]);
            return $handover;
        });
    }

    public function correct(User $snapshot, int $id, array $input): DeliveryCashHandover
    {
        $data = Validator::make($input, ['action' => 'required|in:cancel,reverse', 'reason' => 'required|string|min:5|max:1000', 'confirmed' => 'accepted'])->validate();
        return app(OrganizationFinancialTransaction::class)->run((int) $snapshot->organization_id, function () use ($snapshot, $id, $data) {
            $actor = $snapshot->fresh(); $service = app(DeliveryCollectionService::class);
            $handover = DeliveryCashHandover::where('organization_id', $actor->organization_id)->findOrFail($id);
            $collection = $this->locked($actor, $handover->collection_id);
            $handover = $collection->handovers->firstWhere('id', $id);
            $next = $data['action'] === 'cancel' ? 'cancelled' : 'reversed';
            if ($next === 'cancelled') {
                abort_unless((int) $actor->id === (int) $handover->collector_user_id, 403);
                if (!in_array($handover->status, ['pending','cancelled'], true)) $this->fail('Only an unconfirmed handover can be cancelled.');
            } else {
                abort_unless($service->canReceive($actor) && (int) $actor->id !== (int) $handover->collector_user_id, 403);
                if (!in_array($handover->status, ['confirmed','reversed'], true)) $this->fail('Only a confirmed cash receipt can be reversed.');
            }
            if ($handover->status === $next) return $handover;
            $handover->update(['status' => $next, 'corrected_by_user_id' => $actor->id, 'corrected_at' => now(), 'correction_reason' => $data['reason']]);
            $service->audit($actor, 'cash.handover_'.$next, $handover, ['reason' => $data['reason'],
                'received_amount' => $handover->received_amount, 'submitted_amount' => $handover->submitted_amount]);
            return $handover;
        });
    }
}
