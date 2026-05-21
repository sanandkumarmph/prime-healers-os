<?php

namespace App\Services;

use App\Models\User;

class DashboardVisibilityService
{
    public function forUser(?User $user): array
    {
        if (!$user) {
            return [
                'delivery_focused' => false,
                'warehouse_focused' => false,
                'finance_focused' => false,
                'sales_focused' => false,
                'finance_widgets' => false,
                'staff_workload' => false,
                'business_signals' => false,
                'inventory_intelligence' => false,
                'sales_analytics' => false,
                'organization_analytics' => false,
                'widget_keys' => [],
                'widget_order' => [],
            ];
        }

        $isDeliveryFocused = $this->isDeliveryFocused($user);
        $isWarehouseFocused = $this->isWarehouseFocused($user);
        $isFinanceFocused = $this->isFinanceFocused($user);
        $isSalesFocused = $this->isSalesFocused($user);
        $widgets = $this->widgetRegistry()->visibleWidgetsForUser($user);
        $widgetKeys = $widgets->pluck('widget_key')->values()->all();
        $widgetMap = $widgets->mapWithKeys(fn (array $item) => [$item['widget_key'] => $item])->all();
        $canViewFinanceWidgets = $this->containsWidget($widgetKeys, [
            'primary_outstanding_dues',
            'kpi_outstanding_invoices',
            'kpi_unpaid_renewal_invoices',
            'kpi_unbilled_sales',
            'kpi_collections_this_month',
            'snapshot_collections_today',
            'snapshot_open_invoices',
            'widget_pending_payments',
            'section_finance_summary',
            'section_recent_payments',
            'alert_large_unpaid_invoices',
        ]);

        return [
            'delivery_focused' => $isDeliveryFocused,
            'warehouse_focused' => $isWarehouseFocused,
            'finance_focused' => $isFinanceFocused,
            'sales_focused' => $isSalesFocused,
            'finance_widgets' => $canViewFinanceWidgets,
            'staff_workload' => in_array('section_staff_workload', $widgetKeys, true),
            'business_signals' => in_array('section_business_partner_signals', $widgetKeys, true),
            'inventory_intelligence' => in_array('section_inventory_intelligence', $widgetKeys, true),
            'sales_analytics' => in_array('section_sales_overview', $widgetKeys, true),
            'organization_analytics' => in_array('section_organization_analytics', $widgetKeys, true),
            'widget_keys' => $widgetKeys,
            'widget_order' => $widgetMap,
        ];
    }

    public function widgetRegistry(): DashboardWidgetRegistryService
    {
        return app(DashboardWidgetRegistryService::class);
    }

    public function canViewFinanceWidgets(?User $user): bool
    {
        if (!$user) {
            return false;
        }

        return $this->containsWidget($this->widgetRegistry()->widgetKeysForUser($user), [
            'primary_outstanding_dues',
            'kpi_outstanding_invoices',
            'kpi_unpaid_renewal_invoices',
            'kpi_unbilled_sales',
            'kpi_collections_this_month',
            'snapshot_collections_today',
            'snapshot_open_invoices',
            'widget_pending_payments',
            'section_finance_summary',
        ]);
    }

    public function canViewInventoryIntelligence(?User $user): bool
    {
        if (!$user) {
            return false;
        }

        return $this->isLeadership($user)
            || $this->hasAnyDashboardVisibilityPermission($user, [
                'dashboard.inventory_intelligence',
                'dashboard.organization_analytics',
            ])
            || $this->isWarehouseFocused($user);
    }

    public function canViewStaffWorkload(?User $user): bool
    {
        if (!$user) {
            return false;
        }

        return $this->isLeadership($user)
            || $this->hasAnyDashboardVisibilityPermission($user, [
                'dashboard.staff_workload',
                'dashboard.organization_analytics',
            ]);
    }

    public function canViewBusinessSignals(?User $user): bool
    {
        if (!$user) {
            return false;
        }

        return $this->isLeadership($user)
            || $this->hasAnyDashboardVisibilityPermission($user, [
                'dashboard.business_signals',
                'dashboard.organization_analytics',
            ]);
    }

    public function canViewSalesAnalytics(?User $user): bool
    {
        if (!$user) {
            return false;
        }

        return ($this->isLeadership($user)
                || $this->hasAnyDashboardVisibilityPermission($user, [
                    'dashboard.sales_analytics',
                    'dashboard.organization_analytics',
                ]))
            && $user->canAccessAnyModule(['sales', 'rentals'], 'read');
    }

    public function canViewOrganizationAnalytics(?User $user): bool
    {
        if (!$user) {
            return false;
        }

        return $this->isLeadership($user)
            || $this->hasAnyDashboardVisibilityPermission($user, [
                'dashboard.organization_analytics',
            ]);
    }

    public function isDeliveryFocused(?User $user): bool
    {
        return in_array($user?->effective_role, [
            User::ROLE_DELIVERY,
            User::ROLE_DELIVERY_EXECUTIVE,
            User::ROLE_VENDOR,
            User::ROLE_THIRD_PARTY,
        ], true);
    }

    public function isWarehouseFocused(?User $user): bool
    {
        if (!$user) {
            return false;
        }

        return ($user->canAccessModule('assets', 'read') || $user->canAccessModule('warehouses', 'read'))
            && !$user->canAccessModule('sales', 'read')
            && !$user->canAccessModule('payments', 'read')
            && !$user->canAccessModule('invoices', 'read')
            && !$user->canAccessModule('deliveries', 'read');
    }

    public function isFinanceFocused(?User $user): bool
    {
        if (!$user) {
            return false;
        }

        return $this->canViewFinanceWidgets($user)
            && !$user->canAccessModule('deliveries', 'read')
            && !$user->canAccessModule('assets', 'read')
            && !$user->canAccessModule('warehouses', 'read');
    }

    public function isSalesFocused(?User $user): bool
    {
        return in_array($user?->effective_role, [
            User::ROLE_SALES,
            User::ROLE_SALES_RENEWALS,
        ], true);
    }

    private function isLeadership(User $user): bool
    {
        return $user->isSuperAdmin() || $user->isAdminOperations();
    }

    private function hasAnyDashboardVisibilityPermission(User $user, array $permissions): bool
    {
        return $user->hasAnyPermission($permissions);
    }

    private function containsWidget(array $widgetKeys, array $candidates): bool
    {
        foreach ($candidates as $widgetKey) {
            if (in_array($widgetKey, $widgetKeys, true)) {
                return true;
            }
        }

        return false;
    }
}
