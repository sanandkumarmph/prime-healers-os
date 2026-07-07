<?php

namespace App\Support;

use App\Models\Asset;
use App\Models\BusinessPartner;
use App\Models\City;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PartnerClient;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Rental;
use App\Models\Sale;
use App\Models\SaleInventory;
use App\Models\Staff;
use App\Models\Vendor;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class ImportSetupWizard
{
    private const PHASES = [
        'foundation' => [
            'title' => 'Foundation Data',
            'subtitle' => 'Cities, catalog, customers, partners, and vendors first.',
            'icon' => 'FD',
            'keys' => ['products', 'customers', 'vendors', 'staff'],
        ],
        'inventory' => [
            'title' => 'Inventory',
            'subtitle' => 'Opening balances and physical stock after master data.',
            'icon' => 'IN',
            'keys' => ['opening-balances', 'assets'],
        ],
        'transactions' => [
            'title' => 'Transactions',
            'subtitle' => 'Rentals, sales, invoices, and collections after setup.',
            'icon' => 'TX',
            'keys' => ['rentals', 'sales', 'payments'],
        ],
    ];

    public function buildForCatalog(array|Collection $catalog, int $organizationId): array
    {
        $catalog = collect($catalog)->keyBy(fn (array $card, string|int $key) => $card['key'] ?? $key);
        $counts = $this->counts($organizationId);

        $cards = $catalog
            ->map(fn (array $card, string $key) => $this->stateFor($key, $organizationId, $counts, $catalog))
            ->all();

        $phases = collect(self::PHASES)
            ->map(function (array $phase, string $phaseKey) use ($catalog, $cards) {
                $items = collect($phase['keys'])
                    ->filter(fn (string $key) => $catalog->has($key))
                    ->map(fn (string $key) => $cards[$key])
                    ->values();

                $complete = $items->where('status', 'completed')->count();
                $total = $items->count();

                return $phase + [
                    'key' => $phaseKey,
                    'items' => $items,
                    'complete' => $complete,
                    'total' => $total,
                    'percent' => $total > 0 ? (int) round(($complete / $total) * 100) : 0,
                ];
            })
            ->filter(fn (array $phase) => $phase['total'] > 0)
            ->values();

        $total = $phases->sum('total');
        $complete = $phases->sum('complete');
        $next = collect($cards)->first(fn (array $card) => $card['status'] === 'ready')
            ?? collect($cards)->first(fn (array $card) => $card['status'] === 'blocked');

        return [
            'counts' => $counts,
            'cards' => $cards,
            'phases' => $phases,
            'next' => $next,
            'overall' => [
                'complete' => $complete,
                'total' => $total,
                'percent' => $total > 0 ? (int) round(($complete / $total) * 100) : 0,
            ],
        ];
    }

    public function stateForModule(string $module, int $organizationId, array|Collection $catalog = []): array
    {
        return $this->stateFor($module, $organizationId, $this->counts($organizationId), collect($catalog)->keyBy('key'));
    }

    private function stateFor(string $module, int $organizationId, array $counts, Collection $catalog): array
    {
        $missing = $this->missingDependencies($module, $counts, $catalog);
        $count = $this->moduleRecordCount($module, $counts);

        $status = match (true) {
            count($missing) > 0 => 'blocked',
            $count > 0 => 'completed',
            default => 'ready',
        };

        return [
            'key' => $module,
            'status' => $status,
            'status_label' => match ($status) {
                'blocked' => 'Blocked',
                'completed' => 'Completed',
                default => 'Ready to Import',
            },
            'record_count' => $count,
            'missing' => $missing,
            'download_note' => count($missing) > 0
                ? 'You can download the template now, but import requires completing prerequisites first.'
                : null,
        ];
    }

    private function missingDependencies(string $module, array $counts, Collection $catalog): array
    {
        return match ($module) {
            'customers' => $this->missing([
                ['label' => 'Cities', 'ready' => $counts['cities'] > 0],
            ]),
            'assets' => $this->missing([
                ['label' => 'Product Master', 'module' => 'products', 'ready' => $counts['products'] > 0],
                ['label' => 'Warehouses', 'ready' => $counts['warehouses'] > 0],
            ], $catalog),
            'rentals' => $this->missing([
                ['label' => 'Product Master', 'module' => 'products', 'ready' => $counts['products'] > 0],
                ['label' => 'Customers / Actual Clients', 'module' => 'customers', 'ready' => $counts['customers_total'] > 0],
                ['label' => 'Warehouses', 'ready' => $counts['warehouses'] > 0],
                ['label' => 'Rental Assets', 'module' => 'assets', 'ready' => $counts['rental_assets'] > 0],
            ], $catalog),
            'sales' => $this->missing([
                ['label' => 'Product Master', 'module' => 'products', 'ready' => $counts['products'] > 0],
                ['label' => 'Customers / Actual Clients', 'module' => 'customers', 'ready' => $counts['customers_total'] > 0],
                ['label' => 'Sale Stock', 'ready' => $counts['sale_stock'] > 0],
            ], $catalog),
            'payments' => $this->missing([
                ['label' => 'Invoices', 'ready' => $counts['invoices'] > 0],
            ], $catalog),
            'opening-balances' => $this->missing([
                ['label' => 'Customers', 'module' => 'customers', 'ready' => $counts['customers'] > 0],
            ], $catalog),
            default => [],
        };
    }

    private function missing(array $dependencies, ?Collection $catalog = null): array
    {
        return collect($dependencies)
            ->filter(fn (array $dependency) => !($dependency['ready'] ?? false))
            ->map(function (array $dependency) use ($catalog) {
                $module = $dependency['module'] ?? null;

                return [
                    'label' => $dependency['label'],
                    'module' => $module,
                    'href' => $module && $catalog?->has($module)
                        ? ($catalog->get($module)['upload_href'] ?? route('imports.module', $module))
                        : null,
                ];
            })
            ->values()
            ->all();
    }

    private function moduleRecordCount(string $module, array $counts): int
    {
        return match ($module) {
            'products' => $counts['products'],
            'customers' => $counts['customers_total'],
            'vendors' => $counts['vendors'],
            'staff' => $counts['staff'],
            'assets' => $counts['rental_assets'],
            'rentals' => $counts['rentals'],
            'sales' => $counts['sales'],
            'opening-balances' => $counts['opening_balances'],
            'payments' => $counts['payments'],
            default => 0,
        };
    }

    private function counts(int $organizationId): array
    {
        $saleStock = (int) SaleInventory::query()
            ->where('organization_id', $organizationId)
            ->where('quantity_in_stock', '>', 0)
            ->sum('quantity_in_stock');

        return [
            'cities' => $this->count(City::class, $organizationId),
            'warehouses' => $this->count(Warehouse::class, $organizationId),
            'products' => $this->count(Product::class, $organizationId),
            'customers' => $this->count(Customer::class, $organizationId),
            'business_partners' => $this->count(BusinessPartner::class, $organizationId),
            'partner_clients' => $this->count(PartnerClient::class, $organizationId),
            'vendors' => $this->count(Vendor::class, $organizationId),
            'staff' => $this->count(Staff::class, $organizationId),
            'rental_assets' => $this->count(Asset::class, $organizationId, fn ($query) => $query->where('asset_stage', Asset::STAGE_RENTAL_STOCK)),
            'sale_stock' => $saleStock,
            'rentals' => $this->count(Rental::class, $organizationId),
            'sales' => $this->count(Sale::class, $organizationId),
            'invoices' => $this->count(Invoice::class, $organizationId),
            'payments' => $this->count(Payment::class, $organizationId),
            'opening_balances' => $this->openingBalanceCount($organizationId),
        ] + [
            'customers_total' => $this->count(Customer::class, $organizationId) + $this->count(PartnerClient::class, $organizationId),
        ];
    }

    private function count(string $modelClass, int $organizationId, ?callable $scope = null): int
    {
        /** @var Model $model */
        $model = new $modelClass();
        $query = $modelClass::query();

        if (in_array('organization_id', $model->getFillable(), true)) {
            $query->where('organization_id', $organizationId);
        }

        if ($scope) {
            $scope($query);
        }

        return (int) $query->count();
    }

    private function openingBalanceCount(int $organizationId): int
    {
        $query = Customer::query()->where('organization_id', $organizationId);

        if (!in_array('opening_balance', (new Customer())->getFillable(), true)) {
            return 0;
        }

        return (int) $query->where('opening_balance', '!=', 0)->count();
    }
}
