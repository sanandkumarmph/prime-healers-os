<?php

namespace App\Services\Metrics;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

class CollectionMetricsService
{
    public function summary(Builder $paymentQuery, ?Carbon $today = null): array
    {
        $today = ($today ?? Carbon::today())->copy()->startOfDay();

        return [
            'paymentsReceivedToday' => round((float) ((clone $paymentQuery)
                ->whereDate('payment_date', $today)
                ->sum('amount') ?? 0), 2),
            'paymentsReceivedThisMonth' => round((float) ((clone $paymentQuery)
                ->whereYear('payment_date', $today->year)
                ->whereMonth('payment_date', $today->month)
                ->sum('amount') ?? 0), 2),
        ];
    }
}
