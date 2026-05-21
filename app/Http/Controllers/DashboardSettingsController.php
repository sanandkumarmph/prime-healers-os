<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Services\DashboardWidgetRegistryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardSettingsController extends Controller
{
    private function ensureAuthorized(): void
    {
        $user = auth()->user();

        abort_unless(
            $user?->isSuperAdmin()
            || $user?->isAdminOperations()
            || ($user?->canAccessModule('settings', 'update') ?? false),
            403
        );
    }

    private function orgId(): int
    {
        return (int) auth()->user()->organization_id;
    }

    private function registry(): DashboardWidgetRegistryService
    {
        return app(DashboardWidgetRegistryService::class);
    }

    public function edit(Request $request): View
    {
        $this->ensureAuthorized();

        $roles = Role::forOrganization($this->orgId())
            ->where('is_active', true)
            ->orderBy('name')
            ->get();

        $selectedRole = $roles->firstWhere('id', (int) $request->integer('role_id')) ?? $roles->first();

        abort_unless($selectedRole, 404);

        $widgets = $this->registry()->configurationForRole($selectedRole)
            ->groupBy('category');

        return view('organization.dashboard-settings', [
            'roles' => $roles,
            'selectedRole' => $selectedRole,
            'widgetsByCategory' => $widgets,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $this->ensureAuthorized();

        $validated = $request->validate([
            'role_id' => ['required', 'integer'],
            'widgets' => ['array'],
            'widgets.*.is_enabled' => ['nullable', 'boolean'],
            'widgets.*.sort_order' => ['nullable', 'integer', 'min:1', 'max:9999'],
        ]);

        $role = Role::forOrganization($this->orgId())->findOrFail((int) $validated['role_id']);

        $this->registry()->saveRoleConfiguration($role, $validated['widgets'] ?? []);

        return redirect()
            ->route('organization.dashboard-settings.edit', ['role_id' => $role->id])
            ->with('success', 'Dashboard widget settings updated successfully.');
    }
}
