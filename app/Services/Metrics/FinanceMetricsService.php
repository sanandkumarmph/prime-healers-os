<?php

namespace App\Services\Metrics;

use Illuminate\Database\Eloquent\Builder;

class FinanceMetricsService
{
    public function summary(
        Builder $rentalQuery,
        array $salesSummary,
        array $invoiceSummary,
        array $pendingReceivables = [],
        array $renewalFinance = []
    ): array {
        $rentalOrderValue = round((float) ((clone $rentalQuery)->sum('rental_amount') ?? 0), 2);
        $depositValue = round((float) ((clone $rentalQuery)->sum('deposit_amount') ?? 0), 2);
        $transportValue = round((float) ((clone $rentalQuery)->sum('transport_amount') ?? 0), 2);
        $otherChargesValue = round((float) ((clone $rentalQuery)->sum('other_amount') ?? 0), 2);
        $salesOrderValue = round((float) ($salesSummary['totalSalesAmount'] ?? 0), 2);

        $grossOrderComponents = round(
            $rentalOrderValue
            + $salesOrderValue
            + $depositValue
            + $transportValue
            + $otherChargesValue,
            2
        );

        $netBilledAmount = round((float) ($invoiceSummary['totalBilled'] ?? 0), 2);
        $outstandingDuesAmount = round((float) ($invoiceSummary['outstandingAmount'] ?? 0), 2);

        $unbilledRentalAmount = round((float) ($pendingReceivables['unbilledRentalReceivableAmount'] ?? 0), 2);
        $unbilledSalesAmount = round((float) ($salesSummary['unbilledSalesAmount'] ?? $pendingReceivables['unbilledSaleReceivableAmount'] ?? 0), 2);
        $unbilledRenewalAmount = round((float) ($renewalFinance['unbilledRenewalAmount'] ?? 0), 2);

        $knownUnbilledGapAmount = round(
            $unbilledRentalAmount + $unbilledSalesAmount + $unbilledRenewalAmount,
            2
        );

        $reconciliationGapAmount = round($grossOrderComponents - $netBilledAmount, 2);
        $adjustmentGapAmount = round($reconciliationGapAmount - $knownUnbilledGapAmount, 2);

        if (abs($adjustmentGapAmount) < 0.005) {
            $adjustmentGapAmount = 0.0;
        }

        return [
            'rentalOrderValue' => $rentalOrderValue,
            'salesOrderValue' => $salesOrderValue,
            'depositValue' => $depositValue,
            'transportValue' => $transportValue,
            'otherChargesValue' => $otherChargesValue,
            'grossOrderComponents' => $grossOrderComponents,
            'netBilledAmount' => $netBilledAmount,
            'outstandingDuesAmount' => $outstandingDuesAmount,
            'unbilledRentalAmount' => $unbilledRentalAmount,
            'unbilledSalesAmount' => $unbilledSalesAmount,
            'unbilledRenewalAmount' => $unbilledRenewalAmount,
            'knownUnbilledGapAmount' => $knownUnbilledGapAmount,
            'reconciliationGapAmount' => $reconciliationGapAmount,
            'adjustmentGapAmount' => $adjustmentGapAmount,
        ];
    }
}
