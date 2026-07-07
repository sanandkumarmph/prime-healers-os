<?php

namespace App\Http\Controllers;

use App\Models\BusinessPartner;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\FollowUp;
use App\Models\Invoice;
use App\Models\PartnerClient;
use App\Models\Rental;
use App\Models\Sale;
use App\Models\User;
use App\Services\Metrics\DashboardMetricsService;
use App\Services\NotificationCenterService;
use App\Support\ActivityLogger;
use App\Support\FollowUpManager;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class CommunicationCenterController extends Controller
{
    public function __construct(private readonly FollowUpManager $followUpManager)
    {
    }

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
        $this->ensureCommunicationAccess();

        if (!$this->followUpManager->available()) {
            return view('communications.index', [
                'followUps' => collect(),
                'tab' => 'pending',
                'counts' => [],
                'assignableUsers' => $this->assignableUsers(),
                'activeFilters' => [],
                'today' => Carbon::today(),
                'featureReady' => false,
            ]);
        }

        $this->followUpManager->syncOperationalFollowUps($this->orgId());

        $today = Carbon::today();
        $tab = $this->normalizeTab((string) $request->get('tab', 'pending'));

        $countQuery = $this->applyScopedVisibility($this->baseQuery(false));
        $countQuery = $this->applyFilters($countQuery, $request);

        $counts = $this->dashboardMetrics()->followUpCounts($countQuery, $today);
        $counts = array_merge($counts, $this->inboxCounts($countQuery));

        $query = $this->applyScopedVisibility($this->baseQuery());
        $query = $this->applyFilters($query, $request);
        $query = $this->applyTab($query, $tab, $today);
        $this->applySort($query, (string) $request->get('sort', 'unread_first'));

        /** @var LengthAwarePaginator $followUps */
        $followUps = $query->paginate(15)->withQueryString();

        return view('communications.index', [
            'followUps' => $followUps,
            'tab' => $tab,
            'counts' => $counts,
            'assignableUsers' => $this->assignableUsers(),
            'activeFilters' => $this->activeFilters($request),
            'today' => $today,
            'featureReady' => true,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->ensureCommunicationAccess();
        abort_unless($this->followUpManager->available(), 503, 'Follow-up feature is not available until the follow_ups table is migrated.');

        $validated = $request->validate([
            'customer_id' => ['nullable', 'integer'],
            'business_partner_id' => ['nullable', 'integer'],
            'partner_client_id' => ['nullable', 'integer'],
            'rental_id' => ['nullable', 'integer'],
            'sale_id' => ['nullable', 'integer'],
            'invoice_id' => ['nullable', 'integer'],
            'delivery_id' => ['nullable', 'integer'],
            'followup_type' => ['required', Rule::in(array_keys(FollowUp::TYPES))],
            'due_at' => ['required', 'date'],
            'assigned_user_id' => ['nullable', 'integer'],
            'priority' => ['required', Rule::in(array_keys(FollowUp::PRIORITIES))],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $context = $this->resolvedContext($validated);

        $followUp = FollowUp::create([
            'organization_id' => $this->orgId(),
            'customer_id' => $context['customer_id'],
            'business_partner_id' => $context['business_partner_id'],
            'partner_client_id' => $context['partner_client_id'],
            'rental_id' => $context['rental_id'],
            'sale_id' => $context['sale_id'],
            'invoice_id' => $context['invoice_id'],
            'delivery_id' => $context['delivery_id'],
            'assigned_user_id' => $this->validatedAssignedUserId($validated['assigned_user_id'] ?? null),
            'followup_type' => $validated['followup_type'],
            'title' => $this->derivedTitle($validated['followup_type'], $context),
            'note' => $validated['note'] ?? null,
            'due_at' => Carbon::parse($validated['due_at']),
            'status' => FollowUp::STATUS_PENDING,
            'priority' => $validated['priority'],
            'created_by_user_id' => auth()->id(),
            'is_system_generated' => false,
            'source' => 'manual',
        ]);

        $followUp->loadMissing($this->followUpRelations());

        ActivityLogger::log('followup.created', $followUp, [
            'followup_type' => $followUp->followup_type,
            'priority' => $followUp->priority,
            'status' => $followUp->status,
            'assigned_user_id' => $followUp->assigned_user_id,
            'due_at' => optional($followUp->due_at)->toDateTimeString(),
            'note_type' => 'follow-up',
        ], 'Follow-up added for operational coordination.');

        if ($followUp->assigned_user_id && $followUp->assignedUser) {
            $this->notifications()->notifyFollowUpAssignment($followUp, $followUp->assignedUser);
        }

        return back()->with('success', 'Follow-up added successfully.');
    }

    public function complete(Request $request, FollowUp $followUp): RedirectResponse
    {
        $followUp = $this->scopedFollowUp($followUp);

        if (in_array($followUp->status, [FollowUp::STATUS_COMPLETED, FollowUp::STATUS_CANCELLED], true)) {
            return back()->with('info', 'This follow-up is already closed.');
        }

        $validated = $request->validate([
            'completion_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $followUp->update([
            'status' => FollowUp::STATUS_COMPLETED,
            'completed_at' => now(),
            'note' => $this->mergedNote($followUp->note, $validated['completion_note'] ?? null, 'Completed: '),
        ]);

        ActivityLogger::log('followup.completed', $followUp->fresh(), [
            'followup_type' => $followUp->followup_type,
            'priority' => $followUp->priority,
            'status' => FollowUp::STATUS_COMPLETED,
            'note_type' => 'follow-up',
        ], 'Follow-up marked as completed.');

        return back()->with('success', 'Follow-up marked complete.');
    }

    public function reschedule(Request $request, FollowUp $followUp): RedirectResponse
    {
        $followUp = $this->scopedFollowUp($followUp);

        $validated = $request->validate([
            'due_at' => ['required', 'date'],
            'reschedule_note' => ['nullable', 'string', 'max:2000'],
            'assigned_user_id' => ['nullable', 'integer'],
            'priority' => ['nullable', Rule::in(array_keys(FollowUp::PRIORITIES))],
        ]);

        $newDueAt = Carbon::parse($validated['due_at']);

        $previousAssignedUserId = (int) ($followUp->assigned_user_id ?? 0);

        $followUp->update([
            'due_at' => $newDueAt,
            'assigned_user_id' => $this->validatedAssignedUserId($validated['assigned_user_id'] ?? $followUp->assigned_user_id),
            'priority' => $validated['priority'] ?? $followUp->priority,
            'status' => FollowUp::STATUS_PENDING,
            'completed_at' => null,
            'note' => $this->mergedNote($followUp->note, $validated['reschedule_note'] ?? null, 'Rescheduled: '),
        ]);

        ActivityLogger::log('followup.rescheduled', $followUp->fresh(), [
            'followup_type' => $followUp->followup_type,
            'priority' => $followUp->priority,
            'status' => $followUp->status,
            'due_at' => $newDueAt->toDateTimeString(),
            'note_type' => 'follow-up',
        ], 'Follow-up rescheduled.');

        $followUp->loadMissing($this->followUpRelations());
        if ($followUp->assigned_user_id && (int) $followUp->assigned_user_id !== $previousAssignedUserId && $followUp->assignedUser) {
            $this->notifications()->notifyFollowUpAssignment($followUp, $followUp->assignedUser);
        }

        return back()->with('success', 'Follow-up rescheduled successfully.');
    }

    private function ensureCommunicationAccess(): void
    {
        $user = auth()->user();

        if (!$user) {
            abort(403);
        }

        $allowed = $user->isSuperAdmin()
            || $user->isAdminOperations()
            || $user->canAccessAnyModule(['rentals', 'sales', 'customers', 'deliveries', 'invoices'], 'read');

        abort_unless($allowed, 403);
    }

    private function orgId(): int
    {
        return (int) auth()->user()->organization_id;
    }

    private function tabs(): array
    {
        return [
            'all' => 'All',
            'unread' => 'Unread',
            'assigned_me' => 'Assigned to Me',
            'staff_messages' => 'Staff Messages',
            'system_alerts' => 'System Alerts',
            'payments' => 'Payment Follow-ups',
            'delivery' => 'Delivery / Service',
            'completed' => 'Completed',
        ];
    }

    private function normalizeTab(string $tab): string
    {
        $legacyTabs = ['pending', 'today', 'overdue', 'renewals', 'pickups', 'notes'];

        return array_key_exists($tab, $this->tabs()) || in_array($tab, $legacyTabs, true) ? $tab : 'all';
    }

    private function baseQuery(bool $withRelations = true): Builder
    {
        $query = FollowUp::query()->where('organization_id', $this->orgId());

        if ($withRelations) {
            $query->with($this->followUpRelations());
        }

        return $query;
    }

    private function followUpRelations(): array
    {
        return [
            'customer',
            'businessPartner',
            'partnerClient',
            'rental.product',
            'rental.customer',
            'rental.businessPartner',
            'rental.partnerClient',
            'sale.product',
            'sale.customer',
            'sale.businessPartner',
            'sale.partnerClient',
            'invoice.customer',
            'invoice.rental.product',
            'invoice.rental.customer',
            'invoice.rental.businessPartner',
            'invoice.rental.partnerClient',
            'invoice.sale.product',
            'invoice.sale.customer',
            'invoice.sale.businessPartner',
            'invoice.sale.partnerClient',
            'delivery.rental.product',
            'delivery.rental.customer',
            'delivery.rental.businessPartner',
            'delivery.rental.partnerClient',
            'delivery.sale.product',
            'delivery.sale.customer',
            'delivery.sale.businessPartner',
            'delivery.sale.partnerClient',
            'assignedUser',
            'createdByUser',
        ];
    }

    private function applyScopedVisibility(Builder $query): Builder
    {
        return $this->dashboardMetrics()->scopeFollowUpVisibility($query, auth()->user());
    }

    private function applyFilters(Builder $query, Request $request): Builder
    {
        $search = trim((string) $request->get('search', ''));
        $priority = trim((string) $request->get('priority', ''));
        $staff = trim((string) $request->get('staff', ''));
        $type = trim((string) $request->get('type', ''));

        if ($search !== '') {
            $query->where(function (Builder $searchQuery) use ($search): void {
                $searchQuery->where('title', 'like', '%' . $search . '%')
                    ->orWhere('note', 'like', '%' . $search . '%')
                    ->orWhereHas('customer', fn (Builder $customerQuery) => $customerQuery
                        ->where('name', 'like', '%' . $search . '%')
                        ->orWhere('phone', 'like', '%' . $search . '%'))
                    ->orWhereHas('businessPartner', fn (Builder $partnerQuery) => $partnerQuery
                        ->where('business_name', 'like', '%' . $search . '%')
                        ->orWhere('phone', 'like', '%' . $search . '%'))
                    ->orWhereHas('partnerClient', fn (Builder $clientQuery) => $clientQuery
                        ->where('client_name', 'like', '%' . $search . '%')
                        ->orWhere('phone', 'like', '%' . $search . '%'))
                    ->orWhereHas('rental.product', fn (Builder $productQuery) => $productQuery->where('name', 'like', '%' . $search . '%'))
                    ->orWhereHas('sale.product', fn (Builder $productQuery) => $productQuery->where('name', 'like', '%' . $search . '%'))
                    ->orWhereHas('delivery.rental.product', fn (Builder $productQuery) => $productQuery->where('name', 'like', '%' . $search . '%'))
                    ->orWhereHas('delivery.sale.product', fn (Builder $productQuery) => $productQuery->where('name', 'like', '%' . $search . '%'));
            });
        }

        if ($priority !== '' && array_key_exists($priority, FollowUp::PRIORITIES)) {
            $query->where('priority', $priority);
        }

        if ($type !== '' && array_key_exists($type, FollowUp::TYPES)) {
            $query->where('followup_type', $type);
        }

        if ($staff !== '') {
            if ($staff === 'unassigned') {
                $query->whereNull('assigned_user_id');
            } elseif ($staff === 'me') {
                $query->where('assigned_user_id', auth()->id());
            } elseif (str_starts_with($staff, 'user:')) {
                $query->where('assigned_user_id', (int) substr($staff, 5));
            }
        }

        return $query;
    }

    private function applyTab(Builder $query, string $tab, Carbon $today): Builder
    {
        return match ($tab) {
            'pending' => $query->whereNotIn('status', [FollowUp::STATUS_COMPLETED, FollowUp::STATUS_CANCELLED]),
            'unread' => $query->whereNotIn('status', [FollowUp::STATUS_COMPLETED, FollowUp::STATUS_CANCELLED]),
            'assigned_me' => $query->where('assigned_user_id', auth()->id())->whereNotIn('status', [FollowUp::STATUS_COMPLETED, FollowUp::STATUS_CANCELLED]),
            'staff_messages' => $query->where('is_system_generated', false)->whereIn('followup_type', [FollowUp::TYPE_GENERAL, FollowUp::TYPE_CALLBACK, FollowUp::TYPE_COMPLAINT, FollowUp::TYPE_ESCALATION]),
            'system_alerts' => $query->where('is_system_generated', true),
            'today' => $query->whereNotIn('status', [FollowUp::STATUS_COMPLETED, FollowUp::STATUS_CANCELLED])->whereDate('due_at', $today),
            'overdue' => $query->whereNotIn('status', [FollowUp::STATUS_COMPLETED, FollowUp::STATUS_CANCELLED])->where('due_at', '<', now()),
            'renewals' => $query->where('followup_type', FollowUp::TYPE_RENEWAL),
            'payments' => $query->where('followup_type', FollowUp::TYPE_PAYMENT),
            'pickups' => $query->where('followup_type', FollowUp::TYPE_PICKUP),
            'delivery' => $query->whereIn('followup_type', [FollowUp::TYPE_DELIVERY, FollowUp::TYPE_SERVICE]),
            'notes' => $query->whereIn('followup_type', [FollowUp::TYPE_GENERAL, FollowUp::TYPE_CALLBACK, FollowUp::TYPE_COMPLAINT, FollowUp::TYPE_ESCALATION]),
            'completed' => $query->where('status', FollowUp::STATUS_COMPLETED),
            default => $query,
        };
    }

    private function applySort(Builder $query, string $sort): Builder
    {
        return match ($sort) {
            'overdue_first' => $query
                ->orderByRaw('CASE WHEN status NOT IN (?, ?) AND due_at < ? THEN 0 ELSE 1 END', [FollowUp::STATUS_COMPLETED, FollowUp::STATUS_CANCELLED, now()])
                ->latest('due_at')
                ->latest('id'),
            'high_priority' => $query
                ->orderByRaw("CASE priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")
                ->latest('due_at')
                ->latest('id'),
            'latest' => $query->latest('created_at')->latest('id'),
            default => $query
                ->orderByRaw('CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END', [FollowUp::STATUS_COMPLETED, FollowUp::STATUS_CANCELLED])
                ->latest('due_at')
                ->latest('id'),
        };
    }

    private function inboxCounts(Builder $query): array
    {
        return [
            'all' => (clone $query)->count(),
            'unread' => (clone $query)->whereNotIn('status', [FollowUp::STATUS_COMPLETED, FollowUp::STATUS_CANCELLED])->count(),
            'assigned_me' => (clone $query)->where('assigned_user_id', auth()->id())->whereNotIn('status', [FollowUp::STATUS_COMPLETED, FollowUp::STATUS_CANCELLED])->count(),
            'staff_messages' => (clone $query)->where('is_system_generated', false)->whereIn('followup_type', [FollowUp::TYPE_GENERAL, FollowUp::TYPE_CALLBACK, FollowUp::TYPE_COMPLAINT, FollowUp::TYPE_ESCALATION])->count(),
            'system_alerts' => (clone $query)->where('is_system_generated', true)->count(),
            'payments' => (clone $query)->where('followup_type', FollowUp::TYPE_PAYMENT)->count(),
            'delivery' => (clone $query)->whereIn('followup_type', [FollowUp::TYPE_DELIVERY, FollowUp::TYPE_SERVICE])->count(),
            'completed' => (clone $query)->where('status', FollowUp::STATUS_COMPLETED)->count(),
        ];
    }

    private function activeFilters(Request $request): array
    {
        $filters = [];

        if (filled($request->get('search'))) {
            $filters[] = 'Search: ' . trim((string) $request->get('search'));
        }

        if (filled($request->get('priority'))) {
            $filters[] = 'Priority: ' . ucfirst((string) $request->get('priority'));
        }

        if (filled($request->get('type')) && array_key_exists((string) $request->get('type'), FollowUp::TYPES)) {
            $filters[] = 'Type: ' . FollowUp::TYPES[(string) $request->get('type')];
        }

        if (filled($request->get('staff'))) {
            $staff = trim((string) $request->get('staff'));
            $filters[] = $staff === 'me' ? 'Staff: Me' : 'Staff: ' . $staff;
        }

        if (filled($request->get('sort'))) {
            $filters[] = 'Sort: ' . str_replace('_', ' ', ucfirst((string) $request->get('sort')));
        }

        return $filters;
    }

    private function assignableUsers(): Collection
    {
        return User::query()
            ->where('organization_id', $this->orgId())
            ->when(Schema::hasColumn('users', 'is_active'), fn (Builder $query) => $query->where('is_active', true))
            ->orderBy('name')
            ->get(['id', 'name', 'role']);
    }

    private function validatedAssignedUserId(?int $assignedUserId): ?int
    {
        if (!$assignedUserId) {
            return null;
        }

        return User::query()
            ->where('organization_id', $this->orgId())
            ->whereKey($assignedUserId)
            ->value('id');
    }

    private function scopedFollowUp(FollowUp $followUp): FollowUp
    {
        abort_if((int) $followUp->organization_id !== $this->orgId(), 404);

        $query = $this->applyScopedVisibility(FollowUp::query()->whereKey($followUp->id));
        abort_unless($query->exists(), 403);

        return $followUp;
    }

    private function resolvedContext(array $validated): array
    {
        $context = [
            'customer_id' => null,
            'business_partner_id' => null,
            'partner_client_id' => null,
            'rental_id' => null,
            'sale_id' => null,
            'invoice_id' => null,
            'delivery_id' => null,
        ];

        $customer = $this->scopedModel(Customer::class, $validated['customer_id'] ?? null);
        $businessPartner = $this->scopedModel(BusinessPartner::class, $validated['business_partner_id'] ?? null);
        $partnerClient = $this->scopedModel(PartnerClient::class, $validated['partner_client_id'] ?? null);
        $rental = $this->scopedModel(Rental::class, $validated['rental_id'] ?? null, ['customer', 'businessPartner', 'partnerClient']);
        $sale = $this->scopedModel(Sale::class, $validated['sale_id'] ?? null, ['customer', 'businessPartner', 'partnerClient']);
        $invoice = $this->scopedModel(Invoice::class, $validated['invoice_id'] ?? null, ['customer', 'rental.customer', 'rental.businessPartner', 'rental.partnerClient', 'sale.customer', 'sale.businessPartner', 'sale.partnerClient']);
        $delivery = $this->scopedModel(Delivery::class, $validated['delivery_id'] ?? null, ['rental.customer', 'rental.businessPartner', 'rental.partnerClient', 'sale.customer', 'sale.businessPartner', 'sale.partnerClient']);

        if ($delivery) {
            $context['delivery_id'] = $delivery->id;
            $context['rental_id'] = $delivery->rental_id;
            $context['sale_id'] = $delivery->sale_id;
            $context['customer_id'] = $delivery->rental?->customer_id ?? $delivery->sale?->customer_id;
            $context['business_partner_id'] = $delivery->rental?->business_partner_id ?? $delivery->sale?->business_partner_id;
            $context['partner_client_id'] = $delivery->rental?->partner_client_id ?? $delivery->sale?->partner_client_id;
            $context['invoice_id'] = $delivery->rental?->invoice?->id ?? $delivery->sale?->linked_invoice_id;
        } elseif ($invoice) {
            $context['invoice_id'] = $invoice->id;
            $context['rental_id'] = $invoice->linkedRentalId();
            $context['sale_id'] = $invoice->sale_id;
            $context['customer_id'] = $invoice->customer_id ?: ($invoice->rental?->customer_id ?? $invoice->sale?->customer_id);
            $context['business_partner_id'] = $invoice->rental?->business_partner_id ?? $invoice->sale?->business_partner_id;
            $context['partner_client_id'] = $invoice->rental?->partner_client_id ?? $invoice->sale?->partner_client_id;
        } elseif ($rental) {
            $context['rental_id'] = $rental->id;
            $context['customer_id'] = $rental->customer_id;
            $context['business_partner_id'] = $rental->business_partner_id;
            $context['partner_client_id'] = $rental->partner_client_id;
        } elseif ($sale) {
            $context['sale_id'] = $sale->id;
            $context['customer_id'] = $sale->customer_id;
            $context['business_partner_id'] = $sale->business_partner_id;
            $context['partner_client_id'] = $sale->partner_client_id;
        } else {
            $context['customer_id'] = $customer?->id;
            $context['business_partner_id'] = $businessPartner?->id;
            $context['partner_client_id'] = $partnerClient?->id;
        }

        if ($partnerClient && !$context['partner_client_id']) {
            $context['partner_client_id'] = $partnerClient->id;
            $context['business_partner_id'] = $context['business_partner_id'] ?: $partnerClient->business_partner_id;
        }

        if ($businessPartner && !$context['business_partner_id']) {
            $context['business_partner_id'] = $businessPartner->id;
        }

        if ($customer && !$context['customer_id']) {
            $context['customer_id'] = $customer->id;
        }

        return $context;
    }

    private function scopedModel(string $modelClass, mixed $id, array $with = []): ?object
    {
        $id = (int) $id;

        if ($id <= 0) {
            return null;
        }

        return $modelClass::query()
            ->with($with)
            ->where('organization_id', $this->orgId())
            ->findOrFail($id);
    }

    private function derivedTitle(string $type, array $context): string
    {
        $typeLabel = FollowUp::TYPES[$type] ?? ucfirst($type);

        if ($context['rental_id']) {
            return $typeLabel . ' follow-up for Rental #' . $context['rental_id'];
        }

        if ($context['sale_id']) {
            return $typeLabel . ' follow-up for Sale #' . $context['sale_id'];
        }

        if ($context['invoice_id']) {
            return $typeLabel . ' follow-up for Invoice #' . $context['invoice_id'];
        }

        if ($context['delivery_id']) {
            return $typeLabel . ' follow-up for Task #' . $context['delivery_id'];
        }

        return $typeLabel . ' follow-up';
    }

    private function mergedNote(?string $existing, ?string $incoming, string $prefix = ''): ?string
    {
        $incoming = trim((string) $incoming);

        if ($incoming === '') {
            return $existing;
        }

        return trim(collect([
            $existing,
            $prefix . $incoming,
        ])->filter()->implode(' | '));
    }
}
