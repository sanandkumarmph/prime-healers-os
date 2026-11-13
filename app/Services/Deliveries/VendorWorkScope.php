<?php

namespace App\Services\Deliveries;

use App\Models\CylinderRefill;
use App\Models\Delivery;
use App\Models\Rental;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;

class VendorWorkScope
{
    public function deliveries(Builder $query, User $vendor): Builder
    {
        return $query->where('deliveries.organization_id', $vendor->organization_id)
            ->where('deliveries.assigned_user_id', $vendor->id);
    }

    public function rentals(Builder $query, User $vendor): Builder
    {
        return $query->where('rentals.organization_id', $vendor->organization_id)
            ->where(function (Builder $rentals) use ($vendor) {
                $rentals->whereHas('deliveries', fn (Builder $tasks) => $this->deliveries($tasks, $vendor)
                    ->whereIn('deliveries.type', ['delivery', 'pickup'])
                    ->where('deliveries.status', '!=', 'cancelled'));

                foreach ([
                    'cylinder_refills' => CylinderRefill::available(),
                    'cylinder_jobs' => Schema::hasTable('cylinder_jobs'),
                ] as $table => $available) {
                    if ($available) {
                        $rentals->orWhereExists(fn ($tasks) => $tasks->selectRaw('1')->from($table)
                            ->whereColumn($table.'.rental_id', 'rentals.id')
                            ->where($table.'.organization_id', $vendor->organization_id)
                            ->where($table.'.assigned_user_id', $vendor->id)
                            ->where($table.'.status', '!=', 'cancelled'));
                    }
                }
            });
    }

    public function canViewRental(User $vendor, Rental $rental): bool
    {
        return $this->rentals(Rental::query()->whereKey($rental->getKey()), $vendor)->exists();
    }

    public function cylinderTasks(Builder $query, User $vendor, Rental $rental): Builder
    {
        return $query->where('organization_id', $vendor->organization_id)
            ->where('assigned_user_id', $vendor->id)
            ->where('rental_id', $rental->id)
            ->where('status', '!=', 'cancelled');
    }

    public function assignedTo(User $vendor, Delivery $delivery): bool
    {
        return (int) $delivery->assigned_user_id > 0 && (int) $delivery->assigned_user_id === (int) $vendor->id;
    }
}
