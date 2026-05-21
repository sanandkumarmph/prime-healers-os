<?php

namespace App\Providers;

use App\Models\Asset;
use App\Models\BusinessPartner;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\FollowUp;
use App\Models\Invoice;
use App\Models\Organization;
use App\Models\PartnerClient;
use App\Models\Payment;
use App\Models\Rental;
use App\Models\Sale;
use App\Policies\BusinessPartnerPolicy;
use App\Policies\DeliveryPolicy;
use App\Policies\ImportPolicy;
use App\Policies\CustomerPolicy;
use App\Policies\InvoicePolicy;
use App\Policies\OrganizationSettingsPolicy;
use App\Policies\PartnerClientPolicy;
use App\Policies\PaymentPolicy;
use App\Policies\ReportPolicy;
use App\Policies\RentalPolicy;
use App\Policies\SalePolicy;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\ReportController;
use App\Services\NotificationCenterService;
use App\Support\InternalOrganization;
use Illuminate\Support\ServiceProvider;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;

class AppServiceProvider extends ServiceProvider
{
    private const SIDEBAR_NOTIFICATION_CACHE_TTL_SECONDS = 120;

    /**
     * Register any application services.
     */
    public function register(): void
    {
        View::share('internalSingleOrgMode', InternalOrganization::enabled());
        View::share('internalCompanyName', InternalOrganization::companyName());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::defaultView('vendor.pagination.prime-healers');
        Paginator::defaultSimpleView('vendor.pagination.prime-healers-simple');

        Event::listen('eloquent.creating: *', function (string $eventName, array $data): void {
            $model = $data[0] ?? null;

            if (!$model instanceof \Illuminate\Database\Eloquent\Model) {
                return;
            }

            if (!$this->shouldApplyDefaultOrganization($model)) {
                return;
            }

            if ((int) ($model->getAttribute('organization_id') ?? 0) > 0) {
                return;
            }

            $organizationId = InternalOrganization::id(auth()->user());

            if ($organizationId) {
                $model->setAttribute('organization_id', $organizationId);
            }
        });

        Gate::policy(Customer::class, CustomerPolicy::class);
        Gate::policy(BusinessPartner::class, BusinessPartnerPolicy::class);
        Gate::policy(PartnerClient::class, PartnerClientPolicy::class);
        Gate::policy(Delivery::class, DeliveryPolicy::class);
        Gate::policy(Rental::class, RentalPolicy::class);
        Gate::policy(Sale::class, SalePolicy::class);
        Gate::policy(Invoice::class, InvoicePolicy::class);
        Gate::policy(Payment::class, PaymentPolicy::class);
        Gate::policy(Organization::class, OrganizationSettingsPolicy::class);
        Gate::policy(ReportController::class, ReportPolicy::class);
        Gate::policy(ImportController::class, ImportPolicy::class);

        View::composer('layouts.app', function ($view) {
            $payload = [
                'topbarNotifications' => collect(),
                'topbarNotificationCount' => 0,
                'topbarNotificationsViewAllHref' => null,
                'sidebarPendingCounts' => [],
            ];

            if (app()->runningInConsole() || !auth()->check()) {
                $view->with($payload);
                return;
            }

            $user = auth()->user();
            $user->loadMissing(['assignedRole', 'organization']);
            $organizationId = $user?->organization_id;

            if (!$organizationId) {
                $view->with($payload);
                return;
            }

            $safeRoute = static function (string $name, array $parameters = []) {
                return Route::has($name) ? route($name, $parameters) : null;
            };

            $counts = Cache::remember(
                $this->sidebarNotificationCacheKey($user->id, (int) $organizationId, (string) $user->effective_role, (int) ($user->role_id ?? 0)),
                now()->addSeconds(self::SIDEBAR_NOTIFICATION_CACHE_TTL_SECONDS),
                function () use ($organizationId, $user) {
                    return $this->sidebarNotificationCounts((int) $organizationId, $user);
                }
            );

            $sidebarPendingCounts = $counts['sidebarPendingCounts'] ?? [];
            $overdueRentalsCount = (int) ($counts['overdueRentalsCount'] ?? 0);
            $pendingInvoiceCount = (int) ($counts['pendingInvoiceCount'] ?? 0);

            $deliveriesToday = (int) ($counts['deliveriesToday'] ?? 0);

            $pickupsToday = (int) ($counts['pickupsToday'] ?? 0);

            $maintenanceAssets = (int) ($counts['maintenanceAssets'] ?? 0);

            $followUpsDueToday = (int) ($counts['followUpsDueToday'] ?? 0);

            $overdueFollowUps = (int) ($counts['overdueFollowUps'] ?? 0);

            $failedPickupCount = (int) ($counts['failedPickupCount'] ?? 0);

            $failedDeliveryCount = (int) ($counts['failedDeliveryCount'] ?? 0);

            $assignedFollowUps = (int) ($counts['assignedFollowUps'] ?? 0);
            $notificationService = app(NotificationCenterService::class);
            $notifications = $notificationService->latestFor($user, 8);
            $unreadCount = $notificationService->unreadCountFor($user);
            $viewAllHref = $safeRoute('communication-center.index')
                ?? $safeRoute('dashboard');

            $view->with([
                'topbarNotifications' => $notifications->values(),
                'topbarNotificationCount' => $unreadCount,
                'topbarNotificationsViewAllHref' => $viewAllHref,
                'sidebarPendingCounts' => $sidebarPendingCounts,
            ]);
        });
    }

    private function sidebarNotificationCacheKey(int $userId, int $organizationId, string $effectiveRole, int $roleId): string
    {
        return implode(':', [
            'layout_notifications',
            'v1',
            'org', $organizationId,
            'user', $userId,
            'role', $effectiveRole !== '' ? $effectiveRole : 'none',
            'role_id', $roleId,
        ]);
    }

    private function sidebarNotificationCounts(int $organizationId, $user): array
    {
        $today = now()->toDateString();
        $sidebarPendingCounts = [];
        $overdueRentalsCount = 0;
        $pendingInvoiceCount = 0;
        $deliveriesToday = 0;
        $pickupsToday = 0;
        $maintenanceAssets = 0;
        $followUpsDueToday = 0;
        $overdueFollowUps = 0;
        $failedPickupCount = 0;
        $failedDeliveryCount = 0;
        $assignedFollowUps = 0;

        if ($user?->canAccessModule('rentals', 'read') ?? false) {
            $overdueRentalsCount = Rental::query()
                ->where('organization_id', $organizationId)
                ->where('status', 'active')
                ->whereDate('end_date', '<', $today)
                ->whereHas('deliveries', function ($query) {
                    $query->where('type', 'delivery')->where('status', 'completed');
                })
                ->count();

            if ($overdueRentalsCount > 0) {
                $sidebarPendingCounts['rentals'] = $overdueRentalsCount;
            }
        }

        if ($user?->canAccessModule('deliveries', 'read') ?? false) {
            $openDeliveryTasks = Delivery::query()
                ->where('organization_id', $organizationId)
                ->where('type', 'delivery')
                ->whereIn('status', ['pending', 'in_progress'])
                ->count();

            $openPickupTasks = Delivery::query()
                ->where('organization_id', $organizationId)
                ->where('type', 'pickup')
                ->whereIn('status', ['pending', 'in_progress'])
                ->count();

            $deliveriesToday = Delivery::query()
                ->where('organization_id', $organizationId)
                ->where('type', 'delivery')
                ->whereIn('status', ['pending', 'in_progress'])
                ->whereDate('scheduled_at', $today)
                ->count();

            $pickupsToday = Delivery::query()
                ->where('organization_id', $organizationId)
                ->where('type', 'pickup')
                ->whereIn('status', ['pending', 'in_progress'])
                ->whereDate('scheduled_at', $today)
                ->count();

            $failedPickupCount = Schema::hasColumn('deliveries', 'pickup_status')
                ? Delivery::query()
                    ->where('organization_id', $organizationId)
                    ->where('type', 'pickup')
                    ->where('pickup_status', 'failed_attempt')
                    ->count()
                : 0;

            $failedDeliveryCount = Delivery::query()
                ->where('organization_id', $organizationId)
                ->where('type', 'delivery')
                ->where('status', 'cancelled')
                ->count();

            $sidebarPendingCounts['tasks_board'] = $openDeliveryTasks + $openPickupTasks;
        }

        if ($user?->canAccessModule('invoices', 'read') ?? false) {
            $pendingInvoiceCount = Invoice::query()
                ->where('organization_id', $organizationId)
                ->whereIn('payment_status', ['unpaid', 'partial', 'overdue'])
                ->count();

            if ($pendingInvoiceCount > 0) {
                $sidebarPendingCounts['invoices'] = $pendingInvoiceCount;
            }
        }

        if ($user?->canAccessModule('assets', 'read') ?? false) {
            $awaitingVerificationAssets = Asset::query()
                ->where('organization_id', $organizationId)
                ->where('asset_status', Asset::STATUS_AWAITING_VERIFICATION)
                ->count();

            $maintenanceAssets = Asset::query()
                ->where('organization_id', $organizationId)
                ->where('asset_status', 'maintenance')
                ->count();

            $sidebarPendingCounts['return_verification'] = $awaitingVerificationAssets;
        }

        if (Schema::hasTable('follow_ups') && !($user?->isDelivery() ?? false)) {
            $followUpBaseQuery = FollowUp::query()
                ->where('organization_id', $organizationId)
                ->whereNotIn('status', [FollowUp::STATUS_COMPLETED, FollowUp::STATUS_CANCELLED]);

            $followUpsDueToday = (clone $followUpBaseQuery)
                ->whereDate('due_at', $today)
                ->count();

            $overdueFollowUps = (clone $followUpBaseQuery)
                ->where('due_at', '<', now())
                ->count();

            $assignedFollowUps = (clone $followUpBaseQuery)
                ->where('assigned_user_id', $user->id)
                ->count();

            if ($followUpsDueToday > 0 || $overdueFollowUps > 0) {
                $sidebarPendingCounts['communication_center'] = $followUpsDueToday + $overdueFollowUps;
            }
        }

        return [
            'sidebarPendingCounts' => $sidebarPendingCounts,
            'overdueRentalsCount' => $overdueRentalsCount,
            'pendingInvoiceCount' => $pendingInvoiceCount,
            'deliveriesToday' => $deliveriesToday,
            'pickupsToday' => $pickupsToday,
            'maintenanceAssets' => $maintenanceAssets,
            'followUpsDueToday' => $followUpsDueToday,
            'overdueFollowUps' => $overdueFollowUps,
            'failedPickupCount' => $failedPickupCount,
            'failedDeliveryCount' => $failedDeliveryCount,
            'assignedFollowUps' => $assignedFollowUps,
        ];
    }

    private function shouldApplyDefaultOrganization(\Illuminate\Database\Eloquent\Model $model): bool
    {
        if ($model instanceof Organization) {
            return false;
        }

        if (!InternalOrganization::enabled()) {
            return false;
        }

        static $columnCache = [];

        $connection = $model->getConnectionName() ?: config('database.default');
        $cacheKey = $connection.':'.$model->getTable();

        if (!array_key_exists($cacheKey, $columnCache)) {
            $columnCache[$cacheKey] = Schema::connection($connection)->hasColumn($model->getTable(), 'organization_id');
        }

        return $columnCache[$cacheKey];
    }
}
