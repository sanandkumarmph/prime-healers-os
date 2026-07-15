<?php

namespace App\Http\Controllers;

use App\Models\Delivery;
use App\Models\Asset;
use App\Models\Rental;
use App\Models\RentalReminderLog;
use App\Models\Staff;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Deliveries\DeliveryWorkflowService;
use App\Services\Metrics\DashboardMetricsService;
use App\Support\ActivityLogger;
use App\Support\WhatsAppHelper;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
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
        return User::query()
            ->with('assignedRole')
            ->where('organization_id', $this->orgId())
            ->when(Schema::hasColumn('users', 'is_active'), fn (Builder $query) => $query->where('is_active', true))
            ->orderBy('name')
            ->get()
            ->filter(fn (User $user) => $this->internalPickupUserIsAssignable((int) $user->id))
            ->values();
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
        $fromDate = trim((string) $request->string('from_date'));
        $toDate = trim((string) $request->string('to_date'));
        $reminderUserId = $request->integer('reminder_user_id');
        $renewedByUserId = $request->integer('renewed_by_user_id');

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

        if ($reminderUserId > 0 && $this->hasReminderLogsTable()) {
            $query->whereHas('reminderLogs', function (Builder $reminderQuery) use ($fromDate, $toDate, $reminderUserId) {
                if ($reminderUserId > 0) {
                    $reminderQuery->where('sent_by_user_id', $reminderUserId);
                }

                if ($fromDate !== '') {
                    $reminderQuery->whereDate('sent_at', '>=', $fromDate);
                }

                if ($toDate !== '') {
                    $reminderQuery->whereDate('sent_at', '<=', $toDate);
                }
            });
        }

        if ($renewedByUserId > 0 && $this->hasRentalRenewalsTable()) {
            $query->whereHas('renewals', function (Builder $renewalQuery) use ($fromDate, $toDate, $renewedByUserId) {
                if ($renewedByUserId > 0) {
                    $renewalQuery->where('renewed_by_user_id', $renewedByUserId);
                }

                if ($fromDate !== '') {
                    $renewalQuery->whereDate('created_at', '>=', $fromDate);
                }

                if ($toDate !== '') {
                    $renewalQuery->whereDate('created_at', '<=', $toDate);
                }
            });
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
                function (Builder $renewedQuery) use ($today) {
                    $hasExplicitRenewalFilter = request()->filled('renewed_by_user_id')
                        || request()->filled('from_date')
                        || request()->filled('to_date');

                    return $renewedQuery->whereHas('renewals', function (Builder $renewalQuery) use ($today, $hasExplicitRenewalFilter) {
                        if (!$hasExplicitRenewalFilter) {
                            $renewalQuery->whereDate('created_at', '>=', $today->copy()->subDays(30));
                        }
                    });
                },
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
            'pickup_method' => ['required', Rule::in(['internal_pickup', 'vendor_pickup', 'third_party_pickup', 'customer_self_drop'])],
            'pickup_date' => ['required', 'date'],
            'pickup_time_slot' => ['nullable', 'string', 'max:80'],
            'pickup_notes' => ['nullable', 'string', 'max:500'],
            'assigned_user_id' => ['nullable', 'integer'],
            'vendor_id' => ['nullable', 'integer'],
            'third_party_provider' => ['nullable', 'string', 'max:120'],
            'third_party_tracking_number' => ['nullable', 'string', 'max:120'],
            'third_party_contact_person' => ['nullable', 'string', 'max:120'],
            'third_party_phone' => ['nullable', 'string', 'max:40'],
            'third_party_pickup_cost' => ['nullable', 'numeric', 'min:0'],
        ]);

        $pickupMethod = (string) $validated['pickup_method'];
        $isVendorSupplied = $rental->isVendorSupplied();

        if (!$isVendorSupplied && $pickupMethod === 'vendor_pickup') {
            return redirect()
                ->back()
                ->withErrors(['pickup_method' => 'Vendor pickup is available only for vendor-supplied rentals.'])
                ->withInput();
        }

        if ($isVendorSupplied && $pickupMethod === 'internal_pickup') {
            return redirect()
                ->back()
                ->withErrors(['pickup_method' => 'Internal pickup is available only for in-house rentals.'])
                ->withInput();
        }

        $activePickupQuery = Delivery::query()
            ->where('organization_id', $this->orgId())
            ->where('rental_id', $rental->id)
            ->where('type', 'pickup')
            ->whereIn('status', ['pending', 'in_progress']);

        if ($pickupMethod !== 'customer_self_drop' && $activePickupQuery->exists()) {
            return redirect()
                ->back()
                ->with('error', 'A pickup task is already active for this rental.');
        }

        $scheduledAt = Carbon::parse($validated['pickup_date'] . ' ' . $this->resolvePickupTimeSlot($validated['pickup_time_slot'] ?? null));
        $assignedUserId = null;
        $assignedStaffId = null;
        $assignmentType = 'delivery_team';
        $thirdPartyName = null;
        $thirdPartyContact = null;
        $thirdPartyPhone = null;
        $methodLabel = match ($pickupMethod) {
            'internal_pickup' => 'Internal pickup',
            'vendor_pickup' => 'Vendor pickup',
            'third_party_pickup' => 'Third party pickup',
            'customer_self_drop' => 'Customer self drop',
        };

        if ($pickupMethod === 'customer_self_drop') {
            $redirectAssetId = null;

            DB::transaction(function () use ($rental, $scheduledAt, $validated, $activePickupQuery, &$redirectAssetId, $isVendorSupplied) {
                $activePickupQuery->update([
                    'status' => 'cancelled',
                    'pickup_status' => 'customer_self_drop',
                    'last_pickup_note' => 'Closed because customer self-dropped the equipment.',
                    'last_pickup_note_at' => now(),
                ]);

                if (Schema::hasColumn('rentals', 'pickup_responsibility')) {
                    $rental->forceFill(['pickup_responsibility' => 'customer_self_drop'])->save();
                }

                if ($isVendorSupplied) {
                    $this->deliveryWorkflowService()->finalizePickupCompletionForRental(
                        $this->orgId(),
                        $rental->fresh(),
                        $scheduledAt->toDateTimeString(),
                        null
                    );
                } else {
                    $this->deliveryWorkflowService()->finalizePickupCompletionForRental(
                        $this->orgId(),
                        $rental->fresh(),
                        $scheduledAt->toDateTimeString(),
                        null
                    );

                    $redirectAssetId = $this->singleReturnedAssetAwaitingVerification($rental->fresh())?->id;
                }

                ActivityLogger::log('rental.customer_self_drop_recorded', $rental->fresh(), [
                    'scheduled_at' => $scheduledAt->toDateTimeString(),
                    'pickup_notes' => $validated['pickup_notes'] ?? null,
                    'vendor_supplied' => $isVendorSupplied,
                ], $isVendorSupplied
                    ? 'Customer self drop recorded directly to vendor; PH return verification skipped.'
                    : 'Customer self drop recorded and rental assets moved to return verification.');
            });

            if ($isVendorSupplied) {
                return redirect()
                    ->back()
                    ->with('success', 'Customer self drop recorded as returned directly to vendor. PH return verification was skipped.');
            }

            return redirect()
                ->to($redirectAssetId ? route('assets.verify-return', $redirectAssetId) : route('assets.pending-verification'))
                ->with('success', 'Customer self drop recorded. Complete return verification before making assets available.');
        }

        if ($pickupMethod === 'internal_pickup') {
            $assignedUserId = (int) ($validated['assigned_user_id'] ?? 0);

            if (!$this->internalPickupUserIsAssignable($assignedUserId)) {
                return redirect()
                    ->back()
                    ->withErrors(['assigned_user_id' => 'Choose a delivery or operations executive for internal pickup.'])
                    ->withInput();
            }
        } elseif ($pickupMethod === 'vendor_pickup') {
            $vendor = Vendor::query()
                ->where('organization_id', $this->orgId())
                ->whereKey((int) ($validated['vendor_id'] ?? 0))
                ->first();

            if (!$vendor) {
                return redirect()
                    ->back()
                    ->withErrors(['vendor_id' => 'Choose an active vendor for vendor pickup.'])
                    ->withInput();
            }

            $assignmentType = 'vendor';
            $thirdPartyName = $vendor->name;
        } elseif ($pickupMethod === 'third_party_pickup') {
            if (blank($validated['third_party_provider'] ?? null)) {
                return redirect()
                    ->back()
                    ->withErrors(['third_party_provider' => 'Enter the third-party pickup provider.'])
                    ->withInput();
            }

            $assignmentType = 'third_party';
            $thirdPartyName = trim((string) $validated['third_party_provider']);
            $thirdPartyContact = trim((string) ($validated['third_party_contact_person'] ?? ''));
            $thirdPartyPhone = trim((string) ($validated['third_party_phone'] ?? ''));
        }

        if (Schema::hasColumn('rentals', 'pickup_responsibility')) {
            $rental->forceFill([
                'pickup_responsibility' => $pickupMethod === 'internal_pickup' ? 'ph_internal_pickup' : $pickupMethod,
            ])->save();
        }

        $metadataNotes = collect([
            'Pickup method: ' . $methodLabel . '.',
            !empty($validated['third_party_tracking_number']) ? 'Tracking: ' . trim((string) $validated['third_party_tracking_number']) . '.' : null,
            array_key_exists('third_party_pickup_cost', $validated) && $validated['third_party_pickup_cost'] !== null
                ? 'Pickup cost: ' . number_format((float) $validated['third_party_pickup_cost'], 2, '.', '') . '.'
                : null,
        ])->filter()->implode(' ');

        $notes = trim(collect([
            'Scheduled from Renewal Center.',
            $metadataNotes,
            !empty($validated['pickup_time_slot']) ? 'Preferred slot: ' . $validated['pickup_time_slot'] : null,
            $validated['pickup_notes'] ?? null,
            $rental->deliveryContactNotes() ? 'Service notes: ' . $rental->deliveryContactNotes() : null,
        ])->filter()->implode(' '));

        $pickup = Delivery::create([
            'organization_id' => $this->orgId(),
            'rental_id' => $rental->id,
            'type' => 'pickup',
            'scheduled_at' => $scheduledAt,
            'status' => 'pending',
            'pickup_status' => 'assigned',
            'pickup_time_slot' => $validated['pickup_time_slot'] ?? null,
            'notes' => $notes,
            'assignment_type' => $assignmentType,
            'assigned_user_id' => $assignedUserId,
            'assigned_staff_id' => $assignedStaffId,
            'third_party_name' => $thirdPartyName,
            'third_party_contact' => $thirdPartyContact,
            'third_party_phone' => $thirdPartyPhone,
        ]);

        ActivityLogger::log('rental.pickup_scheduled', $rental, [
            'delivery_id' => $pickup->id,
            'scheduled_at' => $scheduledAt->toDateTimeString(),
            'assigned_user_id' => $assignedUserId,
            'assigned_staff_id' => $assignedStaffId,
            'pickup_method' => $pickupMethod,
            'assignment_type' => $assignmentType,
        ], 'Pickup task scheduled from Renewal Center.');

        return redirect()
            ->back()
            ->with('success', 'Pickup task scheduled successfully.');
    }

    private function deliveryWorkflowService(): DeliveryWorkflowService
    {
        return app(DeliveryWorkflowService::class);
    }

    private function internalPickupUserIsAssignable(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        $user = User::query()
            ->with('assignedRole')
            ->where('organization_id', $this->orgId())
            ->when(Schema::hasColumn('users', 'is_active'), fn ($query) => $query->where('is_active', true))
            ->find($userId);

        if (!$user) {
            return false;
        }

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

    private function singleReturnedAssetAwaitingVerification(Rental $rental): ?Asset
    {
        if (!\App\Models\RentalAsset::hasTable()) {
            return null;
        }

        $assets = Asset::query()
            ->where('organization_id', $this->orgId())
            ->where('asset_stage', Asset::STAGE_RENTAL_STOCK)
            ->where('asset_status', Asset::STATUS_AWAITING_VERIFICATION)
            ->whereIn('id', $rental->rentalAssets()->whereNotNull('returned_at')->pluck('asset_id')->filter()->values())
            ->limit(2)
            ->get();

        return $assets->count() === 1 ? $assets->first() : null;
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
