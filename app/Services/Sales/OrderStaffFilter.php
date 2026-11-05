<?php

namespace App\Services\Sales;

use App\Models\{Rental, Sale, User};
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\{DB, Schema};

class OrderStaffFilter
{
    public function options(User $actor, string $module): Collection
    {
        return User::where('organization_id', $actor->organization_id)->with('assignedRole')
            ->when($actor->scopeType($module) !== 'all', fn ($users) => $users->whereKey($actor->id))
            ->orderBy('name')->get()
            ->reject(fn (User $user) => in_array($user->effective_role, [User::ROLE_VENDOR, User::ROLE_THIRD_PARTY], true))
            ->pluck('name', 'id');
    }

    public function selected(Request $request, User $actor, string $module): int
    {
        $staff = $request->integer('salesperson_id');
        if ($staff !== 0) {
            abort_unless($this->options($actor, $module)->has($staff), 404);
        }

        return $staff;
    }

    public function ordersWithCreator(int $organization, string $kind): array
    {
        $table = $kind === 'rental' ? 'rentals' : 'sales';
        $model = $kind === 'rental' ? Rental::class : Sale::class;
        $creation = DB::table('activity_logs')->where('organization_id', $organization)
            ->where('subject_type', $model)->where('action', $kind.'.created')->whereNotNull('user_id')
            ->selectRaw('subject_id, MIN(user_id) as user_id')->groupBy('subject_id')->havingRaw('COUNT(DISTINCT user_id) = 1');
        $staffColumns = [];
        foreach (['created_by_user_id', 'created_by'] as $column) {
            if (Schema::hasColumn($table, $column)) {
                $staffColumns[] = 'NULLIF(order_record.'.$column.', 0)';
            }
        }
        $staffColumns[] = 'creation.user_id';
        $creator = count($staffColumns) > 1 ? 'COALESCE('.implode(', ', $staffColumns).')' : 'creation.user_id';
        $orders = DB::table($table.' as order_record')->where('order_record.organization_id', $organization)
            ->leftJoinSub($creation, 'creation', 'creation.subject_id', '=', 'order_record.id');

        return [$orders, $creator];
    }

    public function bookedOrders(int $organization, string $kind, string $from, string $to): array
    {
        [$orders, $creator] = $this->ordersWithCreator($organization, $kind);
        $orders->whereNotIn('order_record.'.($kind === 'rental' ? 'status' : 'payment_status'), ['draft', 'cancelled', 'void'])
            ->when($kind === 'sale', fn ($query) => $query->whereNull('order_record.rental_id'))
            ->when($from !== '', fn ($query) => $query->where('order_record.created_at', '>=', $from))
            ->when($to !== '', fn ($query) => $query->whereDate('order_record.created_at', '<=', $to));

        return [$orders, $creator];
    }

    public function bookingSalesperson(int $organization, string $creator): Builder
    {
        return User::where('users.organization_id', $organization)
            ->whereRaw('users.id = '.$creator)
            ->where(function ($users) use ($organization) {
                $users->where(fn ($legacy) => $legacy->whereNull('role_id')->where('role', User::ROLE_SALES))
                    ->orWhereHas('assignedRole', fn ($role) => $role->where('organization_id', $organization)
                        ->where('slug', User::ROLE_SALES));
            })->select('users.id')->toBase();
    }

    public function applyToOrders($query, Request $request, User $actor, string $kind, string $from, string $to): bool
    {
        $module = $kind.'s';
        $staff = $this->selected($request, $actor, $module);
        if (!$staff) {
            return false;
        }

        $booked = $request->query('salesperson_match') === 'booked';
        $query->whereIn($module.'.id', $this->matchingOrders((int) $actor->organization_id, $kind, $staff, $booked, $from, $to));

        return $booked;
    }

    public function matchingOrders(int $organization, string $kind, int $staff, bool $booked = false, string $from = '', string $to = ''): Builder
    {
        if ($booked) {
            [$orders, $creator] = $this->bookedOrders($organization, $kind, $from, $to);

            return $orders->whereRaw($creator.' = ?', [$staff])->select('order_record.id');
        }

        [$orders, $creator] = $this->ordersWithCreator($organization, $kind);
        $bookingSalesperson = $this->bookingSalesperson($organization, $creator);
        $orders->leftJoin('order_incentive_attributions as attribution', function ($join) use ($kind) {
            $join->on('attribution.organization_id', '=', 'order_record.organization_id')
                ->on('attribution.order_id', '=', 'order_record.id')->where('attribution.order_type', $kind);
        })->where(function ($query) use ($creator, $staff, $bookingSalesperson) {
            $query->where('attribution.sales_user_id', $staff)
                ->orWhere(fn ($closer) => $closer->where('attribution.excluded_from_incentives', false)
                    ->where(fn ($record) => $record->where('attribution.closer_user_id', $staff)
                        ->orWhere(fn ($historical) => $historical->whereNull('attribution.closer_user_id')
                            ->whereRaw($creator.' = ?', [$staff])->whereExists($bookingSalesperson))))
                ->orWhere(fn ($fallback) => $fallback->whereNull('attribution.sales_user_id')
                    ->where(fn ($attribution) => $attribution->whereNull('attribution.id')
                        ->orWhere('attribution.excluded_from_incentives', false))
                    ->whereRaw($creator.' = ?', [$staff]));
        });

        return $orders->select('order_record.id');
    }

    public function applyToInvoices($query, int $organization, int $staff): void
    {
        $sales = $this->matchingOrders($organization, 'sale', $staff);
        $rentals = $this->matchingOrders($organization, 'rental', $staff);
        $legacyLinks = DB::table('invoice_items')->where('source_type', 'sale')->whereNotNull('source_id')
            ->selectRaw('invoice_id, MIN(source_id) as sale_id')->groupBy('invoice_id')->havingRaw('COUNT(DISTINCT source_id) = 1');
        $legacySales = DB::query()->fromSub($legacyLinks, 'legacy_sales')->whereIn('sale_id', clone $sales)->select('invoice_id');

        $query->where(fn ($invoices) => $invoices->whereIn('invoices.sale_id', $sales)
            ->orWhere(fn ($rentalInvoices) => $rentalInvoices->whereNull('invoices.sale_id')->whereIn('invoices.rental_id', $rentals))
            ->orWhere(fn ($legacy) => $legacy->whereNull('invoices.sale_id')->whereNull('invoices.rental_id')->whereIn('invoices.id', $legacySales)));
    }
}
