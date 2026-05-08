<?php

namespace App\Services\Finance;

use App\Models\Invoice;
use App\Models\Rental;
use App\Models\Sale;
use Illuminate\Database\Eloquent\Builder;

class InvoiceLinkResolver
{
    public function rentalInvoice(
        Rental $rental,
        int $organizationId,
        bool $hasInvoiceRentalColumn,
        array $fallbackProductIds = [],
        array $excludeInvoiceIds = []
    ): ?Invoice {
        $fallbackProductIds = $fallbackProductIds !== []
            ? $fallbackProductIds
            : [$rental->product_id ?? 0];

        $query = $this->rentalInvoiceLookupQuery(
            $organizationId,
            [$rental->id],
            $hasInvoiceRentalColumn,
            $fallbackProductIds,
            $excludeInvoiceIds
        );

        if ($hasInvoiceRentalColumn) {
            $query->orderByRaw('CASE WHEN rental_id = ? THEN 0 ELSE 1 END', [$rental->id]);
        }

        return $query->latest('id')->first();
    }

    public function rentalInvoiceLookupQuery(
        int $organizationId,
        array $rentalIds,
        bool $hasInvoiceRentalColumn,
        array $fallbackProductIds = [],
        array $excludeInvoiceIds = []
    ): Builder {
        $rentalIds = $this->normalizeIds($rentalIds);
        $fallbackProductIds = $this->normalizeIds($fallbackProductIds);
        $excludeInvoiceIds = $this->normalizeIds($excludeInvoiceIds);

        return Invoice::query()
            ->where('organization_id', $organizationId)
            ->when($excludeInvoiceIds !== [], fn (Builder $query) => $query->whereNotIn('id', $excludeInvoiceIds))
            ->where(function (Builder $query) use ($rentalIds, $fallbackProductIds, $hasInvoiceRentalColumn) {
                if ($hasInvoiceRentalColumn) {
                    $query->whereIn('rental_id', $rentalIds);
                }

                $method = $hasInvoiceRentalColumn ? 'orWhereHas' : 'whereHas';

                $query->{$method}('items', function (Builder $itemQuery) use ($rentalIds, $fallbackProductIds) {
                    $itemQuery
                        ->where('source_type', 'rental')
                        ->where(function (Builder $sourceQuery) use ($rentalIds, $fallbackProductIds) {
                            $sourceQuery->whereIn('source_id', $rentalIds);

                            if ($fallbackProductIds !== []) {
                                $sourceQuery->orWhere(function (Builder $fallbackQuery) use ($fallbackProductIds) {
                                    $fallbackQuery
                                        ->whereNull('source_id')
                                        ->whereIn('product_id', $fallbackProductIds);
                                });
                            }
                        });
                });
            });
    }

    public function saleInvoice(Sale $sale, int $organizationId, bool $hasInvoiceSaleColumn): ?Invoice
    {
        $query = $this->saleInvoiceLookupQuery($organizationId, [$sale->id], $hasInvoiceSaleColumn);

        if ($hasInvoiceSaleColumn) {
            $query->orderByRaw('CASE WHEN sale_id = ? THEN 0 ELSE 1 END', [$sale->id]);
        }

        return $query->latest('id')->first();
    }

    public function saleInvoiceLookupQuery(int $organizationId, array $saleIds, bool $hasInvoiceSaleColumn): Builder
    {
        $saleIds = $this->normalizeIds($saleIds);

        return Invoice::query()
            ->where('organization_id', $organizationId)
            ->where(function (Builder $query) use ($saleIds, $hasInvoiceSaleColumn) {
                if ($hasInvoiceSaleColumn) {
                    $query->whereIn('sale_id', $saleIds);
                }

                $method = $hasInvoiceSaleColumn ? 'orWhereHas' : 'whereHas';

                $query->{$method}('items', function (Builder $itemQuery) use ($saleIds) {
                    $itemQuery
                        ->where('source_type', 'sale')
                        ->whereIn('source_id', $saleIds);
                });
            });
    }

    private function normalizeIds(array $ids): array
    {
        return collect($ids)
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();
    }
}
