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
use App\Models\User;
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
use App\Services\Auth\PasswordRecovery;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\Auth;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Facades\RateLimiter;

class AppServiceProvider extends ServiceProvider
{
    private const SIDEBAR_NOTIFICATION_CACHE_TTL_SECONDS = 120;

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(\App\Services\CustomerPayments\PaymentLinkGuard::class);
        View::share('internalSingleOrgMode', InternalOrganization::enabled());
        View::share('internalCompanyName', InternalOrganization::companyName());
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(Login::class, function (Login $event): void {
            if ($event->guard === 'web' && request()->hasSession()) {
                request()->session()->put('password_hash_web', Auth::guard('web')->hashPasswordForCookie($event->user->getAuthPassword()));
            }
        });
        ResetPassword::createUrlUsing(fn (User $user, string $token) => app(PasswordRecovery::class)->resetUrl($user, $token));
        RateLimiter::for('password-recovery', fn (Request $request) => [
            Limit::perMinute(10)->by('recovery-ip:'.$request->ip()),
            Limit::perMinute(2)->by('recovery-email:'.hash('sha256', is_string($request->input('email')) ? strtolower(trim($request->input('email'))) : 'invalid-input')),
        ]);
        RateLimiter::for('password-reset', fn (Request $request) => Limit::perMinute(10)->by('reset-ip:'.$request->ip()));
        RateLimiter::for('login-ip', fn (Request $request) => Limit::perMinute(20)->by('login-ip:'.$request->ip()));
        RateLimiter::for('account-security', fn (Request $request) => Limit::perMinute(5)->by('account-security:'.($request->user()?->id ?? $request->ip())));

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

        Gate::define('manage-bulk-records', fn (User $user): bool => $user->isSuperAdmin());
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

            $sidebarPendingCounts = Cache::remember(
                $this->sidebarNotificationCacheKey($user->id, (int) $organizationId, (string) $user->effective_role, (int) ($user->role_id ?? 0)),
                now()->addSeconds(self::SIDEBAR_NOTIFICATION_CACHE_TTL_SECONDS),
                function () use ($organizationId, $user) {
                    return $this->sidebarNotificationCounts((int) $organizationId, $user);
                }
            );

            $notificationService = app(NotificationCenterService::class);
            $notifications = $notificationService->latestFor($user, 8);
            $unreadCount = $notificationService->unreadCountFor($user);
            $viewAllHref = $safeRoute('notifications.index')
                ?? $safeRoute('communication-center.index')
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
            'v2',
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
            $sidebarPendingCounts['tasks_board'] = Delivery::query()
                ->where('organization_id', $organizationId)
                ->whereIn('type', ['delivery', 'pickup'])
                ->whereIn('status', ['pending', 'in_progress'])
                ->count();
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

            if ($followUpsDueToday > 0 || $overdueFollowUps > 0) {
                $sidebarPendingCounts['communication_center'] = $followUpsDueToday + $overdueFollowUps;
            }
        }

        return $sidebarPendingCounts;
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
