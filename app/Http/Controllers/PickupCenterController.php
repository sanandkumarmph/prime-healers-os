<?php

namespace App\Http\Controllers;

use App\Models\Delivery;
use App\Models\Staff;
use App\Models\User;
use App\Services\Metrics\DashboardMetricsService;
use App\Services\NotificationCenterService;
use App\Support\ActivityLogger;
use App\Support\FollowUpManager;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PickupCenterController extends Controller
{
    private function dashboardMetrics(): DashboardMetricsService
    {
        return app(DashboardMetricsService::class);
    }

    private function notifications(): NotificationCenterService
    {
        return app(NotificationCenterService::class);
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', Delivery::class);

        $today = now()->startOfDay();
        $tab = $this->normalizeTab((string) $request->get('tab', 'pickup_requested'));
        $sortBy = trim((string) $request->get('sort_by', 'action_priority'));
        $sortDirection = trim((string) $request->get('sort_dir', 'asc')) === 'desc' ? 'desc' : 'asc';

        $countQuery = $this->applyPickupFilters(
            $this->pickupBaseQuery(false),
            $request
        );

        $counts = $this->dashboardMetrics()->pickupCounts($countQuery, $today);

        $pickups = $this->applyPickupFilters($this->pickupBaseQuery(), $request);
        $this->applyPickupTab($pickups, $tab, $today);

        $this->applyPickupSorting($pickups, $sortBy, $sortDirection, $today);

        $pickups = $pickups
            ->paginate(15)
            ->withQueryString();

        $assignableUsers = $this->assignableUsers();
        $assignableStaff = $this->assignableStaff();
        $hasProofs = Schema::hasTable('delivery_proofs');

        return view('pickups.index', [
            'pickups' => $pickups,
            'counts' => $counts,
            'tab' => $tab,
            'sortBy' => $sortBy,
            'sortDirection' => $sortDirection,
            'today' => $today,
            'assignableUsers' => $assignableUsers,
            'assignableStaff' => $assignableStaff,
            'hasProofs' => $hasProofs,
            'failedReasonOptions' => Delivery::FAILED_ATTEMPT_REASONS,
            'activeFilters' => $this->activeFilters($request),
        ]);
    }

    public function assign(Request $request, Delivery $delivery)
    {
        $delivery = $this->pickupDelivery($delivery);
        $this->authorize('update', $delivery);

        if (in_array($delivery->status, ['completed', 'cancelled'], true)) {
            return back()->with('error', 'Closed pickup tasks cannot be reassigned.');
        }

        $validated = $request->validate([
            'pickup_date' => ['required', 'date'],
            'pickup_time_slot' => ['nullable', 'string', 'max:80'],
            'assignment_target' => ['required', 'string', 'max:40'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        [$assignmentType, $assignedUserId, $assignedStaffId] = $this->resolveAssignmentTarget($validated['assignment_target']);
        $scheduledAt = Carbon::parse($validated['pickup_date'] . ' ' . $this->resolvePickupTimeSlot($validated['pickup_time_slot'] ?? null));

        $update = [
            'status' => 'pending',
            'scheduled_at' => $scheduledAt,
            'assignment_type' => $assignmentType,
            'assigned_user_id' => $assignedUserId,
            'assigned_staff_id' => $assignedStaffId,
            'pickup_status' => $assignedUserId || $assignedStaffId ? 'assigned' : 'scheduled',
            'pickup_time_slot' => $validated['pickup_time_slot'] ?? null,
            'failed_attempt_reason' => null,
            'failed_attempt_note' => null,
            'failed_attempt_at' => null,
        ];

        if (!empty($validated['notes'])) {
            $update['last_pickup_note'] = $validated['notes'];
            $update['last_pickup_note_at'] = now();
            $update['notes'] = trim(collect([
                $delivery->notes,
                'Assigned from Pickup Center: ' . $validated['notes'],
            ])->filter()->implode(' | '));
        }

        $delivery->update($update);

        ActivityLogger::log('pickup.assigned', $delivery->fresh(), [
            'scheduled_at' => $scheduledAt->toDateTimeString(),
            'pickup_time_slot' => $validated['pickup_time_slot'] ?? null,
            'assigned_user_id' => $assignedUserId,
            'assigned_staff_id' => $assignedStaffId,
            'notes' => $validated['notes'] ?? null,
        ], 'Pickup assigned from Pickup Center.');

        return back()->with('success', 'Pickup assigned successfully.');
    }

    public function failedAttempt(Request $request, Delivery $delivery)
    {
        $delivery = $this->pickupDelivery($delivery);
        $this->authorize('update', $delivery);

        if (in_array($delivery->status, ['completed', 'cancelled'], true)) {
            return back()->with('error', 'Closed pickup tasks cannot be marked as failed.');
        }

        $validated = $request->validate([
            'failed_attempt_reason' => ['required', Rule::in(Delivery::FAILED_ATTEMPT_REASONS)],
            'failed_attempt_note' => ['nullable', 'string', 'max:1000'],
            'reschedule_date' => ['nullable', 'date'],
            'pickup_time_slot' => ['nullable', 'string', 'max:80'],
        ]);

        $update = [
            'status' => 'pending',
            'pickup_status' => filled($validated['reschedule_date'] ?? null) ? 'rescheduled' : 'failed_attempt',
            'failed_attempt_reason' => $validated['failed_attempt_reason'],
            'failed_attempt_note' => $validated['failed_attempt_note'] ?? null,
            'failed_attempt_at' => now(),
        ];

        if (!empty($validated['failed_attempt_note'])) {
            $update['last_pickup_note'] = $validated['failed_attempt_note'];
            $update['last_pickup_note_at'] = now();
        }

        if (!empty($validated['reschedule_date'])) {
            $update['rescheduled_from_at'] = $delivery->scheduled_at;
            $update['scheduled_at'] = Carbon::parse($validated['reschedule_date'] . ' ' . $this->resolvePickupTimeSlot($validated['pickup_time_slot'] ?? null));
            $update['pickup_time_slot'] = $validated['pickup_time_slot'] ?? null;
        }

        $delivery->update($update);

        ActivityLogger::log('pickup.failed_attempt', $delivery->fresh(), [
            'failed_attempt_reason' => $validated['failed_attempt_reason'],
            'failed_attempt_note' => $validated['failed_attempt_note'] ?? null,
            'rescheduled_at' => optional($delivery->scheduled_at)->toDateTimeString(),
        ], 'Pickup failed attempt recorded.');

        $delivery->loadMissing(['assignedUser', 'rental.customer', 'rental.businessPartner', 'rental.partnerClient', 'sale.customer', 'sale.businessPartner', 'sale.partnerClient']);
        $this->notifications()->notifyDeliveryStatusChange(
            $delivery,
            $this->notifications()->organizationManagers($this->orgId())->merge($delivery->assignedUser ? collect([$delivery->assignedUser]) : collect()),
            'pickup_failed',
            'Pickup attempt failed',
            trim(($delivery->linkedCustomerName() ?: 'Pickup task') . ' · ' . Delivery::failedAttemptReasonLabel($validated['failed_attempt_reason'])),
            'high'
        );

        app(FollowUpManager::class)->ensureFailedPickupFollowUp(
            $delivery->fresh(['rental.customer', 'rental.businessPartner', 'rental.partnerClient', 'rental.product']),
            $validated['failed_attempt_note'] ?? null,
            !empty($validated['reschedule_date'])
                ? Carbon::parse($validated['reschedule_date'] . ' ' . $this->resolvePickupTimeSlot($validated['pickup_time_slot'] ?? null))
                : Carbon::now()->addHours(2)
        );

        return back()->with('success', !empty($validated['reschedule_date'])
            ? 'Failed attempt recorded and pickup rescheduled.'
            : 'Failed attempt recorded.');
    }

    public function reschedule(Request $request, Delivery $delivery)
    {
        $delivery = $this->pickupDelivery($delivery);
        $this->authorize('update', $delivery);

        if (in_array($delivery->status, ['completed', 'cancelled'], true)) {
            return back()->with('error', 'Closed pickup tasks cannot be rescheduled.');
        }

        $validated = $request->validate([
            'pickup_date' => ['required', 'date'],
            'pickup_time_slot' => ['nullable', 'string', 'max:80'],
            'assignment_target' => ['nullable', 'string', 'max:40'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        [$assignmentType, $assignedUserId, $assignedStaffId] = $this->resolveAssignmentTarget((string) ($validated['assignment_target'] ?? ''));
        $scheduledAt = Carbon::parse($validated['pickup_date'] . ' ' . $this->resolvePickupTimeSlot($validated['pickup_time_slot'] ?? null));

        $update = [
            'status' => 'pending',
            'pickup_status' => 'rescheduled',
            'rescheduled_from_at' => $delivery->scheduled_at,
            'scheduled_at' => $scheduledAt,
            'pickup_time_slot' => $validated['pickup_time_slot'] ?? null,
        ];

        if ($assignedUserId || $assignedStaffId || $assignmentType !== '') {
            $update['assignment_type'] = $assignmentType === '' ? $delivery->assignment_type : $assignmentType;
            $update['assigned_user_id'] = $assignedUserId;
            $update['assigned_staff_id'] = $assignedStaffId;
        }

        if (!empty($validated['reason'])) {
            $update['last_pickup_note'] = $validated['reason'];
            $update['last_pickup_note_at'] = now();
            $update['notes'] = trim(collect([
                $delivery->notes,
                'Rescheduled from Pickup Center: ' . $validated['reason'],
            ])->filter()->implode(' | '));
        }

        $delivery->update($update);

        ActivityLogger::log('pickup.rescheduled', $delivery->fresh(), [
            'scheduled_at' => $scheduledAt->toDateTimeString(),
            'pickup_time_slot' => $validated['pickup_time_slot'] ?? null,
            'reason' => $validated['reason'] ?? null,
            'assigned_user_id' => $assignedUserId,
            'assigned_staff_id' => $assignedStaffId,
        ], 'Pickup rescheduled from Pickup Center.');

        return back()->with('success', 'Pickup rescheduled successfully.');
    }

    public function addNote(Request $request, Delivery $delivery)
    {
        $delivery = $this->pickupDelivery($delivery);
        $this->authorize('update', $delivery);

        $validated = $request->validate([
            'note' => ['required', 'string', 'max:1000'],
        ]);

        $delivery->update([
            'last_pickup_note' => $validated['note'],
            'last_pickup_note_at' => now(),
            'notes' => trim(collect([
                $delivery->notes,
                'Pickup Center note: ' . $validated['note'],
            ])->filter()->implode(' | ')),
        ]);

        ActivityLogger::log('pickup.note_added', $delivery->fresh(), [
            'note' => $validated['note'],
        ], 'Pickup note added from Pickup Center.');

        return back()->with('success', 'Pickup note saved.');
    }

    private function normalizeTab(string $tab): string
    {
        $allowed = ['pickup_requested', 'scheduled_today', 'upcoming', 'overdue', 'failed_attempt', 'picked_up', 'all'];

        return in_array($tab, $allowed, true) ? $tab : 'pickup_requested';
    }

    private function pickupDelivery(Delivery $delivery): Delivery
    {
        abort_if((int) $delivery->organization_id !== $this->orgId(), 404);
        abort_if($delivery->type !== 'pickup', 404);

        return $delivery;
    }

    private function pickupBaseQuery(bool $withRelations = true): Builder
    {
        $query = Delivery::query()
            ->where('organization_id', $this->orgId())
            ->where('type', 'pickup');

        if ($withRelations) {
            $query->with([
                'rental.product',
                'rental.customer',
                'rental.businessPartner',
                'rental.partnerClient',
                'rental.invoice',
                'assignedUser',
                'assignedStaff',
            ]);

            if (Schema::hasTable('delivery_proofs')) {
                $query->withCount('proofs');
            }
        }

        return $this->applyVisibilityScope($query);
    }

    private function applyVisibilityScope(Builder $query): Builder
    {
        return $this->dashboardMetrics()->scopePickupVisibility($query, auth()->user());
    }

    private function applyPickupFilters(Builder $query, Request $request): Builder
    {
        $search = trim((string) $request->get('search', ''));
        $city = trim((string) $request->get('city', ''));
        $staff = trim((string) $request->get('staff', ''));
        $product = trim((string) $request->get('product', ''));
        $paymentPending = trim((string) $request->get('payment_pending', ''));
        $failedReason = trim((string) $request->get('failed_reason', ''));
        $pickupStatus = trim((string) $request->get('pickup_status', ''));
        $fromDate = trim((string) $request->get('from_date', ''));
        $toDate = trim((string) $request->get('to_date', ''));

        if ($search !== '') {
            $query->where(function (Builder $searchQuery) use ($search): void {
                $searchQuery->where('id', 'like', '%' . $search . '%')
                    ->orWhere('last_pickup_note', 'like', '%' . $search . '%')
                    ->orWhere('failed_attempt_note', 'like', '%' . $search . '%')
                    ->orWhereHas('rental', function (Builder $rentalQuery) use ($search): void {
                        $rentalQuery->where('id', 'like', '%' . $search . '%')
                            ->orWhere('customer_name', 'like', '%' . $search . '%')
                            ->orWhere('phone', 'like', '%' . $search . '%')
                            ->orWhereHas('customer', fn (Builder $customerQuery) => $customerQuery
                                ->where('name', 'like', '%' . $search . '%')
                                ->orWhere('phone', 'like', '%' . $search . '%'))
                            ->orWhereHas('businessPartner', fn (Builder $partnerQuery) => $partnerQuery
                                ->where('business_name', 'like', '%' . $search . '%')
                                ->orWhere('phone', 'like', '%' . $search . '%'))
                            ->orWhereHas('partnerClient', fn (Builder $clientQuery) => $clientQuery
                                ->where('client_name', 'like', '%' . $search . '%')
                                ->orWhere('phone', 'like', '%' . $search . '%')
                                ->orWhere('address', 'like', '%' . $search . '%'))
                            ->orWhereHas('product', fn (Builder $productQuery) => $productQuery
                                ->where('name', 'like', '%' . $search . '%')
                                ->orWhere('model_name', 'like', '%' . $search . '%'))
                            ->orWhereHas('activeRentalAssets.asset', fn (Builder $assetQuery) => $assetQuery
                                ->where('serial_number', 'like', '%' . $search . '%'));
                    })
                    ->orWhereHas('assignedUser', fn (Builder $userQuery) => $userQuery->where('name', 'like', '%' . $search . '%'))
                    ->orWhereHas('assignedStaff', fn (Builder $staffQuery) => $staffQuery->where('name', 'like', '%' . $search . '%'))
                    ->orWhere('third_party_name', 'like', '%' . $search . '%');
            });
        }

        if ($city !== '') {
            $query->whereHas('rental', function (Builder $rentalQuery) use ($city): void {
                $rentalQuery
                    ->whereHas('customer', fn (Builder $customerQuery) => $customerQuery->where('city', $city))
                    ->orWhereHas('partnerClient', fn (Builder $clientQuery) => $clientQuery->where('city', $city));
            });
        }

        if ($staff !== '') {
            if (str_starts_with($staff, 'user:')) {
                $query->where('assigned_user_id', (int) substr($staff, 5));
            } elseif (str_starts_with($staff, 'staff:')) {
                $query->where('assigned_staff_id', (int) substr($staff, 6));
            } elseif ($staff === 'unassigned') {
                $query->whereNull('assigned_user_id')->whereNull('assigned_staff_id');
            }
        }

        if ($product !== '') {
            $query->whereHas('rental.product', function (Builder $productQuery) use ($product): void {
                $productQuery->where('name', $product);
            });
        }

        if ($paymentPending === '1') {
            $query->whereHas('rental.invoice', function (Builder $invoiceQuery): void {
                $invoiceQuery
                    ->whereNotIn('payment_status', ['paid', 'cancelled'])
                    ->where('balance_amount', '>', 0);
            });
        }

        if ($failedReason !== '') {
            $query->where('failed_attempt_reason', $failedReason);
        }

        if ($pickupStatus !== '' && in_array($pickupStatus, Delivery::PICKUP_STATUSES, true)) {
            $query->where('pickup_status', $pickupStatus);
        }

        if ($fromDate !== '') {
            $query->whereDate('scheduled_at', '>=', $fromDate);
        }

        if ($toDate !== '') {
            $query->whereDate('scheduled_at', '<=', $toDate);
        }

        return $query;
    }

    private function applyPickupTab(Builder $query, string $tab, Carbon $today): Builder
    {
        $openScheduled = function (Builder $openQuery) use ($today): void {
            $openQuery->whereIn('status', ['pending', 'in_progress'])
                ->where(function (Builder $statusQuery): void {
                    $statusQuery->whereNull('pickup_status')
                        ->orWhereNotIn('pickup_status', ['failed_attempt', 'cancelled', 'picked_up']);
                });
        };

        return match ($tab) {
            'pickup_requested' => $query->where('status', 'pending')->whereIn('pickup_status', ['requested', 'scheduled', 'assigned', 'rescheduled']),
            'scheduled_today' => $query->where($openScheduled)->whereDate('scheduled_at', $today),
            'upcoming' => $query->where($openScheduled)->whereDate('scheduled_at', '>', $today),
            'overdue' => $query->where($openScheduled)->whereDate('scheduled_at', '<', $today),
            'failed_attempt' => $query->where('pickup_status', 'failed_attempt'),
            'picked_up' => $query->where(function (Builder $pickedUpQuery): void {
                $pickedUpQuery->where('status', 'completed')
                    ->orWhere('pickup_status', 'picked_up');
            }),
            default => $query,
        };
    }

    private function applyPickupSorting(Builder $query, string $sortBy, string $sortDirection, Carbon $today): void
    {
        $direction = $sortDirection === 'desc' ? 'desc' : 'asc';
        $todayDate = $today->toDateString();

        if ($sortBy === 'schedule_date') {
            $query->orderBy('scheduled_at', $direction);

            return;
        }

        if ($sortBy === 'staff') {
            $query->leftJoin('users as pickup_users', 'deliveries.assigned_user_id', '=', 'pickup_users.id')
                ->leftJoin('staff as pickup_staff', 'deliveries.assigned_staff_id', '=', 'pickup_staff.id')
                ->orderByRaw('COALESCE(pickup_users.name, pickup_staff.name, deliveries.third_party_name, \'Unassigned\') ' . $direction)
                ->select('deliveries.*');

            return;
        }

        if ($sortBy === 'recently_updated') {
            $query->orderBy('updated_at', $direction);

            return;
        }

        $query
            ->orderByRaw("
                CASE
                    WHEN deliveries.status = 'cancelled' THEN 8
                    WHEN deliveries.status = 'completed' OR deliveries.pickup_status = 'picked_up' THEN 7
                    WHEN deliveries.pickup_status = 'failed_attempt' THEN 4
                    WHEN deliveries.status = 'in_progress' THEN 3
                    WHEN deliveries.status = 'pending' AND DATE(deliveries.scheduled_at) < ? THEN 0
                    WHEN deliveries.status = 'pending' AND DATE(deliveries.scheduled_at) = ? THEN 1
                    WHEN deliveries.status = 'pending' AND deliveries.scheduled_at IS NULL THEN 2
                    WHEN deliveries.status = 'pending' AND DATE(deliveries.scheduled_at) > ? THEN 5
                    ELSE 6
                END " . ($direction === 'desc' ? 'desc' : 'asc'),
                [$todayDate, $todayDate, $todayDate]
            )
            ->orderBy('scheduled_at', 'asc')
            ->orderByDesc('id');
    }

    private function assignableUsers()
    {
        return User::query()
            ->with('assignedRole')
            ->where('organization_id', $this->orgId())
            ->when(Schema::hasColumn('users', 'is_active'), fn (Builder $query) => $query->where('is_active', true))
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user) => $this->userAssignableForInternalPickup($user))
            ->values();
    }

    private function assignableStaff()
    {
        return Staff::forOrganization($this->orgId())
            ->eligibleForAssignments()
            ->orderBy('name')
            ->get(['id', 'name', 'assignment_role', 'role']);
    }

    private function resolveAssignmentTarget(string $assignmentTarget): array
    {
        $assignmentTarget = trim($assignmentTarget);

        if (str_starts_with($assignmentTarget, 'user:')) {
            $userId = (int) substr($assignmentTarget, 5);

            abort_unless($this->userAssignableForInternalPickupId($userId), 422, 'Choose a delivery or operations executive for internal pickup.');

            return ['delivery_team', $userId, null];
        }

        if (str_starts_with($assignmentTarget, 'staff:')) {
            return ['vendor', null, (int) substr($assignmentTarget, 6)];
        }

        return ['delivery_team', null, null];
    }

    private function userAssignableForInternalPickupId(int $userId): bool
    {
        $user = User::query()
            ->with('assignedRole')
            ->where('organization_id', $this->orgId())
            ->when(Schema::hasColumn('users', 'is_active'), fn (Builder $query) => $query->where('is_active', true))
            ->find($userId);

        return $user ? $this->userAssignableForInternalPickup($user) : false;
    }

    private function userAssignableForInternalPickup(User $user): bool
    {
        $roleSignals = collect([
            $user->effective_role,
            $user->role,
            $user->assignedRole?->slug,
            $user->assignedRole?->name,
        ])->filter()->map(function ($value) {
            return Str::of((string) $value)
                ->lower()
                ->replace([' ', '-'], '_')
                ->value();
        });

        $blocked = [
            User::ROLE_SUPER_ADMIN,
            User::ROLE_ADMIN_OPERATIONS,
            'admin',
            User::ROLE_FINANCE,
            User::ROLE_SALES,
            User::ROLE_SALES_RENEWALS,
        ];

        if ($roleSignals->contains(fn ($value) => in_array($value, $blocked, true))) {
            return false;
        }

        return $roleSignals->contains(fn ($value) => in_array($value, [
            User::ROLE_DELIVERY_EXECUTIVE,
            User::ROLE_OPERATIONS_EXECUTIVE,
            User::ROLE_DELIVERY,
            'delivery_staff',
            'pickup_staff',
        ], true));
    }

    private function resolvePickupTimeSlot(?string $slot): string
    {
        return match (trim((string) $slot)) {
            '09:00-12:00' => '10:00:00',
            '12:00-15:00' => '13:00:00',
            '15:00-18:00' => '16:00:00',
            '18:00-20:00' => '18:30:00',
            default => '10:00:00',
        };
    }

    private function activeFilters(Request $request): array
    {
        $filters = [];

        foreach ([
            'search' => 'Search',
            'city' => 'City',
            'staff' => 'Staff',
            'product' => 'Product',
            'pickup_status' => 'Status',
            'failed_reason' => 'Failed',
        ] as $key => $label) {
            $value = trim((string) $request->get($key, ''));

            if ($value !== '') {
                $filters[] = $label . ': ' . $value;
            }
        }

        if ($request->get('payment_pending') === '1') {
            $filters[] = 'Payment Pending';
        }

        return $filters;
    }

    private function orgId(): int
    {
        return (int) auth()->user()->organization_id;
    }
}
