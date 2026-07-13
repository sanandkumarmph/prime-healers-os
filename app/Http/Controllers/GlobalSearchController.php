<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\BusinessPartner;
use App\Models\Customer;
use App\Models\Delivery;
use App\Models\Invoice;
use App\Models\PartnerClient;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Rental;
use App\Models\Sale;
use App\Models\Vendor;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
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

    public function suggestions(Request $request): JsonResponse
    {
        $query = trim((string) $request->query('q', ''));
        $type = strtolower(trim((string) $request->query('type', 'all')));
        $limit = min(max((int) $request->query('limit', 12), 1), 20);

        if (mb_strlen($query) < 2) {
            return response()->json([
                'query' => $query,
                'results' => [],
            ]);
        }

        $user = $request->user();
        $organizationId = (int) $user->organization_id;
        $types = $this->suggestionTypes($type);
        $results = collect();

        foreach ($types as $suggestionType) {
            if (!$this->canSuggestType($user, $suggestionType)) {
                continue;
            }

            $results = $results->merge(match ($suggestionType) {
                'customer' => $this->suggestCustomers($organizationId, $query),
                'product' => $this->suggestProducts($organizationId, $query),
                'asset' => $this->suggestAssets($organizationId, $query),
                'rental' => $this->suggestRentals($organizationId, $query),
                'sale' => $this->suggestSales($organizationId, $query),
                'invoice' => $this->suggestInvoices($organizationId, $query),
                'payment' => $this->suggestPayments($organizationId, $query),
                'vendor' => $this->suggestVendors($organizationId, $query),
                'business_partner' => $this->suggestBusinessPartners($organizationId, $query),
                'partner_client' => $this->suggestPartnerClients($organizationId, $query),
                'delivery', 'pickup' => $this->suggestDeliveries($organizationId, $query, $suggestionType),
                default => collect(),
            });

            if ($results->count() >= $limit) {
                break;
            }
        }

        return response()->json([
            'query' => $query,
            'results' => $results->take($limit)->values(),
        ]);
    }

    private function suggestionTypes(string $type): array
    {
        $types = [
            'customer',
            'product',
            'asset',
            'rental',
            'sale',
            'invoice',
            'payment',
            'vendor',
            'business_partner',
            'partner_client',
            'delivery',
            'pickup',
        ];

        $aliases = [
            'all' => $types,
            'global' => $types,
            'customers' => ['customer'],
            'products' => ['product'],
            'assets' => ['asset'],
            'rentals' => ['rental'],
            'sales' => ['sale'],
            'invoices' => ['invoice'],
            'payments' => ['payment'],
            'vendors' => ['vendor'],
            'business-partners' => ['business_partner'],
            'business_partner' => ['business_partner'],
            'partners' => ['business_partner'],
            'actual-client' => ['partner_client'],
            'actual_client' => ['partner_client'],
            'partner-client' => ['partner_client'],
            'deliveries' => ['delivery'],
            'pickups' => ['pickup'],
        ];

        return $aliases[$type] ?? (in_array($type, $types, true) ? [$type] : $types);
    }

    private function canSuggestType($user, string $type): bool
    {
        return match ($type) {
            'customer' => (bool) $user?->canAccessModule('customers', 'read'),
            'product' => (bool) $user?->canAccessModule('products', 'read'),
            'asset' => (bool) $user?->canAccessModule('assets', 'read'),
            'rental' => (bool) $user?->canAccessModule('rentals', 'read'),
            'sale' => (bool) $user?->canAccessModule('sales', 'read'),
            'invoice' => (bool) $user?->canAccessModule('invoices', 'read'),
            'payment' => (bool) $user?->canAccessModule('payments', 'read'),
            'vendor' => (bool) $user?->canAccessModule('vendors', 'read'),
            'business_partner', 'partner_client' => (bool) $user?->canAccessModule('customers', 'read'),
            'delivery', 'pickup' => (bool) $user?->canAccessModule('deliveries', 'read'),
            default => false,
        };
    }

    private function suggestionResult(string $type, int $id, string $title, ?string $subtitle = null, ?string $meta = null, ?string $href = null, ?string $value = null): array
    {
        return [
            'id' => $id,
            'type' => $type,
            'title' => $title,
            'label' => $title,
            'value' => $value ?: $title,
            'subtitle' => $subtitle,
            'meta' => $meta,
            'href' => $href,
        ];
    }

    private function suggestCustomers(int $organizationId, string $query): Collection
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
            ->limit(6)
            ->get()
            ->map(fn (Customer $customer) => $this->suggestionResult(
                'customer',
                $customer->id,
                $customer->displayName(),
                collect([$customer->phone, $customer->email, $customer->city])->filter()->implode(' | '),
                $customer->customer_type ?: 'Customer',
                $this->showRoute('customers.show', $customer->id)
            ));
    }

    private function suggestProducts(int $organizationId, string $query): Collection
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
            ->limit(6)
            ->get()
            ->map(fn (Product $product) => $this->suggestionResult(
                'product',
                $product->id,
                $product->name,
                collect([$product->brand, $product->model_name, $product->sku ?: $product->product_code])->filter()->implode(' | '),
                method_exists($product, 'stockModeLabel') ? $product->stockModeLabel() : 'Product',
                $this->showRoute('products.show', $product->id)
            ));
    }

    private function suggestAssets(int $organizationId, string $query): Collection
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
            ->limit(6)
            ->get()
            ->map(fn (Asset $asset) => $this->suggestionResult(
                'asset',
                $asset->id,
                $asset->asset_name ?: ($asset->product?->name ?? 'Asset #' . $asset->id),
                collect([$asset->serial_number, $asset->barcode_value, $asset->product?->name])->filter()->implode(' | '),
                ucfirst(str_replace('_', ' ', (string) $asset->asset_status)),
                $this->showRoute('assets.show', $asset->id),
                $asset->serial_number ?: $asset->barcode_value ?: ($asset->asset_name ?: '')
            ));
    }

    private function suggestRentals(int $organizationId, string $query): Collection
    {
        $numericQuery = $this->numericQuery($query);

        return Rental::query()
            ->with(['product:id,name,brand,model_name', 'customer:id,name,phone,email,whatsapp_number'])
            ->where('organization_id', $organizationId)
            ->where(function (Builder $rentalQuery) use ($query, $numericQuery) {
                $rentalQuery->where('customer_name', 'like', "%{$query}%")
                    ->orWhere('phone', 'like', "%{$query}%")
                    ->orWhereHas('product', fn (Builder $productQuery) => $productQuery->where('name', 'like', "%{$query}%")->orWhere('brand', 'like', "%{$query}%")->orWhere('model_name', 'like', "%{$query}%"))
                    ->orWhereHas('customer', function (Builder $customerQuery) use ($query) {
                        $customerQuery->where('name', 'like', "%{$query}%")
                            ->orWhere('phone', 'like', "%{$query}%")
                            ->orWhere('email', 'like', "%{$query}%");

                        if (Customer::hasWhatsappNumberColumn()) {
                            $customerQuery->orWhere('whatsapp_number', 'like', "%{$query}%");
                        }
                    });

                if ($numericQuery !== null) {
                    $rentalQuery->orWhere('id', $numericQuery);
                }
            })
            ->latest('id')
            ->limit(6)
            ->get()
            ->map(fn (Rental $rental) => $this->suggestionResult(
                'rental',
                $rental->id,
                'Rental #' . $rental->id,
                collect([$rental->customer_name ?: $rental->customer?->name, $rental->product?->name, $rental->phone])->filter()->implode(' | '),
                ucfirst((string) $rental->status),
                $this->showRoute('rentals.show', $rental->id),
                'Rental #' . $rental->id
            ));
    }

    private function suggestSales(int $organizationId, string $query): Collection
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
                    ->orWhereHas('product', fn (Builder $productQuery) => $productQuery->where('name', 'like', "%{$query}%")->orWhere('brand', 'like', "%{$query}%")->orWhere('model_name', 'like', "%{$query}%"));

                if ($numericQuery !== null) {
                    $saleQuery->orWhere('id', $numericQuery);
                }
            })
            ->latest('id')
            ->limit(6)
            ->get()
            ->map(fn (Sale $sale) => $this->suggestionResult(
                'sale',
                $sale->id,
                'Sale #' . $sale->id,
                collect([$sale->customer?->name, $sale->product?->name])->filter()->implode(' | '),
                ucfirst((string) $sale->payment_status),
                $this->showRoute('sales.show', $sale->id),
                'Sale #' . $sale->id
            ));
    }

    private function suggestInvoices(int $organizationId, string $query): Collection
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
                    ->orWhereHas('customer', fn (Builder $customerQuery) => $customerQuery->where('name', 'like', "%{$query}%")->orWhere('phone', 'like', "%{$query}%")->orWhere('email', 'like', "%{$query}%"));
            })
            ->latest('id')
            ->limit(6)
            ->get()
            ->map(fn (Invoice $invoice) => $this->suggestionResult(
                'invoice',
                $invoice->id,
                $invoice->invoice_number ?: 'Invoice #' . $invoice->id,
                collect([$invoice->bill_to_name ?: $invoice->customer?->name, $invoice->bill_to_phone])->filter()->implode(' | '),
                ucfirst((string) $invoice->payment_status),
                $this->showRoute('invoices.show', $invoice->id),
                $invoice->invoice_number ?: 'Invoice #' . $invoice->id
            ));
    }

    private function suggestPayments(int $organizationId, string $query): Collection
    {
        $numericQuery = $this->numericQuery($query);

        return Payment::query()
            ->with(['customer:id,name,phone,email', 'invoice:id,invoice_number', 'rental:id'])
            ->where('organization_id', $organizationId)
            ->where(function (Builder $paymentQuery) use ($query, $numericQuery) {
                $paymentQuery->where('payment_method', 'like', "%{$query}%")
                    ->orWhere('notes', 'like', "%{$query}%")
                    ->orWhereHas('customer', fn (Builder $customerQuery) => $customerQuery->where('name', 'like', "%{$query}%")->orWhere('phone', 'like', "%{$query}%"))
                    ->orWhereHas('invoice', fn (Builder $invoiceQuery) => $invoiceQuery->where('invoice_number', 'like', "%{$query}%"));

                if ($numericQuery !== null) {
                    $paymentQuery->orWhere('id', $numericQuery);
                }
            })
            ->latest('id')
            ->limit(6)
            ->get()
            ->map(fn (Payment $payment) => $this->suggestionResult(
                'payment',
                $payment->id,
                'Payment #' . $payment->id,
                collect([$payment->customer?->name, $payment->invoice?->invoice_number, $payment->paymentMethodLabel()])->filter()->implode(' | '),
                filled($payment->amount) ? 'Rs. ' . number_format((float) $payment->amount, 2) : 'Payment',
                $payment->invoice_id ? $this->showRoute('invoices.show', $payment->invoice_id) : ($payment->rental_id ? $this->showRoute('rentals.show', $payment->rental_id) : null),
                'Payment #' . $payment->id
            ));
    }

    private function suggestVendors(int $organizationId, string $query): Collection
    {
        return Vendor::query()
            ->where('organization_id', $organizationId)
            ->search($query)
            ->orderBy('name')
            ->limit(6)
            ->get()
            ->map(fn (Vendor $vendor) => $this->suggestionResult(
                'vendor',
                $vendor->id,
                $vendor->name,
                collect([$vendor->contact_person, $vendor->phone, $vendor->city])->filter()->implode(' | '),
                $vendor->vendor_type ?: 'Vendor',
                $this->showRoute('vendors.show', $vendor->id)
            ));
    }

    private function suggestBusinessPartners(int $organizationId, string $query): Collection
    {
        return BusinessPartner::query()
            ->where('organization_id', $organizationId)
            ->where(function (Builder $partnerQuery) use ($query) {
                $partnerQuery->where('business_name', 'like', "%{$query}%")
                    ->orWhere('contact_person', 'like', "%{$query}%")
                    ->orWhere('phone', 'like', "%{$query}%")
                    ->orWhere('email', 'like', "%{$query}%")
                    ->orWhere('city', 'like', "%{$query}%");
            })
            ->orderBy('business_name')
            ->limit(6)
            ->get()
            ->map(fn (BusinessPartner $partner) => $this->suggestionResult(
                'business_partner',
                $partner->id,
                $partner->displayName(),
                collect([$partner->contact_person, $partner->phone, $partner->city])->filter()->implode(' | '),
                'Business Partner',
                $this->showRoute('business-partners.show', $partner->id)
            ));
    }

    private function suggestPartnerClients(int $organizationId, string $query): Collection
    {
        return PartnerClient::query()
            ->with(['businessPartner:id,business_name'])
            ->where('organization_id', $organizationId)
            ->where(function (Builder $clientQuery) use ($query) {
                $clientQuery->where('client_name', 'like', "%{$query}%")
                    ->orWhere('phone', 'like', "%{$query}%")
                    ->orWhere('alternate_phone', 'like', "%{$query}%")
                    ->orWhere('address', 'like', "%{$query}%")
                    ->orWhere('city', 'like', "%{$query}%");
            })
            ->orderBy('client_name')
            ->limit(6)
            ->get()
            ->map(fn (PartnerClient $client) => $this->suggestionResult(
                'partner_client',
                $client->id,
                $client->displayName(),
                collect([$client->primaryPhone(), $client->city, $client->businessPartner?->business_name])->filter()->implode(' | '),
                'Actual Client',
                $client->business_partner_id ? $this->showRoute('business-partners.show', $client->business_partner_id) : null
            ));
    }

    private function suggestDeliveries(int $organizationId, string $query, string $type): Collection
    {
        $numericQuery = $this->numericQuery($query);

        return Delivery::query()
            ->with(['rental.customer:id,name,phone,email,whatsapp_number', 'rental.product:id,name,brand,model_name', 'sale.customer:id,name,phone,email,whatsapp_number', 'sale.product:id,name,brand,model_name'])
            ->where('organization_id', $organizationId)
            ->where('type', $type === 'pickup' ? 'pickup' : 'delivery')
            ->where(function (Builder $deliveryQuery) use ($query, $numericQuery) {
                $deliveryQuery->where('notes', 'like', "%{$query}%")
                    ->orWhereHas('rental', function (Builder $rentalQuery) use ($query, $numericQuery) {
                        $rentalQuery->where('customer_name', 'like', "%{$query}%")
                            ->orWhere('phone', 'like', "%{$query}%")
                            ->orWhereHas('customer', fn (Builder $customerQuery) => $customerQuery->where('name', 'like', "%{$query}%")->orWhere('phone', 'like', "%{$query}%"))
                            ->orWhereHas('product', fn (Builder $productQuery) => $productQuery->where('name', 'like', "%{$query}%"));

                        if ($numericQuery !== null) {
                            $rentalQuery->orWhere('id', $numericQuery);
                        }
                    })
                    ->orWhereHas('sale', function (Builder $saleQuery) use ($query, $numericQuery) {
                        $saleQuery->whereHas('customer', fn (Builder $customerQuery) => $customerQuery->where('name', 'like', "%{$query}%")->orWhere('phone', 'like', "%{$query}%"))
                            ->orWhereHas('product', fn (Builder $productQuery) => $productQuery->where('name', 'like', "%{$query}%"));

                        if ($numericQuery !== null) {
                            $saleQuery->orWhere('id', $numericQuery);
                        }
                    });

                if ($numericQuery !== null) {
                    $deliveryQuery->orWhere('id', $numericQuery);
                }
            })
            ->latest('id')
            ->limit(6)
            ->get()
            ->map(function (Delivery $delivery) use ($type) {
                $customerName = $delivery->rental?->customer_name
                    ?: $delivery->rental?->customer?->name
                    ?: $delivery->sale?->customer?->name;

                return $this->suggestionResult(
                    $type,
                    $delivery->id,
                    ucfirst($type) . ' #' . $delivery->id,
                    collect([$customerName, $delivery->rental?->product?->name ?: $delivery->sale?->product?->name])->filter()->implode(' | '),
                    ucfirst((string) $delivery->status),
                    $this->showRoute('deliveries.show', $delivery->id),
                    ucfirst($type) . ' #' . $delivery->id
                );
            });
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
