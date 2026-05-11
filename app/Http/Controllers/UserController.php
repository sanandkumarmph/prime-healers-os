<?php

namespace App\Http\Controllers;

use App\Models\City;
use App\Models\Delivery;
use App\Models\Invoice;
use App\Models\Rental;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Support\InternalOrganization;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class UserController extends Controller
{
    private function orgId(): int
    {
        return (int) (InternalOrganization::id(auth()->user()) ?? auth()->user()->organization_id);
    }

    private function scopedUser(User $user): User
    {
        abort_if($user->organization_id !== $this->orgId(), 403);

        return $user;
    }

    private function validationRules(?User $user = null): array
    {
        $organizationId = $this->orgId();

        return [
            'name' => 'required|string|max:255',
            'email' => [
                'required',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($user?->id),
            ],
            'phone' => PhoneNumber::validationRules(),
            'phone_country_code' => 'nullable|string|max:8',
            'address' => 'nullable|string',
            'role_id' => ['required', Rule::exists('roles', 'id')->where(fn ($query) => $query->where('organization_id', $organizationId))],
            'city_id' => ['nullable', Rule::exists('cities', 'id')->where(fn ($query) => $query->where('organization_id', $organizationId))],
            'password' => [$user ? 'nullable' : 'required', 'string', 'min:8'],
            'is_active' => 'nullable|boolean',
        ];
    }

    public function index(Request $request)
    {
        $search = trim((string) $request->get('search', ''));
        $roleId = trim((string) $request->get('role_id', ''));
        $cityId = trim((string) $request->get('city_id', ''));
        $status = trim((string) $request->get('status', ''));

        $users = User::query()
            ->where('organization_id', $this->orgId())
            ->with(['assignedRole', 'cityRecord'])
            ->when($search !== '', function ($query) use ($search) {
                $query->where(function ($innerQuery) use ($search) {
                    $innerQuery->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            })
            ->when($roleId !== '', fn ($query) => $query->where('role_id', $roleId))
            ->when($cityId !== '', fn ($query) => $query->where('city_id', $cityId))
            ->when($status !== '', fn ($query) => $query->where('is_active', $status === 'active'))
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        return view('users.index', [
            'users' => $users,
            'search' => $search,
            'roleId' => $roleId,
            'cityId' => $cityId,
            'status' => $status,
            'roles' => Role::forOrganization($this->orgId())->where('is_active', true)->orderBy('name')->get(),
            'cities' => City::forOrganization($this->orgId())->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function create()
    {
        return view('users.create', [
            'user' => new User(['is_active' => true]),
            'roles' => Role::forOrganization($this->orgId())->where('is_active', true)->orderBy('name')->get(),
            'cities' => City::forOrganization($this->orgId())->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate($this->validationRules());
        $validated = PhoneNumber::normalizeFields($validated, ['phone']);
        $role = Role::forOrganization($this->orgId())->findOrFail($validated['role_id']);

        $user = User::create([
            'organization_id' => $this->orgId(),
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'address' => $validated['address'] ?? null,
            'password' => Hash::make($validated['password']),
            'role_id' => $role->id,
            'role' => $role->slug,
            'city_id' => $validated['city_id'] ?? null,
            'is_internal' => true,
            'is_active' => (bool) ($validated['is_active'] ?? false),
        ]);

        return redirect()->route('users.show', $user)->with('success', 'User created successfully.');
    }

    public function show(User $user)
    {
        $user = $this->scopedUser($user);
        $user->load(['assignedRole', 'cityRecord']);

        return view('users.show', compact('user'));
    }

    public function edit(User $user)
    {
        $user = $this->scopedUser($user);

        return view('users.edit', [
            'user' => $user,
            'roles' => Role::forOrganization($this->orgId())->where('is_active', true)->orderBy('name')->get(),
            'cities' => City::forOrganization($this->orgId())->where('is_active', true)->orderBy('name')->get(),
        ]);
    }

    public function update(Request $request, User $user)
    {
        $user = $this->scopedUser($user);
        $validated = $request->validate($this->validationRules($user));
        $validated = PhoneNumber::normalizeFields($validated, ['phone']);
        $role = Role::forOrganization($this->orgId())->findOrFail($validated['role_id']);

        $user->fill([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'address' => $validated['address'] ?? null,
            'role_id' => $role->id,
            'role' => $role->slug,
            'city_id' => $validated['city_id'] ?? null,
            'is_active' => (bool) ($validated['is_active'] ?? false),
        ]);

        if (filled($validated['password'] ?? null)) {
            $user->password = Hash::make($validated['password']);
        }

        $user->save();

        return redirect()->route('users.show', $user)->with('success', 'User updated successfully.');
    }

    public function destroy(User $user)
    {
        $user = $this->scopedUser($user);

        if ((int) $user->id === (int) auth()->id()) {
            return redirect()->route('users.index')->with('error', 'You cannot delete your own account.');
        }

        if (
            User::where('organization_id', $this->orgId())
                ->where('is_active', true)
                ->where('id', '!=', $user->id)
                ->doesntExist()
        ) {
            return redirect()->back()->with('error', 'Cannot delete the last active user in this organization.');
        }

        $dependencyLabels = [];

        if (Schema::hasColumn('rentals', 'created_by_user_id') && Rental::where('organization_id', $this->orgId())->where('created_by_user_id', $user->id)->exists()) {
            $dependencyLabels[] = 'rentals created by this user';
        }

        if (Schema::hasColumn('sales', 'created_by_user_id') && Sale::where('organization_id', $this->orgId())->where('created_by_user_id', $user->id)->exists()) {
            $dependencyLabels[] = 'sales created by this user';
        }

        if (Schema::hasColumn('sales', 'created_by') && Sale::where('organization_id', $this->orgId())->where('created_by', $user->id)->exists()) {
            $dependencyLabels[] = 'sales created by this user';
        }

        if (Schema::hasColumn('invoices', 'created_by') && Invoice::where('organization_id', $this->orgId())->where('created_by', $user->id)->exists()) {
            $dependencyLabels[] = 'invoices created by this user';
        }

        if (Schema::hasColumn('deliveries', 'assigned_user_id') && Delivery::where('organization_id', $this->orgId())->where('assigned_user_id', $user->id)->exists()) {
            $dependencyLabels[] = 'delivery or pickup assignments';
        }

        if (!empty($dependencyLabels)) {
            return redirect()
                ->back()
                ->with('error', 'Cannot delete this user because they are linked to ' . implode(', ', array_unique($dependencyLabels)) . '.');
        }

        $user->delete();

        return redirect()->route('users.index')->with('success', 'User deleted successfully.');
    }
}
