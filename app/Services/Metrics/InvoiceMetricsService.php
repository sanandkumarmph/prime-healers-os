<?php

namespace App\Services\Metrics;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class InvoiceMetricsService
{
    public function summary(Builder $invoiceQuery, ?Carbon $today = null): array
    {
        $today = ($today ?? Carbon::today())->copy()->startOfDay();
        $openStatuses = ['unpaid', 'partial', 'overdue'];

        $totalInvoices = (clone $invoiceQuery)->count();
        $paidInvoices = (clone $invoiceQuery)->where('payment_status', 'paid')->count();
        $openInvoices = (clone $invoiceQuery)->whereIn('payment_status', $openStatuses)->count();
        $overdueInvoices = (clone $invoiceQuery)->overdue($today)->count();
        $outstandingAmount = round((float) ((clone $invoiceQuery)->whereIn('payment_status', $openStatuses)->sum('balance_amount') ?? 0), 2);
        $totalBilled = round((float) ((clone $invoiceQuery)->sum('total_amount') ?? 0), 2);

        $salesInvoiceIds = $this->salesLinkedInvoiceIds($invoiceQuery);
        $salesOutstandingCount = 0;
        $salesOutstandingAmount = 0.0;

        if ($salesInvoiceIds->isNotEmpty()) {
            $salesOutstandingCount = (clone $invoiceQuery)
                ->whereIn('id', $salesInvoiceIds->all())
                ->whereIn('payment_status', $openStatuses)
                ->count();

            $salesOutstandingAmount = round((float) ((clone $invoiceQuery)
                ->whereIn('id', $salesInvoiceIds->all())
                ->whereIn('payment_status', $openStatuses)
                ->sum('balance_amount') ?? 0), 2);
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
            'salesOutstandingCount' => (int) $salesOutstandingCount,
            'salesOutstandingAmount' => $salesOutstandingAmount,
            'rentalOutstandingCount' => $rentalOutstandingCount,
            'rentalOutstandingAmount' => $rentalOutstandingAmount,
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
