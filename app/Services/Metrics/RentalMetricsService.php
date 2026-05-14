<?php

namespace App\Services\Metrics;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Rental;
use App\Models\RentalRenewal;
use App\Models\Sale;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RentalMetricsService
{
    public function headlineSnapshot(Builder $summaryQuery, ?Carbon $today = null): array
    {
        $today = ($today ?? Carbon::today())->copy()->startOfDay();

        $totalRentals = (clone $summaryQuery)
            ->where('status', '!=', 'cancelled')
            ->count();

        $activeRentals = (clone $summaryQuery)
            ->where('status', 'active')
            ->lifecycleStarted()
            ->count();

        $currentRentals = (clone $summaryQuery)
            ->effectivelyActive($today)
            ->count();

        $overdueRentals = (clone $summaryQuery)
            ->overdue($today)
            ->count();

        $returnedRentals = (clone $summaryQuery)
            ->where('status', 'returned')
            ->count();

        $endingSoonCount = (clone $summaryQuery)
            ->endingSoon($today)
            ->count();

        return [
            'totalRentals' => (int) $totalRentals,
            'activeRentals' => (int) $activeRentals,
            'currentRentals' => (int) $currentRentals,
            'overdueRentals' => (int) $overdueRentals,
            'returnedRentals' => (int) $returnedRentals,
            'endingSoonCount' => (int) $endingSoonCount,
        ];
    }

    public function dashboardLifecycleSnapshot(Collection $rentals, ?Carbon $today = null): array
    {
        $today = ($today ?? Carbon::today())->copy()->startOfDay();

        $liveRentals = $rentals->filter(function (Rental $rental) {
            return $rental->status === 'active'
                && !$rental->hasDeliveryPending()
                && $rental->hasDeliveryStarted();
        });

        $currentRentals = $liveRentals->filter(fn (Rental $rental) => !$rental->isOverdue($today));
        $overdueRentals = $liveRentals->filter(fn (Rental $rental) => $rental->isOverdue($today));

        $pendingDelivery = $rentals->filter(fn (Rental $rental) => $rental->hasDeliveryPending());
        $pendingPickup = $rentals->filter(function (Rental $rental) use ($today) {
            return $rental->status === 'active'
                && $rental->hasDeliveryStarted()
                && !$rental->isOverdue($today)
                && in_array($rental->pickupStatus(), ['pending', 'in_progress'], true);
        });

        $outForDelivery = $rentals->filter(fn (Rental $rental) => $rental->deliveryStatus() === 'in_progress');
        $outForPickup = $rentals->filter(fn (Rental $rental) => $rental->pickupStatus() === 'in_progress');
        $returnedRentals = $rentals->filter(fn (Rental $rental) => $rental->status === 'returned');
        $returnsDueToday = $liveRentals->filter(fn (Rental $rental) => optional($rental->end_date)?->isSameDay($today) ?? false);

        return [
            'activeRentals' => (int) $liveRentals->count(),
            'currentRentals' => (int) $currentRentals->count(),
            'overdueRentals' => (int) $overdueRentals->count(),
            'pendingDeliveryCount' => (int) $pendingDelivery->count(),
            'pendingPickupCount' => (int) $pendingPickup->count(),
            'outForDeliveryCount' => (int) $outForDelivery->count(),
            'outForPickupCount' => (int) $outForPickup->count(),
            'returnedRentals' => (int) $returnedRentals->count(),
            'returnsDueTodayCount' => (int) $returnsDueToday->count(),
        ];
    }

    public function renewalFinanceSnapshot(iterable $rentalIds, int $organizationId): array
    {
        if (!Schema::hasTable('rental_renewals')) {
            return [
                'unbilledRenewalCount' => 0,
                'unbilledRenewalAmount' => 0.0,
                'unpaidRenewalCount' => 0,
                'unpaidRenewalAmount' => 0.0,
            ];
        }

        $normalizedRentalIds = collect($rentalIds)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $renewals = RentalRenewal::query()
            ->where('organization_id', $organizationId)
            ->when($normalizedRentalIds->isNotEmpty(), fn ($query) => $query->whereIn('rental_id', $normalizedRentalIds->all()))
            ->with(['invoice', 'payment'])
            ->get();

        $unbilledRenewals = $renewals->filter(fn (RentalRenewal $renewal) => !$renewal->invoice_id && $renewal->outstandingAmount() > 0);
        $unpaidRenewals = $renewals->filter(function (RentalRenewal $renewal) {
            if (!$renewal->invoice) {
                return false;
            }

            return in_array((string) $renewal->invoice->payment_status, ['unpaid', 'partial', 'overdue'], true)
                && (float) ($renewal->invoice->balance_amount ?? 0) > 0;
        });

        return [
            'unbilledRenewalCount' => (int) $unbilledRenewals->count(),
            'unbilledRenewalAmount' => round((float) $unbilledRenewals->sum(fn (RentalRenewal $renewal) => $renewal->outstandingAmount()), 2),
            'unpaidRenewalCount' => (int) $unpaidRenewals->count(),
            'unpaidRenewalAmount' => round((float) $unpaidRenewals->sum(fn (RentalRenewal $renewal) => (float) ($renewal->invoice->balance_amount ?? 0)), 2),
        ];
    }

    public function pendingReceivablesSnapshot(
        Collection $dashboardRentalCollection,
        Builder $invoiceQuery,
        Builder $salesQuery,
        Carbon $today,
        int $organizationId
    ): array {
        $unpaidInvoiceCount = (clone $invoiceQuery)
            ->whereIn('payment_status', ['unpaid', 'partial', 'overdue'])
            ->count();

        $unpaidInvoiceAmount = round((float) (clone $invoiceQuery)
            ->whereIn('payment_status', ['unpaid', 'partial', 'overdue'])
            ->sum('balance_amount'), 2);

        $overdueInvoiceCount = (clone $invoiceQuery)
            ->overdue($today)
            ->count();

        $rentalIds = $dashboardRentalCollection
            ->pluck('id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $invoiceLinkedRentalIds = collect();
        $directRentalPaymentSums = collect();

        if ($rentalIds->isNotEmpty()) {
            $invoiceLinkedRentalIds = DB::table('invoice_items')
                ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
                ->where('invoices.organization_id', $organizationId)
                ->where('invoice_items.source_type', 'rental')
                ->whereIn('invoice_items.source_id', $rentalIds->all())
                ->whereNotIn('invoices.payment_status', ['cancelled'])
                ->pluck('invoice_items.source_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();

            if (Schema::hasColumn('invoices', 'rental_id')) {
                $invoiceLinkedRentalIds = $invoiceLinkedRentalIds
                    ->concat(
                        Invoice::query()
                            ->where('organization_id', $organizationId)
                            ->whereIn('rental_id', $rentalIds->all())
                            ->whereNotIn('payment_status', ['cancelled'])
                            ->pluck('rental_id')
                            ->map(fn ($id) => (int) $id)
                    )
                    ->unique()
                    ->values();
            }

            if (Schema::hasTable('payments') && Schema::hasColumn('payments', 'rental_id')) {
                $directRentalPaymentSums = Payment::query()
                    ->when(Schema::hasColumn('payments', 'organization_id'), fn ($query) => $query->where('organization_id', $organizationId))
                    ->whereIn('rental_id', $rentalIds->all())
                    ->when(Schema::hasColumn('payments', 'invoice_id'), fn ($query) => $query->whereNull('invoice_id'))
                    ->selectRaw('rental_id, COALESCE(SUM(amount), 0) as aggregate_amount')
                    ->groupBy('rental_id')
                    ->pluck('aggregate_amount', 'rental_id');
            }
        }

        $unbilledRentalReceivables = $dashboardRentalCollection
            ->filter(function (Rental $rental) use ($invoiceLinkedRentalIds) {
                if ($rental->status === 'cancelled') {
                    return false;
                }

                if (!$rental->hasDeliveryStarted()) {
                    return false;
                }

                return !$invoiceLinkedRentalIds->contains((int) $rental->id);
            })
            ->map(function (Rental $rental) use ($directRentalPaymentSums) {
                $grossAmount = $this->rentalCommercialTotal($rental);
                $directPayments = round((float) ($directRentalPaymentSums->get((int) $rental->id) ?? 0), 2);
                $balance = round(max($grossAmount - $directPayments, 0), 2);

                return [
                    'rental_id' => (int) $rental->id,
                    'balance' => $balance,
                ];
            })
            ->filter(fn ($row) => $row['balance'] > 0)
            ->values();

        $salesCollection = (clone $salesQuery)->get(['id', 'sale_amount', 'payment_status']);
        $saleIds = $salesCollection
            ->pluck('id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $invoiceLinkedSaleIds = collect();

        if ($saleIds->isNotEmpty()) {
            $invoiceLinkedSaleIds = DB::table('invoice_items')
                ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
                ->where('invoices.organization_id', $organizationId)
                ->where('invoice_items.source_type', 'sale')
                ->whereIn('invoice_items.source_id', $saleIds->all())
                ->whereNotIn('invoices.payment_status', ['cancelled'])
                ->pluck('invoice_items.source_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values();

            if (Schema::hasColumn('invoices', 'sale_id')) {
                $invoiceLinkedSaleIds = $invoiceLinkedSaleIds
                    ->concat(
                        Invoice::query()
                            ->where('organization_id', $organizationId)
                            ->whereIn('sale_id', $saleIds->all())
                            ->whereNotIn('payment_status', ['cancelled'])
                            ->pluck('sale_id')
                            ->map(fn ($id) => (int) $id)
                    )
                    ->unique()
                    ->values();
            }
        }

        $unbilledSaleReceivables = $salesCollection
            ->filter(function (Sale $sale) use ($invoiceLinkedSaleIds) {
                return $sale->payment_status !== 'paid'
                    && !$invoiceLinkedSaleIds->contains((int) $sale->id);
            })
            ->map(fn (Sale $sale) => [
                'sale_id' => (int) $sale->id,
                'balance' => round((float) ($sale->sale_amount ?? 0), 2),
            ])
            ->filter(fn ($row) => $row['balance'] > 0)
            ->values();

        return [
            'pendingReceivableCount' => (int) $unpaidInvoiceCount + $unbilledRentalReceivables->count() + $unbilledSaleReceivables->count(),
            'pendingReceivableAmount' => round($unpaidInvoiceAmount + $unbilledRentalReceivables->sum('balance') + $unbilledSaleReceivables->sum('balance'), 2),
            'pendingReceivableOverdueCount' => (int) $overdueInvoiceCount,
            'unbilledRentalReceivableCount' => (int) $unbilledRentalReceivables->count(),
            'unbilledRentalReceivableAmount' => round((float) $unbilledRentalReceivables->sum('balance'), 2),
            'unbilledSaleReceivableCount' => (int) $unbilledSaleReceivables->count(),
            'unbilledSaleReceivableAmount' => round((float) $unbilledSaleReceivables->sum('balance'), 2),
        ];
    }

    private function rentalCommercialTotal(Rental $rental): float
    {
        return round(
            (float) ($rental->rental_amount ?? 0)
            + (float) ($rental->deposit_amount ?? 0)
            + (float) ($rental->transport_amount ?? 0)
            + (float) ($rental->other_amount ?? 0),
            2
        );
    }
}
