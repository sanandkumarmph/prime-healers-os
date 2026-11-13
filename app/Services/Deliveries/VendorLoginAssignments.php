<?php

namespace App\Services\Deliveries;

use App\Models\User;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class VendorLoginAssignments
{
    public function options(int $organizationId): Collection
    {
        return $this->query($organizationId)->orderBy('name')->orderBy('id')->get()
            ->filter(fn (User $user) => $this->eligible($user))->values();
    }

    public function rule(int $organizationId): ValidationRule
    {
        return new class($this, $organizationId) implements ValidationRule {
            public function __construct(private VendorLoginAssignments $assignments, private int $organizationId)
            {
            }

            public function validate(string $attribute, mixed $value, Closure $fail): void
            {
                if (!$this->assignments->selectable($this->organizationId, (int) $value)) {
                    $fail('Choose an active Vendor login from this company.');
                }
            }
        };
    }

    public function selectable(int $organizationId, int $id): bool
    {
        return $this->eligible($this->query($organizationId)->find($id));
    }

    public function resolve(int $organizationId, ?int $id, string $field = 'vendor_user_id'): ?User
    {
        if ($id === null) {
            return null;
        }

        $user = $this->query($organizationId)->lockForUpdate()->find($id);
        if (!$this->eligible($user)) {
            throw ValidationException::withMessages([$field => 'Choose an active Vendor login from this company.']);
        }

        return $user;
    }

    private function query(int $organizationId): Builder
    {
        return User::query()->where('organization_id', $organizationId)->where('is_active', true)
            ->with('assignedRole')->where(function (Builder $users) use ($organizationId) {
                $users->where(fn (Builder $legacy) => $legacy->whereNull('role_id')->where('role', User::ROLE_VENDOR))
                    ->orWhereHas('assignedRole', fn (Builder $role) => $role->where('organization_id', $organizationId)
                        ->where('is_active', true)->where('slug', User::ROLE_VENDOR));
            });
    }

    private function eligible(?User $user): bool
    {
        return $user?->isVendor() && $user->canAccessModule('deliveries', 'read') && $user->canAccessModule('deliveries', 'update');
    }
}
