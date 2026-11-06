<?php

namespace App\Services\Deliveries;

use App\Models\{Delivery, Invoice, User};
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderCollectionSetup
{
    public const METHODS = ['cash' => 'Cash', 'online' => 'Online', 'both' => 'Cash or online'];

    public function allowed(User $user, string $module): bool
    {
        return $user->canAccessModule($module, 'create')
            && ($user->isSuperAdmin() || $user->isAdminOperations() || $user->isSales() || $user->isSalesRenewals()
                || $user->effective_role === 'admin');
    }

    public function eligibleOperator(User $user): bool
    {
        return $user->is_active && in_array($user->effective_role, [User::ROLE_DELIVERY, User::ROLE_DELIVERY_EXECUTIVE, User::ROLE_OPERATIONS_EXECUTIVE], true)
            && $user->canAccessModule('deliveries', 'read') && $user->canAccessModule('deliveries', 'update');
    }

    public function operators(int $organizationId): Collection
    {
        return User::query()->where('organization_id', $organizationId)->where('is_active', true)
            ->with('assignedRole')->orderBy('name')->get(['id', 'name', 'organization_id', 'role', 'role_id', 'is_active'])
            ->filter(fn (User $user) => $this->eligibleOperator($user));
    }

    public function validate(Request $request, string $module): ?array
    {
        $request->validate(['collection_enabled' => 'nullable|boolean']);
        if (!$request->boolean('collection_enabled')) {
            return null;
        }
        abort_unless($this->allowed($request->user(), $module), 403);
        if ($request->filled('collection_assignee_id') || $request->filled('collection_mode')) {
            throw ValidationException::withMessages([
                'collection_enabled' => 'Choose one collection owner: the delivery operator or the Sales team member.',
            ]);
        }

        return $request->validate([
            'collection_submission_token' => 'required|uuid',
            'collection_amount' => 'required|numeric|decimal:0,2|min:0.01|max:99999999.99',
            'collection_methods' => 'required|in:cash,online,both',
            'collection_instructions' => 'nullable|string|max:1000',
        ]);
    }

    public function claim(array $setup, int $organizationId): void
    {
        try {
            DB::table('order_collection_submissions')->insert([
                'organization_id' => $organizationId,
                'token' => strtolower($setup['collection_submission_token']),
                'created_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'collection_enabled' => 'This order submission was already saved. Check the order list before creating another order.',
            ])->status(409);
        }
    }

    public function attach(array $setup, ?Delivery $task, Invoice $invoice): void
    {
        $operator = $task?->assignedUser;
        if (!$task || $task->assignment_type !== 'delivery_team' || !$operator
            || (int) $operator->organization_id !== (int) $task->organization_id || !$this->eligibleOperator($operator)) {
            throw ValidationException::withMessages([
                'collection_enabled' => 'Choose internal delivery and assign an active Delivery operator with task access.',
            ]);
        }

        if (DeliveryCollectionService::cents($setup['collection_amount']) > DeliveryCollectionService::cents($invoice->refresh()->balance_amount)) {
            throw ValidationException::withMessages([
                'collection_amount' => 'Enter a collection amount no greater than the unpaid invoice balance.',
            ]);
        }

        $task->forceFill([
            'collection_required' => true,
            'collection_amount_to_collect' => $setup['collection_amount'],
            'collection_allowed_methods' => $setup['collection_methods'],
            'collection_instructions' => $setup['collection_instructions'] ?? null,
        ])->save();

        app(DeliveryCollectionService::class)->audit(auth()->user(), 'delivery.collection_assigned', $task, [
            'invoice_id' => $invoice->id,
            'operator_id' => $operator->id,
            'amount' => $task->collection_amount_to_collect,
            'methods' => $task->collection_allowed_methods,
        ]);
    }
}
