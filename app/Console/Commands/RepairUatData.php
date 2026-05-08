<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Rental;
use App\Models\RentalAsset;
use App\Models\RentalItem;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class RepairUatData extends Command
{
    protected $signature = 'rentnexis:repair-uat-data
        {--organization= : Limit the scan to one organization id}
        {--apply : Apply only safe repairs}';

    protected $description = 'Dry-run safe UAT data repairs for legacy rental invoices and rental linkage gaps.';

    public function handle(): int
    {
        $organizationId = $this->option('organization');
        $apply = (bool) $this->option('apply');

        $report = [
            'legacy_invoice_repairs' => $this->scanLegacyRentalInvoiceRepairs($organizationId),
            'missing_rental_items' => $this->scanMissingRentalItems($organizationId),
            'missing_active_rental_assets' => $this->scanMissingActiveRentalAssets($organizationId),
        ];

        $this->info('Rentnexis UAT repair audit');
        $this->line('Mode: '.($apply ? 'APPLY SAFE REPAIRS' : 'DRY RUN'));
        $this->line('Scope: '.($organizationId ? 'organization #'.$organizationId : 'all organizations'));

        $mutations = 0;

        foreach ($report as $section => $entries) {
            $this->newLine();
            $this->components->twoColumnDetail(
                str_replace('_', ' ', ucfirst($section)),
                count($entries).' finding(s)'
            );

            if ($entries === []) {
                continue;
            }

            $headers = array_keys($entries[0]);
            $this->table($headers, array_map(fn (array $row) => array_values($row), $entries));
        }

        if ($apply) {
            $mutations += $this->applyLegacyRentalInvoiceRepairs($report['legacy_invoice_repairs']);
            $mutations += $this->applyMissingRentalItems($report['missing_rental_items']);
            $mutations += $this->applyMissingActiveRentalAssets($report['missing_active_rental_assets']);
        }

        $this->newLine();
        $safeFixes = collect($report)
            ->flatten(1)
            ->where('action', 'safe_fix')
            ->count();
        $manualReviews = collect($report)
            ->flatten(1)
            ->where('action', 'manual_review')
            ->count();
        $skipped = collect($report)
            ->flatten(1)
            ->where('action', 'skip')
            ->count();

        $this->line('Safe fixes available: '.$safeFixes);
        $this->line('Manual review required: '.$manualReviews);
        $this->line('Skipped: '.$skipped);

        if ($apply) {
            $this->info('Safe repairs applied: '.$mutations);
        } else {
            $this->comment('Dry run only. Re-run with --apply to perform safe repairs.');
        }

        return self::SUCCESS;
    }

    private function applyOrganization($query, string $table, mixed $organizationId)
    {
        if ($organizationId === null || $organizationId === '') {
            return $query;
        }

        return $query->where($table.'.organization_id', (int) $organizationId);
    }

    private function scanLegacyRentalInvoiceRepairs(mixed $organizationId): array
    {
        if (!Schema::hasTable('invoices') || !Schema::hasTable('invoice_items')) {
            return [];
        }

        $legacyChargeRows = $this->applyOrganization(
            DB::table('invoice_items')
                ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
                ->where(function ($query) {
                    $query->where('invoice_items.description', 'like', 'Rental deposit for rental #%')
                        ->orWhere('invoice_items.description', 'like', 'Transport charge for rental #%');
                })
                ->select('invoices.id')
                ->distinct(),
            'invoices',
            $organizationId
        )->pluck('id');

        if ($legacyChargeRows->isEmpty()) {
            return [];
        }

        return Invoice::query()
            ->with('items')
            ->whereIn('id', $legacyChargeRows->all())
            ->orderBy('id')
            ->get()
            ->map(function (Invoice $invoice) {
                $depositRows = $invoice->items->filter(fn (InvoiceItem $item) => str_starts_with((string) $item->description, 'Rental deposit for rental #'));
                $transportRows = $invoice->items->filter(fn (InvoiceItem $item) => str_starts_with((string) $item->description, 'Transport charge for rental #'));
                $remainingRows = $invoice->items->reject(fn (InvoiceItem $item) => $depositRows->contains($item) || $transportRows->contains($item));
                $rentalRows = $remainingRows->where('source_type', 'rental');
                $manualRows = $remainingRows->reject(fn (InvoiceItem $item) => $item->source_type === 'rental');

                $legacyDeposit = round((float) $depositRows->sum('line_total'), 2);
                $legacyTransport = round((float) $transportRows->sum('line_total'), 2);
                $repairedDeposit = max(round((float) ($invoice->deposit_amount ?? 0), 2), $legacyDeposit);
                $repairedTransport = max(round((float) ($invoice->shipping_charges ?? 0), 2), $legacyTransport);
                $rentalSubtotal = round((float) $rentalRows->sum('line_total'), 2);
                $manualLineTotal = round((float) $manualRows->sum('line_total'), 2);
                $repairedTotal = round($rentalSubtotal + $manualLineTotal + $repairedDeposit + $repairedTransport, 2);

                $currentTotal = round((float) ($invoice->total_amount ?? 0), 2);
                $safe = $rentalRows->isNotEmpty() && abs($currentTotal - $repairedTotal) <= 0.01;

                return [
                    'invoice_id' => $invoice->id,
                    'invoice' => $invoice->invoice_number ?? 'Invoice #'.$invoice->id,
                    'legacy_deposit' => number_format($legacyDeposit, 2, '.', ''),
                    'legacy_transport' => number_format($legacyTransport, 2, '.', ''),
                    'current_total' => number_format($currentTotal, 2, '.', ''),
                    'repaired_total' => number_format($repairedTotal, 2, '.', ''),
                    'action' => $safe ? 'safe_fix' : 'manual_review',
                    'notes' => $safe
                        ? 'Move deposit/transport to invoice header and remove legacy charge rows.'
                        : 'Totals or remaining rows need manual review before cleanup.',
                ];
            })
            ->all();
    }

    private function applyLegacyRentalInvoiceRepairs(array $entries): int
    {
        $applied = 0;

        foreach ($entries as $entry) {
            if (($entry['action'] ?? null) !== 'safe_fix') {
                continue;
            }

            DB::transaction(function () use ($entry, &$applied) {
                $invoice = Invoice::query()->with('items', 'payments')->findOrFail((int) $entry['invoice_id']);

                $depositRows = $invoice->items->filter(fn (InvoiceItem $item) => str_starts_with((string) $item->description, 'Rental deposit for rental #'));
                $transportRows = $invoice->items->filter(fn (InvoiceItem $item) => str_starts_with((string) $item->description, 'Transport charge for rental #'));
                $remainingRows = $invoice->items->reject(fn (InvoiceItem $item) => $depositRows->contains($item) || $transportRows->contains($item));
                $rentalRows = $remainingRows->where('source_type', 'rental');
                $manualRows = $remainingRows->reject(fn (InvoiceItem $item) => $item->source_type === 'rental');

                $depositAmount = max(round((float) ($invoice->deposit_amount ?? 0), 2), round((float) $depositRows->sum('line_total'), 2));
                $transportAmount = max(round((float) ($invoice->shipping_charges ?? 0), 2), round((float) $transportRows->sum('line_total'), 2));
                $rentalSubtotal = round((float) $rentalRows->sum('line_total'), 2);
                $manualLineTotal = round((float) $manualRows->sum('line_total'), 2);
                $totalAmount = round($rentalSubtotal + $manualLineTotal + $depositAmount + $transportAmount, 2);

                InvoiceItem::query()
                    ->where('invoice_id', $invoice->id)
                    ->where(function ($query) {
                        $query->where('description', 'like', 'Rental deposit for rental #%')
                            ->orWhere('description', 'like', 'Transport charge for rental #%');
                    })
                    ->delete();

                $paidAmount = min(round((float) $invoice->payments()->sum('amount'), 2), $totalAmount);

                $invoice->forceFill([
                    'subtotal' => $rentalSubtotal,
                    'taxable_amount' => $rentalSubtotal,
                    'deposit_amount' => $depositAmount,
                    'shipping_charges' => $transportAmount,
                    'total_amount' => $totalAmount,
                    'paid_amount' => $paidAmount,
                    'balance_amount' => max($totalAmount - $paidAmount, 0),
                ])->save();

                $invoice->refresh()->syncFinancialStatus();
                $applied++;
            });
        }

        return $applied;
    }

    private function scanMissingRentalItems(mixed $organizationId): array
    {
        if (!Schema::hasTable('rentals') || !Schema::hasTable('rental_items')) {
            return [];
        }

        return $this->applyOrganization(
            Rental::query()
                ->whereNotExists(function ($query) {
                    $query->selectRaw('1')
                        ->from('rental_items')
                        ->whereColumn('rental_items.rental_id', 'rentals.id');
                }),
            'rentals',
            $organizationId
        )
            ->orderBy('id')
            ->get()
            ->map(function (Rental $rental) {
                $assetIds = RentalAsset::query()
                    ->where('rental_id', $rental->id)
                    ->whereNull('returned_at')
                    ->pluck('asset_id')
                    ->map(fn ($id) => (int) $id)
                    ->values()
                    ->all();

                $safe = filled($rental->product_id) && (int) ($rental->quantity ?? 0) > 0;

                return [
                    'rental_id' => $rental->id,
                    'customer' => $rental->customer_name ?? 'Rental #'.$rental->id,
                    'quantity' => (int) ($rental->quantity ?? 0),
                    'product_id' => (int) ($rental->product_id ?? 0),
                    'action' => $safe ? 'safe_fix' : 'manual_review',
                    'notes' => $safe
                        ? 'Create one fallback rental item from existing rental fields.'
                        : 'Missing product/quantity context; review manually.',
                    'asset_ids' => $assetIds === [] ? '-' : implode(',', $assetIds),
                ];
            })
            ->all();
    }

    private function applyMissingRentalItems(array $entries): int
    {
        $applied = 0;

        foreach ($entries as $entry) {
            if (($entry['action'] ?? null) !== 'safe_fix') {
                continue;
            }

            DB::transaction(function () use ($entry, &$applied) {
                $rental = Rental::query()->findOrFail((int) $entry['rental_id']);

                if ($rental->rentalItems()->exists()) {
                    return;
                }

                $quantity = max((int) ($rental->quantity ?? 0), 1);
                $deliveryStatus = $rental->deliveryStatus();
                $pickupStatus = $rental->pickupStatus();
                $assetIds = RentalAsset::query()
                    ->where('rental_id', $rental->id)
                    ->whereNull('returned_at')
                    ->pluck('asset_id')
                    ->map(fn ($id) => (int) $id)
                    ->values()
                    ->all();

                $rental->rentalItems()->create([
                    'organization_id' => $rental->organization_id,
                    'product_id' => $rental->product_id,
                    'asset_ids' => $assetIds === [] ? null : $assetIds,
                    'quantity' => $quantity,
                    'delivered_quantity' => $deliveryStatus === 'completed' ? $quantity : 0,
                    'returned_quantity' => in_array($pickupStatus, ['completed', 'partial_return'], true) && $rental->status === 'returned' ? $quantity : 0,
                    'unit_rental_amount' => round((float) ($rental->rental_amount ?? 0) / max($quantity, 1), 2),
                    'line_total' => (float) ($rental->rental_amount ?? 0),
                    'notes' => 'Backfilled by rentnexis:repair-uat-data.',
                ]);

                $applied++;
            });
        }

        return $applied;
    }

    private function scanMissingActiveRentalAssets(mixed $organizationId): array
    {
        if (!Schema::hasTable('rentals') || !Schema::hasTable('rental_assets') || !Schema::hasTable('rental_items')) {
            return [];
        }

        return $this->applyOrganization(
            Rental::query()
                ->where('status', 'active')
                ->whereNotExists(function ($query) {
                    $query->selectRaw('1')
                        ->from('rental_assets')
                        ->whereColumn('rental_assets.rental_id', 'rentals.id')
                        ->whereNull('returned_at');
                }),
            'rentals',
            $organizationId
        )
            ->orderBy('id')
            ->get()
            ->map(function (Rental $rental) {
                $rentalItemAssetIds = RentalItem::query()
                    ->where('rental_id', $rental->id)
                    ->pluck('asset_ids')
                    ->filter()
                    ->flatMap(function ($assetIds) {
                        $decoded = json_decode((string) $assetIds, true);

                        return collect(is_array($decoded) ? $decoded : []);
                    })
                    ->filter()
                    ->map(fn ($id) => (int) $id)
                    ->unique()
                    ->values();

                $availableAssetIds = $rentalItemAssetIds
                    ->filter(function (int $assetId) use ($rental) {
                        $alreadyAssigned = RentalAsset::query()
                            ->where('asset_id', $assetId)
                            ->whereNull('returned_at')
                            ->exists();

                        if ($alreadyAssigned) {
                            return false;
                        }

                        return DB::table('assets')
                            ->where('id', $assetId)
                            ->where('organization_id', $rental->organization_id)
                            ->where('product_id', $rental->product_id)
                            ->where('asset_stage', 'rental_stock')
                            ->exists();
                    })
                    ->values();

                $safe = $availableAssetIds->count() >= max((int) ($rental->quantity ?? 0), 1);

                return [
                    'rental_id' => $rental->id,
                    'customer' => $rental->customer_name ?? 'Rental #'.$rental->id,
                    'quantity' => (int) ($rental->quantity ?? 0),
                    'candidate_asset_ids' => $availableAssetIds->isEmpty() ? '-' : $availableAssetIds->implode(','),
                    'action' => $safe ? 'safe_fix' : 'manual_review',
                    'notes' => $safe
                        ? 'Create active rental_assets from rental item asset_ids.'
                        : 'No unambiguous asset mapping was found.',
                ];
            })
            ->all();
    }

    private function applyMissingActiveRentalAssets(array $entries): int
    {
        $applied = 0;

        foreach ($entries as $entry) {
            if (($entry['action'] ?? null) !== 'safe_fix') {
                continue;
            }

            DB::transaction(function () use ($entry, &$applied) {
                $rental = Rental::query()->findOrFail((int) $entry['rental_id']);

                if ($rental->activeRentalAssets()->exists()) {
                    return;
                }

                $assetIds = collect(explode(',', (string) ($entry['candidate_asset_ids'] ?? '')))
                    ->filter(fn ($id) => trim($id) !== '')
                    ->map(fn ($id) => (int) trim($id))
                    ->values();

                foreach ($assetIds->take(max((int) ($rental->quantity ?? 0), 1)) as $assetId) {
                    RentalAsset::create([
                        'organization_id' => $rental->organization_id,
                        'rental_id' => $rental->id,
                        'asset_id' => $assetId,
                        'assigned_at' => $rental->start_date ?? now(),
                    ]);
                }

                $applied++;
            });
        }

        return $applied;
    }
}
