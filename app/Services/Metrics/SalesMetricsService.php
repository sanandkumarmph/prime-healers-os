<?php

namespace App\Services\Metrics;

use App\Models\Invoice;
use App\Models\Sale;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class SalesMetricsService
{
    public function summary(Builder $salesQuery, int $organizationId, bool $hasInvoiceSaleColumn = false, bool $includeAmounts = true): array
    {
        $totalSales = (clone $salesQuery)->count();
        $paidSalesCount = (clone $salesQuery)->where('payment_status', 'paid')->count();
        $pendingSalesCount = (clone $salesQuery)->whereIn('payment_status', ['pending', 'partial'])->count();
        $totalSalesAmount = $includeAmounts ? round((float) ((clone $salesQuery)->sum('sale_amount') ?? 0), 2) : 0.0;
        $totalTransportationAmount = $includeAmounts ? round((float) ((clone $salesQuery)->sum('shipping_charges') ?? 0), 2) : 0.0;
        $receivables = $this->receivablesSnapshot($salesQuery, $organizationId, $hasInvoiceSaleColumn, $includeAmounts);
        $pendingSalesAmount = (float) ($receivables['totalPendingSalesAmount'] ?? 0);
        $paidSalesAmount = round(max($totalSalesAmount - $pendingSalesAmount, 0), 2);

        return array_merge($receivables, [
            'totalSales' => (int) $totalSales,
            'paidSalesCount' => (int) $paidSalesCount,
            'pendingSalesCount' => (int) $pendingSalesCount,
            'totalSalesAmount' => $totalSalesAmount,
            'totalTransportationAmount' => $totalTransportationAmount,
            'totalSaleValue' => round(max($totalSalesAmount - $totalTransportationAmount, 0), 2),
            'paidSalesAmount' => $paidSalesAmount,
            'pendingSalesAmount' => $pendingSalesAmount,
        ]);
    }

    public function receivablesSnapshot(Builder $salesQuery, int $organizationId, bool $hasInvoiceSaleColumn = false, bool $includeAmounts = true): array
    {
        $salesCollection = (clone $salesQuery)->get($includeAmounts ? ['id', 'sale_amount'] : ['id']);
        $saleIds = $salesCollection
            ->pluck('id')
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($saleIds->isEmpty()) {
            return [
                'outstandingInvoiceCount' => 0,
                'outstandingInvoiceAmount' => 0.0,
                'unbilledSalesCount' => 0,
                'unbilledSalesAmount' => 0.0,
                'totalPendingSalesAmount' => 0.0,
            ];
        }

        $linkedInvoices = DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('invoices.organization_id', $organizationId)
            ->where('invoice_items.source_type', 'sale')
            ->whereIn('invoice_items.source_id', $saleIds->all())
            ->whereNotIn('invoices.payment_status', ['cancelled'])
            ->select([
                'invoice_items.source_id',
                'invoices.id as invoice_id',
                'invoices.payment_status',
                'invoices.balance_amount',
            ])
            ->get();

        if ($hasInvoiceSaleColumn) {
            $linkedInvoices = $linkedInvoices
                ->concat(
                    Invoice::query()
                        ->where('organization_id', $organizationId)
                        ->whereIn('sale_id', $saleIds->all())
                        ->whereNotIn('payment_status', ['cancelled'])
                        ->get([
                            'sale_id as source_id',
                            'id as invoice_id',
                            'payment_status',
                            'balance_amount',
                        ])
                )
                ->unique(fn ($invoice) => ((int) $invoice->invoice_id) . ':' . ((int) $invoice->source_id));
        }

        $linkedSaleIds = $linkedInvoices
            ->pluck('source_id')
            ->map(fn ($id) => (int) $id)
            ->unique();

        $outstandingInvoices = $linkedInvoices
            ->filter(fn ($invoice) => in_array($invoice->payment_status, ['unpaid', 'partial', 'overdue'], true))
            ->unique('invoice_id');

        $outstandingInvoiceAmount = $includeAmounts ? round((float) $outstandingInvoices->sum(fn ($invoice) => (float) ($invoice->balance_amount ?? 0)), 2) : 0.0;
        $unbilledSales = $salesCollection
            ->filter(fn (Sale $sale) => !$linkedSaleIds->contains((int) $sale->id));
        $unbilledSalesAmount = $includeAmounts ? round((float) $unbilledSales->sum(fn (Sale $sale) => (float) ($sale->sale_amount ?? 0)), 2) : 0.0;

        return [
            'outstandingInvoiceCount' => (int) $outstandingInvoices->count(),
            'outstandingInvoiceAmount' => $outstandingInvoiceAmount,
            'unbilledSalesCount' => (int) $unbilledSales->count(),
            'unbilledSalesAmount' => $unbilledSalesAmount,
            'totalPendingSalesAmount' => round($outstandingInvoiceAmount + $unbilledSalesAmount, 2),
        ];
    }
}
