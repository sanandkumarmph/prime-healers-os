<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Rental;
use App\Models\RentalAsset;
use App\Models\Sale;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorOrderDetail;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ReportController extends Controller
{
    private ?bool $paymentsHaveOrganizationColumn = null;
    private ?bool $paymentsHaveCustomerColumn = null;
    private ?bool $paymentsHaveInvoiceColumn = null;
    private ?bool $paymentsHaveRentalColumn = null;

    private function orgId(): int
    {
        return (int) Auth::user()->organization_id;
    }

    private function paymentsHaveOrganizationColumn(): bool
    {
        return $this->paymentsHaveOrganizationColumn ??= Schema::hasColumn('payments', 'organization_id');
    }

    private function paymentsHaveCustomerColumn(): bool
    {
        return $this->paymentsHaveCustomerColumn ??= Schema::hasColumn('payments', 'customer_id');
    }

    private function paymentsHaveInvoiceColumn(): bool
    {
        return $this->paymentsHaveInvoiceColumn ??= Schema::hasColumn('payments', 'invoice_id');
    }

    private function paymentsHaveRentalColumn(): bool
    {
        return $this->paymentsHaveRentalColumn ??= Schema::hasColumn('payments', 'rental_id');
    }

    private function yearMonthExpression(string $column): string
    {
        return DB::connection()->getDriverName() === 'sqlite'
            ? "strftime('%Y-%m', {$column})"
            : "DATE_FORMAT({$column}, '%Y-%m')";
    }

    private function filters(Request $request): array
    {
        return [
            'from_date' => trim((string) $request->get('from_date', '')),
            'to_date' => trim((string) $request->get('to_date', '')),
            'city' => trim((string) $request->get('city', '')),
            'period' => trim((string) $request->get('period', 'month')) ?: 'month',
            'fulfilment_source' => trim((string) $request->get('fulfilment_source', '')),
            'product_category' => trim((string) $request->get('product_category', '')),
            'vendor_id' => trim((string) $request->get('vendor_id', '')),
            'warehouse_id' => trim((string) $request->get('warehouse_id', '')),
            'customer_id' => trim((string) $request->get('customer_id', '')),
            'business_partner_id' => trim((string) $request->get('business_partner_id', '')),
            'staff_user_id' => trim((string) $request->get('staff_user_id', '')),
            'product_id' => trim((string) $request->get('product_id', '')),
            'referred_by' => trim((string) $request->get('referred_by', '')),
            'report' => trim((string) $request->get('report', 'overview')) ?: 'overview',
            'tab' => trim((string) $request->get('tab', 'revenue')) ?: 'revenue',
        ];
    }

    private function filterOptions(): array
    {
        $organizationId = $this->orgId();

        $cities = Customer::query()
            ->where('organization_id', $organizationId)
            ->whereNotNull('city')
            ->where('city', '!=', '')
            ->distinct()
            ->orderBy('city')
            ->pluck('city');

        $vendors = Vendor::query()
            ->where('organization_id', $organizationId)
            ->when(Schema::hasColumn('vendors', 'is_active'), fn ($query) => $query->where('is_active', true))
            ->orderBy('name')
            ->get(['id', 'name']);

        $warehouses = Warehouse::query()
            ->where('organization_id', $organizationId)
            ->orderBy('name')
            ->get(['id', 'name']);

        $customers = Customer::query()
            ->where('organization_id', $organizationId)
            ->orderBy('name')
            ->get(['id', 'name']);

        $products = Product::query()
            ->where('organization_id', $organizationId)
            ->orderBy('name')
            ->get(['id', 'name']);

        $productCategories = Product::query()
            ->where('organization_id', $organizationId)
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->distinct()
            ->orderBy('category')
            ->pluck('category');

        $businessPartners = \App\Models\BusinessPartner::query()
            ->where('organization_id', $organizationId)
            ->orderBy('name')
            ->get(['id', 'name']);

        $staffUsers = User::query()
            ->where('organization_id', $organizationId)
            ->when(Schema::hasColumn('users', 'is_active'), fn ($query) => $query->where('is_active', true))
            ->orderBy('name')
            ->get(['id', 'name']);

        return compact('cities', 'vendors', 'warehouses', 'customers', 'products', 'productCategories', 'businessPartners', 'staffUsers');
    }

    private function applyDateRange($query, string $column, array $filters)
    {
        if ($filters['from_date'] !== '' && $filters['to_date'] !== '') {
            $query->whereBetween(DB::raw("date({$column})"), [$filters['from_date'], $filters['to_date']]);
        } elseif ($filters['from_date'] !== '') {
            $query->whereDate($column, '>=', $filters['from_date']);
        } elseif ($filters['to_date'] !== '') {
            $query->whereDate($column, '<=', $filters['to_date']);
        }

        return $query;
    }

    private function rentalQuery(array $filters)
    {
        $query = Rental::query()
            ->with([
                'customer',
                'product',
                'dispatchWarehouse',
                'deliveryStaff',
                'pickupStaff',
                'deliveryRecord.assignedUser',
                'deliveryRecord.assignedStaff',
                'pickupRecord.assignedUser',
                'pickupRecord.assignedStaff',
                'vendorOrderDetail.vendor',
                'createdBy',
            ])
            ->where('rentals.organization_id', $this->orgId());

        if ($filters['city'] !== '') {
            $query->whereHas('customer', fn ($customerQuery) => $customerQuery->where('city', 'like', '%' . $filters['city'] . '%'));
        }

        if ($filters['fulfilment_source'] !== '') {
            $query->where('fulfilment_source', $filters['fulfilment_source']);
        }

        if ($filters['product_category'] !== '') {
            $query->whereHas('product', fn ($productQuery) => $productQuery->where('category', $filters['product_category']));
        }

        if ($filters['vendor_id'] !== '') {
            $this->applyVendorFilterToRentalQuery($query, (int) $filters['vendor_id']);
        }

        if ($filters['warehouse_id'] !== '') {
            $query->where('dispatch_warehouse_id', (int) $filters['warehouse_id']);
        }

        if ($filters['customer_id'] !== '') {
            $query->where('customer_id', (int) $filters['customer_id']);
        }

        if ($filters['business_partner_id'] !== '') {
            $query->where('business_partner_id', (int) $filters['business_partner_id']);
        }

        if ($filters['staff_user_id'] !== '') {
            $query->where('created_by_user_id', (int) $filters['staff_user_id']);
        }

        if ($filters['product_id'] !== '') {
            $query->where('product_id', (int) $filters['product_id']);
        }

        if ($filters['referred_by'] !== '' && Schema::hasColumn('rentals', 'referred_by')) {
            $query->where('referred_by', 'like', '%' . $filters['referred_by'] . '%');
        }

        return $this->applyDateRange($query, 'start_date', $filters);
    }

    private function invoiceQuery(array $filters)
    {
        $query = Invoice::query()
            ->with(['customer', 'items', 'payments'])
            ->where('invoices.organization_id', $this->orgId());

        if ($filters['city'] !== '') {
            $query->where(function ($cityQuery) use ($filters) {
                $cityQuery
                    ->where('bill_to_city', 'like', '%' . $filters['city'] . '%')
                    ->orWhereHas('customer', fn ($customerQuery) => $customerQuery->where('city', 'like', '%' . $filters['city'] . '%'));
            });
        }

        if ($filters['customer_id'] !== '') {
            $query->where('customer_id', (int) $filters['customer_id']);
        }

        if ($filters['product_id'] !== '') {
            $query->whereHas('items', fn ($itemQuery) => $itemQuery->where('product_id', (int) $filters['product_id']));
        }

        if ($filters['vendor_id'] !== '' || $filters['warehouse_id'] !== '' || $filters['fulfilment_source'] !== '' || $filters['product_category'] !== '' || $filters['business_partner_id'] !== '' || $filters['staff_user_id'] !== '' || $filters['referred_by'] !== '') {
            $rentalIdQuery = Rental::query()
                ->select('id')
                ->forOrganization($this->orgId());

            if ($filters['vendor_id'] !== '') {
                $this->applyVendorFilterToRentalQuery($rentalIdQuery, (int) $filters['vendor_id']);
            }

            if ($filters['warehouse_id'] !== '') {
                $rentalIdQuery->where('dispatch_warehouse_id', (int) $filters['warehouse_id']);
            }

            if ($filters['fulfilment_source'] !== '') {
                $rentalIdQuery->where('fulfilment_source', $filters['fulfilment_source']);
            }

            if ($filters['product_category'] !== '') {
                $rentalIdQuery->whereHas('product', fn ($productQuery) => $productQuery->where('category', $filters['product_category']));
            }

            if ($filters['business_partner_id'] !== '') {
                $rentalIdQuery->where('business_partner_id', (int) $filters['business_partner_id']);
            }

            if ($filters['staff_user_id'] !== '') {
                $rentalIdQuery->where('created_by_user_id', (int) $filters['staff_user_id']);
            }

            if ($filters['referred_by'] !== '' && Schema::hasColumn('rentals', 'referred_by')) {
                $rentalIdQuery->where('referred_by', 'like', '%' . $filters['referred_by'] . '%');
            }

            $saleIdQuery = $this->saleQuery($filters)->select('id');

            $query->where(function ($sourceQuery) use ($rentalIdQuery, $saleIdQuery) {
                $sourceQuery
                    ->whereHas('items', function ($itemQuery) use ($rentalIdQuery) {
                        $itemQuery
                            ->where('source_type', 'rental')
                            ->whereIn('source_id', $rentalIdQuery);
                    })
                    ->orWhereHas('items', function ($itemQuery) use ($saleIdQuery) {
                        $itemQuery
                            ->where('source_type', 'sale')
                            ->whereIn('source_id', $saleIdQuery);
                    });
            });
        }

        return $this->applyDateRange($query, 'invoice_date', $filters);
    }

    private function saleQuery(array $filters)
    {
        $query = Sale::query()
            ->with(['customer', 'businessPartner', 'product', 'warehouse', 'vendorOrderDetail.vendor'])
            ->where('sales.organization_id', $this->orgId());

        if ($filters['city'] !== '') {
            $query->whereHas('customer', fn ($customerQuery) => $customerQuery->where('city', 'like', '%' . $filters['city'] . '%'));
        }

        if ($filters['fulfilment_source'] !== '') {
            $query->where('fulfilment_source', $filters['fulfilment_source']);
        }

        if ($filters['vendor_id'] !== '') {
            $query->where(function ($vendorQuery) use ($filters) {
                $vendorQuery
                    ->where('vendor_id', (int) $filters['vendor_id'])
                    ->orWhereHas('vendorOrderDetail', fn ($detailQuery) => $detailQuery->where('vendor_id', (int) $filters['vendor_id']));
            });
        }

        if ($filters['warehouse_id'] !== '') {
            $query->where('warehouse_id', (int) $filters['warehouse_id']);
        }

        if ($filters['customer_id'] !== '') {
            $query->where('customer_id', (int) $filters['customer_id']);
        }

        if ($filters['business_partner_id'] !== '') {
            $query->where('business_partner_id', (int) $filters['business_partner_id']);
        }

        if ($filters['staff_user_id'] !== '') {
            $query->where('created_by_user_id', (int) $filters['staff_user_id']);
        }

        if ($filters['product_id'] !== '') {
            $query->where('product_id', (int) $filters['product_id']);
        }

        if ($filters['product_category'] !== '') {
            $query->whereHas('product', fn ($productQuery) => $productQuery->where('category', $filters['product_category']));
        }

        return $this->applyDateRange($query, 'sale_date', $filters);
    }

    private function deliveryQuery(array $filters)
    {
        $query = \App\Models\Delivery::query()
            ->with(['assignedUser', 'rental.customer', 'rental.product', 'sale.customer', 'sale.product'])
            ->where('organization_id', $this->orgId());

        if ($filters['staff_user_id'] !== '') {
            $query->where('assigned_user_id', (int) $filters['staff_user_id']);
        }

        if ($filters['fulfilment_source'] !== '' || $filters['city'] !== '' || $filters['vendor_id'] !== '' || $filters['warehouse_id'] !== '' || $filters['customer_id'] !== '' || $filters['business_partner_id'] !== '' || $filters['product_id'] !== '' || $filters['product_category'] !== '') {
            $query->where(function ($linkedQuery) use ($filters) {
                $linkedQuery
                    ->whereHas('rental', fn ($rentalQuery) => $rentalQuery->whereIn('rentals.id', (clone $this->rentalQuery($filters))->select('rentals.id')))
                    ->orWhereHas('sale', fn ($saleQuery) => $saleQuery->whereIn('sales.id', (clone $this->saleQuery($filters))->select('sales.id')));
            });
        }

        return $this->applyDateRange($query, 'scheduled_at', $filters);
    }

    private function vendorOrderQuery(array $filters)
    {
        $query = VendorOrderDetail::query()
            ->with(['vendor', 'rental.product', 'rental.customer', 'sale.product', 'sale.customer'])
            ->forOrganization($this->orgId());

        if ($filters['vendor_id'] !== '') {
            $query->where('vendor_id', (int) $filters['vendor_id']);
        }

        if ($filters['fulfilment_source'] !== '') {
            $query->where('fulfilment_source', $filters['fulfilment_source']);
        }

        if ($filters['city'] !== '' || $filters['warehouse_id'] !== '' || $filters['customer_id'] !== '' || $filters['business_partner_id'] !== '' || $filters['product_id'] !== '' || $filters['product_category'] !== '' || $filters['staff_user_id'] !== '') {
            $query->where(function ($linkedQuery) use ($filters) {
                $linkedQuery
                    ->whereHas('rental', fn ($rentalQuery) => $rentalQuery->whereIn('rentals.id', (clone $this->rentalQuery($filters))->select('rentals.id')))
                    ->orWhereHas('sale', fn ($saleQuery) => $saleQuery->whereIn('sales.id', (clone $this->saleQuery($filters))->select('sales.id')));
            });
        }

        if ($filters['from_date'] !== '' || $filters['to_date'] !== '') {
            $query->where(function ($dateQuery) use ($filters) {
                $dateQuery
                    ->whereHas('rental', fn ($rentalQuery) => $this->applyDateRange($rentalQuery, 'start_date', $filters))
                    ->orWhereHas('sale', fn ($saleQuery) => $this->applyDateRange($saleQuery, 'sale_date', $filters))
                    ->orWhere(function ($unlinkedQuery) use ($filters) {
                        $unlinkedQuery
                            ->whereNull('rental_id')
                            ->whereNull('sale_id');
                        $this->applyDateRange($unlinkedQuery, 'created_at', $filters);
                    });
            });
        }

        return $query;
    }

    private function paymentQuery(array $filters)
    {
        $query = Payment::query()->with(['customer', 'invoice', 'rental.customer', 'rental.product']);

        if ($this->paymentsHaveOrganizationColumn()) {
            $query->where('organization_id', $this->orgId());
        } else {
            $query->whereHas('rental', fn ($rentalQuery) => $rentalQuery->where('organization_id', $this->orgId()));
        }

        $query->where(function ($linkedPaymentQuery) {
            $hasLinkedSource = false;

            if ($this->paymentsHaveInvoiceColumn()) {
                $hasLinkedSource = true;
                $linkedPaymentQuery->whereHas('invoice');
            }

            if ($this->paymentsHaveRentalColumn()) {
                $hasLinkedSource = true;

                if ($this->paymentsHaveInvoiceColumn()) {
                    $linkedPaymentQuery->orWhereHas('rental');
                } else {
                    $linkedPaymentQuery->whereHas('rental');
                }
            }

            if (!$hasLinkedSource) {
                $linkedPaymentQuery->whereRaw('1 = 0');
            }
        });

        if ($filters['customer_id'] !== '') {
            if ($this->paymentsHaveCustomerColumn()) {
                $query->where('customer_id', (int) $filters['customer_id']);
            } else {
                $query->whereHas('rental', fn ($rentalQuery) => $rentalQuery->where('customer_id', (int) $filters['customer_id']));
            }
        }

        if ($filters['city'] !== '') {
            $query->where(function ($paymentQuery) use ($filters) {
                if ($this->paymentsHaveCustomerColumn()) {
                    $paymentQuery->whereHas('customer', fn ($customerQuery) => $customerQuery->where('city', 'like', '%' . $filters['city'] . '%'));
                }

                $paymentQuery->orWhereHas('rental.customer', fn ($customerQuery) => $customerQuery->where('city', 'like', '%' . $filters['city'] . '%'));
            });
        }

        if ($filters['vendor_id'] !== '' || $filters['warehouse_id'] !== '' || $filters['product_id'] !== '' || $filters['fulfilment_source'] !== '' || $filters['product_category'] !== '' || $filters['business_partner_id'] !== '' || $filters['staff_user_id'] !== '') {
            $query->whereHas('rental', function ($rentalQuery) use ($filters) {
                if ($filters['vendor_id'] !== '') {
                    $this->applyVendorFilterToRentalQuery($rentalQuery, (int) $filters['vendor_id']);
                }

                if ($filters['warehouse_id'] !== '') {
                    $rentalQuery->where('dispatch_warehouse_id', (int) $filters['warehouse_id']);
                }

                if ($filters['product_id'] !== '') {
                    $rentalQuery->where('product_id', (int) $filters['product_id']);
                }

                if ($filters['fulfilment_source'] !== '') {
                    $rentalQuery->where('fulfilment_source', $filters['fulfilment_source']);
                }

                if ($filters['product_category'] !== '') {
                    $rentalQuery->whereHas('product', fn ($productQuery) => $productQuery->where('category', $filters['product_category']));
                }

                if ($filters['business_partner_id'] !== '') {
                    $rentalQuery->where('business_partner_id', (int) $filters['business_partner_id']);
                }

                if ($filters['staff_user_id'] !== '') {
                    $rentalQuery->where('created_by_user_id', (int) $filters['staff_user_id']);
                }
            });
        }

        return $this->applyDateRange($query, 'payment_date', $filters);
    }

    private function customerBaseQuery(array $filters)
    {
        $query = Customer::query()
            ->where('customers.organization_id', $this->orgId());

        if ($filters['city'] !== '') {
            $query->where('customers.city', 'like', '%' . $filters['city'] . '%');
        }

        if ($filters['customer_id'] !== '') {
            $query->where('customers.id', (int) $filters['customer_id']);
        }

        return $this->applyDateRange($query, 'customers.created_at', $filters);
    }

    private function customerQuery(array $filters)
    {
        return $this->customerBaseQuery($filters)
            ->withCount([
                'rentals',
                'sales',
                'invoices',
                'rentals as active_rentals_count' => fn ($rentalQuery) => $rentalQuery->where('status', 'active'),
            ]);
    }

    private function assetQuery(array $filters)
    {
        $query = Asset::query()
            ->with(['product', 'warehouse'])
            ->where('assets.organization_id', $this->orgId());

        if ($filters['warehouse_id'] !== '') {
            $query->where('warehouse_id', (int) $filters['warehouse_id']);
        }

        if ($filters['product_id'] !== '') {
            $query->where('product_id', (int) $filters['product_id']);
        }

        if ($filters['product_category'] !== '') {
            $query->whereHas('product', fn ($productQuery) => $productQuery->where('category', $filters['product_category']));
        }

        return $this->applyDateRange($query, 'purchase_date', $filters);
    }

    private function applyVendorFilterToRentalQuery($query, int $vendorUserId)
    {
        if ($vendorUserId <= 0) {
            return $query;
        }

        return $query->where(function ($vendorQuery) use ($vendorUserId) {
            $vendorQuery
                ->where('vendor_id', $vendorUserId)
                ->orWhereHas('vendorOrderDetail', fn ($detailQuery) => $detailQuery->where('vendor_id', $vendorUserId))
                ->orWhereHas('deliveryRecord', fn ($deliveryQuery) => $deliveryQuery->where('assigned_user_id', $vendorUserId))
                ->orWhereHas('pickupRecord', fn ($pickupQuery) => $pickupQuery->where('assigned_user_id', $vendorUserId));
        });
    }

    private function vendorLabelForRental(Rental $rental): string
    {
        $deliveryVendor = $this->vendorNameFromDeliveryRecord($rental->deliveryRecord);
        $pickupVendor = $this->vendorNameFromDeliveryRecord($rental->pickupRecord);

        return $rental->vendorOrderDetail?->vendor?->name
            ?? $rental->vendor?->name
            ?? $deliveryVendor
            ?? $pickupVendor
            ?? 'Unassigned';
    }

    private function vendorNameFromDeliveryRecord($delivery): ?string
    {
        if (!$delivery || ($delivery->assignment_type ?? null) !== 'vendor') {
            return null;
        }

        if ($delivery->assignedUser && $delivery->assignedUser->isVendor()) {
            return $delivery->assignedUser->name;
        }

        if ($delivery->assignedStaff) {
            return $delivery->assignedStaff->name;
        }

        return null;
    }

    private function buildSummaryCards(array $filters): array
    {
        $today = Carbon::today();
        $activeRentals = (clone $this->rentalQuery($filters))->effectivelyActive()->count();
        $overdueRentals = (clone $this->rentalQuery($filters))->overdue()->count();
        $outstandingDues = (clone $this->invoiceQuery($filters))->sum('balance_amount');
        $paymentsToday = (clone $this->paymentQuery($filters))->whereDate('payment_date', $today)->sum('amount');
        $availableAssets = (clone $this->assetQuery($filters))->where('asset_status', 'available')->count();
        $invoicesThisMonth = (clone $this->invoiceQuery($filters))
            ->whereYear('invoice_date', $today->year)
            ->whereMonth('invoice_date', $today->month)
            ->count();

        return [
            [
                'key' => 'active_rentals',
                'label' => 'Active Rentals',
                'value' => $activeRentals,
                'url' => route('rentals.index', array_filter([
                    'status' => 'active',
                    'city' => $filters['city'] ?: null,
                    'customer_id' => $filters['customer_id'] ?: null,
                    'warehouse_id' => $filters['warehouse_id'] ?: null,
                ])),
            ],
            [
                'key' => 'overdue_rentals',
                'label' => 'Overdue Rentals',
                'value' => $overdueRentals,
                'url' => route('rentals.index', array_filter([
                    'filter' => 'overdue',
                    'city' => $filters['city'] ?: null,
                    'customer_id' => $filters['customer_id'] ?: null,
                    'warehouse_id' => $filters['warehouse_id'] ?: null,
                ])),
            ],
            [
                'key' => 'outstanding_dues',
                'label' => 'Outstanding Dues',
                'value' => round((float) $outstandingDues, 2),
                'url' => route('invoices.index', array_filter([
                    'status' => 'overdue',
                    'customer_id' => $filters['customer_id'] ?: null,
                ])),
            ],
            [
                'key' => 'payments_today',
                'label' => 'Payments Today',
                'value' => round((float) $paymentsToday, 2),
                'url' => route('reports.index', array_filter(['report' => 'payments_today'] + $filters)),
            ],
            [
                'key' => 'available_assets',
                'label' => 'Available Assets',
                'value' => $availableAssets,
                'url' => route('assets.index', array_filter([
                    'asset_status' => 'available',
                    'warehouse_id' => $filters['warehouse_id'] ?: null,
                    'product_id' => $filters['product_id'] ?: null,
                ])),
            ],
            [
                'key' => 'invoices_this_month',
                'label' => 'Invoices This Month',
                'value' => $invoicesThisMonth,
                'url' => route('reports.index', array_filter(['report' => 'invoices_this_month'] + $filters)),
            ],
        ];
    }

    private function monthlyTrendData($query, string $dateColumn, string $valueExpression = 'count(*)')
    {
        $monthExpression = $this->yearMonthExpression($dateColumn);

        return (clone $query)
            ->selectRaw("{$monthExpression} as month_key")
            ->selectRaw("{$valueExpression} as aggregate_value")
            ->groupBy('month_key')
            ->orderBy('month_key')
            ->get()
            ->map(fn ($row) => [
                'month' => $row->month_key,
                'value' => (float) $row->aggregate_value,
            ]);
    }

    private function reportGroups(array $filters): array
    {
        $rentalQuery = $this->rentalQuery($filters);
        $invoiceQuery = $this->invoiceQuery($filters);
        $paymentQuery = $this->paymentQuery($filters);
        $saleQuery = $this->saleQuery($filters);
        $deliveryQuery = $this->deliveryQuery($filters);
        $vendorOrderQuery = $this->vendorOrderQuery($filters);
        $customerQuery = $this->customerQuery($filters);
        $customerAggregateQuery = $this->customerBaseQuery($filters);
        $assetQuery = $this->assetQuery($filters);

        $rentalRevenue = (clone $rentalQuery)->sum('rental_amount');
        $rentalDeposit = (clone $rentalQuery)->sum('deposit_amount');
        $rentalTransport = (clone $rentalQuery)->sum('transport_amount');
        $rentalOtherCharges = (clone $rentalQuery)->sum('other_amount');
        $salesRevenue = (clone $saleQuery)->sum('sale_amount');
        $invoiceRevenue = (clone $invoiceQuery)->sum('total_amount');
        $invoiceDeposit = (clone $invoiceQuery)->sum('deposit_amount');
        $invoiceTransport = (clone $invoiceQuery)->sum('shipping_charges');
        $totalRevenue = max((float) $invoiceRevenue, (float) $rentalRevenue + (float) $salesRevenue + (float) $rentalDeposit + (float) $rentalTransport + (float) $rentalOtherCharges);
        $depositRevenue = max((float) $invoiceDeposit, (float) $rentalDeposit);
        $transportRevenue = max((float) $invoiceTransport, (float) $rentalTransport + (float) (clone $saleQuery)->sum('shipping_charges'));
        $otherChargesRevenue = (float) $rentalOtherCharges;

        $activeRentals = (clone $rentalQuery)->effectivelyActive()->count();
        $returnedRentals = (clone $rentalQuery)->where('status', 'returned')->count();
        $overdueRentals = (clone $rentalQuery)->overdue()->count();
        $endingSoonRentals = (clone $rentalQuery)->endingSoon()->count();
        $totalRentals = (clone $rentalQuery)->count();
        $totalSales = (clone $saleQuery)->count();

        $cityWiseRentals = (clone $rentalQuery)
            ->join('customers', 'customers.id', '=', 'rentals.customer_id')
            ->selectRaw('customers.city as label, COUNT(rentals.id) as total')
            ->whereNotNull('customers.city')
            ->groupBy('customers.city')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        $vendorWiseRentals = (clone $rentalQuery)
            ->get()
            ->groupBy(fn ($rental) => $this->vendorLabelForRental($rental))
            ->map(fn ($items, $label) => (object) [
                'label' => $label,
                'total' => $items->count(),
            ])
            ->sortByDesc('total')
            ->take(10)
            ->values();

        $warehouseWiseRentals = (clone $rentalQuery)
            ->leftJoin('warehouses', 'warehouses.id', '=', 'rentals.dispatch_warehouse_id')
            ->selectRaw("COALESCE(warehouses.name, 'Not Assigned') as label, COUNT(rentals.id) as total")
            ->groupBy('label')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        $productWiseRentals = (clone $rentalQuery)
            ->join('products', 'products.id', '=', 'rentals.product_id')
            ->selectRaw('products.name as label, COUNT(rentals.id) as total, SUM(rentals.quantity) as quantity_total')
            ->groupBy('products.name')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        $monthlyRentalsTrend = $this->monthlyTrendData($rentalQuery, 'start_date');
        $monthlyRevenueTrend = $this->monthlyTrendData($invoiceQuery, 'invoice_date', 'SUM(total_amount)');
        $monthlySalesTrend = $this->monthlyTrendData($saleQuery, 'sale_date', 'SUM(sale_amount)');

        $paymentsToday = (clone $paymentQuery)->whereDate('payment_date', Carbon::today())->sum('amount');
        $paymentsThisMonth = (clone $paymentQuery)
            ->whereYear('payment_date', Carbon::today()->year)
            ->whereMonth('payment_date', Carbon::today()->month)
            ->sum('amount');
        $outstandingDues = (clone $invoiceQuery)->sum('balance_amount');
        $overdueInvoices = (clone $invoiceQuery)->overdue()->count();

        $customerOutstanding = (clone $invoiceQuery)
            ->join('customers', 'customers.id', '=', 'invoices.customer_id')
            ->selectRaw('customers.id as customer_id, customers.name as label, SUM(invoices.balance_amount) as total')
            ->where('invoices.balance_amount', '>', 0)
            ->groupBy('customers.id', 'customers.name')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        $paymentModeSummary = (clone $paymentQuery)
            ->selectRaw("COALESCE(payment_method, 'other') as label, COUNT(id) as total_count, SUM(amount) as amount_total")
            ->groupBy('label')
            ->orderByDesc('amount_total')
            ->get();

        $monthlyCollectionsTrend = $this->monthlyTrendData($paymentQuery, 'payment_date', 'SUM(amount)');
        $collectionEfficiency = $totalRevenue > 0
            ? round((((float) (clone $paymentQuery)->sum('amount')) / $totalRevenue) * 100, 1)
            : 0.0;

        $topCustomers = (clone $customerQuery)
            ->orderByDesc('rentals_count')
            ->limit(10)
            ->get(['id', 'name', 'rentals_count']);

        $repeatCustomers = (clone $customerQuery)
            ->has('rentals', '>', 1)
            ->orderByDesc('rentals_count')
            ->limit(10)
            ->get(['id', 'name', 'rentals_count']);

        $inactiveCustomers = (clone $customerQuery)
            ->whereDoesntHave('rentals', fn ($rentalBase) => $rentalBase->whereDate('start_date', '>=', Carbon::today()->subDays(90)))
            ->whereDoesntHave('sales', fn ($saleBase) => $saleBase->whereDate('sale_date', '>=', Carbon::today()->subDays(90)))
            ->whereDoesntHave('invoices', fn ($invoiceBase) => $invoiceBase->whereDate('invoice_date', '>=', Carbon::today()->subDays(90)))
            ->orderBy('name')
            ->limit(10)
            ->get(['id', 'name', 'city']);

        $customerMonthExpression = $this->yearMonthExpression('customers.created_at');

        $newCustomersByMonth = (clone $customerAggregateQuery)
            ->selectRaw("{$customerMonthExpression} as month_key, COUNT(customers.id) as total")
            ->groupBy('month_key')
            ->orderBy('month_key')
            ->get()
            ->map(fn ($row) => ['month' => $row->month_key, 'value' => (int) $row->total]);

        $cityWiseCustomerCount = (clone $customerAggregateQuery)
            ->selectRaw("COALESCE(customers.city, 'Unknown') as label, COUNT(customers.id) as total")
            ->groupBy('label')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        $hasReferralColumns = Schema::hasColumn('rentals', 'referred_by');
        $referralRentalsQuery = $hasReferralColumns
            ? (clone $rentalQuery)->whereNotNull('referred_by')->where('referred_by', '!=', '')
            : null;
        $referralRentalCount = $referralRentalsQuery ? (clone $referralRentalsQuery)->count() : 0;
        $referralRevenue = $referralRentalsQuery
            ? round((float) (clone $referralRentalsQuery)->selectRaw('SUM(rental_amount + deposit_amount + transport_amount + other_amount) as total')->value('total'), 2)
            : 0.0;
        $referralLeaderboard = $referralRentalsQuery
            ? (clone $referralRentalsQuery)
                ->selectRaw("COALESCE(NULLIF(referral_source_type, ''), 'other') as referral_type")
                ->selectRaw('referred_by as label')
                ->selectRaw('COUNT(id) as rentals_count')
                ->selectRaw('SUM(rental_amount + deposit_amount + transport_amount + other_amount) as revenue')
                ->groupBy('referral_type', 'referred_by')
                ->orderByDesc('rentals_count')
                ->orderByDesc('revenue')
                ->limit(10)
                ->get()
            : collect();

        $availableAssets = (clone $assetQuery)->where('asset_status', 'available')->count();
        $rentedAssets = (clone $assetQuery)->where('asset_status', 'rented')->count();
        $maintenanceAssets = (clone $assetQuery)->where('asset_status', 'maintenance')->count();
        $reservedBlockedAssets = (clone $assetQuery)->whereIn('asset_status', ['reserved', 'blocked', 'reserved_for_sale'])->count();
        $assetTotal = (clone $assetQuery)->count();
        $assetUtilizationPercent = $assetTotal > 0
            ? round((($rentedAssets + $reservedBlockedAssets) / $assetTotal) * 100, 1)
            : 0.0;

        $warehouseStockSummary = (clone $assetQuery)
            ->leftJoin('warehouses', 'warehouses.id', '=', 'assets.warehouse_id')
            ->selectRaw("COALESCE(warehouses.name, 'Not Assigned') as label, COUNT(assets.id) as total")
            ->groupBy('label')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        $productUtilization = Product::query()
            ->where('organization_id', $this->orgId())
            ->when($filters['product_id'] !== '', fn ($query) => $query->where('id', (int) $filters['product_id']))
            ->when($filters['product_category'] !== '', fn ($query) => $query->where('category', $filters['product_category']))
            ->withCount([
                'assets as total_assets' => fn ($assetBase) => $assetBase
                    ->when($filters['warehouse_id'] !== '', fn ($warehouseQuery) => $warehouseQuery->where('warehouse_id', (int) $filters['warehouse_id'])),
                'assets as available_assets' => fn ($assetBase) => $assetBase
                    ->when($filters['warehouse_id'] !== '', fn ($warehouseQuery) => $warehouseQuery->where('warehouse_id', (int) $filters['warehouse_id']))
                    ->where('asset_status', 'available'),
                'assets as rented_assets' => fn ($assetBase) => $assetBase
                    ->when($filters['warehouse_id'] !== '', fn ($warehouseQuery) => $warehouseQuery->where('warehouse_id', (int) $filters['warehouse_id']))
                    ->whereIn('asset_status', ['rented', 'reserved', 'blocked']),
                'assets as maintenance_assets' => fn ($assetBase) => $assetBase
                    ->when($filters['warehouse_id'] !== '', fn ($warehouseQuery) => $warehouseQuery->where('warehouse_id', (int) $filters['warehouse_id']))
                    ->where('asset_status', 'maintenance'),
                'rentals as active_rentals' => fn ($rentalBase) => $rentalBase->where('status', 'active'),
            ])
            ->orderByDesc('rented_assets')
            ->limit(10)
            ->get(['id', 'name', 'category', 'available_quantity', 'total_quantity']);

        $lowStockThresholdExpression = DB::connection()->getDriverName() === 'sqlite'
            ? 'max(2, total_quantity * 0.15)'
            : 'GREATEST(2, total_quantity * 0.15)';
        $lowStockProducts = Product::query()
            ->where('organization_id', $this->orgId())
            ->when($filters['product_id'] !== '', fn ($query) => $query->where('id', (int) $filters['product_id']))
            ->when($filters['product_category'] !== '', fn ($query) => $query->where('category', $filters['product_category']))
            ->whereRaw("available_quantity <= {$lowStockThresholdExpression}")
            ->orderBy('available_quantity')
            ->limit(10)
            ->get(['id', 'name', 'available_quantity', 'total_quantity']);

        $categoryAvailability = (clone $assetQuery)
            ->join('products', 'products.id', '=', 'assets.product_id')
            ->selectRaw("COALESCE(products.category, 'Uncategorized') as label")
            ->selectRaw("SUM(CASE WHEN assets.asset_status = 'available' THEN 1 ELSE 0 END) as available_count")
            ->selectRaw("SUM(CASE WHEN assets.asset_status IN ('rented', 'reserved', 'blocked') THEN 1 ELSE 0 END) as committed_count")
            ->selectRaw('COUNT(assets.id) as total_count')
            ->groupBy('label')
            ->orderByDesc('total_count')
            ->limit(10)
            ->get();

        $idleAssets = (clone $assetQuery)
            ->where('asset_status', 'available')
            ->whereDoesntHave('activeRentalAssignments')
            ->where('updated_at', '<=', Carbon::today()->subDays(30))
            ->limit(20)
            ->get(['id', 'asset_name', 'serial_number', 'product_id', 'warehouse_id']);

        $invoicesThisMonth = (clone $invoiceQuery)
            ->whereYear('invoice_date', Carbon::today()->year)
            ->whereMonth('invoice_date', Carbon::today()->month)
            ->count();
        $unpaidInvoices = (clone $invoiceQuery)->where('payment_status', 'unpaid')->count();
        $partialInvoices = (clone $invoiceQuery)->where('payment_status', 'partial')->count();
        $paidInvoices = (clone $invoiceQuery)->where('payment_status', 'paid')->count();
        $monthlyInvoiceTrend = $this->monthlyTrendData($invoiceQuery, 'invoice_date');

        $businessPartnerRows = collect()
            ->merge((clone $rentalQuery)->with(['businessPartner', 'partnerClient'])->whereNotNull('business_partner_id')->get()->map(fn ($rental) => [
                'id' => $rental->business_partner_id,
                'label' => $rental->businessPartner?->name ?? 'Business Partner',
                'client' => $rental->partnerClient?->displayName() ?? null,
                'rental_revenue' => (float) ($rental->rental_amount ?? 0) + (float) ($rental->deposit_amount ?? 0) + (float) ($rental->transport_amount ?? 0) + (float) ($rental->other_amount ?? 0),
                'sales_revenue' => 0.0,
                'rentals' => 1,
                'sales' => 0,
            ]))
            ->merge((clone $saleQuery)->with(['businessPartner', 'partnerClient'])->whereNotNull('business_partner_id')->get()->map(fn ($sale) => [
                'id' => $sale->business_partner_id,
                'label' => $sale->businessPartner?->name ?? 'Business Partner',
                'client' => $sale->partnerClient?->displayName() ?? null,
                'rental_revenue' => 0.0,
                'sales_revenue' => (float) ($sale->sale_amount ?? 0),
                'rentals' => 0,
                'sales' => 1,
            ]))
            ->groupBy('id')
            ->map(fn ($items) => (object) [
                'id' => $items->first()['id'],
                'label' => $items->first()['label'],
                'revenue' => round($items->sum('rental_revenue') + $items->sum('sales_revenue'), 2),
                'actual_clients' => $items->pluck('client')->filter()->unique()->count(),
                'rentals_count' => $items->sum('rentals'),
                'sales_count' => $items->sum('sales'),
                'outstanding' => (float) (clone $invoiceQuery)->whereHas('customer', fn ($customerBase) => $customerBase->where('name', $items->first()['label']))->sum('balance_amount'),
                'collection_percent' => $items->sum('rental_revenue') + $items->sum('sales_revenue') > 0 ? round((max(0, $items->sum('rental_revenue') + $items->sum('sales_revenue') - (float) (clone $invoiceQuery)->whereHas('customer', fn ($customerBase) => $customerBase->where('name', $items->first()['label']))->sum('balance_amount')) / ($items->sum('rental_revenue') + $items->sum('sales_revenue'))) * 100, 1) : 0,
            ])
            ->sortByDesc('revenue')
            ->take(10)
            ->values();

        $rentalCreators = (clone $rentalQuery)
            ->whereNotNull('created_by_user_id')
            ->get(['created_by_user_id', 'rental_amount', 'deposit_amount', 'transport_amount', 'other_amount'])
            ->groupBy('created_by_user_id');
        $saleCreators = (clone $saleQuery)
            ->whereNotNull('created_by_user_id')
            ->get(['created_by_user_id', 'sale_amount'])
            ->groupBy('created_by_user_id');
        $staffNames = User::query()
            ->where('organization_id', $this->orgId())
            ->whereIn('id', $rentalCreators->keys()->merge($saleCreators->keys())->filter()->unique()->values())
            ->pluck('name', 'id');
        $followUpsByUser = Schema::hasTable('follow_ups')
            ? DB::table('follow_ups')
                ->where('organization_id', $this->orgId())
                ->where('status', 'completed')
                ->when($filters['staff_user_id'] !== '', fn ($query) => $query->where('assigned_user_id', (int) $filters['staff_user_id']))
                ->selectRaw('assigned_user_id, COUNT(*) as total')
                ->groupBy('assigned_user_id')
                ->pluck('total', 'assigned_user_id')
            : collect();
        $salesStaffPerformance = $rentalCreators->keys()
            ->merge($saleCreators->keys())
            ->merge($followUpsByUser->keys())
            ->filter()
            ->unique()
            ->map(fn ($userId) => (object) [
                'label' => $staffNames[$userId] ?? ('User #' . $userId),
                'orders_created' => ($rentalCreators->get($userId)?->count() ?? 0) + ($saleCreators->get($userId)?->count() ?? 0),
                'revenue_generated' => round(($rentalCreators->get($userId)?->sum(fn ($rental) => (float) $rental->rental_amount + (float) $rental->deposit_amount + (float) $rental->transport_amount + (float) $rental->other_amount) ?? 0) + ($saleCreators->get($userId)?->sum('sale_amount') ?? 0), 2),
                'collections_supported' => 0,
                'followups_completed' => (int) ($followUpsByUser[$userId] ?? 0),
            ])
            ->sortByDesc('revenue_generated')
            ->take(10)
            ->values();

        $deliveryStaffPerformance = (clone $deliveryQuery)
            ->whereNotNull('assigned_user_id')
            ->get(['assigned_user_id', 'type', 'status', 'scheduled_at', 'completed_at'])
            ->groupBy('assigned_user_id')
            ->map(fn ($items, $userId) => (object) [
                'label' => $items->first()->assignedUser?->name ?? ('User #' . $userId),
                'assigned_deliveries' => $items->where('type', 'delivery')->count(),
                'completed_deliveries' => $items->where('type', 'delivery')->where('status', 'completed')->count(),
                'delayed_deliveries' => $items->where('type', 'delivery')->filter(fn ($delivery) => $delivery->status !== 'completed' && $delivery->scheduled_at && $delivery->scheduled_at->lt(now()))->count(),
                'pickups_completed' => $items->where('type', 'pickup')->whereIn('status', ['completed', 'picked_up'])->count(),
            ])
            ->sortByDesc('assigned_deliveries')
            ->take(10)
            ->values();

        $vendorOrders = (clone $vendorOrderQuery)->get();
        $vendorRevenue = round($vendorOrders->sum(fn ($detail) => $detail->customerRevenue()), 2);
        $vendorCost = round($vendorOrders->sum(fn ($detail) => $detail->totalVendorCost()), 2);
        $vendorMargin = round($vendorRevenue - $vendorCost, 2);
        $vendorCompletedStates = ['delivery_completed', 'pickup_completed', 'completed'];
        $vendorOrdersCount = $vendorOrders->count();
        $vendorOnTimeFulfilments = $vendorOrders
            ->filter(fn ($detail) => in_array($detail->operationalFulfilmentStatus(), $vendorCompletedStates, true) && ($detail->vendor_order_status ?? null) !== 'cancelled')
            ->count();
        $vendorCancelledFulfilments = $vendorOrders
            ->filter(fn ($detail) => ($detail->vendor_order_status ?? null) === 'cancelled')
            ->count();
        $vendorDelayedFulfilments = $vendorOrders
            ->filter(fn ($detail) => $detail->fulfilment_source === VendorOrderDetail::FULFILMENT_SOURCE_VENDOR_SUPPLIED
                && !in_array($detail->operationalFulfilmentStatus(), $vendorCompletedStates, true)
                && ($detail->vendor_order_status ?? null) !== 'cancelled')
            ->count();
        $vendorFulfilmentScore = $vendorOrdersCount > 0
            ? round(max(0, (($vendorOnTimeFulfilments - ($vendorDelayedFulfilments * 0.5) - $vendorCancelledFulfilments) / $vendorOrdersCount) * 100), 1)
            : 0.0;
        $topVendors = $vendorOrders
            ->groupBy('vendor_id')
            ->map(function ($items) use ($vendorCompletedStates) {
                $revenue = round($items->sum(fn ($detail) => $detail->customerRevenue()), 2);
                $cost = round($items->sum(fn ($detail) => $detail->totalVendorCost()), 2);
                $orders = $items->count();
                $onTime = $items
                    ->filter(fn ($detail) => in_array($detail->operationalFulfilmentStatus(), $vendorCompletedStates, true) && ($detail->vendor_order_status ?? null) !== 'cancelled')
                    ->count();
                $cancelled = $items->filter(fn ($detail) => ($detail->vendor_order_status ?? null) === 'cancelled')->count();
                $delayed = $items
                    ->filter(fn ($detail) => $detail->fulfilment_source === VendorOrderDetail::FULFILMENT_SOURCE_VENDOR_SUPPLIED
                        && !in_array($detail->operationalFulfilmentStatus(), $vendorCompletedStates, true)
                        && ($detail->vendor_order_status ?? null) !== 'cancelled')
                    ->count();

                return (object) [
                    'label' => $items->first()->vendor?->name ?? 'Unassigned vendor',
                    'orders' => $orders,
                    'revenue' => $revenue,
                    'cost' => $cost,
                    'margin' => round($revenue - $cost, 2),
                    'margin_percent' => $revenue > 0 ? round((($revenue - $cost) / $revenue) * 100, 1) : 0.0,
                    'on_time' => $onTime,
                    'delayed' => $delayed,
                    'cancelled' => $cancelled,
                    'fulfilment_score' => $orders > 0 ? round(max(0, (($onTime - ($delayed * 0.5) - $cancelled) / $orders) * 100), 1) : 0.0,
                ];
            })
            ->sortByDesc('revenue')
            ->take(10)
            ->values();
        $delayedVendorFulfilment = $vendorOrders
            ->filter(fn ($detail) => $detail->fulfilment_source === VendorOrderDetail::FULFILMENT_SOURCE_VENDOR_SUPPLIED
                && !in_array($detail->operationalFulfilmentStatus(), $vendorCompletedStates, true)
                && ($detail->vendor_order_status ?? null) !== 'cancelled')
            ->take(8)
            ->values();
        $vendorTrend = $vendorOrders
            ->groupBy(fn ($detail) => optional($detail->created_at)->format('Y-m') ?: 'Unknown')
            ->map(fn ($items, $month) => [
                'month' => $month,
                'revenue' => round($items->sum(fn ($detail) => $detail->customerRevenue()), 2),
                'cost' => round($items->sum(fn ($detail) => $detail->totalVendorCost()), 2),
                'margin' => round($items->sum(fn ($detail) => $detail->grossMargin()), 2),
            ])
            ->sortBy('month')
            ->values();
        $highCostVendors = $topVendors
            ->filter(fn ($vendor) => $vendor->cost > 0 && ($vendor->revenue <= 0 || $vendor->cost >= ($vendor->revenue * 0.75)))
            ->sortByDesc('cost')
            ->take(6)
            ->values();
        $lowMarginVendors = $topVendors
            ->filter(fn ($vendor) => $vendor->orders > 0 && $vendor->margin_percent <= 10)
            ->sortBy('margin_percent')
            ->take(6)
            ->values();
        $delayedVendors = $topVendors
            ->filter(fn ($vendor) => $vendor->delayed > 0)
            ->sortByDesc('delayed')
            ->take(6)
            ->values();

        $deliveryLogisticsCost = round($vendorOrders->sum(fn ($detail) => (float) ($detail->vendor_delivery_cost ?? 0) + (float) ($detail->vendor_pickup_cost ?? 0)), 2);
        $maintenanceCost = round($vendorOrders->sum('other_vendor_cost'), 2);
        $grossMargin = round($totalRevenue - $vendorCost, 2);
        $estimatedEbitda = round($totalRevenue - $vendorCost - $deliveryLogisticsCost - $maintenanceCost, 2);

        $averageRentalDuration = (clone $rentalQuery)
            ->get(['start_date', 'end_date'])
            ->filter(fn ($rental) => $rental->start_date && $rental->end_date)
            ->avg(fn ($rental) => max(1, $rental->start_date->diffInDays($rental->end_date)));
        $productTrends = $productWiseRentals
            ->map(function ($row) use ($productUtilization) {
                $product = $productUtilization->firstWhere('name', $row->label);
                $utilization = ($product && (int) $product->total_assets > 0)
                    ? round(((int) $product->rented_assets / (int) $product->total_assets) * 100, 1)
                    : 0.0;

                return (object) [
                    'label' => $row->label,
                    'rental_frequency' => (int) $row->total,
                    'quantity_total' => (int) $row->quantity_total,
                    'utilization_percent' => $utilization,
                    'demand_projection' => $row->total >= 5 ? 'Rising' : ($row->total >= 2 ? 'Stable' : 'Low signal'),
                    'suggested_procurement' => $utilization >= 80 ? 'Consider procurement' : ($utilization >= 60 ? 'Monitor demand' : 'No immediate need'),
                ];
            })
            ->take(10)
            ->values();

        return [
            'revenue_analytics' => [
                'total_revenue' => round((float) $totalRevenue, 2),
                'rental_revenue' => round((float) $rentalRevenue, 2),
                'sales_revenue' => round((float) $salesRevenue, 2),
                'deposit' => round((float) $depositRevenue, 2),
                'transport' => round((float) $transportRevenue, 2),
                'other_charges' => round((float) $otherChargesRevenue, 2),
                'composition' => [
                    ['label' => 'Rental', 'value' => round((float) $rentalRevenue, 2), 'color' => '#4f46e5'],
                    ['label' => 'Sales', 'value' => round((float) $salesRevenue, 2), 'color' => '#0891b2'],
                    ['label' => 'Deposit', 'value' => round((float) $depositRevenue, 2), 'color' => '#16a34a'],
                    ['label' => 'Transport', 'value' => round((float) $transportRevenue, 2), 'color' => '#f59e0b'],
                    ['label' => 'Other', 'value' => round((float) $otherChargesRevenue, 2), 'color' => '#64748b'],
                ],
                'revenue_trend' => $monthlyRevenueTrend,
                'rental_trend' => $monthlyRentalsTrend,
                'sales_trend' => $monthlySalesTrend,
                'collection_efficiency' => $collectionEfficiency,
            ],
            'rental_reports' => [
                'metrics' => compact('activeRentals', 'returnedRentals', 'overdueRentals', 'endingSoonRentals', 'totalRentals'),
                'city_wise' => $cityWiseRentals,
                'vendor_wise' => $vendorWiseRentals,
                'warehouse_wise' => $warehouseWiseRentals,
                'product_wise' => $productWiseRentals,
                'monthly_trend' => $monthlyRentalsTrend,
            ],
            'collection_reports' => [
                'payments_today' => round((float) $paymentsToday, 2),
                'payments_this_month' => round((float) $paymentsThisMonth, 2),
                'outstanding_dues' => round((float) $outstandingDues, 2),
                'overdue_invoices' => $overdueInvoices,
                'customer_outstanding' => $customerOutstanding,
                'payment_mode_summary' => $paymentModeSummary,
                'monthly_trend' => $monthlyCollectionsTrend,
            ],
            'customer_reports' => [
                'top_customers' => $topCustomers,
                'repeat_customers' => $repeatCustomers,
                'inactive_customers' => $inactiveCustomers,
                'new_customers_by_month' => $newCustomersByMonth,
                'city_wise_counts' => $cityWiseCustomerCount,
                'business_partner_performance' => $businessPartnerRows,
                'referral_summary' => [
                    'rental_count' => $referralRentalCount,
                    'revenue' => $referralRevenue,
                    'unique_referrers' => $referralLeaderboard->pluck('label')->filter()->unique()->count(),
                    'leaderboard' => $referralLeaderboard,
                    'available' => $hasReferralColumns,
                ],
            ],
            'inventory_reports' => [
                'available_assets' => $availableAssets,
                'rented_assets' => $rentedAssets,
                'maintenance_assets' => $maintenanceAssets,
                'reserved_blocked_assets' => $reservedBlockedAssets,
                'utilization_percent' => $assetUtilizationPercent,
                'warehouse_stock_summary' => $warehouseStockSummary,
                'product_utilization' => $productUtilization,
                'low_stock_products' => $lowStockProducts,
                'high_demand_products' => $productWiseRentals->take(8)->values(),
                'category_availability' => $categoryAvailability,
                'idle_assets' => $idleAssets,
            ],
            'sales_invoice_reports' => [
                'invoices_this_month' => $invoicesThisMonth,
                'unpaid_invoices' => $unpaidInvoices,
                'partial_invoices' => $partialInvoices,
                'paid_invoices' => $paidInvoices,
                'monthly_invoice_trend' => $monthlyInvoiceTrend,
                'sales_count' => $totalSales,
            ],
            'staff_performance' => [
                'sales_staff' => $salesStaffPerformance,
                'delivery_staff' => $deliveryStaffPerformance,
            ],
            'vendor_analytics' => [
                'vendor_supplied_rentals' => (clone $rentalQuery)->where('fulfilment_source', VendorOrderDetail::FULFILMENT_SOURCE_VENDOR_SUPPLIED)->count(),
                'vendor_supplied_sales' => (clone $saleQuery)->where('fulfilment_source', VendorOrderDetail::FULFILMENT_SOURCE_VENDOR_SUPPLIED)->count(),
                'vendor_revenue' => $vendorRevenue,
                'vendor_cost' => $vendorCost,
                'vendor_margin' => $vendorMargin,
                'vendor_orders_count' => $vendorOrdersCount,
                'fulfilment_performance' => [
                    'on_time' => $vendorOnTimeFulfilments,
                    'delayed' => $vendorDelayedFulfilments,
                    'cancelled' => $vendorCancelledFulfilments,
                    'score' => $vendorFulfilmentScore,
                ],
                'top_vendors' => $topVendors,
                'delayed_vendor_fulfilment' => $delayedVendorFulfilment,
                'vendor_trend' => $vendorTrend,
                'vendor_risk' => [
                    'delayed_vendors' => $delayedVendors,
                    'high_cost_vendors' => $highCostVendors,
                    'low_margin_vendors' => $lowMarginVendors,
                ],
            ],
            'profitability_reports' => [
                'total_revenue' => round((float) $totalRevenue, 2),
                'vendor_cost' => $vendorCost,
                'delivery_logistics_cost' => $deliveryLogisticsCost,
                'repair_maintenance_cost' => $maintenanceCost,
                'gross_margin' => $grossMargin,
                'estimated_ebitda' => $estimatedEbitda,
                'is_estimated' => true,
            ],
            'product_trends' => [
                'fast_moving_products' => $productWiseRentals->take(8)->values(),
                'average_rental_duration' => round((float) ($averageRentalDuration ?? 0), 1),
                'utilization_trend' => $productTrends,
                'demand_projection' => $productTrends->where('demand_projection', 'Rising')->values(),
                'suggested_procurement' => $productTrends->filter(fn ($row) => $row->suggested_procurement !== 'No immediate need')->values(),
            ],
        ];
    }

    private function buildExportDataset(array $filters, string $reportKey): array
    {
        return match ($reportKey) {
            'active_rentals' => $this->rentalExportDataset((clone $this->rentalQuery($filters))->effectivelyActive()->latest('start_date')->get(), 'Active Rentals'),
            'returned_rentals' => $this->rentalExportDataset((clone $this->rentalQuery($filters))->where('status', 'returned')->latest('returned_at')->get(), 'Returned Rentals'),
            'overdue_rentals' => $this->rentalExportDataset((clone $this->rentalQuery($filters))->overdue()->latest('end_date')->get(), 'Overdue Rentals'),
            'ending_soon_rentals' => $this->rentalExportDataset((clone $this->rentalQuery($filters))->endingSoon()->latest('end_date')->get(), 'Ending Soon Rentals'),
            'payments_today' => $this->paymentsExportDataset((clone $this->paymentQuery($filters))->whereDate('payment_date', Carbon::today())->latest('payment_date')->get(), 'Payments Today'),
            'payments_this_month' => $this->paymentsExportDataset((clone $this->paymentQuery($filters))->whereYear('payment_date', Carbon::today()->year)->whereMonth('payment_date', Carbon::today()->month)->latest('payment_date')->get(), 'Payments This Month'),
            'outstanding_dues', 'overdue_invoices' => $this->invoicesExportDataset((clone $this->invoiceQuery($filters))->overdue()->latest('due_date')->get(), 'Overdue Invoices'),
            'customer_outstanding' => $this->customerOutstandingExportDataset($filters),
            'available_assets' => $this->assetsExportDataset((clone $this->assetQuery($filters))->where('asset_status', 'available')->latest()->get(), 'Available Assets'),
            'rented_assets' => $this->assetsExportDataset((clone $this->assetQuery($filters))->where('asset_status', 'rented')->latest()->get(), 'Rented Assets'),
            'maintenance_assets' => $this->assetsExportDataset((clone $this->assetQuery($filters))->where('asset_status', 'maintenance')->latest()->get(), 'Maintenance Assets'),
            'warehouse_stock_summary' => $this->warehouseStockExportDataset($filters),
            'product_utilization' => $this->productUtilizationExportDataset($filters),
            'unpaid_invoices' => $this->invoicesExportDataset((clone $this->invoiceQuery($filters))->where('payment_status', 'unpaid')->latest('invoice_date')->get(), 'Unpaid Invoices'),
            'partial_invoices' => $this->invoicesExportDataset((clone $this->invoiceQuery($filters))->where('payment_status', 'partial')->latest('invoice_date')->get(), 'Partial Invoices'),
            'paid_invoices' => $this->invoicesExportDataset((clone $this->invoiceQuery($filters))->where('payment_status', 'paid')->latest('invoice_date')->get(), 'Paid Invoices'),
            'invoices_this_month' => $this->invoicesExportDataset((clone $this->invoiceQuery($filters))->whereYear('invoice_date', Carbon::today()->year)->whereMonth('invoice_date', Carbon::today()->month)->latest('invoice_date')->get(), 'Invoices This Month'),
            default => $this->rentalExportDataset((clone $this->rentalQuery($filters))->latest('start_date')->limit(200)->get(), 'Filtered Rentals'),
        };
    }

    private function rentalExportDataset($rentals, string $title): array
    {
        return [
            'title' => $title,
            'columns' => ['Rental ID', 'Customer', 'City', 'Product', 'Quantity', 'Warehouse', 'Vendor', 'Start Date', 'End Date', 'Status', 'Amount'],
            'rows' => $rentals->map(fn ($rental) => [
                $rental->id,
                $rental->customer_name ?: ($rental->customer->name ?? 'N/A'),
                $rental->customer->city ?? '',
                $rental->product->name ?? 'N/A',
                $rental->quantity,
                $rental->dispatchWarehouse->name ?? '',
                $this->vendorLabelForRental($rental),
                optional($rental->start_date)->format('Y-m-d'),
                optional($rental->end_date)->format('Y-m-d'),
                $rental->status,
                number_format((float) ($rental->rental_amount ?? 0), 2, '.', ''),
            ])->all(),
        ];
    }

    private function paymentsExportDataset($payments, string $title): array
    {
        return [
            'title' => $title,
            'columns' => ['Payment Date', 'Customer', 'Invoice Ref', 'Rental Ref', 'Amount', 'Mode', 'Notes'],
            'rows' => $payments->map(fn ($payment) => [
                optional($payment->payment_date)->format('Y-m-d'),
                $payment->customer->name ?? $payment->rental->customer->name ?? 'N/A',
                $payment->invoice->invoice_number ?? '',
                $payment->rental_id ?? '',
                number_format((float) $payment->amount, 2, '.', ''),
                method_exists($payment, 'paymentMethodLabel') ? $payment->paymentMethodLabel() : $payment->payment_method,
                $payment->notes,
            ])->all(),
        ];
    }

    private function invoicesExportDataset($invoices, string $title): array
    {
        return [
            'title' => $title,
            'columns' => ['Invoice No', 'Customer', 'City', 'Date', 'Amount', 'Paid', 'Due', 'Status'],
            'rows' => $invoices->map(fn ($invoice) => [
                $invoice->invoice_number,
                $invoice->bill_to_name ?: ($invoice->customer->name ?? 'N/A'),
                $invoice->bill_to_city ?: ($invoice->customer->city ?? ''),
                optional($invoice->invoice_date)->format('Y-m-d'),
                number_format((float) $invoice->total_amount, 2, '.', ''),
                number_format((float) $invoice->paid_amount, 2, '.', ''),
                number_format((float) $invoice->balance_amount, 2, '.', ''),
                $invoice->payment_status ?: $invoice->status,
            ])->all(),
        ];
    }

    private function assetsExportDataset($assets, string $title): array
    {
        return [
            'title' => $title,
            'columns' => ['Asset ID', 'Serial Number', 'Barcode', 'Product', 'Warehouse', 'Condition', 'Status'],
            'rows' => $assets->map(fn ($asset) => [
                $asset->id,
                $asset->serial_number,
                $asset->barcode_value,
                $asset->product->name ?? 'N/A',
                $asset->warehouse->name ?? '',
                $asset->condition_status,
                $asset->asset_status,
            ])->all(),
        ];
    }

    private function customerOutstandingExportDataset(array $filters): array
    {
        $rows = (clone $this->invoiceQuery($filters))
            ->join('customers', 'customers.id', '=', 'invoices.customer_id')
            ->selectRaw('customers.name as customer_name, customers.city as city, SUM(invoices.balance_amount) as outstanding_total, COUNT(invoices.id) as invoice_count')
            ->where('invoices.balance_amount', '>', 0)
            ->groupBy('customers.name', 'customers.city')
            ->orderByDesc('outstanding_total')
            ->get();

        return [
            'title' => 'Customer Outstanding',
            'columns' => ['Customer', 'City', 'Outstanding', 'Open Invoices'],
            'rows' => $rows->map(fn ($row) => [
                $row->customer_name,
                $row->city,
                number_format((float) $row->outstanding_total, 2, '.', ''),
                $row->invoice_count,
            ])->all(),
        ];
    }

    private function warehouseStockExportDataset(array $filters): array
    {
        $rows = (clone $this->assetQuery($filters))
            ->leftJoin('warehouses', 'warehouses.id', '=', 'assets.warehouse_id')
            ->selectRaw("COALESCE(warehouses.name, 'Not Assigned') as warehouse_name")
            ->selectRaw("SUM(CASE WHEN assets.asset_status = 'available' THEN 1 ELSE 0 END) as available_count")
            ->selectRaw("SUM(CASE WHEN assets.asset_status = 'rented' THEN 1 ELSE 0 END) as rented_count")
            ->selectRaw("SUM(CASE WHEN assets.asset_status = 'maintenance' THEN 1 ELSE 0 END) as maintenance_count")
            ->selectRaw('COUNT(assets.id) as total_count')
            ->groupBy('warehouse_name')
            ->orderByDesc('total_count')
            ->get();

        return [
            'title' => 'Warehouse Stock Summary',
            'columns' => ['Warehouse', 'Total Assets', 'Available', 'Rented', 'Maintenance'],
            'rows' => $rows->map(fn ($row) => [
                $row->warehouse_name,
                $row->total_count,
                $row->available_count,
                $row->rented_count,
                $row->maintenance_count,
            ])->all(),
        ];
    }

    private function productUtilizationExportDataset(array $filters): array
    {
        $rows = Product::query()
            ->where('organization_id', $this->orgId())
            ->when($filters['product_id'] !== '', fn ($query) => $query->where('id', (int) $filters['product_id']))
            ->withCount([
                'assets as total_assets',
                'assets as rented_assets' => fn ($assetBase) => $assetBase
                    ->when($filters['warehouse_id'] !== '', fn ($warehouseQuery) => $warehouseQuery->where('warehouse_id', (int) $filters['warehouse_id']))
                    ->whereIn('asset_status', ['rented', 'reserved']),
                'rentals as active_rentals' => fn ($rentalBase) => $rentalBase
                    ->when($filters['customer_id'] !== '', fn ($customerQuery) => $customerQuery->where('customer_id', (int) $filters['customer_id']))
                    ->where('status', 'active'),
            ])
            ->orderByDesc('rented_assets')
            ->get(['name']);

        return [
            'title' => 'Product Utilization',
            'columns' => ['Product', 'Total Assets', 'Rented or Reserved Assets', 'Active Rentals'],
            'rows' => $rows->map(fn ($row) => [
                $row->name,
                $row->total_assets,
                $row->rented_assets,
                $row->active_rentals,
            ])->all(),
        ];
    }

    private function streamCsv(array $dataset)
    {
        $filename = str($dataset['title'])->slug('-') . '-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($dataset) {
            $output = fopen('php://output', 'w');
            fputcsv($output, $dataset['columns']);

            foreach ($dataset['rows'] as $row) {
                fputcsv($output, $row);
            }

            fclose($output);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function index(Request $request)
    {
        $this->authorize('viewAny', self::class);

        $filters = $this->filters($request);
        $payload = [
            'filters' => $filters,
            'filterOptions' => $this->filterOptions(),
            'summaryCards' => $this->buildSummaryCards($filters),
            'reportGroups' => $this->reportGroups($filters),
            'exportUrl' => route('reports.export.csv', $filters),
        ];

        if (view()->exists('reports.index')) {
            return view('reports.index', $payload);
        }

        return response()->json($payload);
    }

    public function exportCsv(Request $request)
    {
        $this->authorize('export', self::class);

        $filters = $this->filters($request);
        $dataset = $this->buildExportDataset($filters, $filters['report']);

        return $this->streamCsv($dataset);
    }
}
