<?php

use App\Console\Commands\RepairUatData;
use App\Console\Commands\ResetAppData;
use App\Console\Commands\MigrateProofFiles;
use App\Console\Commands\RepairCompletedDeliveryProgress;
use App\Console\Commands\ResetUatData;
use Illuminate\Console\Application as ArtisanApplication;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

ArtisanApplication::starting(function ($artisan) {
    $artisan->resolveCommands([ResetAppData::class, RepairUatData::class, RepairCompletedDeliveryProgress::class, ResetUatData::class, MigrateProofFiles::class]);
});

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('rentnexis:audit-data {--organization= : Limit checks to one organization id} {--json : Output machine-readable JSON}', function () {
    $organizationId = $this->option('organization');
    $issues = [];

    $applyOrganization = function ($query, string $table = null) use ($organizationId) {
        if ($organizationId === null || $organizationId === '') {
            return $query;
        }

        $column = $table ? $table.'.organization_id' : 'organization_id';

        return $query->where($column, (int) $organizationId);
    };

    $tableExists = fn (string $table): bool => Schema::hasTable($table);
    $hasColumn = fn (string $table, string $column): bool => $tableExists($table) && Schema::hasColumn($table, $column);

    if ($tableExists('invoices') && $tableExists('invoice_items')) {
        $query = DB::table('invoices')
            ->select('id', 'invoice_number', 'total_amount', 'payment_status')
            ->whereNotExists(function ($subquery) {
                $subquery->selectRaw('1')
                    ->from('invoice_items')
                    ->whereColumn('invoice_items.invoice_id', 'invoices.id');
            })
            ->orderBy('id');

        $issues['invoices_without_items'] = $applyOrganization($query, 'invoices')->get()->map(fn ($invoice) => [
            'id' => $invoice->id,
            'invoice' => $invoice->invoice_number ?? 'Invoice #'.$invoice->id,
            'amount' => (float) ($invoice->total_amount ?? 0),
            'status' => $invoice->payment_status ?? 'unknown',
        ])->all();
    }

    if ($tableExists('invoices') && $tableExists('payments') && $hasColumn('payments', 'invoice_id')) {
        $paymentTotals = DB::table('payments')
            ->select('invoice_id', DB::raw('COALESCE(SUM(amount), 0) as payment_total'))
            ->whereNotNull('invoice_id')
            ->groupBy('invoice_id');

        $query = DB::table('invoices')
            ->leftJoinSub($paymentTotals, 'payment_totals', function ($join) {
                $join->on('payment_totals.invoice_id', '=', 'invoices.id');
            })
            ->select(
                'invoices.id',
                'invoices.invoice_number',
                'invoices.total_amount',
                'invoices.paid_amount',
                'invoices.balance_amount',
                'invoices.payment_status',
                DB::raw('COALESCE(payment_totals.payment_total, 0) as payment_total')
            )
            ->whereRaw('ABS(COALESCE(invoices.paid_amount, 0) - COALESCE(payment_totals.payment_total, 0)) > 0.01')
            ->orderBy('invoices.id');

        $issues['invoice_payment_mismatches'] = $applyOrganization($query, 'invoices')->get()->map(fn ($invoice) => [
            'id' => $invoice->id,
            'invoice' => $invoice->invoice_number ?? 'Invoice #'.$invoice->id,
            'status' => $invoice->payment_status ?? 'unknown',
            'invoice_paid' => (float) ($invoice->paid_amount ?? 0),
            'payment_rows_total' => (float) ($invoice->payment_total ?? 0),
            'balance' => (float) ($invoice->balance_amount ?? 0),
        ])->all();
    }

    if ($tableExists('deliveries')) {
        $query = DB::table('deliveries')->select('id', 'type', 'status', 'scheduled_at');

        if ($hasColumn('deliveries', 'rental_id') && $hasColumn('deliveries', 'sale_id')) {
            $query->whereNull('rental_id')->whereNull('sale_id');
        } elseif ($hasColumn('deliveries', 'rental_id')) {
            $query->whereNull('rental_id');
        } elseif ($hasColumn('deliveries', 'sale_id')) {
            $query->whereNull('sale_id');
        }

        $issues['deliveries_without_order_link'] = $applyOrganization($query, 'deliveries')->orderBy('id')->get()->map(fn ($delivery) => [
            'id' => $delivery->id,
            'type' => $delivery->type ?? 'unknown',
            'status' => $delivery->status ?? 'unknown',
            'scheduled_at' => $delivery->scheduled_at,
        ])->all();
    }

    if ($tableExists('sales') && $tableExists('invoice_items')) {
        $query = DB::table('sales')
            ->select('id', 'customer_id', 'sale_amount', 'payment_status')
            ->whereNotExists(function ($subquery) {
                $subquery->selectRaw('1')
                    ->from('invoice_items')
                    ->where('invoice_items.source_type', 'sale')
                    ->whereColumn('invoice_items.source_id', 'sales.id');
            })
            ->orderBy('id');

        $issues['sales_without_invoice_items'] = $applyOrganization($query, 'sales')->get()->map(fn ($sale) => [
            'id' => $sale->id,
            'customer_id' => $sale->customer_id,
            'amount' => (float) ($sale->sale_amount ?? 0),
            'status' => $sale->payment_status ?? 'unknown',
        ])->all();
    }

    if ($tableExists('rentals') && $tableExists('deliveries') && $hasColumn('deliveries', 'rental_id')) {
        $query = DB::table('rentals')
            ->select('id', 'customer_name', 'status', 'start_date', 'end_date')
            ->where('status', 'active')
            ->whereNotExists(function ($subquery) {
                $subquery->selectRaw('1')
                    ->from('deliveries')
                    ->whereColumn('deliveries.rental_id', 'rentals.id')
                    ->where('deliveries.type', 'delivery');
            })
            ->orderBy('id');

        $issues['active_rentals_without_delivery_task'] = $applyOrganization($query, 'rentals')->get()->map(fn ($rental) => [
            'id' => $rental->id,
            'customer' => $rental->customer_name ?? 'Rental #'.$rental->id,
            'status' => $rental->status,
            'start_date' => $rental->start_date,
            'end_date' => $rental->end_date,
        ])->all();
    }

    if ($tableExists('invoices') && $tableExists('invoice_items')) {
        $legacyRentalChargeQuery = DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->select(
                'invoices.id',
                'invoices.invoice_number',
                'invoices.deposit_amount',
                'invoices.shipping_charges',
                DB::raw("SUM(CASE WHEN invoice_items.description LIKE 'Rental deposit for rental #%'
                    THEN COALESCE(invoice_items.line_total, 0) ELSE 0 END) as legacy_deposit_total"),
                DB::raw("SUM(CASE WHEN invoice_items.description LIKE 'Transport charge for rental #%'
                    THEN COALESCE(invoice_items.line_total, 0) ELSE 0 END) as legacy_transport_total"),
                DB::raw('COUNT(*) as legacy_rows')
            )
            ->where(function ($query) {
                $query->where('invoice_items.description', 'like', 'Rental deposit for rental #%')
                    ->orWhere('invoice_items.description', 'like', 'Transport charge for rental #%');
            })
            ->groupBy('invoices.id', 'invoices.invoice_number', 'invoices.deposit_amount', 'invoices.shipping_charges')
            ->orderBy('invoices.id');

        $issues['rental_invoices_with_legacy_charge_rows'] = $applyOrganization($legacyRentalChargeQuery, 'invoices')->get()->map(fn ($invoice) => [
            'id' => $invoice->id,
            'invoice' => $invoice->invoice_number ?? 'Invoice #'.$invoice->id,
            'legacy_rows' => (int) ($invoice->legacy_rows ?? 0),
            'legacy_deposit_total' => (float) ($invoice->legacy_deposit_total ?? 0),
            'legacy_transport_total' => (float) ($invoice->legacy_transport_total ?? 0),
            'invoice_deposit_amount' => (float) ($invoice->deposit_amount ?? 0),
            'invoice_shipping_charges' => (float) ($invoice->shipping_charges ?? 0),
        ])->all();
    }

    if ($tableExists('products') && $hasColumn('products', 'available_quantity') && $hasColumn('products', 'total_quantity')) {
        $query = DB::table('products')
            ->select('id', 'name', 'available_quantity', 'total_quantity')
            ->where(function ($subquery) {
                $subquery->whereColumn('available_quantity', '>', 'total_quantity')
                    ->orWhere('available_quantity', '<', 0)
                    ->orWhere('total_quantity', '<', 0);
            })
            ->orderBy('id');

        $issues['product_quantity_mismatches'] = $applyOrganization($query, 'products')->get()->map(fn ($product) => [
            'id' => $product->id,
            'product' => $product->name ?? 'Product #'.$product->id,
            'available' => (int) $product->available_quantity,
            'total' => (int) $product->total_quantity,
        ])->all();
    }

    $summary = collect($issues)->map(fn (array $rows) => count($rows))->all();

    if ($this->option('json')) {
        $this->line(json_encode([
            'organization_id' => $organizationId ? (int) $organizationId : null,
            'summary' => $summary,
            'issues' => $issues,
        ], JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }

    $this->info('Rentnexis data audit');
    $this->line('Scope: '.($organizationId ? 'organization #'.$organizationId : 'all organizations'));

    foreach ($issues as $key => $rows) {
        $this->newLine();
        $this->components->twoColumnDetail(str_replace('_', ' ', ucfirst($key)), count($rows).' issue(s)');

        if ($rows === []) {
            continue;
        }

        $headers = array_keys($rows[0]);
        $this->table($headers, array_map(fn (array $row) => array_values($row), $rows));
    }

    $this->newLine();
    $totalIssues = array_sum($summary);

    if ($totalIssues === 0) {
        $this->info('No data integrity issues found by this audit.');
    } else {
        $this->warn($totalIssues.' data issue(s) found. Review before going live.');
    }

    return $totalIssues === 0 ? self::SUCCESS : self::FAILURE;
})->purpose('Audit Rentnexis data integrity without changing records');

Artisan::command('rentnexis:repair-data {--organization= : Limit repairs to one organization id} {--apply : Write safe repairs to the database}', function () {
    $organizationId = $this->option('organization');
    $apply = (bool) $this->option('apply');

    if (! Schema::hasTable('invoices') || ! Schema::hasTable('payments') || ! Schema::hasColumn('payments', 'invoice_id')) {
        $this->error('Required invoices/payments tables or columns are missing.');

        return self::FAILURE;
    }

    $paymentTotals = DB::table('payments')
        ->select('invoice_id', DB::raw('COALESCE(SUM(amount), 0) as payment_total'))
        ->whereNotNull('invoice_id')
        ->groupBy('invoice_id');

    $safePaymentBackfills = DB::table('invoices')
        ->leftJoinSub($paymentTotals, 'payment_totals', function ($join) {
            $join->on('payment_totals.invoice_id', '=', 'invoices.id');
        })
        ->select(
            'invoices.id',
            'invoices.organization_id',
            'invoices.customer_id',
            'invoices.invoice_number',
            'invoices.invoice_date',
            'invoices.paid_amount',
            DB::raw('COALESCE(payment_totals.payment_total, 0) as payment_total')
        )
        ->where('invoices.payment_status', 'paid')
        ->where('invoices.paid_amount', '>', 0)
        ->whereRaw('COALESCE(payment_totals.payment_total, 0) = 0')
        ->when($organizationId, fn ($query) => $query->where('invoices.organization_id', (int) $organizationId))
        ->orderBy('invoices.id')
        ->get();

    $manualReview = [];
    $safeRentalInvoiceChargeRepairs = collect();

    if (Schema::hasTable('invoice_items')) {
        $manualReview['invoices_without_items'] = DB::table('invoices')
            ->select('id', 'invoice_number', 'total_amount', 'payment_status')
            ->whereNotExists(function ($subquery) {
                $subquery->selectRaw('1')
                    ->from('invoice_items')
                    ->whereColumn('invoice_items.invoice_id', 'invoices.id');
            })
            ->when($organizationId, fn ($query) => $query->where('organization_id', (int) $organizationId))
            ->orderBy('id')
            ->get()
            ->map(fn ($invoice) => [
                'id' => $invoice->id,
                'invoice' => $invoice->invoice_number,
                'amount' => (float) $invoice->total_amount,
                'status' => $invoice->payment_status,
            ])
            ->all();

        $safeRentalInvoiceChargeRepairs = DB::table('invoice_items')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->leftJoin('rentals', 'rentals.id', '=', 'invoices.rental_id')
            ->select(
                'invoices.id',
                'invoices.organization_id',
                'invoices.invoice_number',
                'invoices.rental_id',
                'invoices.deposit_amount',
                'invoices.shipping_charges',
                'rentals.deposit_amount as rental_deposit_amount',
                'rentals.transport_amount as rental_transport_amount',
                DB::raw("SUM(CASE WHEN invoice_items.description LIKE 'Rental deposit for rental #%'
                    THEN COALESCE(invoice_items.line_total, 0) ELSE 0 END) as legacy_deposit_total"),
                DB::raw("SUM(CASE WHEN invoice_items.description LIKE 'Transport charge for rental #%'
                    THEN COALESCE(invoice_items.line_total, 0) ELSE 0 END) as legacy_transport_total"),
                DB::raw('COUNT(*) as legacy_rows')
            )
            ->where(function ($query) {
                $query->where('invoice_items.description', 'like', 'Rental deposit for rental #%')
                    ->orWhere('invoice_items.description', 'like', 'Transport charge for rental #%');
            })
            ->when($organizationId, fn ($query) => $query->where('invoices.organization_id', (int) $organizationId))
            ->groupBy(
                'invoices.id',
                'invoices.organization_id',
                'invoices.invoice_number',
                'invoices.rental_id',
                'invoices.deposit_amount',
                'invoices.shipping_charges',
                'rentals.deposit_amount',
                'rentals.transport_amount'
            )
            ->orderBy('invoices.id')
            ->get();
    }

    $manualReview['payment_mismatches_with_existing_rows'] = DB::table('invoices')
        ->leftJoinSub($paymentTotals, 'payment_totals', function ($join) {
            $join->on('payment_totals.invoice_id', '=', 'invoices.id');
        })
        ->select('invoices.id', 'invoices.invoice_number', 'invoices.paid_amount', DB::raw('COALESCE(payment_totals.payment_total, 0) as payment_total'))
        ->whereRaw('ABS(COALESCE(invoices.paid_amount, 0) - COALESCE(payment_totals.payment_total, 0)) > 0.01')
        ->whereRaw('COALESCE(payment_totals.payment_total, 0) > 0')
        ->when($organizationId, fn ($query) => $query->where('invoices.organization_id', (int) $organizationId))
        ->orderBy('invoices.id')
        ->get()
        ->map(fn ($invoice) => [
            'id' => $invoice->id,
            'invoice' => $invoice->invoice_number,
            'invoice_paid' => (float) $invoice->paid_amount,
            'payment_rows_total' => (float) $invoice->payment_total,
        ])
        ->all();

    if (Schema::hasTable('deliveries') && Schema::hasColumn('deliveries', 'rental_id') && Schema::hasColumn('deliveries', 'sale_id')) {
        $manualReview['deliveries_without_order_link'] = DB::table('deliveries')
            ->select('id', 'type', 'status', 'scheduled_at')
            ->whereNull('rental_id')
            ->whereNull('sale_id')
            ->when($organizationId, fn ($query) => $query->where('organization_id', (int) $organizationId))
            ->orderBy('id')
            ->get()
            ->map(fn ($delivery) => [
                'id' => $delivery->id,
                'type' => $delivery->type,
                'status' => $delivery->status,
                'scheduled_at' => $delivery->scheduled_at,
            ])
            ->all();
    }

    $this->info($apply ? 'Applying safe Rentnexis data repairs' : 'Dry-run: safe Rentnexis data repairs');

    $paymentRows = $safePaymentBackfills->map(fn ($invoice) => [
        'invoice_id' => $invoice->id,
        'invoice' => $invoice->invoice_number,
        'amount' => (float) $invoice->paid_amount,
        'payment_date' => $invoice->invoice_date,
    ])->all();

    $this->components->twoColumnDetail('Safe payment rows to backfill', count($paymentRows));

    if ($paymentRows !== []) {
        $this->table(array_keys($paymentRows[0]), array_map(fn ($row) => array_values($row), $paymentRows));
    }

    $legacyRentalChargeRows = $safeRentalInvoiceChargeRepairs->map(fn ($invoice) => [
        'invoice_id' => $invoice->id,
        'invoice' => $invoice->invoice_number,
        'rental_id' => $invoice->rental_id,
        'legacy_rows' => (int) ($invoice->legacy_rows ?? 0),
        'legacy_deposit_total' => (float) ($invoice->legacy_deposit_total ?? 0),
        'legacy_transport_total' => (float) ($invoice->legacy_transport_total ?? 0),
    ])->all();

    $this->components->twoColumnDetail('Safe rental invoice charge repairs', count($legacyRentalChargeRows));

    if ($legacyRentalChargeRows !== []) {
        $this->table(array_keys($legacyRentalChargeRows[0]), array_map(fn ($row) => array_values($row), $legacyRentalChargeRows));
    }

    if ($apply && $safePaymentBackfills->isNotEmpty()) {
        DB::transaction(function () use ($safePaymentBackfills) {
            $paymentColumns = Schema::getColumnListing('payments');

            foreach ($safePaymentBackfills as $invoice) {
                $payload = [
                    'organization_id' => $invoice->organization_id,
                    'customer_id' => $invoice->customer_id,
                    'invoice_id' => $invoice->id,
                    'payment_date' => $invoice->invoice_date ?: now()->toDateString(),
                    'amount' => $invoice->paid_amount,
                    'payment_method' => 'other',
                    'notes' => 'Backfilled from historical paid invoice during data repair.',
                    'created_at' => now(),
                    'updated_at' => now(),
                ];

                if (in_array('rental_id', $paymentColumns, true)) {
                    $payload['rental_id'] = null;
                }

                DB::table('payments')->insert(array_intersect_key($payload, array_flip($paymentColumns)));
            }
        });

        $this->info('Safe payment backfills applied.');
    } elseif (! $apply) {
        $this->comment('No changes written. Re-run with --apply to create only the safe payment rows shown above.');
    }

    if ($apply && $safeRentalInvoiceChargeRepairs->isNotEmpty()) {
        DB::transaction(function () use ($safeRentalInvoiceChargeRepairs) {
            foreach ($safeRentalInvoiceChargeRepairs as $invoiceRow) {
                $invoice = \App\Models\Invoice::query()->with('items')->find($invoiceRow->id);

                if (! $invoice) {
                    continue;
                }

                $legacyChargeItems = $invoice->items->filter(function ($item) {
                    return str_starts_with((string) $item->description, 'Rental deposit for rental #')
                        || str_starts_with((string) $item->description, 'Transport charge for rental #');
                });

                if ($legacyChargeItems->isEmpty()) {
                    continue;
                }

                $depositAmount = $invoiceRow->rental_id
                    ? round((float) ($invoiceRow->rental_deposit_amount ?? 0), 2)
                    : round((float) ($invoiceRow->legacy_deposit_total ?? 0), 2);
                $shippingCharges = $invoiceRow->rental_id
                    ? round((float) ($invoiceRow->rental_transport_amount ?? 0), 2)
                    : round((float) ($invoiceRow->legacy_transport_total ?? 0), 2);

                $legacyChargeItems->each->delete();
                $invoice->refresh()->load('items');

                $subtotal = round((float) $invoice->items->sum(fn ($item) => (float) ($item->line_total ?? 0)), 2);
                $totalAmount = round($subtotal + $depositAmount + $shippingCharges, 2);

                $invoice->forceFill([
                    'subtotal' => $subtotal,
                    'taxable_amount' => $subtotal,
                    'deposit_amount' => $depositAmount,
                    'shipping_charges' => $shippingCharges,
                    'total_amount' => $totalAmount,
                ])->save();

                $invoice->syncFinancialStatus();
            }
        });

        $this->info('Safe rental invoice charge repairs applied.');
    }

    $this->newLine();
    $this->warn('Manual review still required for ambiguous records:');

    foreach ($manualReview as $label => $rows) {
        $this->components->twoColumnDetail(str_replace('_', ' ', ucfirst($label)), count($rows));

        if ($rows !== []) {
            $this->table(array_keys($rows[0]), array_map(fn ($row) => array_values($row), $rows));
        }
    }

    return self::SUCCESS;
})->purpose('Safely repair unambiguous Rentnexis data issues; dry-run by default');
