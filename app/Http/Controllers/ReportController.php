<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Rental;
use App\Models\RentalAsset;
use App\Models\User;
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

    private function filters(Request $request): array
    {
        return [
            'from_date' => trim((string) $request->get('from_date', '')),
            'to_date' => trim((string) $request->get('to_date', '')),
            'city' => trim((string) $request->get('city', '')),
            'vendor_id' => trim((string) $request->get('vendor_id', '')),
            'warehouse_id' => trim((string) $request->get('warehouse_id', '')),
            'customer_id' => trim((string) $request->get('customer_id', '')),
            'product_id' => trim((string) $request->get('product_id', '')),
            'report' => trim((string) $request->get('report', 'overview')) ?: 'overview',
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

        $vendors = User::query()
            ->with('assignedRole')
            ->where('organization_id', $organizationId)
            ->when(Schema::hasColumn('users', 'is_active'), fn ($query) => $query->where('is_active', true))
            ->where(function ($query) {
                $query->where('role', User::ROLE_VENDOR)
                    ->orWhereHas('assignedRole', fn ($roleQuery) => $roleQuery->where('slug', User::ROLE_VENDOR));
            })
            ->orderBy('name')
            ->get(['id', 'name', 'role', 'role_id']);

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

        return compact('cities', 'vendors', 'warehouses', 'customers', 'products');
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
            ])
            ->where('rentals.organization_id', $this->orgId());

        if ($filters['city'] !== '') {
            $query->whereHas('customer', fn ($customerQuery) => $customerQuery->where('city', 'like', '%' . $filters['city'] . '%'));
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

        if ($filters['product_id'] !== '') {
            $query->where('product_id', (int) $filters['product_id']);
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

        if ($filters['vendor_id'] !== '' || $filters['warehouse_id'] !== '') {
            $rentalIdQuery = Rental::query()
                ->select('id')
                ->forOrganization($this->orgId());

            if ($filters['vendor_id'] !== '') {
                $this->applyVendorFilterToRentalQuery($rentalIdQuery, (int) $filters['vendor_id']);
            }

            if ($filters['warehouse_id'] !== '') {
                $rentalIdQuery->where('dispatch_warehouse_id', (int) $filters['warehouse_id']);
            }

            $query->whereHas('items', function ($itemQuery) use ($rentalIdQuery) {
                $itemQuery
                    ->where('source_type', 'rental')
                    ->whereIn('source_id', $rentalIdQuery);
            });
        }

        return $this->applyDateRange($query, 'invoice_date', $filters);
    }

    private function paymentQuery(array $filters)
    {
        $query = Payment::query()->with(['customer', 'invoice', 'rental.customer', 'rental.product']);

        if ($this->paymentsHaveOrganizationColumn()) {
            $query->where('organization_id', $this->orgId());
        } else {
            $query->whereHas('rental', fn ($rentalQuery) => $rentalQuery->where('organization_id', $this->orgId()));
        }

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

        if ($filters['vendor_id'] !== '' || $filters['warehouse_id'] !== '' || $filters['product_id'] !== '') {
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

        return $this->applyDateRange($query, 'purchase_date', $filters);
    }

    private function applyVendorFilterToRentalQuery($query, int $vendorUserId)
    {
        if ($vendorUserId <= 0) {
            return $query;
        }

        return $query->where(function ($vendorQuery) use ($vendorUserId) {
            $vendorQuery
                ->whereHas('deliveryRecord', fn ($deliveryQuery) => $deliveryQuery->where('assigned_user_id', $vendorUserId))
                ->orWhereHas('pickupRecord', fn ($pickupQuery) => $pickupQuery->where('assigned_user_id', $vendorUserId));
        });
    }

    private function vendorLabelForRental(Rental $rental): string
    {
        $deliveryVendor = $this->vendorNameFromDeliveryRecord($rental->deliveryRecord);
        $pickupVendor = $this->vendorNameFromDeliveryRecord($rental->pickupRecord);

        return $deliveryVendor ?? $pickupVendor ?? 'Unassigned';
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
        return (clone $query)
            ->selectRaw("DATE_FORMAT({$dateColumn}, '%Y-%m') as month_key")
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
        $customerQuery = $this->customerQuery($filters);
        $customerAggregateQuery = $this->customerBaseQuery($filters);
        $assetQuery = $this->assetQuery($filters);

        $activeRentals = (clone $rentalQuery)->effectivelyActive()->count();
        $returnedRentals = (clone $rentalQuery)->where('status', 'returned')->count();
        $overdueRentals = (clone $rentalQuery)->overdue()->count();
        $endingSoonRentals = (clone $rentalQuery)->endingSoon()->count();

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

        $topCustomers = (clone $customerQuery)
            ->orderByDesc('rentals_count')
            ->limit(10)
            ->get(['id', 'name', 'rentals_count']);

        $repeatCustomers = (clone $customerQuery)
            ->having('rentals_count', '>', 1)
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

        $newCustomersByMonth = (clone $customerAggregateQuery)
            ->selectRaw("DATE_FORMAT(customers.created_at, '%Y-%m') as month_key, COUNT(customers.id) as total")
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

        $availableAssets = (clone $assetQuery)->where('asset_status', 'available')->count();
        $rentedAssets = (clone $assetQuery)->where('asset_status', 'rented')->count();
        $maintenanceAssets = (clone $assetQuery)->where('asset_status', 'maintenance')->count();

        $warehouseStockSummary = (clone $assetQuery)
            ->leftJoin('warehouses', 'warehouses.id', '=', 'assets.warehouse_id')
            ->selectRaw("COALESCE(warehouses.name, 'Not Assigned') as label, COUNT(assets.id) as total")
            ->groupBy('label')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        $productUtilization = Product::query()
            ->where('organization_id', $this->orgId())
            ->withCount([
                'assets as total_assets',
                'assets as rented_assets' => fn ($assetBase) => $assetBase->whereIn('asset_status', ['rented', 'reserved']),
                'rentals as active_rentals' => fn ($rentalBase) => $rentalBase->where('status', 'active'),
            ])
            ->orderByDesc('rented_assets')
            ->limit(10)
            ->get(['id', 'name']);

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

        return [
            'rental_reports' => [
                'metrics' => compact('activeRentals', 'returnedRentals', 'overdueRentals', 'endingSoonRentals'),
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
            ],
            'inventory_reports' => [
                'available_assets' => $availableAssets,
                'rented_assets' => $rentedAssets,
                'maintenance_assets' => $maintenanceAssets,
                'warehouse_stock_summary' => $warehouseStockSummary,
                'product_utilization' => $productUtilization,
                'idle_assets' => $idleAssets,
            ],
            'sales_invoice_reports' => [
                'invoices_this_month' => $invoicesThisMonth,
                'unpaid_invoices' => $unpaidInvoices,
                'partial_invoices' => $partialInvoices,
                'paid_invoices' => $paidInvoices,
                'monthly_invoice_trend' => $monthlyInvoiceTrend,
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
