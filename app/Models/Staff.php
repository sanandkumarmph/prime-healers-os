<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class Staff extends Model
{
    public const ROLE_OPTIONS = [
        'admin',
        'office',
        'delivery',
        'pickup',
        'technician',
        'vendor',
        'third_party',
    ];

    public const ASSIGNMENT_ROLES = [
        'delivery',
        'pickup',
        'vendor',
        'third_party',
        'technician',
        'office',
        'admin',
    ];

    public const DELIVERY_ASSIGNMENT_ROLES = [
        'delivery',
        'pickup',
        'vendor',
        'third_party',
    ];

    protected $table = 'staff';

    protected $fillable = [
        'name',
        'role',
        'assignment_role',
        'is_assignment_enabled',
        'phone',
        'email',
        'address',
        'city',
        'notes',
        'joining_date',
        'salary',
        'status',
        'organization_id',
    ];

    protected $casts = [
        'is_assignment_enabled' => 'boolean',
    ];

    protected static ?bool $hasAssignmentRoleColumn = null;

    protected static ?bool $hasAssignmentEnabledColumn = null;

    protected static ?bool $hasCityColumn = null;

    protected static ?bool $hasNotesColumn = null;

    public static function hasAssignmentRoleColumn(): bool
    {
        return static::$hasAssignmentRoleColumn ??= Schema::hasColumn('staff', 'assignment_role');
    }

    public static function hasAssignmentEnabledColumn(): bool
    {
        return static::$hasAssignmentEnabledColumn ??= Schema::hasColumn('staff', 'is_assignment_enabled');
    }

    public static function hasCityColumn(): bool
    {
        return static::$hasCityColumn ??= Schema::hasColumn('staff', 'city');
    }

    public static function hasNotesColumn(): bool
    {
        return static::$hasNotesColumn ??= Schema::hasColumn('staff', 'notes');
    }

    public static function normalizedRole(?string $value): string
    {
        $role = strtolower(trim((string) $value));

        return match ($role) {
            'third party', 'third_party' => 'third_party',
            'delivery staff', 'delivery_staff' => 'delivery',
            'pickup staff', 'pickup_staff' => 'pickup',
            default => in_array($role, self::ROLE_OPTIONS, true) ? $role : 'office',
        };
    }

    public static function roleLabel(?string $value): string
    {
        return match (self::normalizedRole($value)) {
            'admin' => 'Admin',
            'office' => 'Office',
            'delivery' => 'Delivery Staff',
            'pickup' => 'Pickup Staff',
            'technician' => 'Technician',
            'vendor' => 'Vendor',
            'third_party' => 'Third Party',
            default => 'Office',
        };
    }

    public static function assignmentRoleOptions(): array
    {
        return self::ASSIGNMENT_ROLES;
    }

    public static function deliveryPickupAssignmentRoles(): array
    {
        return self::DELIVERY_ASSIGNMENT_ROLES;
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeStatusFilter(Builder $query, ?string $status): Builder
    {
        if (!filled($status)) {
            return $query;
        }

        return $query->where('status', $status);
    }

    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        $search = trim((string) $search);

        if ($search === '') {
            return $query;
        }

        return $query->where(function (Builder $innerQuery) use ($search) {
            $innerQuery->where('name', 'like', "%{$search}%")
                ->orWhere('phone', 'like', "%{$search}%")
                ->orWhere('email', 'like', "%{$search}%")
                ->orWhere('role', 'like', "%{$search}%");

            if (self::hasAssignmentRoleColumn()) {
                $innerQuery->orWhere('assignment_role', 'like', "%{$search}%");
            }

            if (self::hasCityColumn()) {
                $innerQuery->orWhere('city', 'like', "%{$search}%");
            }
        });
    }

    public function scopeRoleFilter(Builder $query, ?string $role): Builder
    {
        if (!filled($role)) {
            return $query;
        }

        $normalizedRole = self::normalizedRole($role);

        if (self::hasAssignmentRoleColumn()) {
            return $query->where(function (Builder $innerQuery) use ($normalizedRole) {
                $innerQuery->where('assignment_role', $normalizedRole)
                    ->orWhere('role', $normalizedRole);
            });
        }

        return $query->where('role', $normalizedRole);
    }

    public function scopeAssignmentEnabled(Builder $query): Builder
    {
        if (self::hasAssignmentEnabledColumn()) {
            return $query->where('is_assignment_enabled', true);
        }

        return $query;
    }

    public function scopeAssignableForDeliveryAndPickup(Builder $query): Builder
    {
        if (self::hasAssignmentRoleColumn()) {
            return $query->whereIn('assignment_role', self::DELIVERY_ASSIGNMENT_ROLES);
        }

        return $query->whereIn('role', self::DELIVERY_ASSIGNMENT_ROLES);
    }

    public function scopeEligibleForAssignments(Builder $query): Builder
    {
        return $query->active()
            ->assignmentEnabled()
            ->assignableForDeliveryAndPickup();
    }

    public function getEffectiveRoleAttribute(): string
    {
        if (self::hasAssignmentRoleColumn() && filled($this->assignment_role)) {
            return self::normalizedRole($this->assignment_role);
        }

        return self::normalizedRole($this->role);
    }

    public function getRoleDisplayAttribute(): string
    {
        return self::roleLabel($this->effective_role);
    }

    public function getAssignmentDisplayAttribute(): string
    {
        return $this->role_display;
    }

    public function getAssignmentEligibleAttribute(): bool
    {
        $effectiveRole = $this->effective_role;

        $enabled = self::hasAssignmentEnabledColumn()
            ? (bool) $this->is_assignment_enabled
            : in_array($effectiveRole, self::DELIVERY_ASSIGNMENT_ROLES, true);

        return $this->status === 'active'
            && $enabled
            && in_array($effectiveRole, self::DELIVERY_ASSIGNMENT_ROLES, true);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function deliveryRentals()
    {
        return $this->hasMany(Rental::class, 'delivery_staff_id');
    }

    public function pickupRentals()
    {
        return $this->hasMany(Rental::class, 'pickup_staff_id');
    }

    public function assignedDeliveries()
    {
        return $this->hasMany(Delivery::class, 'assigned_staff_id');
    }

    public function recentDeliveryAssignments()
    {
        return $this->assignedDeliveries()->where('type', 'delivery');
    }

    public function recentPickupAssignments()
    {
        return $this->assignedDeliveries()->where('type', 'pickup');
    }
}
