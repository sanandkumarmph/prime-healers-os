<?php

namespace App\Services\Metrics;

use App\Models\Payment;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class InvoiceMetricsService
{
    private const OUTSTANDING_STATUSES = ['unpaid', 'partial', 'overdue'];
    public function __construct(private readonly CollectionMetricsService $collectionMetrics)
    {
    }

    public function summary(Builder $invoiceQuery, ?Carbon $today = null, bool $includeAmounts = true, ?bool $includeDues = null): array
    {
        $includeDues ??= $includeAmounts;
        $today = ($today ?? Carbon::today())->copy()->startOfDay();
        $openStatuses = self::OUTSTANDING_STATUSES;

        $totalInvoices = (clone $invoiceQuery)->count();
        $paidInvoices = (clone $invoiceQuery)->where('payment_status', 'paid')->count();
        $openInvoices = (clone $invoiceQuery)->whereIn('payment_status', $openStatuses)->count();
        $overdueInvoices = (clone $invoiceQuery)->overdue($today)->count();
        $outstandingAmount = $includeDues ? round((float) ((clone $invoiceQuery)->whereIn('payment_status', $openStatuses)->sum('balance_amount') ?? 0), 2) : 0.0;
        $totalBilled = $includeAmounts ? round((float) ((clone $invoiceQuery)->sum('total_amount') ?? 0), 2) : 0.0;
        $collections = $includeAmounts ? $this->collectionMetrics->summary(
            Payment::query()
                ->whereIn('invoice_id', (clone $invoiceQuery)->reorder()->select('invoices.id'))
                ->whereHas('invoice', fn (Builder $query) => $query
                    ->whereColumn('invoices.organization_id', 'payments.organization_id')),
            $today
        ) : [];

        $salesInvoiceIds = $this->salesLinkedInvoiceIds($invoiceQuery);
        $salesOutstandingCount = 0;
        $salesOutstandingAmount = 0.0;

        if ($salesInvoiceIds->isNotEmpty()) {
            $salesOutstandingCount = (clone $invoiceQuery)
                ->whereIn('id', $salesInvoiceIds->all())
                ->whereIn('payment_status', $openStatuses)
                ->count();

            $salesOutstandingAmount = $includeDues ? round((float) ((clone $invoiceQuery)
                ->whereIn('id', $salesInvoiceIds->all())
                ->whereIn('payment_status', $openStatuses)
                ->sum('balance_amount') ?? 0), 2) : 0.0;
        }

        $rentalOutstandingCount = max((int) $openInvoices - (int) $salesOutstandingCount, 0);
        $rentalOutstandingAmount = round(max((float) $outstandingAmount - (float) $salesOutstandingAmount, 0), 2);

        return [
            'totalInvoices' => (int) $totalInvoices,
            'paidInvoices' => (int) $paidInvoices,
            'openInvoices' => (int) $openInvoices,
            'overdueInvoices' => (int) $overdueInvoices,
            'outstandingAmount' => $outstandingAmount,
            'totalBilled' => $totalBilled,
            'collectedToday' => $collections['paymentsReceivedToday'] ?? 0.0,
            'collectedThisMonth' => $collections['paymentsReceivedThisMonth'] ?? 0.0,
            'salesOutstandingCount' => (int) $salesOutstandingCount,
            'salesOutstandingAmount' => $salesOutstandingAmount,
            'rentalOutstandingCount' => $rentalOutstandingCount,
            'rentalOutstandingAmount' => $rentalOutstandingAmount,
        ];
    }

    public function aging(Builder $invoiceQuery, Carbon $today): array
    {
        $day30 = $today->copy()->subDays(30)->toDateString();
        $day60 = $today->copy()->subDays(60)->toDateString();
        $day90 = $today->copy()->subDays(90)->toDateString();
        $balances = (clone $invoiceQuery)->reorder()
            ->whereNotIn('payment_status', ['paid', 'cancelled'])
            ->where('status', '!=', 'cancelled')
            ->where('balance_amount', '>', 0)
            ->selectRaw('SUM(CASE WHEN due_date IS NULL OR due_date >= ? THEN balance_amount ELSE 0 END) AS current_amount,
                SUM(CASE WHEN due_date < ? AND due_date >= ? THEN balance_amount ELSE 0 END) AS days_31_60,
                SUM(CASE WHEN due_date < ? AND due_date >= ? THEN balance_amount ELSE 0 END) AS days_61_90,
                SUM(CASE WHEN due_date < ? THEN balance_amount ELSE 0 END) AS days_91_plus',
                [$day30, $day30, $day60, $day60, $day90, $day90])->toBase()->first();

        return [
            'current' => (float) $balances->current_amount,
            'days_31_60' => (float) $balances->days_31_60,
            'days_61_90' => (float) $balances->days_61_90,
            'days_91_plus' => (float) $balances->days_91_plus,
        ];
    }

    private function salesLinkedInvoiceIds(Builder $invoiceQuery): Collection
    {
        $query = (clone $invoiceQuery)->where(function ($invoiceScope) {
            if (Schema::hasColumn('invoices', 'sale_id')) {
                $invoiceScope->whereNotNull('sale_id');
            }

            $invoiceScope->orWhereExists(function ($itemQuery) {
                $itemQuery->selectRaw('1')
                    ->from('invoice_items')
                    ->whereColumn('invoice_items.invoice_id', 'invoices.id')
                    ->where('invoice_items.source_type', 'sale');
            });
        });

        return $query->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();
    }
}
