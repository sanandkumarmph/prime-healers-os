<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Models\Rental;
use App\Services\Finance\InvoiceLinkResolver;
use App\Services\Finance\InvoiceSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SyncRentalInvoices extends Command
{
    protected $signature = 'rentnexis:sync-rental-invoices
        {--organization= : Limit to one organization id}
        {--rental=* : Limit to one or more rental ids}
        {--apply : Apply the invoice resync instead of previewing}';

    protected $description = 'Resync linked rental invoices from the latest rental details.';

    public function handle(
        InvoiceLinkResolver $resolver,
        InvoiceSyncService $invoiceSyncService
    ): int {
        $organizationId = $this->option('organization');
        $rentalIds = collect((array) $this->option('rental'))
            ->filter(fn ($id) => filled($id))
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->values();
        $apply = (bool) $this->option('apply');

        $query = Rental::query()
            ->with(['customer', 'product', 'renewals']);

        if (Rental::hasRentalItemsTable()) {
            $query->with('rentalItems.product');
        }

        if ($organizationId !== null && $organizationId !== '') {
            $query->where('organization_id', (int) $organizationId);
        }

        if ($rentalIds->isNotEmpty()) {
            $query->whereIn('id', $rentalIds->all());
        }

        $hasInvoiceRentalColumn = Schema::hasColumn('invoices', 'rental_id');
        $hasRentalItemsTable = Rental::hasRentalItemsTable();
        $hasRentalRenewalsTable = Schema::hasTable('rental_renewals');

        $rows = [];
        $updated = 0;

        $rentals = $query->orderBy('id')->get();

        foreach ($rentals as $rental) {
            $renewalInvoiceIds = $hasRentalRenewalsTable
                ? $rental->renewals->pluck('invoice_id')->filter()->map(fn ($id) => (int) $id)->all()
                : [];

            $invoice = $resolver->rentalInvoice(
                $rental,
                (int) $rental->organization_id,
                $hasInvoiceRentalColumn,
                [$rental->product_id ?? 0],
                $renewalInvoiceIds
            );

            if (!$invoice) {
                continue;
            }

            $beforeTotal = round((float) ($invoice->total_amount ?? 0), 2);
            $beforeBalance = round((float) ($invoice->balance_amount ?? 0), 2);

            if ($apply) {
                DB::transaction(function () use (
                    $invoiceSyncService,
                    $rental,
                    $invoice,
                    $hasInvoiceRentalColumn,
                    $hasRentalItemsTable
                ) {
                    $organization = Organization::query()
                        ->findOrFail((int) $rental->organization_id);
                    $freshRentalWith = ['customer', 'product', 'renewals'];

                    if ($hasRentalItemsTable) {
                        $freshRentalWith[] = 'rentalItems.product';
                    }

                    $invoiceSyncService->syncRentalInvoiceFromRental(
                        (int) $rental->organization_id,
                        $rental->fresh($freshRentalWith),
                        $invoice->fresh(),
                        $organization,
                        $hasInvoiceRentalColumn,
                        $hasRentalItemsTable
                    );
                });

                $invoice->refresh();
                $updated++;
            }

            $rows[] = [
                'rental_id' => $rental->id,
                'invoice_id' => $invoice->id,
                'invoice' => $invoice->invoice_number ?? ('Invoice #' . $invoice->id),
                'before_total' => number_format($beforeTotal, 2, '.', ''),
                'after_total' => number_format((float) ($invoice->total_amount ?? $beforeTotal), 2, '.', ''),
                'before_balance' => number_format($beforeBalance, 2, '.', ''),
                'after_balance' => number_format((float) ($invoice->balance_amount ?? $beforeBalance), 2, '.', ''),
            ];
        }

        $this->info('Rental invoice sync');
        $this->line('Mode: ' . ($apply ? 'APPLY' : 'PREVIEW'));
        $this->line('Scope: ' . ($organizationId ? 'organization #' . $organizationId : 'all organizations'));
        $this->line('Matched rentals: ' . count($rows));

        if ($rows !== []) {
            $this->table(
                ['Rental', 'Invoice', 'Invoice No.', 'Before Total', 'After Total', 'Before Balance', 'After Balance'],
                array_map(fn (array $row) => array_values($row), $rows)
            );
        }

        if ($apply) {
            $this->info('Invoices resynced: ' . $updated);
        } else {
            $this->comment('Preview only. Re-run with --apply to save changes.');
        }

        return self::SUCCESS;
    }
}
