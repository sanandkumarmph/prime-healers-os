<?php

namespace App\Http\Controllers;

use App\Models\Delivery;
use App\Models\Rental;
use App\Models\RentalReminderLog;
use App\Models\Role;
use App\Models\Staff;
use App\Models\User;
use App\Services\Metrics\DashboardMetricsService;
use App\Support\ActivityLogger;
use App\Support\WhatsAppHelper;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class RenewalCenterController extends Controller
{
    private function dashboardMetrics(): DashboardMetricsService
    {
        return app(DashboardMetricsService::class);
    }

    private function orgId(): int
    {
        return (int) auth()->user()->organization_id;
    }

    private function ensureRentalAccess(): void
    {
        $user = auth()->user();

        if (!$user || !$user->canManageRentals()) {
            abort(403, 'Unauthorized. Only admin or sales can manage rentals.');
        }
    }

    private function hasRentalRenewalsTable(): bool
    {
        return Schema::hasTable('rental_renewals');
    }

    private function hasReminderLogsTable(): bool
    {
        return Schema::hasTable('rental_reminder_logs');
    }

    private function hasDeliveryAssignmentColumns(): bool
    {
        return Schema::hasColumn('deliveries', 'assigned_user_id')
            && Schema::hasColumn('deliveries', 'assigned_staff_id');
    }

    private function hasBusinessPartnerTables(): bool
    {
        return Schema::hasTable('business_partners')
            && Schema::hasTable('partner_clients')
            && Schema::hasColumn('rentals', 'customer_type')
            && Schema::hasColumn('rentals', 'business_partner_id')
            && Schema::hasColumn('rentals', 'partner_client_id');
    }

    private function assignableUsers(): Collection
    {
        $allowedRoles = ['delivery', 'pickup'];
        $allowedRoleIds = collect();

        if (Schema::hasColumn('users', 'role_id')) {
            $allowedRoleIds = Role::query()
                ->whereIn('slug', $allowedRoles)
                ->pluck('id');
        }

        return User::query()
            ->where('organization_id', $this->orgId())
            ->when(Schema::hasColumn('users', 'is_active'), fn (Builder $query) => $query->where('is_active', true))
            ->where(function (Builder $query) use ($allowedRoles, $allowedRoleIds) {
                $query->whereIn('role', $allowedRoles);

                if ($allowedRoleIds->isNotEmpty()) {
                    $query->orWhereIn('role_id', $allowedRoleIds);
                }
            })
            ->orderBy('name')
            ->get(['id', 'name', 'role']);
    }

    private function assignableStaffMembers(): Collection
    {
        return Staff::query()
            ->where('organization_id', $this->orgId())
            ->where('status', 'active')
            ->when(Schema::hasColumn('staff', 'is_assignment_enabled'), fn (Builder $query) => $query->where('is_assignment_enabled', true))
            ->orderBy('name')
            ->get(['id', 'name', 'assignment_role']);
    }

    private function baseRenewalQuery(bool $includeRelations = true): Builder
    {
        $query = Rental::query()
            ->forOrganization($this->orgId())
            ->whereNotIn('status', ['returned', 'cancelled'])
            ->lifecycleStarted()
            ->select('rentals.*');

        if ($this->hasReminderLogsTable()) {
            $query->selectSub(
                RentalReminderLog::query()
                    ->selectRaw('MAX(sent_at)')
                    ->whereColumn('rental_id', 'rentals.id')
                    ->where('organization_id', $this->orgId()),
                'latest_reminder_sent_at'
            );
        }

        if ($includeRelations) {
            $with = [
                'product:id,name,brand,model_name',
                'invoice:id,organization_id,rental_id,invoice_number,payment_status,status,balance_amount,total_amount,due_date',
                'deliveryRecord:id,organization_id,rental_id,type,status,assigned_user_id,assigned_staff_id,scheduled_at,completed_at,notes',
                'deliveryRecord.assignedUser:id,name',
                'deliveryRecord.assignedStaff:id,name,assignment_role',
                'pickupRecord:id,organization_id,rental_id,type,status,assigned_user_id,assigned_staff_id,scheduled_at,completed_at,notes',
                'pickupRecord.assignedUser:id,name',
                'pickupRecord.assignedStaff:id,name,assignment_role',
                'customer' => fn ($query) => $query->select(\App\Models\Customer::relationSelectColumns()),
            ];

            if ($this->hasBusinessPartnerTables()) {
                $with['businessPartner'] = fn ($query) => $query->select(\App\Models\BusinessPartner::relationSelectColumns());
                $with['partnerClient'] = fn ($query) => $query->select(\App\Models\PartnerClient::relationSelectColumns());
            }

            if ($this->hasRentalRenewalsTable()) {
                $with[] = 'renewals.invoice:id,organization_id,invoice_number,payment_status,status,balance_amount,total_amount,rental_id';
                $with[] = 'renewals.payment:id,organization_id,amount,payment_date';
                $with[] = 'renewals.renewedBy:id,name';
            }

            $query->with($with);
        }

        return $query;
    }

    private function applySearchAndFilters(Builder $query, Request $request): Builder
    {
        $search = trim((string) $request->string('search'));
        $city = trim((string) $request->string('city'));
        $productId = $request->integer('product_id');
        $staff = trim((string) $request->string('staff'));
        $overdueDays = $request->integer('overdue_days');
        $paymentStatus = trim((string) $request->string('payment_status'));
        $deliveryStatus = trim((string) $request->string('delivery_status'));
        $pickupStatus = trim((string) $request->string('pickup_status'));

        if ($search !== '') {
            $query->where(function (Builder $innerQuery) use ($search) {
                $innerQuery->where('rentals.id', $search)
                    ->orWhere('customer_name', 'like', '%' . $search . '%')
                    ->orWhere('phone', 'like', '%' . $search . '%')
                    ->orWhereHas('product', function (Builder $productQuery) use ($search) {
                        $productQuery
                            ->where('name', 'like', '%' . $search . '%')
                            ->orWhere('brand', 'like', '%' . $search . '%')
                            ->orWhere('model_name', 'like', '%' . $search . '%');
                    })
                    ->orWhereHas('customer', function (Builder $customerQuery) use ($search) {
                        $customerQuery
                            ->where('name', 'like', '%' . $search . '%')
                            ->orWhere('phone', 'like', '%' . $search . '%');
                    });

                if ($this->hasBusinessPartnerTables()) {
                    $innerQuery
                        ->orWhereHas('businessPartner', function (Builder $partnerQuery) use ($search) {
                            $partnerQuery
                                ->where('business_name', 'like', '%' . $search . '%')
                                ->orWhere('contact_person', 'like', '%' . $search . '%')
                                ->orWhere('phone', 'like', '%' . $search . '%');
                        })
                        ->orWhereHas('partnerClient', function (Builder $clientQuery) use ($search) {
                            $clientQuery
                                ->where('client_name', 'like', '%' . $search . '%')
                                ->orWhere('phone', 'like', '%' . $search . '%');
                        });
                }
            });
        }

        if ($city !== '') {
            $query->where(function (Builder $innerQuery) use ($city) {
                $innerQuery->whereHas('customer', fn (Builder $customerQuery) => $customerQuery->where('city', 'like', '%' . $city . '%'));

                if ($this->hasBusinessPartnerTables()) {
                    $innerQuery->orWhereHas('partnerClient', fn (Builder $clientQuery) => $clientQuery->where('city', 'like', '%' . $city . '%'));
                }
            });
        }

        if ($productId > 0) {
            $query->where('product_id', $productId);
        }

        if ($staff !== '') {
            $query->where(function (Builder $innerQuery) use ($staff) {
                $this->applyStaffFilter($innerQuery, $staff, 'pickupRecord');
                $this->applyStaffFilter($innerQuery, $staff, 'deliveryRecord', true);
            });
        }

        if ($overdueDays > 0) {
            $query->whereDate('end_date', '<=', Carbon::today()->copy()->subDays($overdueDays));
        }

        if ($paymentStatus !== '') {
            $query->where(function (Builder $innerQuery) use ($paymentStatus) {
                $innerQuery->whereHas('invoice', fn (Builder $invoiceQuery) => $invoiceQuery->where('payment_status', $paymentStatus));

                if ($this->hasRentalRenewalsTable()) {
                    $innerQuery->orWhereHas('renewals.invoice', fn (Builder $invoiceQuery) => $invoiceQuery->where('payment_status', $paymentStatus));
                }
            });
        }

        if ($deliveryStatus !== '') {
            $query->whereHas('deliveryRecord', fn (Builder $deliveryQuery) => $deliveryQuery->where('status', $deliveryStatus));
        }

        if ($pickupStatus !== '') {
            $query->whereHas('pickupRecord', fn (Builder $pickupQuery) => $pickupQuery->where('status', $pickupStatus));
        }

        return $query;
    }

    private function applyStaffFilter(Builder $query, string $staff, string $relation, bool $useOr = false): void
    {
        $method = $useOr ? 'orWhereHas' : 'whereHas';

        if (str_starts_with($staff, 'user:') && $this->hasDeliveryAssignmentColumns()) {
            $userId = (int) substr($staff, 5);
            $query->{$method}($relation, fn (Builder $deliveryQuery) => $deliveryQuery->where('assigned_user_id', $userId));
            return;
        }

        if (str_starts_with($staff, 'staff:') && $this->hasDeliveryAssignmentColumns()) {
            $staffId = (int) substr($staff, 6);
            $query->{$method}($relation, fn (Builder $deliveryQuery) => $deliveryQuery->where('assigned_staff_id', $staffId));
        }
    }

    private function applyTab(Builder $query, string $tab, Carbon $today): Builder
    {
        return match ($tab) {
            'due_today' => $query->whereDate('end_date', $today),
            'next_7_days' => $query->whereBetween('end_date', [$today->copy()->addDay(), $today->copy()->addDays(7)]),
            'overdue' => $query->whereDate('end_date', '<', $today),
            'awaiting_confirmation' => $query
                ->whereDate('end_date', '<=', $today->copy()->addDays(7))
                ->whereDoesntHave('pickupRecord', fn (Builder $pickupQuery) => $pickupQuery->whereIn('status', ['pending', 'in_progress']))
                ->when($this->hasReminderLogsTable(), fn (Builder $reminderQuery) => $reminderQuery->whereHas('reminderLogs')),
            'pickup_requested' => $query->whereHas('pickupRecord', fn (Builder $pickupQuery) => $pickupQuery->whereIn('status', ['pending', 'in_progress'])),
            'renewed' => $query->when(
                $this->hasRentalRenewalsTable(),
                fn (Builder $renewedQuery) => $renewedQuery->whereHas('renewals', fn (Builder $renewalQuery) => $renewalQuery->whereDate('created_at', '>=', $today->copy()->subDays(30))),
                fn (Builder $renewedQuery) => $renewedQuery->whereRaw('1 = 0')
            ),
            default => $query,
        };
    }

    private function applySorting(Builder $query, string $sortBy, string $sortDir, Carbon $today): Builder
    {
        $direction = strtolower($sortDir) === 'desc' ? 'desc' : 'asc';

        if ($sortBy === 'action_priority') {
            $priorityDirection = $direction === 'desc' ? 'desc' : 'asc';

            return $query
                ->orderByRaw(
                    "CASE
                        WHEN EXISTS (
                            SELECT 1 FROM deliveries
                            WHERE deliveries.organization_id = rentals.organization_id
                              AND deliveries.rental_id = rentals.id
                              AND deliveries.type = 'pickup'
                              AND deliveries.status IN ('pending', 'in_progress')
                        ) THEN 5
                        WHEN date(rentals.end_date) < date(?) THEN 1
                        WHEN date(rentals.end_date) = date(?) THEN 2
                        WHEN rentals.end_date IS NULL THEN 3
                        WHEN date(rentals.end_date) > date(?) AND date(rentals.end_date) <= date(?) THEN 4
                        ELSE 6
                    END {$priorityDirection}",
                    [$today->toDateString(), $today->toDateString(), $today->toDateString(), $today->copy()->addDays(7)->toDateString()]
                )
                ->orderBy('end_date')
                ->orderByDesc('updated_at');
        }

        return match ($sortBy) {
            'renewal_date' => $query->orderBy('end_date', $direction),
            'status' => $query->orderBy('status', $direction)->orderBy('end_date'),
            'customer' => $query->orderBy('customer_name', $direction),
            'product' => $query->whereHas('product')->orderBy('product_id', $direction),
            'amount' => $query->orderBy('rental_amount', $direction),
            'updated_at' => $query->orderBy('updated_at', $direction),
            default => $query->orderBy('end_date')->orderByDesc('updated_at'),
        };
    }

    private function reminderMessageFor(Rental $rental): string
    {
        $amount = number_format((float) ($rental->rental_amount ?? 0), 2);
        $renewalDate = optional($rental->end_date)->format('d M Y') ?: 'N/A';
        $reminderTo = $rental->reminderContactName();
        $serviceClient = $rental->deliveryContactName();
        $product = $rental->product->name ?? 'Rental item';

        return trim(
            "Dear {$reminderTo},\n\n"
            . "Rental renewal is due for:\n\n"
            . "Client: {$serviceClient}\n"
            . "Product: {$product}\n"
            . "Renewal Date: {$renewalDate}\n"
            . "Amount: ₹{$amount}\n\n"
            . "Please confirm renewal or pickup."
        );
    }

    private function currentInvoiceFor(Rental $rental)
    {
        if ($this->hasRentalRenewalsTable() && $rental->relationLoaded('renewals')) {
            $renewalInvoice = $rental->renewals
                ->map(fn ($renewal) => $renewal->invoice)
                ->filter()
                ->sortByDesc('id')
                ->first();

            if ($renewalInvoice) {
                return $renewalInvoice;
            }
        }

        return $rental->invoice;
    }

    private function tabCounts(Request $request, Carbon $today): array
    {
        $baseQuery = $this->applySearchAndFilters($this->baseRenewalQuery(false), $request);

        return $this->dashboardMetrics()->renewalCounts($baseQuery, $today);
    }

    private function productsForFilter(): Collection
    {
        return \App\Models\Product::query()
            ->where('organization_id', $this->orgId())
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    public function index(Request $request)
    {
        $this->ensureRentalAccess();

        $today = Carbon::today();
        $tab = (string) $request->get('tab', 'due_today');
        $sortBy = (string) $request->get('sort_by', 'action_priority');
        $sortDir = (string) $request->get('sort_dir', 'asc');

        $query = $this->applySearchAndFilters($this->baseRenewalQuery(), $request);
        $query = $this->applyTab($query, $tab, $today);
        $query = $this->applySorting($query, $sortBy, $sortDir, $today);

        /** @var LengthAwarePaginator $rentals */
        $rentals = $query->paginate(15)->withQueryString();

        $rentals->getCollection()->transform(function (Rental $rental) {
            $rental->setAttribute('renewal_reminder_message', $this->reminderMessageFor($rental));
            $rental->setAttribute('renewal_reminder_number', WhatsAppHelper::normalizeNumber($rental->reminderContactPhone()));
            $rental->setAttribute('renewal_invoice_target_id', optional($this->currentInvoiceFor($rental))->id);

            return $rental;
        });

        $counts = $this->tabCounts($request, $today);
        $activeFilters = collect([
            'search' => trim((string) $request->get('search', '')),
            'city' => trim((string) $request->get('city', '')),
            'product_id' => $request->get('product_id', ''),
            'staff' => trim((string) $request->get('staff', '')),
            'overdue_days' => $request->get('overdue_days', ''),
            'payment_status' => trim((string) $request->get('payment_status', '')),
            'delivery_status' => trim((string) $request->get('delivery_status', '')),
            'pickup_status' => trim((string) $request->get('pickup_status', '')),
        ])->filter(fn ($value) => filled($value));

        return view('renewals.index', [
            'rentals' => $rentals,
            'tab' => $tab,
            'counts' => $counts,
            'sortBy' => $sortBy,
            'sortDir' => $sortDir,
            'products' => $this->productsForFilter(),
            'assignableUsers' => $this->assignableUsers(),
            'assignableStaff' => $this->assignableStaffMembers(),
            'activeFilters' => $activeFilters,
            'today' => $today,
        ]);
    }

    public function markReminderSent(Request $request, Rental $rental)
    {
        $this->ensureRentalAccess();
        abort_if((int) $rental->organization_id !== $this->orgId(), 404);

        $relations = ['product', 'customer'];

        if ($this->hasBusinessPartnerTables()) {
            $relations[] = 'businessPartner';
            $relations[] = 'partnerClient';
        }

        $rental->loadMissing($relations);

        if (!$this->hasReminderLogsTable()) {
            return redirect()
                ->back()
                ->with('error', 'Reminder logging table is not available in this environment.');
        }

        $number = WhatsAppHelper::normalizeNumber($rental->reminderContactPhone());

        if (!$number) {
            return redirect()
                ->back()
                ->with('error', 'No reminder contact number is available for this rental.');
        }

        $message = $this->reminderMessageFor($rental);

        RentalReminderLog::create([
            'organization_id' => $this->orgId(),
            'rental_id' => $rental->id,
            'customer_id' => $rental->customer_id,
            'reminder_type' => 'renewal',
            'sent_via' => 'whatsapp',
            'sent_to_number' => $number,
            'message_preview' => $message,
            'sent_by_user_id' => auth()->id(),
            'sent_at' => now(),
        ]);

        ActivityLogger::log('rental.reminder.marked_sent', $rental, [
            'sent_to_number' => $number,
            'reminder_type' => 'renewal',
        ], 'Renewal reminder marked as sent from Renewal Center.');

        return redirect()
            ->back()
            ->with('success', 'Renewal reminder marked as sent.');
    }

    public function schedulePickup(Request $request, Rental $rental)
    {
        $this->ensureRentalAccess();
        abort_if((int) $rental->organization_id !== $this->orgId(), 404);

        $validated = $request->validate([
            'pickup_date' => ['required', 'date'],
            'pickup_time_slot' => ['nullable', 'string', 'max:80'],
            'pickup_notes' => ['nullable', 'string', 'max:500'],
            'assignment_target' => ['nullable', 'string', 'max:40'],
        ]);

        if (Delivery::query()
            ->where('organization_id', $this->orgId())
            ->where('rental_id', $rental->id)
            ->where('type', 'pickup')
            ->whereIn('status', ['pending', 'in_progress'])
            ->exists()) {
            return redirect()
                ->back()
                ->with('error', 'A pickup task is already active for this rental.');
        }

        $scheduledAt = Carbon::parse($validated['pickup_date'] . ' ' . $this->resolvePickupTimeSlot($validated['pickup_time_slot'] ?? null));
        $assignmentTarget = (string) ($validated['assignment_target'] ?? '');
        $assignedUserId = null;
        $assignedStaffId = null;
        $assignmentType = 'delivery_team';

        if (str_starts_with($assignmentTarget, 'user:')) {
            $assignedUserId = (int) substr($assignmentTarget, 5);
        } elseif (str_starts_with($assignmentTarget, 'staff:')) {
            $assignedStaffId = (int) substr($assignmentTarget, 6);
            $assignmentType = 'vendor';
        }

        $notes = trim(collect([
            'Scheduled from Renewal Center.',
            $validated['pickup_time_slot'] ? 'Preferred slot: ' . $validated['pickup_time_slot'] : null,
            $validated['pickup_notes'] ?: null,
            $rental->deliveryContactNotes() ? 'Service notes: ' . $rental->deliveryContactNotes() : null,
        ])->filter()->implode(' '));

        $pickup = Delivery::create([
            'organization_id' => $this->orgId(),
            'rental_id' => $rental->id,
            'type' => 'pickup',
            'scheduled_at' => $scheduledAt,
            'status' => 'pending',
            'pickup_status' => 'requested',
            'pickup_time_slot' => $validated['pickup_time_slot'] ?? null,
            'notes' => $notes,
            'assignment_type' => $assignmentType,
            'assigned_user_id' => $assignedUserId,
            'assigned_staff_id' => $assignedStaffId,
        ]);

        ActivityLogger::log('rental.pickup_scheduled', $rental, [
            'delivery_id' => $pickup->id,
            'scheduled_at' => $scheduledAt->toDateTimeString(),
            'assigned_user_id' => $assignedUserId,
            'assigned_staff_id' => $assignedStaffId,
        ], 'Pickup task scheduled from Renewal Center.');

        return redirect()
            ->back()
            ->with('success', 'Pickup task scheduled successfully.');
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
}
