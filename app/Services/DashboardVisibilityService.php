<?php

namespace App\Services;

use App\Models\User;

class DashboardVisibilityService
{
    public function forUser(?User $user): array
    {
        $isDeliveryFocused = $this->isDeliveryFocused($user);
        $isWarehouseFocused = $this->isWarehouseFocused($user);
        $isFinanceFocused = $this->isFinanceFocused($user);
        $isSalesFocused = $this->isSalesFocused($user);
        $canViewFinanceWidgets = $this->canViewFinanceWidgets($user);
        $canViewOrganizationAnalytics = $this->canViewOrganizationAnalytics($user) && $canViewFinanceWidgets;

        return [
            'delivery_focused' => $isDeliveryFocused,
            'warehouse_focused' => $isWarehouseFocused,
            'finance_focused' => $isFinanceFocused,
            'sales_focused' => $isSalesFocused,
            'finance_widgets' => $canViewFinanceWidgets,
            'staff_workload' => $this->canViewStaffWorkload($user),
            'business_signals' => $this->canViewBusinessSignals($user),
            'inventory_intelligence' => $this->canViewInventoryIntelligence($user),
            'sales_analytics' => $this->canViewSalesAnalytics($user),
            'organization_analytics' => $canViewOrganizationAnalytics,
        ];
    }

    public function canViewFinanceWidgets(?User $user): bool
    {
        return $user?->canViewFinanceDashboard() ?? false;
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
}
