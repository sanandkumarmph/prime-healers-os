<?php

namespace App\Services\Finance;

use App\Models\Organization;
use Closure;
use Illuminate\Support\Facades\DB;

class OrganizationFinancialTransaction
{
    public function run(int $organizationId, Closure $callback): mixed
    {
        return DB::transaction(function () use ($organizationId, $callback) {
            // Share PHOS's existing payment-submission lock before reading balances.
            Organization::query()->whereKey($organizationId)->lockForUpdate()->firstOrFail();

            return $callback();
        });
    }
}
