<?php

namespace App\Http\Controllers;

use App\Models\Role;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class RoleController extends Controller
{
    private function orgId(): int
    {
        return (int) auth()->user()->organization_id;
    }

    private function scopedRole(Role $role): Role
    {
        abort_if($role->organization_id !== $this->orgId(), 403);

        return $role;
    }

    private function validationRules(?Role $role = null): array
    {
        $organizationId = $this->orgId();

        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('roles', 'name')
                    ->where(fn ($query) => $query->where('organization_id', $organizationId))
                    ->ignore($role?->id),
            ],
            'slug' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('roles', 'slug')
                    ->where(fn ($query) => $query->where('organization_id', $organizationId))
                    ->ignore($role?->id),
            ],
            'description' => 'nullable|string',
            'permissions' => 'nullable|array',
            'permissions.*' => 'nullable|array',
            'permissions.*.*' => ['string'],
            'is_active' => 'nullable|boolean',
        ];
    }

    public function index(Request $request)
    {
        $search = trim((string) $request->get('search', ''));
        $organizationId = $this->orgId();
        $roleSummaryQuery = Role::query()->forOrganization($organizationId);

        $summary = [
            'total_roles' => (clone $roleSummaryQuery)->count(),
            'system_roles' => (clone $roleSummaryQuery)->where('is_system', true)->count(),
            'custom_roles' => (clone $roleSummaryQuery)->where('is_system', false)->count(),
            'assigned_users' => User::query()
                ->where('organization_id', $organizationId)
                ->whereNotNull('role_id')
                ->count(),
        ];

        $roles = Role::query()
            ->forOrganization($organizationId)
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($innerQuery) use ($search) {
                    $innerQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('slug', 'like', "%{$search}%");
                });
            })
            ->with(['users:id,role_id,name,email,phone,is_active'])
            ->withCount('users')
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('roles.index', [
            'roles' => $roles,
            'search' => $search,
            'summary' => $summary,
            'permissionModules' => Role::moduleOptions(),
            'permissionActions' => Role::actionOptions(),
            'specialPermissions' => Role::specialPermissionOptions(),
        ]);
    }

    public function create()
    {
        return view('roles.create', [
            'role' => new Role(['is_active' => true]),
            'permissionModules' => Role::moduleOptions(),
            'permissionActions' => Role::actionOptions(),
            'specialPermissions' => Role::specialPermissionOptions(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->validationRules());
        $permissions = Role::normalizePermissions($validated['permissions'] ?? []);

        $role = Role::create([
            'organization_id' => $this->orgId(),
            'name' => $validated['name'],
            'slug' => Str::slug($validated['slug'] ?: $validated['name'], '_'),
            'description' => $validated['description'] ?? null,
            'permissions' => $permissions,
            'is_system' => false,
            'is_active' => (bool) ($validated['is_active'] ?? false),
        ]);

        return redirect()->route('roles.show', $role)->with('success', 'Role created successfully.');
    }

    public function show(Role $role)
    {
        $role = $this->scopedRole($role);
        $role->load(['users.cityRecord']);

        return view('roles.show', [
            'role' => $role,
            'permissionModules' => Role::moduleOptions(),
            'permissionActions' => Role::actionOptions(),
            'specialPermissions' => Role::specialPermissionOptions(),
        ]);
    }

    public function edit(Role $role)
    {
        $role = $this->scopedRole($role);

        return view('roles.edit', [
            'role' => $role,
            'permissionModules' => Role::moduleOptions(),
            'permissionActions' => Role::actionOptions(),
            'specialPermissions' => Role::specialPermissionOptions(),
        ]);
    }

    public function update(Request $request, Role $role)
    {
        $role = $this->scopedRole($role);
        $validated = $request->validate($this->validationRules($role));

        $role->update([
            'name' => $validated['name'],
            'slug' => Str::slug($validated['slug'] ?: $validated['name'], '_'),
            'description' => $validated['description'] ?? null,
            'permissions' => Role::normalizePermissions($validated['permissions'] ?? []),
            'is_active' => (bool) ($validated['is_active'] ?? false),
        ]);

        return redirect()->route('roles.show', $role)->with('success', 'Role updated successfully.');
    }

    public function destroy(Role $role)
    {
        $role = $this->scopedRole($role);

        if ($role->users()->exists()) {
            return redirect()->route('roles.index')->with('error', 'Remove assigned users before deleting this role.');
        }

        $role->delete();

        return redirect()->route('roles.index')->with('success', 'Role deleted successfully.');
    }
}
