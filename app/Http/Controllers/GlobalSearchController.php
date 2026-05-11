<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\Rental;
use App\Models\Sale;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class GlobalSearchController extends Controller
{
    public function index(Request $request): View
    {
        $query = trim((string) $request->query('q', ''));
        $performedSearch = $query !== '';
        $validationMessage = null;
        $organizationId = (int) auth()->user()->organization_id;
        $user = auth()->user();

        $customers = collect();
        $products = collect();
        $assets = collect();
        $rentals = collect();
        $sales = collect();
        $invoices = collect();
        $deliveries = collect();

        if ($performedSearch && mb_strlen($query) < 2) {
            $validationMessage = 'Enter at least 2 characters to search.';
        } elseif ($performedSearch) {
            if ($user?->canAccessModule('customers', 'read')) {
                $customers = $this->searchCustomers($organizationId, $query);
            }

            if ($user?->canAccessModule('products', 'read')) {
                $products = $this->searchProducts($organizationId, $query);
            }

            if ($user?->canAccessModule('assets', 'read')) {
                $assets = $this->searchAssets($organizationId, $query);
            }

            if ($user?->canAccessModule('rentals', 'read')) {
                $rentals = $this->searchRentals($organizationId, $query);
            }

            if ($user?->canAccessModule('sales', 'read')) {
                $sales = $this->searchSales($organizationId, $query);
            }

            if ($user?->canAccessModule('invoices', 'read')) {
                $invoices = $this->searchInvoices($organizationId, $query);
            }

            if ($user?->canAccessModule('deliveries', 'read')) {
                $deliveries = $this->searchDeliveries($organizationId, $query);
            }
        }

        $resultGroups = collect([
            ['key' => 'customers', 'title' => 'Customers', 'results' => $customers],
            ['key' => 'products', 'title' => 'Medical Equipment', 'results' => $products],
            ['key' => 'assets', 'title' => 'Equipment Units', 'results' => $assets],
            ['key' => 'rentals', 'title' => 'Patient Rentals', 'results' => $rentals],
            ['key' => 'sales', 'title' => 'Equipment Sales', 'results' => $sales],
            ['key' => 'invoices', 'title' => 'Invoices', 'results' => $invoices],
            ['key' => 'deliveries', 'title' => 'Dispatch / Deliveries', 'results' => $deliveries],
        ]);

        $hasResults = $resultGroups->contains(fn (array $group) => $group['results']->isNotEmpty());

        return view('search.index', compact(
            'query',
            'performedSearch',
            'validationMessage',
            'resultGroups',
            'hasResults'
        ));
    }

    private function showRoute(string $routeName, mixed $parameter): ?string
    {
        if (!Route::has($routeName)) {
            return null;
        }

        return route($routeName, $parameter);
    }

    private function numericQuery(?string $query): ?int
    {
        return is_numeric($query) ? (int) $query : null;
    }

    private function searchCustomers(int $organizationId, string $query): Collection
    {
        return Customer::query()
            ->where('organization_id', $organizationId)
            ->where(function (Builder $customerQuery) use ($query) {
                $customerQuery->where('name', 'like', "%{$query}%")
                    ->orWhere('first_name', 'like', "%{$query}%")
                    ->orWhere('last_name', 'like', "%{$query}%")
                    ->orWhere('phone', 'like', "%{$query}%")
                    ->orWhere('email', 'like', "%{$query}%")
                    ->orWhere('company_name', 'like', "%{$query}%");

                if (Customer::hasContactNameColumn()) {
                    $customerQuery->orWhere('contact_name', 'like', "%{$query}%");
                }

                if (Customer::hasWhatsappNumberColumn()) {
                    $customerQuery->orWhere('whatsapp_number', 'like', "%{$query}%");
                }
            })
            ->orderBy('name')
            ->limit(8)
            ->get()
            ->map(function (Customer $customer) {
                return (object) [
                    'title' => $customer->displayName(),
                    'subtitle' => collect([
                        $customer->phone,
                        $customer->email,
                        $customer->city,
                    ])->filter()->implode(' • '),
                    'meta' => $customer->company_name ?: ($customer->customer_type ?: 'Customer'),
                    'href' => $this->showRoute('customers.show', $customer->id),
                ];
            });
    }

    private function searchProducts(int $organizationId, string $query): Collection
    {
        return Product::query()
            ->where('organization_id', $organizationId)
            ->where(function (Builder $productQuery) use ($query) {
                $productQuery->where('name', 'like', "%{$query}%")
                    ->orWhere('model_name', 'like', "%{$query}%")
                    ->orWhere('brand', 'like', "%{$query}%")
                    ->orWhere('product_code', 'like', "%{$query}%")
                    ->orWhere('sku', 'like', "%{$query}%")
                    ->orWhere('category', 'like', "%{$query}%");
            })
            ->orderBy('name')
            ->limit(8)
            ->get()
            ->map(function (Product $product) {
                return (object) [
                    'title' => $product->name,
                    'subtitle' => collect([
                        $product->brand,
                        $product->model_name,
                        $product->product_code,
                    ])->filter()->implode(' • '),
                    'meta' => $product->stockModeLabel(),
                    'href' => $this->showRoute('products.show', $product->id),
                ];
            });
    }

    private function searchAssets(int $organizationId, string $query): Collection
    {
        return Asset::query()
            ->with(['product:id,name,brand,model_name'])
            ->where('organization_id', $organizationId)
            ->where(function (Builder $assetQuery) use ($query) {
                $assetQuery->where('asset_name', 'like', "%{$query}%")
                    ->orWhere('serial_number', 'like', "%{$query}%")
                    ->orWhere('barcode_value', 'like', "%{$query}%")
                    ->orWhere('batch_number', 'like', "%{$query}%")
                    ->orWhereHas('product', function (Builder $productQuery) use ($query) {
                        $productQuery->where('name', 'like', "%{$query}%")
                            ->orWhere('brand', 'like', "%{$query}%")
                            ->orWhere('model_name', 'like', "%{$query}%");
                    });
            })
            ->latest('id')
            ->limit(8)
            ->get()
            ->map(function (Asset $asset) {
                return (object) [
                    'title' => $asset->asset_name ?: ($asset->product?->name ?? 'Equipment Unit'),
                    'subtitle' => collect([
                        $asset->serial_number,
                        $asset->barcode_value,
                        $asset->product?->name,
                    ])->filter()->implode(' • '),
                    'meta' => ucfirst(str_replace('_', ' ', (string) $asset->asset_status)),
                    'href' => $this->showRoute('assets.show', $asset->id),
                ];
            });
    }

    private function searchRentals(int $organizationId, string $query): Collection
    {
        $numericQuery = $this->numericQuery($query);

        return Rental::query()
            ->with(['product:id,name,brand,model_name', 'customer:id,name,phone,email,whatsapp_number'])
            ->where('organization_id', $organizationId)
            ->where(function (Builder $rentalQuery) use ($query, $numericQuery) {
                $rentalQuery->where('customer_name', 'like', "%{$query}%")
                    ->orWhere('phone', 'like', "%{$query}%")
                    ->orWhereHas('product', function (Builder $productQuery) use ($query) {
                        $productQuery->where('name', 'like', "%{$query}%")
                            ->orWhere('brand', 'like', "%{$query}%")
                            ->orWhere('model_name', 'like', "%{$query}%");
                    })
                    ->orWhereHas('customer', function (Builder $customerQuery) use ($query) {
                        $customerQuery->where('name', 'like', "%{$query}%")
                            ->orWhere('phone', 'like', "%{$query}%")
                            ->orWhere('email', 'like', "%{$query}%");

                        if (Customer::hasWhatsappNumberColumn()) {
                            $customerQuery->orWhere('whatsapp_number', 'like', "%{$query}%");
                        }
                    })
                    ->orWhereHas('activeRentalAssets.asset', function (Builder $assetQuery) use ($query) {
                        $assetQuery->where('serial_number', 'like', "%{$query}%")
                            ->orWhere('barcode_value', 'like', "%{$query}%");
                    });

                if ($numericQuery !== null) {
                    $rentalQuery->orWhere('id', $numericQuery);
                }
            })
            ->latest('id')
            ->limit(8)
            ->get()
            ->map(function (Rental $rental) {
                return (object) [
                    'title' => 'Rental #' . $rental->id,
                    'subtitle' => collect([
                        $rental->customer_name ?: $rental->customer?->name,
                        $rental->product?->name,
                        $rental->phone,
                    ])->filter()->implode(' • '),
                    'meta' => ucfirst((string) $rental->status),
                    'href' => $this->showRoute('rentals.show', $rental->id),
                ];
            });
    }

    private function searchSales(int $organizationId, string $query): Collection
    {
        $numericQuery = $this->numericQuery($query);

        return Sale::query()
            ->with(['customer:id,name,phone,email,whatsapp_number', 'product:id,name,brand,model_name'])
            ->where('organization_id', $organizationId)
            ->where(function (Builder $saleQuery) use ($query, $numericQuery) {
                $saleQuery->where('notes', 'like', "%{$query}%")
                    ->orWhereHas('customer', function (Builder $customerQuery) use ($query) {
                        $customerQuery->where('name', 'like', "%{$query}%")
                            ->orWhere('phone', 'like', "%{$query}%")
                            ->orWhere('email', 'like', "%{$query}%");

                        if (Customer::hasWhatsappNumberColumn()) {
                            $customerQuery->orWhere('whatsapp_number', 'like', "%{$query}%");
                        }
                    })
                    ->orWhereHas('product', function (Builder $productQuery) use ($query) {
                        $productQuery->where('name', 'like', "%{$query}%")
                            ->orWhere('brand', 'like', "%{$query}%")
                            ->orWhere('model_name', 'like', "%{$query}%");
                    });

                if ($numericQuery !== null) {
                    $saleQuery->orWhere('id', $numericQuery);
                }
            })
            ->latest('id')
            ->limit(8)
            ->get()
            ->map(function (Sale $sale) {
                return (object) [
                    'title' => 'Sale #' . $sale->id,
                    'subtitle' => collect([
                        $sale->customer?->name,
                        $sale->product?->name,
                        filled($sale->sale_amount) ? '₹' . number_format((float) $sale->sale_amount, 2) : null,
                    ])->filter()->implode(' • '),
                    'meta' => ucfirst((string) $sale->payment_status),
                    'href' => $this->showRoute('sales.show', $sale->id),
                ];
            });
    }

    private function searchInvoices(int $organizationId, string $query): Collection
    {
        return Invoice::query()
            ->with(['customer:id,name,phone,email'])
            ->where('organization_id', $organizationId)
            ->where(function (Builder $invoiceQuery) use ($query) {
                $invoiceQuery->where('invoice_number', 'like', "%{$query}%")
                    ->orWhere('reference_number', 'like', "%{$query}%")
                    ->orWhere('purchase_order_number', 'like', "%{$query}%")
                    ->orWhere('bill_to_name', 'like', "%{$query}%")
                    ->orWhere('bill_to_phone', 'like', "%{$query}%")
                    ->orWhereHas('customer', function (Builder $customerQuery) use ($query) {
                        $customerQuery->where('name', 'like', "%{$query}%")
                            ->orWhere('phone', 'like', "%{$query}%")
                            ->orWhere('email', 'like', "%{$query}%");
                    })
                    ->orWhereHas('items', function (Builder $itemQuery) use ($query) {
                        $itemQuery->where('description', 'like', "%{$query}%");
                    });
            })
            ->latest('id')
            ->limit(8)
            ->get()
            ->map(function (Invoice $invoice) {
                return (object) [
                    'title' => $invoice->invoice_number ?: ('Invoice #' . $invoice->id),
                    'subtitle' => collect([
                        $invoice->bill_to_name ?: $invoice->customer?->name,
                        $invoice->bill_to_phone,
                        filled($invoice->total_amount) ? '₹' . number_format((float) $invoice->total_amount, 2) : null,
                    ])->filter()->implode(' • '),
                    'meta' => ucfirst((string) $invoice->payment_status),
                    'href' => $this->showRoute('invoices.show', $invoice->id),
                ];
            });
    }

    private function searchDeliveries(int $organizationId, string $query): Collection
    {
        $numericQuery = $this->numericQuery($query);

        return Delivery::query()
            ->with([
                'rental.customer:id,name,phone,email,whatsapp_number',
                'rental.product:id,name,brand,model_name',
                'sale.customer:id,name,phone,email,whatsapp_number',
                'sale.product:id,name,brand,model_name',
            ])
            ->where('organization_id', $organizationId)
            ->where(function (Builder $deliveryQuery) use ($query, $numericQuery) {
                $deliveryQuery->where('notes', 'like', "%{$query}%");

                if (Schema::hasColumn('deliveries', 'third_party_name')) {
                    $deliveryQuery->orWhere('third_party_name', 'like', "%{$query}%");
                }

                if (Schema::hasColumn('deliveries', 'third_party_contact')) {
                    $deliveryQuery->orWhere('third_party_contact', 'like', "%{$query}%");
                }

                if (Schema::hasColumn('deliveries', 'third_party_phone')) {
                    $deliveryQuery->orWhere('third_party_phone', 'like', "%{$query}%");
                }

                $deliveryQuery->orWhereHas('rental', function (Builder $rentalQuery) use ($query, $numericQuery) {
                    $rentalQuery->where('customer_name', 'like', "%{$query}%")
                        ->orWhere('phone', 'like', "%{$query}%")
                        ->orWhereHas('customer', function (Builder $customerQuery) use ($query) {
                            $customerQuery->where('name', 'like', "%{$query}%")
                                ->orWhere('phone', 'like', "%{$query}%")
                                ->orWhere('email', 'like', "%{$query}%");

                            if (Customer::hasWhatsappNumberColumn()) {
                                $customerQuery->orWhere('whatsapp_number', 'like', "%{$query}%");
                            }
                        })
                        ->orWhereHas('product', function (Builder $productQuery) use ($query) {
                            $productQuery->where('name', 'like', "%{$query}%")
                                ->orWhere('brand', 'like', "%{$query}%")
                                ->orWhere('model_name', 'like', "%{$query}%");
                        });

                    if ($numericQuery !== null) {
                        $rentalQuery->orWhere('id', $numericQuery);
                    }
                })->orWhereHas('sale', function (Builder $saleQuery) use ($query, $numericQuery) {
                    $saleQuery->whereHas('customer', function (Builder $customerQuery) use ($query) {
                        $customerQuery->where('name', 'like', "%{$query}%")
                            ->orWhere('phone', 'like', "%{$query}%")
                            ->orWhere('email', 'like', "%{$query}%");

                        if (Customer::hasWhatsappNumberColumn()) {
                            $customerQuery->orWhere('whatsapp_number', 'like', "%{$query}%");
                        }
                    })->orWhereHas('product', function (Builder $productQuery) use ($query) {
                        $productQuery->where('name', 'like', "%{$query}%")
                            ->orWhere('brand', 'like', "%{$query}%")
                            ->orWhere('model_name', 'like', "%{$query}%");
                    });

                    if ($numericQuery !== null) {
                        $saleQuery->orWhere('id', $numericQuery);
                    }
                });

                if ($numericQuery !== null) {
                    $deliveryQuery->orWhere('id', $numericQuery);
                }
            })
            ->latest('id')
            ->limit(8)
            ->get()
            ->map(function (Delivery $delivery) {
                $titlePrefix = $delivery->type === 'pickup' ? 'Pickup' : 'Delivery';
                $linkedRecord = $delivery->rental ? ('Rental #' . $delivery->rental->id) : ($delivery->sale ? ('Sale #' . $delivery->sale->id) : null);
                $customerName = $delivery->rental?->customer_name
                    ?: $delivery->rental?->customer?->name
                    ?: $delivery->sale?->customer?->name;

                return (object) [
                    'title' => $titlePrefix . ' #' . $delivery->id,
                    'subtitle' => collect([
                        $customerName,
                        $linkedRecord,
                    ])->filter()->implode(' • '),
                    'meta' => ucfirst((string) $delivery->status),
                    'href' => $this->showRoute('deliveries.show', $delivery->id),
                ];
            });
    }
}
