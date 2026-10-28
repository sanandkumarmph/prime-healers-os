<?php

namespace App\Services\Availability;

use App\Models\Asset;
use App\Models\Product;
use App\Models\Rental;
use App\Models\RentalAsset;
use App\Models\User;
use App\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/** A read-only forecast. A quotation never reserves stock. */
class RentalAvailability
{
    public function authorize(User $user): void
    {
        abort_unless($user->is_active && $user->organization()->where('is_active', true)->exists()
            && ($user->canAccessModule('rentals', 'read')
                || ($user->canAccessModule('enquiries', 'read') && $user->canAccessModule('quotations', 'create'))), 403);
    }

    public function cityScoped(User $user): bool
    {
        $module = $user->canAccessModule('rentals', 'read') ? 'rentals' : 'quotations';

        // PHOS uses role-based self/assigned scopes rather than Rentnexis's
        // editable scope_type column. Restricted staff see their operating city.
        return $user->scopeType($module) !== 'all';
    }

    public function warehouses(User $user): Builder
    {
        $this->authorize($user);
        $query = Warehouse::where('organization_id', $user->organization_id)->where('is_active', true);
        if ($this->cityScoped($user)) {
            $query->where('city_id', $user->city_id ?: -1);
        }

        return $query;
    }

    public function check(User $user, array $input): array
    {
        $this->authorize($user);
        $timezone = config('app.timezone', 'Asia/Kolkata');
        $today = CarbonImmutable::today($timezone)->toDateString();
        $start = $input['start_date'];
        $end = $input['end_date'];
        if ($start < $today || CarbonImmutable::parse($start)->diffInDays(CarbonImmutable::parse($end)) > 366) {
            throw ValidationException::withMessages(['start_date' => 'Choose today or a future date, for a period of up to one year.']);
        }
        $warehouseId = ! empty($input['warehouse_id']) ? (int) $input['warehouse_id'] : null;
        $warehouseIds = $this->warehouses($user)->pluck('id');
        if ($warehouseId && ! $warehouseIds->contains($warehouseId)) {
            throw ValidationException::withMessages(['warehouse_id' => 'Choose an available warehouse in your operating area.']);
        }
        $requests = collect($input['lines'])->groupBy('product_id')->map(fn ($lines) => $lines->sum('quantity'));
        $products = Product::where('organization_id', $user->organization_id)->whereIn('id', $requests->keys())->get();
        if ($products->count() !== $requests->count() || $products->contains(fn ($p) => ! $p->canRent())) {
            throw ValidationException::withMessages(['lines' => 'Choose rental products from your organisation.']);
        }
        $rows = [];
        foreach ($products as $product) {
            $counts = ['ready_now' => 0, 'expected_back' => 0, 'reserved_later' => 0, 'committed' => 0, 'overdue' => 0, 'unavailable' => 0];
            $locationUnknown = ! $product->tracksRentalStock() && ($warehouseId || $this->cityScoped($user));
            if (! $locationUnknown && $product->tracksRentalStock()) {
                $assets = Asset::where('organization_id', $user->organization_id)->where('product_id', $product->id)
                    ->where('asset_stage', Asset::STAGE_RENTAL_STOCK)->whereIn('warehouse_id', $warehouseIds)
                    ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
                    ->when($this->cityScoped($user), fn ($q) => $q->whereIn('warehouse_id', $warehouseIds))->get();
                // Do not hide inconsistent assignments: they block the unit instead of making it look available.
                $assignments = RentalAsset::with('rental.rentalItems')->whereIn('asset_id', $assets->pluck('id'))
                    ->whereNull('returned_at')->get()->groupBy('asset_id');
                foreach ($assets as $asset) {
                    if ($asset->condition_status !== 'good' || ! in_array($asset->asset_status, ['available', 'reserved', 'rented'], true)) {
                        $counts['unavailable']++;

                        continue;
                    }
                    $holds = $assignments->get($asset->id, collect());
                    if ($holds->isEmpty()) {
                        $counts[$asset->asset_status === 'available' ? 'ready_now' : 'unavailable']++;

                        continue;
                    }
                    $buckets = [];
                    foreach ($holds as $hold) {
                        $rental = $hold->rental;
                        if ((int) $hold->organization_id !== (int) $user->organization_id || ! $rental
                            || (int) $rental->organization_id !== (int) $user->organization_id || $rental->status !== 'active') {
                            $buckets[] = 'unavailable';

                            continue;
                        }
                        $lines = $rental->rentalItems->where('product_id', $product->id);
                        if ($rental->fulfilment_source === 'vendor_supplied') {
                            $buckets[] = 'unavailable';

                            continue;
                        }
                        $from = $rental->start_date?->format('Y-m-d');
                        $until = $rental->end_date?->format('Y-m-d');
                        foreach ($lines as $line) {
                            if ($line->start_date && (! $from || $line->start_date->format('Y-m-d') < $from)) {
                                $from = $line->start_date->format('Y-m-d');
                            }
                            if ($line->end_date && (! $until || $line->end_date->format('Y-m-d') > $until)) {
                                $until = $line->end_date->format('Y-m-d');
                            }
                        }
                        $buckets[] = $this->bucket($from, $until, $today, $start, $end);
                    }
                    // One serial unit is counted once, including legacy duplicate assignment rows.
                    foreach (['unavailable', 'overdue', 'committed', 'expected_back', 'reserved_later'] as $bucket) {
                        if (in_array($bucket, $buckets, true)) {
                            $counts[$bucket]++;
                            break;
                        }
                    }
                }
            } elseif (! $locationUnknown && $product->usesUntrackedStock()) {
                $counts['ready_now'] = max(0, (int) $product->available_quantity);
                $rentals = Rental::with('rentalItems')->where('organization_id', $user->organization_id)->where('status', 'active')
                    ->where(fn ($q) => $q->where('product_id', $product->id)->orWhereHas('rentalItems', fn ($l) => $l->where('product_id', $product->id)))->get();
                foreach ($rentals as $rental) {
                    $lines = $rental->displayRentalItems();
                    foreach ($lines as $line) {
                        if ((int) $line->product_id !== (int) $product->id || $rental->fulfilment_source === 'vendor_supplied') {
                            continue;
                        }
                        $quantity = max(0, (int) ($line->ordered_quantity ?? $line->quantity) - (int) ($line->returned_quantity ?? 0));
                        $from = ($line->start_date ?? $rental->start_date) ? CarbonImmutable::parse($line->start_date ?? $rental->start_date)->toDateString() : null;
                        $until = ($line->end_date ?? $rental->end_date) ? CarbonImmutable::parse($line->end_date ?? $rental->end_date)->toDateString() : null;
                        if ($rental->end_date && (! $until || $rental->end_date->format('Y-m-d') > $until)) {
                            $until = $rental->end_date->format('Y-m-d');
                        }
                        $counts[$this->bucket($from, $until, $today, $start, $end)] += $quantity;
                    }
                }
                // Legacy quantities can be inconsistent. Never forecast beyond the declared pool.
                $room = max(0, (int) $product->total_quantity - $counts['ready_now']);
                $counts['expected_back'] = min($counts['expected_back'], $room);
                $counts['reserved_later'] = min($counts['reserved_later'], max(0, $room - $counts['expected_back']));
            }
            $forecast = $counts['ready_now'] + $counts['expected_back'] + $counts['reserved_later'];
            $requested = (int) $requests[$product->id];
            $rows[] = ['product_id' => $product->id, 'name' => $product->name, 'requested' => $requested,
                'tracked' => $product->tracksRentalStock(), 'location_unknown' => (bool) $locationUnknown,
                'forecast' => $forecast, 'shortfall' => max(0, $requested - $forecast),
                'status' => $locationUnknown ? 'Check total stock' : ($counts['ready_now'] >= $requested ? 'Ready to allocate' : ($forecast >= $requested ? 'Depends on scheduling / returns' : 'Shortfall'))] + $counts;
        }

        return ['start_date' => $start, 'end_date' => $end, 'checked_at' => now()->toIso8601String(), 'rows' => $rows];
    }

    private function bucket(?string $from, ?string $until, string $today, string $start, string $end): string
    {
        if (! $from || ! $until || $until < $from) {
            return 'unavailable';
        }
        if ($until < $today) {
            return 'overdue';
        }
        if ($until < $start) {
            return 'expected_back';
        }
        if ($from > $end) {
            return 'reserved_later';
        }

        return 'committed';
    }
}
