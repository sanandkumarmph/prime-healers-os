<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Vendor;
use App\Models\VendorOrderDetail;
use App\Services\Vendors\VendorFulfilmentService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Pagination\Paginator as PaginationState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VendorOrderReportController extends Controller
{
    private function orgId(): int
    {
        return (int) auth()->user()->organization_id;
    }

    private function canViewCosts(): bool
    {
        return auth()->user()?->hasPermission('vendor_costs.view') ?? false;
    }

    private function canUpdateCosts(): bool
    {
        return auth()->user()?->hasPermission('vendor_costs.update') ?? false;
    }

    private function filters(Request $request): array
    {
        return [
            'vendor_id' => (int) $request->integer('vendor_id'),
            'order_type' => trim((string) $request->input('order_type', '')),
            'payment_status' => trim((string) $request->input('payment_status', '')),
            'derived_state' => trim((string) $request->input('derived_state', '')),
            'city' => trim((string) $request->input('city', '')),
            'product_id' => (int) $request->integer('product_id'),
            'from_date' => trim((string) $request->input('from_date', '')),
            'to_date' => trim((string) $request->input('to_date', '')),
            'margin_band' => trim((string) $request->input('margin_band', '')),
        ];
    }

    private function filterOptions(): array
    {
        $organizationId = $this->orgId();

        return [
            'vendors' => Vendor::query()
                ->where('organization_id', $organizationId)
                ->orderBy('name')
                ->get(['id', 'name', 'vendor_type', 'is_active']),
            'products' => Product::query()
                ->where('organization_id', $organizationId)
                ->orderBy('name')
                ->get(['id', 'name', 'model_name']),
            'cities' => Customer::query()
                ->where('organization_id', $organizationId)
                ->whereNotNull('city')
                ->where('city', '!=', '')
                ->distinct()
                ->orderBy('city')
                ->pluck('city'),
        ];
    }

    private function baseQuery(): Builder
    {
        return VendorOrderDetail::query()
            ->forOrganization($this->orgId())
            ->with([
                'vendor',
                'rental.customer',
                'rental.product',
                'sale.customer',
                'sale.product',
                'sale.saleItems.product',
            ]);
    }

    private function applyDateFilter(Builder $query, array $filters): Builder
    {
        if ($filters['from_date'] === '' && $filters['to_date'] === '') {
            return $query;
        }

        return $query->where(function (Builder $outer) use ($filters) {
            $outer
                ->where(function (Builder $rentalBranch) use ($filters) {
                    $rentalBranch
                        ->where('order_type', VendorOrderDetail::ORDER_TYPE_RENTAL)
                        ->whereHas('rental', function (Builder $rentalQuery) use ($filters) {
                            if ($filters['from_date'] !== '') {
                                $rentalQuery->whereDate('start_date', '>=', $filters['from_date']);
                            }

                            if ($filters['to_date'] !== '') {
                                $rentalQuery->whereDate('start_date', '<=', $filters['to_date']);
                            }
                        });
                })
                ->orWhere(function (Builder $saleBranch) use ($filters) {
                    $saleBranch
                        ->where('order_type', VendorOrderDetail::ORDER_TYPE_SALE)
                        ->whereHas('sale', function (Builder $saleQuery) use ($filters) {
                            if ($filters['from_date'] !== '') {
                                $saleQuery->whereDate('sale_date', '>=', $filters['from_date']);
                            }

                            if ($filters['to_date'] !== '') {
                                $saleQuery->whereDate('sale_date', '<=', $filters['to_date']);
                            }
                        });
                });
        });
    }

    private function filteredRows(array $filters): Collection
    {
        $query = $this->baseQuery()
            ->where('fulfilment_source', VendorOrderDetail::FULFILMENT_SOURCE_VENDOR_SUPPLIED);

        if ($filters['vendor_id'] > 0) {
            $query->where('vendor_id', $filters['vendor_id']);
        }

        if (in_array($filters['order_type'], VendorOrderDetail::ORDER_TYPES, true)) {
            $query->where('order_type', $filters['order_type']);
        }

        if ($filters['payment_status'] !== '' && in_array($filters['payment_status'], VendorOrderDetail::VENDOR_PAYMENT_STATUSES, true)) {
            $query->where('vendor_payment_status', $filters['payment_status']);
        }

        if ($filters['city'] !== '') {
            $query->where(function (Builder $outer) use ($filters) {
                $outer
                    ->whereHas('rental.customer', fn (Builder $customerQuery) => $customerQuery->where('city', 'like', '%' . $filters['city'] . '%'))
                    ->orWhereHas('sale.customer', fn (Builder $customerQuery) => $customerQuery->where('city', 'like', '%' . $filters['city'] . '%'));
            });
        }

        if ($filters['product_id'] > 0) {
            $query->where(function (Builder $outer) use ($filters) {
                $outer
                    ->whereHas('rental', fn (Builder $rentalQuery) => $rentalQuery->where('product_id', $filters['product_id']))
                    ->orWhereHas('sale', function (Builder $saleQuery) use ($filters) {
                        $saleQuery->where('product_id', $filters['product_id'])
                            ->orWhereHas('saleItems', fn (Builder $itemQuery) => $itemQuery->where('product_id', $filters['product_id']));
                    });
            });
        }

        $rows = $this->applyDateFilter($query, $filters)
            ->get()
            ->sortByDesc(fn (VendorOrderDetail $detail) => $this->orderDate($detail)?->timestamp ?? $detail->created_at?->timestamp ?? 0)
            ->values();

        if ($filters['margin_band'] !== '') {
            $rows = $rows->filter(fn (VendorOrderDetail $detail) => $this->matchesMarginBand($detail, $filters['margin_band']))->values();
        }

        if ($filters['derived_state'] !== '' && in_array($filters['derived_state'], VendorOrderDetail::DERIVED_RECONCILIATION_STATES, true)) {
            $rows = $rows->filter(fn (VendorOrderDetail $detail) => in_array($filters['derived_state'], $detail->derivedStates(), true))->values();
        }

        return $rows;
    }

    private function matchesMarginBand(VendorOrderDetail $detail, string $marginBand): bool
    {
        $margin = $detail->grossMargin();

        return match ($marginBand) {
            'negative' => $margin < 0,
            'zero' => abs($margin) < 0.01,
            'positive' => $margin > 0,
            default => true,
        };
    }

    private function orderDate(VendorOrderDetail $detail): ?Carbon
    {
        return $detail->order_type === VendorOrderDetail::ORDER_TYPE_RENTAL
            ? $detail->rental?->start_date
            : $detail->sale?->sale_date;
    }

    private function orderNumber(VendorOrderDetail $detail): string
    {
        return $detail->order_type === VendorOrderDetail::ORDER_TYPE_RENTAL
            ? 'Rental #' . $detail->rental_id
            : 'Sale #' . $detail->sale_id;
    }

    private function productLabel(VendorOrderDetail $detail): string
    {
        if ($detail->order_type === VendorOrderDetail::ORDER_TYPE_RENTAL) {
            return (string) ($detail->rental?->product?->name ?? 'Rental Product');
        }

        $saleItems = $detail->sale?->saleItems ?? collect();
        if ($saleItems->isNotEmpty()) {
            return $saleItems->map(fn ($item) => $item->product?->name ?? 'Product')->filter()->unique()->implode(', ');
        }

        return (string) ($detail->sale?->product?->name ?? 'Sale Product');
    }

    private function customerLabel(VendorOrderDetail $detail): string
    {
        return $detail->order_type === VendorOrderDetail::ORDER_TYPE_RENTAL
            ? $detail->rental?->billingContactName()
            : $detail->sale?->billingContactName();
    }

    private function cityLabel(VendorOrderDetail $detail): string
    {
        return $detail->order_type === VendorOrderDetail::ORDER_TYPE_RENTAL
            ? ($detail->rental?->billingContactCity() ?: '—')
            : ($detail->sale?->billingContactCity() ?: '—');
    }

    private function summary(Collection $rows): array
    {
        $revenue = round($rows->sum(fn (VendorOrderDetail $detail) => $detail->customerRevenue()), 2);
        $cost = round($rows->sum(fn (VendorOrderDetail $detail) => $detail->totalVendorCost()), 2);
        $margin = round($rows->sum(fn (VendorOrderDetail $detail) => $detail->grossMargin()), 2);

        return [
            'order_count' => $rows->count(),
            'revenue' => $revenue,
            'vendor_cost' => $cost,
            'gross_margin' => $margin,
            'margin_percent' => $revenue > 0 ? round(($margin / $revenue) * 100, 2) : 0.0,
            'paid_count' => $rows->where('vendor_payment_status', 'paid')->count(),
            'negative_margin_count' => $rows->filter(fn (VendorOrderDetail $detail) => in_array('negative_margin', $detail->derivedStates(), true))->count(),
            'unpaid_vendor_count' => $rows->filter(fn (VendorOrderDetail $detail) => in_array('unpaid_vendor', $detail->derivedStates(), true))->count(),
            'customer_unpaid_count' => $rows->filter(fn (VendorOrderDetail $detail) => in_array('customer_unpaid', $detail->derivedStates(), true))->count(),
            'vendor_invoice_missing_count' => $rows->filter(fn (VendorOrderDetail $detail) => in_array('vendor_invoice_missing', $detail->derivedStates(), true))->count(),
            'vendor_payable' => round($rows->sum(fn (VendorOrderDetail $detail) => $detail->vendorPayable()), 2),
            'vendor_paid' => round($rows->sum(fn (VendorOrderDetail $detail) => $detail->vendorPaidAmount()), 2),
            'vendor_balance_payable' => round($rows->sum(fn (VendorOrderDetail $detail) => $detail->vendorBalancePayable()), 2),
        ];
    }

    private function paginate(Collection $rows, Request $request): LengthAwarePaginator
    {
        $perPage = 15;
        $page = max((int) $request->integer('page', 1), 1);
        $items = $rows->slice(($page - 1) * $perPage, $perPage)->values();

        return new Paginator(
            $items,
            $rows->count(),
            $perPage,
            $page,
            [
                'path' => PaginationState::resolveCurrentPath(),
                'query' => $request->query(),
            ]
        );
    }

    public function index(Request $request)
    {
        abort_unless(auth()->user()?->hasPermission('vendor_reports.view') ?? false, 403);

        $filters = $this->filters($request);
        $rows = $this->filteredRows($filters);

        return view('vendor-orders.index', [
            'filters' => $filters,
            'filterOptions' => $this->filterOptions(),
            'summary' => $this->summary($rows),
            'rows' => $this->paginate($rows, $request),
            'canViewCosts' => $this->canViewCosts(),
            'canUpdateCosts' => $this->canUpdateCosts(),
        ]);
    }

    public function exportCsv(Request $request): StreamedResponse
    {
        abort_unless(auth()->user()?->hasPermission('vendor_reports.export') ?? false, 403);

        $filters = $this->filters($request);
        $rows = $this->filteredRows($filters);
        $includeCosts = $this->canViewCosts();

        $headers = [
            'Order Type',
            'Order Number',
            'Vendor',
            'Customer',
            'Product',
            'City',
            'Order Date',
            'Delivery Responsibility',
            'Pickup Responsibility',
            'Revenue',
        ];

        if ($includeCosts) {
            $headers = array_merge($headers, [
                'Procurement Cost',
                'Vendor Delivery Cost',
                'Vendor Pickup Cost',
                'Other Vendor Cost',
                'Total Vendor Cost',
                'Gross Margin',
                'Margin %',
                'Vendor Payable',
                'Vendor Paid',
                'Balance Payable',
            ]);
        }

        $headers = array_merge($headers, [
            'Vendor Order Status',
            'Vendor Payment Status',
            'Customer Payment Status',
            'Operational Fulfilment Status',
            'Derived States',
            'Vendor Invoice Number',
            'Notes',
        ]);

        return response()->streamDownload(function () use ($rows, $headers, $includeCosts) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, $headers);

            foreach ($rows as $detail) {
                $row = [
                    ucfirst($detail->order_type),
                    $this->orderNumber($detail),
                    $detail->vendor?->name ?? 'Vendor',
                    $this->customerLabel($detail),
                    $this->productLabel($detail),
                    $this->cityLabel($detail),
                    optional($this->orderDate($detail))->format('Y-m-d') ?: '',
                    $detail->deliveryResponsibilityLabel(),
                    $detail->pickupResponsibilityLabel() ?: '—',
                    number_format($detail->customerRevenue(), 2, '.', ''),
                ];

                if ($includeCosts) {
                    $row = array_merge($row, [
                        number_format((float) ($detail->procurement_cost ?? 0), 2, '.', ''),
                        number_format((float) ($detail->vendor_delivery_cost ?? 0), 2, '.', ''),
                        number_format((float) ($detail->vendor_pickup_cost ?? 0), 2, '.', ''),
                        number_format((float) ($detail->other_vendor_cost ?? 0), 2, '.', ''),
                        number_format($detail->totalVendorCost(), 2, '.', ''),
                        number_format($detail->grossMargin(), 2, '.', ''),
                        number_format($detail->grossMarginPercent(), 2, '.', ''),
                        number_format($detail->vendorPayable(), 2, '.', ''),
                        number_format($detail->vendorPaidAmount(), 2, '.', ''),
                        number_format($detail->vendorBalancePayable(), 2, '.', ''),
                    ]);
                }

                $row = array_merge($row, [
                    $detail->vendor_order_status,
                    $detail->vendor_payment_status,
                    $detail->customerPaymentStatus(),
                    $detail->operationalFulfilmentStatus(),
                    implode(' | ', $detail->derivedStateLabels()),
                    $detail->vendor_invoice_number,
                    $detail->notes,
                ]);

                fputcsv($handle, $row);
            }

            fclose($handle);
        }, 'vendor-orders-report.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function updateCosts(Request $request, VendorOrderDetail $vendorOrderDetail)
    {
        abort_unless(auth()->user()?->hasPermission('vendor_costs.update') ?? false, 403);
        abort_unless((int) $vendorOrderDetail->organization_id === $this->orgId(), 404);

        $validated = $request->validate([
            'procurement_cost' => ['nullable', 'numeric', 'min:0'],
            'vendor_delivery_cost' => ['nullable', 'numeric', 'min:0'],
            'vendor_pickup_cost' => ['nullable', 'numeric', 'min:0'],
            'other_vendor_cost' => ['nullable', 'numeric', 'min:0'],
            'vendor_order_status' => ['nullable', 'in:' . implode(',', VendorOrderDetail::VENDOR_ORDER_STATUSES)],
            'vendor_payment_status' => ['nullable', 'in:' . implode(',', VendorOrderDetail::VENDOR_PAYMENT_STATUSES)],
            'vendor_invoice_number' => ['nullable', 'string', 'max:255'],
            'vendor_paid_at' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
        ]);

        if (($validated['vendor_payment_status'] ?? null) === 'paid' && blank($validated['vendor_paid_at'] ?? null)) {
            $validated['vendor_paid_at'] = now();
        }

        app(VendorFulfilmentService::class)->syncCosts($vendorOrderDetail, $validated);

        return redirect()
            ->back()
            ->with('success', 'Vendor costs updated.');
    }
}
