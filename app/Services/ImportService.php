<?php

namespace App\Services;

use App\Http\Controllers\RentalController;
use App\Models\Asset;
use App\Models\AssetMovement;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Rental;
use App\Models\RentalItem;
use App\Models\SaleInventory;
use App\Models\Warehouse;
use App\Services\Imports\ImportMatchSignatureService;
use App\Support\PhoneNumber;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use RuntimeException;
use ZipArchive;

class ImportService
{
    private const STORAGE_DIR = 'imports';
    private const CUSTOMER = 'customers';
    private const PRODUCT = 'products';
    private const ASSET = 'assets';
    private const RENTAL = 'rentals';

    private array $productCache = [];
    private array $warehouseCache = [];
    private array $customerCache = [];

    public function createSnapshot(array $payload): string
    {
        return $this->saveSnapshot($payload);
    }

    public function replaceSnapshot(string $key, array $payload): void
    {
        $this->overwriteSnapshot($key, $payload);
    }

    public function matchProductForImport(int $organizationId, array $mapped): array
    {
        return $this->resolveProductMatch($organizationId, $mapped);
    }

    public function findCustomerForImportPayload(array $payload, int $organizationId): ?Customer
    {
        return $this->findCustomerForImport($payload, $organizationId);
    }

    public function resolveWarehouseForImport(int $organizationId, array $mapped, bool $required = true): ?Warehouse
    {
        return $this->resolveWarehouse($organizationId, $mapped, $required);
    }

    public function modules(): array
    {
        return [
            self::CUSTOMER => [
                'label' => 'Customer Import',
                'module_permission' => 'customers',
                'description' => 'Import and dedupe customers by phone or email within the current organization.',
                'priority' => 2,
                'fields' => [
                    'name' => ['label' => 'Customer Name', 'required' => true, 'aliases' => ['name', 'customer', 'customer_name', 'full_name']],
                    'customer_type' => ['label' => 'Customer Type', 'aliases' => ['customer_type', 'type']],
                    'phone' => ['label' => 'Phone', 'aliases' => ['phone', 'mobile', 'contact_number']],
                    'whatsapp_number' => ['label' => 'WhatsApp Number', 'aliases' => ['whatsapp', 'whatsapp_number']],
                    'email' => ['label' => 'Email', 'aliases' => ['email', 'mail']],
                    'company_name' => ['label' => 'Company Name', 'aliases' => ['company', 'company_name']],
                    'contact_name' => ['label' => 'Contact Name', 'aliases' => ['contact_name', 'contact_person']],
                    'gst_number' => ['label' => 'GST Number', 'aliases' => ['gst', 'gst_number', 'gstin']],
                    'address' => ['label' => 'Address', 'aliases' => ['address', 'street_address']],
                    'city' => ['label' => 'City', 'aliases' => ['city']],
                    'state' => ['label' => 'State', 'aliases' => ['state']],
                    'pincode' => ['label' => 'Pincode', 'aliases' => ['pincode', 'postal_code', 'zip']],
                    'patient_name' => ['label' => 'Patient Name', 'aliases' => ['patient_name', 'patient']],
                    'notes' => ['label' => 'Notes', 'aliases' => ['notes', 'remarks']],
                ],
                'template' => [
                    ['Customer Name', 'Customer Type', 'Phone', 'WhatsApp Number', 'Email', 'Company Name', 'Contact Name', 'GST Number', 'Address', 'City', 'State', 'Pincode', 'Patient Name', 'Notes'],
                    ['Aarav Sharma', 'Individual', '9876543210', '9876543210', 'aarav@example.com', '', '', '', '221B MG Road', 'Delhi', 'Delhi', '110001', '', 'Existing oxygen customer'],
                    ['Care Plus Clinic', 'Business', '9810012345', '9810012345', 'procurement@careplus.test', 'Care Plus Clinic', 'Ananya Rao', '29ABCDE1234F1Z5', '12, Sector 18', 'Noida', 'Uttar Pradesh', '201301', 'Rahul Verma', 'Corporate account'],
                ],
                'chunk_size' => 100,
            ],
            self::PRODUCT => [
                'label' => 'Product Master Import',
                'module_permission' => 'products',
                'description' => 'Import product master rows with untracked quantity or tracked stock modes.',
                'priority' => 3,
                'fields' => [
                    'name' => ['label' => 'Product Name', 'required' => true, 'aliases' => ['name', 'product_name', 'product']],
                    'category' => ['label' => 'Category', 'aliases' => ['category']],
                    'brand' => ['label' => 'Brand', 'aliases' => ['brand']],
                    'model_name' => ['label' => 'Model Name', 'aliases' => ['model', 'model_name']],
                    'product_code' => ['label' => 'Product Code', 'aliases' => ['product_code', 'code']],
                    'sku' => ['label' => 'SKU', 'aliases' => ['sku']],
                    'product_type' => ['label' => 'Product Type', 'aliases' => ['product_type', 'type']],
                    'sellable' => ['label' => 'Sellable', 'aliases' => ['sellable', 'is_sellable']],
                    'rentable' => ['label' => 'Rentable', 'aliases' => ['rentable', 'is_rentable']],
                    'stock_mode' => ['label' => 'Stock Mode', 'required' => true, 'aliases' => ['stock_mode', 'tracking_mode']],
                    'quantity' => ['label' => 'Quantity', 'aliases' => ['quantity', 'qty', 'total_quantity']],
                    'price_per_day' => ['label' => 'Price Per Day', 'aliases' => ['price_per_day', 'daily_rate', 'rental_price']],
                    'rental_price_15_days' => ['label' => 'Rental Price 15 Days', 'aliases' => ['rental_price_15_days', 'rental_15_days']],
                    'rental_price_30_days' => ['label' => 'Rental Price 30 Days', 'aliases' => ['rental_price_30_days', 'monthly_rental_price']],
                    'rental_price_3_months' => ['label' => 'Rental Price 3 Months', 'aliases' => ['rental_price_3_months', 'quarterly_rental_price']],
                    'sale_price' => ['label' => 'Sale Price', 'aliases' => ['sale_price', 'selling_price']],
                    'rental_price' => ['label' => 'Rental Price', 'aliases' => ['rental_price']],
                    'deposit' => ['label' => 'Deposit', 'aliases' => ['deposit', 'deposit_amount']],
                ],
                'template' => [
                    ['Product Name', 'Product Type', 'Stock Mode', 'Quantity', 'Price Per Day', 'Sale Price', 'Product Code', 'SKU'],
                    ['Oxygen Concentrator 5 LPM', 'rentable', 'tracked_rental', '0', '450', '', 'OXY-5L', 'OXY5L'],
                    ['BiPAP Disposable Filter', 'sellable', 'untracked', '150', '', '180', 'BIPAP-FLTR', 'BF-180'],
                ],
                'chunk_size' => 100,
            ],
            self::ASSET => [
                'label' => 'Asset Import',
                'module_permission' => 'assets',
                'description' => 'Highest-priority import for sale units and rental assets with pending-serial support.',
                'priority' => 1,
                'fields' => [
                    'product_code' => ['label' => 'Product Code', 'aliases' => ['product_code', 'code']],
                    'sku' => ['label' => 'SKU', 'aliases' => ['sku']],
                    'product_name' => ['label' => 'Product Name', 'required' => true, 'aliases' => ['product_name', 'product', 'name']],
                    'brand' => ['label' => 'Brand', 'required' => true, 'aliases' => ['brand']],
                    'model_name' => ['label' => 'Model Name', 'required' => true, 'aliases' => ['model', 'model_name']],
                    'warehouse_code' => ['label' => 'Warehouse Code', 'aliases' => ['warehouse_code', 'warehouse']],
                    'warehouse_name' => ['label' => 'Warehouse Name', 'aliases' => ['warehouse_name', 'warehouse_label']],
                    'asset_name' => ['label' => 'Asset Name', 'aliases' => ['asset_name', 'unit_name']],
                    'serial_number' => ['label' => 'Serial Number', 'aliases' => ['serial_number', 'serial', 'unit_serial']],
                    'barcode_value' => ['label' => 'Barcode', 'aliases' => ['barcode', 'barcode_value']],
                    'batch_number' => ['label' => 'Batch Number', 'aliases' => ['batch', 'batch_number']],
                    'asset_stage' => ['label' => 'Asset Type / Stage', 'aliases' => ['asset_stage', 'asset_type', 'stock_type', 'type']],
                    'purchase_date' => ['label' => 'Purchase Date', 'aliases' => ['purchase_date']],
                    'purchase_cost' => ['label' => 'Purchase Cost', 'aliases' => ['purchase_cost', 'cost']],
                    'condition_status' => ['label' => 'Condition Status', 'aliases' => ['condition_status', 'condition']],
                    'asset_status' => ['label' => 'Asset Status', 'aliases' => ['asset_status', 'status']],
                    'last_service_date' => ['label' => 'Last Service Date', 'aliases' => ['last_service_date']],
                    'next_service_date' => ['label' => 'Next Service Date', 'aliases' => ['next_service_date']],
                    'notes' => ['label' => 'Notes', 'aliases' => ['notes', 'remarks']],
                ],
                'template' => [
                    ['Product Code', 'Product Name', 'Brand', 'Model Name', 'Warehouse Code', 'Asset Type / Stage', 'Serial Number', 'Barcode', 'Condition Status', 'Asset Status', 'Purchase Date', 'Purchase Cost', 'Notes'],
                    ['OXY-5L', 'Oxygen Concentrator 5 LPM', 'Philips', 'SimplyGo', 'MAIN', 'rental_stock', 'OC5L-001', 'OC5L-001', 'good', 'available', '2025-01-10', '32000', 'Imported from legacy sheet'],
                    ['BIPAP-MAIN', 'BiPAP Machine', 'ResMed', 'AirCurve 10', 'MAIN', 'new_stock', '', '', 'good', 'available_for_sale', '2025-02-01', '45000', 'Blank serial will generate PENDING serial'],
                ],
                'chunk_size' => 100,
            ],
            self::RENTAL => [
                'label' => 'Rental Import',
                'module_permission' => 'rentals',
                'description' => 'Basic rental migration with customer/product matching and serial-pending support.',
                'priority' => 4,
                'fields' => [
                    'customer_phone' => ['label' => 'Customer Phone', 'aliases' => ['customer_phone', 'phone']],
                    'customer_email' => ['label' => 'Customer Email', 'aliases' => ['customer_email', 'email']],
                    'customer_name' => ['label' => 'Customer Name', 'required' => true, 'aliases' => ['customer_name', 'customer', 'name']],
                    'product_code' => ['label' => 'Product Code', 'aliases' => ['product_code', 'code']],
                    'sku' => ['label' => 'SKU', 'aliases' => ['sku']],
                    'product_name' => ['label' => 'Product Name', 'required' => true, 'aliases' => ['product_name', 'product']],
                    'brand' => ['label' => 'Brand', 'required' => true, 'aliases' => ['brand']],
                    'model_name' => ['label' => 'Model Name', 'required' => true, 'aliases' => ['model', 'model_name']],
                    'warehouse_code' => ['label' => 'Dispatch Warehouse Code', 'aliases' => ['warehouse_code', 'dispatch_warehouse_code']],
                    'warehouse_name' => ['label' => 'Dispatch Warehouse Name', 'aliases' => ['warehouse_name', 'dispatch_warehouse']],
                    'asset_serials' => ['label' => 'Asset Serials', 'aliases' => ['asset_serials', 'serials', 'serial_number']],
                    'quantity' => ['label' => 'Quantity', 'aliases' => ['quantity', 'qty']],
                    'start_date' => ['label' => 'Start Date', 'required' => true, 'aliases' => ['start_date', 'rental_start_date']],
                    'end_date' => ['label' => 'End Date', 'required' => true, 'aliases' => ['end_date', 'rental_end_date']],
                    'rental_amount' => ['label' => 'Rental Amount', 'aliases' => ['rental_amount', 'amount']],
                    'deposit_amount' => ['label' => 'Deposit Amount', 'aliases' => ['deposit_amount', 'deposit']],
                    'transport_amount' => ['label' => 'Transport Amount', 'aliases' => ['transport_amount', 'transport']],
                    'other_amount' => ['label' => 'Other Amount', 'aliases' => ['other_amount', 'other']],
                    'status' => ['label' => 'Rental Status', 'required' => true, 'aliases' => ['status', 'rental_status']],
                    'payment_status' => ['label' => 'Payment Status', 'aliases' => ['payment_status']],
                    'paid_amount' => ['label' => 'Paid Amount', 'aliases' => ['paid_amount']],
                    'payment_date' => ['label' => 'Payment Date', 'aliases' => ['payment_date']],
                    'payment_method' => ['label' => 'Payment Method', 'aliases' => ['payment_method']],
                    'invoice_status' => ['label' => 'Invoice Status', 'aliases' => ['invoice_status']],
                    'delivery_status' => ['label' => 'Delivery Status', 'aliases' => ['delivery_status']],
                    'delivery_date' => ['label' => 'Delivery Date', 'aliases' => ['delivery_date']],
                    'pickup_status' => ['label' => 'Pickup Status', 'aliases' => ['pickup_status']],
                    'pickup_date' => ['label' => 'Pickup Date', 'aliases' => ['pickup_date']],
                    'notes' => ['label' => 'Notes', 'aliases' => ['notes', 'remarks']],
                ],
                'template' => [
                    ['Customer Name', 'Customer Phone', 'Product Name', 'Brand', 'Model Name', 'Dispatch Warehouse Code', 'Asset Serials', 'Quantity', 'Start Date', 'End Date', 'Rental Amount', 'Deposit Amount', 'Transport Amount', 'Status', 'Payment Status', 'Paid Amount', 'Invoice Status', 'Delivery Status', 'Delivery Date', 'Pickup Status', 'Pickup Date', 'Notes'],
                    ['Aarav Sharma', '9876543210', 'Oxygen Concentrator 5 LPM', 'Philips', 'SimplyGo', 'MAIN', 'OC5L-001', '1', '2026-04-01', '2026-04-15', '4500', '5000', '350', 'Active', 'pending', '', 'generated', 'assigned', '2026-04-01', 'not_assigned', '', 'Migrated active rental'],
                    ['Care Plus Clinic', '9810012345', 'BiPAP Machine', 'ResMed', 'AirCurve 10', 'MAIN', '', '1', '2026-03-01', '2026-03-10', '7000', '0', '0', 'Completed', 'paid', '7000', 'paid', 'completed', '2026-03-01', 'completed', '2026-03-10', 'Serial pending import'],
                ],
                'chunk_size' => 100,
            ],
        ];
    }

    public function module(string $module): array
    {
        $module = strtolower(trim($module));
        $config = $this->modules()[$module] ?? null;

        if (!$config) {
            throw new RuntimeException('Unsupported import module.');
        }

        return $config + ['key' => $module];
    }

    public function templateRows(string $module): array
    {
        return $this->module($module)['template'];
    }

    public function fieldOptions(string $module): array
    {
        return $this->module($module)['fields'];
    }

    public function storeUpload(string $module, UploadedFile $file, int $organizationId, int $userId): array
    {
        $parsed = $this->parseSpreadsheet($file);
        $key = $this->saveSnapshot([
            'kind' => 'upload',
            'module' => $module,
            'organization_id' => $organizationId,
            'user_id' => $userId,
            'original_name' => $file->getClientOriginalName(),
            'headers' => $parsed['headers'],
            'rows' => $parsed['rows'],
            'row_count' => count($parsed['rows']),
            'created_at' => now()->toIso8601String(),
        ]);

        return ['key' => $key] + $parsed;
    }

    public function loadSnapshot(string $key): array
    {
        $path = $this->snapshotPath($key);

        if (!Storage::disk('local')->exists($path)) {
            throw new RuntimeException('Import snapshot not found.');
        }

        return json_decode((string) Storage::disk('local')->get($path), true, 512, JSON_THROW_ON_ERROR);
    }

    public function deleteSnapshot(?string $key): void
    {
        $key = trim((string) $key);

        if ($key === '') {
            return;
        }

        Storage::disk('local')->delete($this->snapshotPath($key));
    }

    public function buildPreview(string $module, string $uploadKey, array $mapping, int $organizationId): array
    {
        $upload = $this->loadSnapshot($uploadKey);
        $validRows = [];
        $invalidRows = [];
        $assetPrecheck = [
            'untracked_products' => [],
        ];

        foreach (($upload['rows'] ?? []) as $index => $row) {
            $rowNumber = $index + 2;
            $mapped = $this->applyMapping($row, $mapping);
            [$normalized, $errors] = $this->normalizeRow($module, $mapped, $organizationId, $rowNumber);
            $guidance = $module === self::ASSET
                ? $this->assetStockModeGuidance($mapped, $organizationId)
                : null;

            if (!empty($errors)) {
                $invalidRows[] = [
                    'row_number' => $rowNumber,
                    'source' => $row,
                    'mapped' => $mapped,
                    'errors' => $errors,
                    'guidance' => $guidance,
                ];

                if (($guidance['type'] ?? null) === 'untracked_product') {
                    $productId = (int) ($guidance['product_id'] ?? 0);

                    if ($productId > 0) {
                        $assetPrecheck['untracked_products'][$productId] = [
                            'product_id' => $productId,
                            'product_name' => $guidance['product_name'],
                            'current_mode' => $guidance['current_mode'],
                            'current_mode_label' => $guidance['current_mode_label'],
                        ];
                    }
                }
                continue;
            }

            if (!empty($normalized['import_action'])) {
                $mapped['import_action'] = ucfirst((string) $normalized['import_action']);
            }

            $validRows[] = [
                'row_number' => $rowNumber,
                'payload' => $normalized,
                'mapped' => $mapped,
            ];
        }

        $key = $this->saveSnapshot([
            'kind' => 'preview',
            'module' => $module,
            'organization_id' => $organizationId,
            'upload_key' => $uploadKey,
            'mapping' => $mapping,
            'headers' => $upload['headers'] ?? [],
            'row_count' => count($upload['rows'] ?? []),
            'valid_rows' => $validRows,
            'invalid_rows' => $invalidRows,
            'asset_precheck' => [
                'untracked_products' => array_values($assetPrecheck['untracked_products']),
            ],
            'created_at' => now()->toIso8601String(),
        ]);

        return [
            'key' => $key,
            'valid_count' => count($validRows),
            'invalid_count' => count($invalidRows),
            'valid_rows' => $validRows,
            'invalid_rows' => $invalidRows,
        ];
    }

    public function executePreview(string $module, string $previewKey, int $organizationId, int $userId): array
    {
        $preview = $this->loadSnapshot($previewKey);

        if (!empty($preview['imported_at'])) {
            $lastResult = $preview['last_result'] ?? [
                'processed' => 0,
                'created' => 0,
                'updated' => 0,
                'skipped' => count($preview['invalid_rows'] ?? []),
                'runtime_errors' => [],
            ];

            return $lastResult + [
                'already_imported' => true,
                'error_report_available' => !empty($preview['invalid_rows']) || !empty($lastResult['runtime_errors'] ?? []),
            ];
        }

        $rows = collect($preview['valid_rows'] ?? []);
        $chunkSize = (int) ($this->module($module)['chunk_size'] ?? 100);
        $result = [
            'processed' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped' => count($preview['invalid_rows'] ?? []),
            'runtime_errors' => [],
            'error_report_available' => false,
        ];

        $rows->chunk($chunkSize)->each(function (Collection $chunk) use ($module, $organizationId, $userId, &$result) {
            DB::transaction(function () use ($chunk, $module, $organizationId, $userId, &$result) {
                $touchedProductIds = [];

                foreach ($chunk as $row) {
                    try {
                        $payload = $row['payload'] ?? [];
                        $rowNumber = (int) ($row['row_number'] ?? 0);

                        $action = match ($module) {
                            self::CUSTOMER => $this->importCustomerRow($payload, $organizationId),
                            self::PRODUCT => $this->importProductRow($payload, $organizationId),
                            self::ASSET => $this->importAssetRow($payload, $organizationId, $userId, $touchedProductIds),
                            self::RENTAL => $this->importRentalRow($payload, $organizationId, $userId),
                            default => throw new RuntimeException('Unsupported import module.'),
                        };

                        $result['processed']++;
                        $result[$action]++;
                    } catch (\Throwable $exception) {
                        logger()->error('Import preview execution row failed.', [
                            'module' => $module,
                            'row_number' => (int) ($row['row_number'] ?? 0),
                            'message' => $exception->getMessage(),
                        ]);
                        $result['skipped']++;
                        $result['runtime_errors'][] = [
                            'row_number' => (int) ($row['row_number'] ?? 0),
                            'errors' => [$exception->getMessage()],
                            'mapped' => $row['mapped'] ?? [],
                        ];
                    }
                }

                if ($module === self::ASSET) {
                    collect($touchedProductIds)
                        ->filter()
                        ->unique()
                        ->each(function (int $productId) use ($organizationId) {
                            SaleInventory::syncFromSaleUnits($organizationId, $productId);
                            Product::query()
                                ->where('organization_id', $organizationId)
                                ->find($productId)?->syncLegacyStockFields();
                        });
                }
            });
        });

        $preview['last_result'] = $result + [
            'executed_at' => now()->toIso8601String(),
        ];
        $preview['imported_at'] = now()->toIso8601String();
        $preview['runtime_errors'] = $result['runtime_errors'];
        $this->overwriteSnapshot($previewKey, $preview);
        $result['error_report_available'] = !empty($preview['invalid_rows']) || !empty($result['runtime_errors']);

        return $result;
    }

    public function errorReportRows(string $previewKey): array
    {
        $preview = $this->loadSnapshot($previewKey);
        $rows = [];

        foreach (($preview['invalid_rows'] ?? []) as $row) {
            $rows[] = [
                'row_number' => $row['row_number'] ?? '',
                'status' => 'preview_invalid',
                'errors' => implode(' | ', $row['errors'] ?? []),
                'mapped_data' => json_encode($row['mapped'] ?? [], JSON_UNESCAPED_UNICODE),
            ];
        }

        foreach (($preview['runtime_errors'] ?? []) as $row) {
            $rows[] = [
                'row_number' => $row['row_number'] ?? '',
                'status' => 'import_skipped',
                'errors' => implode(' | ', $row['errors'] ?? []),
                'mapped_data' => json_encode($row['mapped'] ?? [], JSON_UNESCAPED_UNICODE),
            ];
        }

        return $rows;
    }

    public function suggestMapping(string $module, array $headers): array
    {
        $fields = $this->fieldOptions($module);
        $mapping = [];
        $usedHeaders = [];

        foreach ($fields as $fieldKey => $field) {
            $aliases = collect($field['aliases'] ?? [])->prepend($fieldKey)->map(fn ($value) => $this->slugKey($value));
            $matchedHeader = collect($headers)->first(function ($header) use ($aliases, $usedHeaders) {
                return !in_array($header, $usedHeaders, true)
                    && $aliases->contains($this->slugKey($header));
            });

            $mapping[$fieldKey] = $matchedHeader;

            if ($matchedHeader !== null) {
                $usedHeaders[] = $matchedHeader;
            }
        }

        return $mapping;
    }

    private function importCustomerRow(array $payload, int $organizationId): string
    {
        $customer = $this->findCustomerForImport($payload, $organizationId);

        if ($customer) {
            $customer->fill($payload)->save();
            return 'updated';
        }

        Customer::create($payload + ['organization_id' => $organizationId]);
        return 'created';
    }

    private function importProductRow(array $payload, int $organizationId): string
    {
        $product = $this->findProductForImportUpsert($organizationId, $payload);

        if ($product) {
            $product->fill($payload)->save();
            if (($payload['stock_mode'] ?? null) !== Product::STOCK_MODE_UNTRACKED) {
                $product->syncLegacyStockFields();
            }
            return 'updated';
        }

        $product = Product::create($payload + ['organization_id' => $organizationId]);
        if (($payload['stock_mode'] ?? null) !== Product::STOCK_MODE_UNTRACKED) {
            $product->syncLegacyStockFields();
        }

        return 'created';
    }

    private function importAssetRow(array $payload, int $organizationId, int $userId, array &$touchedProductIds): string
    {
        $serial = trim((string) ($payload['serial_number'] ?? ''));
        $existing = $serial !== ''
            ? Asset::query()->where('organization_id', $organizationId)->where('serial_number', $serial)->first()
            : null;
        $product = Product::query()->where('organization_id', $organizationId)->findOrFail((int) $payload['product_id']);

        $writePayload = $payload;
        if ($serial === '') {
            $writePayload['serial_number'] = $this->generatePendingSerial($product, $organizationId);
        }

        if ($existing) {
            $existing->fill($writePayload)->save();
            $asset = $existing;
            $action = 'updated';
        } else {
            $asset = Asset::create($writePayload + ['organization_id' => $organizationId]);
            AssetMovement::create([
                'organization_id' => $organizationId,
                'asset_id' => $asset->id,
                'from_warehouse_id' => null,
                'to_warehouse_id' => $asset->warehouse_id,
                'movement_type' => 'inward',
                'remarks' => 'Imported asset via data import.',
                'moved_by' => $userId,
            ]);
            $action = 'created';
        }

        $touchedProductIds[] = (int) $asset->product_id;

        return $action;
    }

    private function importRentalRow(array $payload, int $organizationId, int $userId): string
    {
        $customer = $this->findCustomerForImport($payload['customer'] ?? [], $organizationId);

        if (!$customer) {
            $customer = Customer::create(($payload['customer'] ?? []) + ['organization_id' => $organizationId]);
        }

        $result = app(RentalController::class)->importRentalFromPayload($payload + [
            'customer_id' => $customer->id,
            'created_by_user_id' => $userId,
        ]);

        return $result['action'] ?? 'created';
    }

    private function normalizeRow(string $module, array $mapped, int $organizationId, int $rowNumber): array
    {
        return match ($module) {
            self::CUSTOMER => $this->normalizeCustomerRow($mapped, $organizationId),
            self::PRODUCT => $this->normalizeProductRow($mapped, $organizationId),
            self::ASSET => $this->normalizeAssetRow($mapped, $organizationId),
            self::RENTAL => $this->normalizeRentalRow($mapped, $organizationId),
            default => [[], ['Unsupported import module for row ' . $rowNumber . '.']],
        };
    }

    private function normalizeCustomerRow(array $mapped, ?int $organizationId = null): array
    {
        $name = $this->cleanText($mapped['name'] ?? '')
            ?: $this->cleanText($mapped['company_name'] ?? '')
            ?: $this->cleanText($mapped['contact_name'] ?? '');
        $phone = $this->normalizePhone($mapped['phone'] ?? null);
        $whatsApp = $this->normalizePhone($mapped['whatsapp_number'] ?? null);
        $email = $this->normalizeEmail($mapped['email'] ?? null);
        $errors = [];

        if ($name === '') {
            $errors[] = 'Customer name is required.';
        }

        if (filled($mapped['phone'] ?? null) && !$phone) {
            $errors[] = 'Phone number is not valid.';
        }

        if (filled($mapped['whatsapp_number'] ?? null) && !$whatsApp) {
            $errors[] = 'WhatsApp number is not valid.';
        }

        if (filled($mapped['email'] ?? null) && !$email) {
            $errors[] = 'Email address is not valid.';
        }

        if ($organizationId && $name !== '' && !$phone && !$email) {
            $existingNameMatches = Customer::query()
                ->where('organization_id', $organizationId)
                ->whereRaw('LOWER(name) = ?', [Str::lower($name)])
                ->count();

            if ($existingNameMatches > 1) {
                $errors[] = 'Multiple customers already use this name. Provide phone or email so the importer can match the correct customer.';
            }
        }

        $type = $this->normalizeCustomerType($mapped['customer_type'] ?? null, $mapped['company_name'] ?? null);

        return [[
            'name' => $name,
            'customer_type' => $type,
            'phone' => $phone,
            'whatsapp_number' => $whatsApp,
            'email' => $email,
            'company_name' => $this->cleanText($mapped['company_name'] ?? null),
            'contact_name' => $this->cleanText($mapped['contact_name'] ?? null),
            'gst_number' => $this->cleanText($mapped['gst_number'] ?? null),
            'address' => $this->cleanText($mapped['address'] ?? null),
            'city' => $this->cleanText($mapped['city'] ?? null),
            'state' => $this->cleanText($mapped['state'] ?? null),
            'pincode' => $this->cleanText($mapped['pincode'] ?? null),
            'patient_name' => $this->cleanText($mapped['patient_name'] ?? null),
            'notes' => $this->cleanText($mapped['notes'] ?? null),
        ], $errors];
    }

    private function normalizeProductRow(array $mapped, ?int $organizationId = null): array
    {
        $name = $this->cleanText($mapped['name'] ?? null);
        $productType = $this->normalizeProductType($mapped['product_type'] ?? null);
        $sellable = $this->normalizeImportBoolean($mapped['sellable'] ?? null);
        $rentable = $this->normalizeImportBoolean($mapped['rentable'] ?? null);
        $stockMode = $this->normalizeStockMode($mapped['stock_mode'] ?? null);
        $quantity = $this->normalizeInteger($mapped['quantity'] ?? 0);
        $brand = $this->cleanText($mapped['brand'] ?? null);
        $modelName = $this->cleanText($mapped['model_name'] ?? null);
        $errors = [];

        if ($name === '') {
            $errors[] = 'Product name is required.';
        }

        if (!$productType) {
            $productType = match (true) {
                $rentable === true => Product::TYPE_RENTABLE,
                $sellable === true => Product::TYPE_SELLABLE,
                $stockMode === Product::STOCK_MODE_TRACKED_RENTAL => Product::TYPE_RENTABLE,
                $stockMode === Product::STOCK_MODE_TRACKED_SALE => Product::TYPE_SELLABLE,
                default => null,
            };
        }

        if (!$productType) {
            $errors[] = 'Product type could not be resolved. Map Product Type or provide Sellable / Rentable values.';
        }

        if (!$stockMode) {
            $errors[] = 'Stock mode must be untracked, tracked_sale, tracked_rental, or tracked_both.';
        }

        if ($quantity < 0) {
            $errors[] = 'Quantity cannot be negative.';
        }

        if ($organizationId && $name !== '' && blank($mapped['product_code'] ?? null) && blank($mapped['sku'] ?? null)) {
            $matchingNameCount = Product::query()
                ->where('organization_id', $organizationId)
                ->whereRaw('LOWER(name) = ?', [Str::lower($name)])
                ->count();

            if ($matchingNameCount > 1 && ($brand === '' || $modelName === '')) {
                $errors[] = 'Multiple products share this name. Specify Brand and Model.';
            }
        }

        $pricePerDay = $this->normalizeDecimal($mapped['price_per_day'] ?? null)
            ?? $this->normalizeDecimal($mapped['rental_price'] ?? null);

        if ($productType === Product::TYPE_RENTABLE && $pricePerDay === null) {
            $errors[] = 'Price per day or rental price is required for rentable products.';
        }

        return [[
            'name' => $name,
            'category' => $this->cleanText($mapped['category'] ?? null),
            'brand' => $brand,
            'model_name' => $modelName,
            'product_code' => $this->cleanText($mapped['product_code'] ?? null),
            'sku' => $this->cleanText($mapped['sku'] ?? null),
            'product_type' => $productType,
            'stock_mode' => $stockMode,
            'total_quantity' => $stockMode === Product::STOCK_MODE_UNTRACKED ? max($quantity, 0) : 0,
            'available_quantity' => $stockMode === Product::STOCK_MODE_UNTRACKED ? max($quantity, 0) : 0,
            'price_per_day' => $pricePerDay,
            'rental_price_15_days' => $this->normalizeDecimal($mapped['rental_price_15_days'] ?? null),
            'rental_price_30_days' => $this->normalizeDecimal($mapped['rental_price_30_days'] ?? null),
            'rental_price_3_months' => $this->normalizeDecimal($mapped['rental_price_3_months'] ?? null),
            'sale_price' => $this->normalizeDecimal($mapped['sale_price'] ?? null),
            'rental_price' => $this->normalizeDecimal($mapped['rental_price'] ?? null),
        ], $errors];
    }

    private function normalizeAssetRow(array $mapped, int $organizationId): array
    {
        $productMatch = $this->resolveProductMatch($organizationId, $mapped);
        $product = $productMatch['product'];
        $warehouse = $this->resolveWarehouse($organizationId, $mapped);
        $errors = [];

        if ($this->cleanText($mapped['product_name'] ?? null) === '') {
            $errors[] = 'Product name is required.';
        }

        if ($productMatch['error']) {
            $errors[] = $productMatch['error'];
        }

        if (!$warehouse) {
            $errors[] = 'Warehouse lookup failed. Map warehouse code or warehouse name to an existing active warehouse.';
        }

        $stage = $this->normalizeAssetStage($mapped['asset_stage'] ?? null);

        if ($product && !$stage) {
            $stage = match (true) {
                $product->tracksSaleStock() && !$product->tracksRentalStock() => Asset::STAGE_NEW_STOCK,
                $product->tracksRentalStock() && !$product->tracksSaleStock() => Asset::STAGE_RENTAL_STOCK,
                default => null,
            };
        }

        if (!$stage) {
            $errors[] = 'Asset type / stage is required for mixed stock products.';
        }

        if ($product && $product->usesUntrackedStock()) {
            $errors[] = 'This product uses quantity-based inventory. Switch it to tracked mode before importing assets.';
        }

        if ($product && $stage === Asset::STAGE_NEW_STOCK && !$product->tracksSaleStock()) {
            $errors[] = $product->name . ' does not allow sale-unit import under its stock mode.';
        }

        if ($product && $stage === Asset::STAGE_RENTAL_STOCK && !$product->tracksRentalStock()) {
            $errors[] = $product->name . ' does not allow rental-asset import under its stock mode.';
        }

        $status = $this->normalizeAssetStatus($mapped['asset_status'] ?? null, $stage);

        if ($stage && !$status) {
            $errors[] = 'Asset status is not valid for the selected asset stage.';
        }

        $condition = $this->normalizeConditionStatus($mapped['condition_status'] ?? null);

        if (filled($mapped['condition_status'] ?? null) && !$condition) {
            $errors[] = 'Condition status must be one of: ' . implode(', ', Asset::CONDITION_STATUSES) . '.';
        }

        $barcode = $this->cleanText($mapped['barcode_value'] ?? null);
        $serial = $this->cleanText($mapped['serial_number'] ?? null);
        $existingBySerial = $serial !== ''
            ? Asset::query()->where('organization_id', $organizationId)->where('serial_number', $serial)->first()
            : null;
        $existingByBarcode = $barcode !== ''
            ? Asset::query()->where('organization_id', $organizationId)->where('barcode_value', $barcode)->first()
            : null;

        if ($existingBySerial && $existingByBarcode && (int) $existingBySerial->id !== (int) $existingByBarcode->id) {
            $errors[] = 'Serial number and barcode point to different existing assets. Fix the row before importing.';
        } elseif ($barcode !== '' && $existingByBarcode && !$existingBySerial) {
            $errors[] = 'Barcode already exists in this organization.';
        }

        return [[
            'product_id' => $product?->id,
            'warehouse_id' => $warehouse?->id,
            'asset_name' => $this->cleanText($mapped['asset_name'] ?? null) ?: ($product?->name ?? null),
            'serial_number' => $serial,
            'barcode_value' => $barcode ?: null,
            'batch_number' => $this->cleanText($mapped['batch_number'] ?? null),
            'asset_stage' => $stage,
            'purchase_date' => $this->normalizeDate($mapped['purchase_date'] ?? null),
            'purchase_cost' => $this->normalizeDecimal($mapped['purchase_cost'] ?? null),
            'condition_status' => $condition ?: 'good',
            'asset_status' => $status ?: ($stage ? Asset::defaultStatusForStage($stage) : null),
            'notes' => $this->cleanText($mapped['notes'] ?? null),
            'last_service_date' => $this->normalizeDate($mapped['last_service_date'] ?? null),
            'next_service_date' => $this->normalizeDate($mapped['next_service_date'] ?? null),
        ], $errors];
    }

    private function assetStockModeGuidance(array $mapped, int $organizationId): ?array
    {
        $product = $this->resolveProductMatch($organizationId, $mapped)['product'];

        if (!$product || !$product->usesUntrackedStock()) {
            return null;
        }

        return [
            'type' => 'untracked_product',
            'product_id' => (int) $product->id,
            'product_name' => $product->name,
            'current_mode' => (string) $product->stock_mode,
            'current_mode_label' => $product->stockModeLabel(),
            'message' => 'This product uses quantity-based inventory. To import assets, switch it to tracked mode.',
            'suggested_modes' => ['Tracked Rental', 'Tracked Both'],
        ];
    }

    private function normalizeRentalRow(array $mapped, int $organizationId): array
    {
        $customerPayload = [
            'name' => $mapped['customer_name'] ?? null,
            'phone' => $mapped['customer_phone'] ?? null,
            'email' => $mapped['customer_email'] ?? null,
        ];
        [$normalizedCustomer, $customerErrors] = $this->normalizeCustomerRow($customerPayload);
        $productMatch = $this->resolveProductMatch($organizationId, $mapped);
        $product = $productMatch['product'];
        $warehouse = $this->resolveWarehouse($organizationId, $mapped, false);
        $status = $this->normalizeRentalStatus($mapped['status'] ?? null);
        $startDate = $this->normalizeDate($mapped['start_date'] ?? null);
        $endDate = $this->normalizeDate($mapped['end_date'] ?? null);
        $quantity = $this->normalizeInteger($mapped['quantity'] ?? null);
        $serials = $this->splitSerials($mapped['asset_serials'] ?? null);
        $resolvedAssets = $product ? $this->resolveAssetsBySerials($organizationId, $product->id, $serials) : collect();
        $missingSerials = collect($serials)->reject(fn ($serial) => $resolvedAssets->pluck('serial_number')->contains($serial))->values();
        $paymentStatus = $this->normalizeImportedPaymentStatus($mapped['payment_status'] ?? null);
        $invoiceStatus = $this->normalizeImportedInvoiceStatus($mapped['invoice_status'] ?? null);
        $deliveryStatus = $this->normalizeImportedTaskStatus($mapped['delivery_status'] ?? null, ['delivered' => 'completed']);
        $pickupStatus = $this->normalizeImportedTaskStatus($mapped['pickup_status'] ?? null, ['picked_up' => 'completed']);
        $paidAmount = $this->normalizeDecimal($mapped['paid_amount'] ?? null);
        $deliveryDate = $this->normalizeDate($mapped['delivery_date'] ?? null);
        $pickupDate = $this->normalizeDate($mapped['pickup_date'] ?? null);
        $paymentDate = $this->normalizeDate($mapped['payment_date'] ?? null);
        $errors = $customerErrors;
        $rawRentalAmount = $this->cleanText($mapped['rental_amount'] ?? null);
        $rawDepositAmount = $this->cleanText($mapped['deposit_amount'] ?? null);
        $rawTransportAmount = $this->cleanText($mapped['transport_amount'] ?? null);
        $rawOtherAmount = $this->cleanText($mapped['other_amount'] ?? null);
        $rentalAmountValue = (float) ($this->normalizeDecimal($mapped['rental_amount'] ?? null) ?? 0.0);
        $depositAmountValue = (float) ($this->normalizeDecimal($mapped['deposit_amount'] ?? null) ?? 0.0);
        $transportAmountValue = (float) ($this->normalizeDecimal($mapped['transport_amount'] ?? null) ?? 0.0);
        $otherAmountValue = (float) ($this->normalizeDecimal($mapped['other_amount'] ?? null) ?? 0.0);
        $expectedInvoiceTotal = round($rentalAmountValue + $depositAmountValue + $transportAmountValue + $otherAmountValue, 2);

        if ($this->cleanText($mapped['product_name'] ?? null) === '') {
            $errors[] = 'Product name is required.';
        }

        if ($productMatch['error']) {
            $errors[] = $productMatch['error'];
        }

        if (!$status) {
            $errors[] = 'Rental status must map to Active or Completed.';
        }

        if (!$startDate) {
            $errors[] = 'Start date is required.';
        }

        if (!$endDate) {
            $errors[] = 'End date is required.';
        }

        if ($startDate && $endDate && $endDate < $startDate) {
            $errors[] = 'End date must be on or after start date.';
        }

        if ($quantity <= 0) {
            $quantity = max(count($serials), 1);
        }

        $rawPaymentStatus = Str::lower(trim((string) ($mapped['payment_status'] ?? '')));
        if ($rawPaymentStatus !== '' && $paymentStatus === null) {
            $errors[] = 'Payment status must be paid, partial, pending, or unpaid.';
        }

        $rawInvoiceStatus = Str::lower(trim((string) ($mapped['invoice_status'] ?? '')));
        if ($rawInvoiceStatus !== '' && $invoiceStatus === null) {
            $errors[] = 'Invoice status must be generated, not_generated, paid, unpaid, partial, or pending.';
        }

        $rawDeliveryStatus = Str::lower(trim((string) ($mapped['delivery_status'] ?? '')));
        if ($rawDeliveryStatus !== '' && $deliveryStatus === null) {
            $errors[] = 'Delivery status must be not_assigned, assigned, pending, or completed.';
        }

        $rawPickupStatus = Str::lower(trim((string) ($mapped['pickup_status'] ?? '')));
        if ($rawPickupStatus !== '' && $pickupStatus === null) {
            $errors[] = 'Pickup status must be not_assigned, assigned, pending, or completed.';
        }

        if ($paymentStatus === 'partial') {
            if ($paidAmount === null || $paidAmount <= 0) {
                $errors[] = 'Paid amount is required and must be greater than 0 when Payment Status is partial.';
            } elseif ($paidAmount >= $expectedInvoiceTotal) {
                $errors[] = 'Paid amount must be less than the total invoice value when Payment Status is partial.';
            }
        }

        if ($paymentStatus === 'pending' && $paidAmount !== null && $paidAmount > 0) {
            $errors[] = 'Paid amount must be blank or 0 when Payment Status is pending.';
        }

        if ($deliveryDate === null && filled($mapped['delivery_date'] ?? null)) {
            $errors[] = 'Delivery date must be a valid date when provided.';
        }

        if ($pickupDate === null && filled($mapped['pickup_date'] ?? null)) {
            $errors[] = 'Pickup date must be a valid date when provided.';
        }

        if ($paymentDate === null && filled($mapped['payment_date'] ?? null)) {
            $errors[] = 'Payment date must be a valid date when provided.';
        }

        $deliveryEffectivelyCompleted = in_array($deliveryStatus, ['completed'], true)
            || $status === 'returned';
        $pickupRequested = in_array($pickupStatus, ['assigned', 'pending', 'completed'], true)
            || $status === 'returned';

        if ($pickupRequested && !$deliveryEffectivelyCompleted) {
            $errors[] = 'Pickup status requires the rental delivery to be completed first.';
        }

        $existingCustomer = $this->findCustomerForImport($normalizedCustomer, $organizationId);
        $matchAction = 'create';
        $matchMessage = null;

        if (empty($errors)) {
            $previewMatch = app(RentalController::class)->describeImportedRentalMatch(
                app(ImportMatchSignatureService::class)->rental(
                    [
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                        'payment_status' => $paymentStatus ?? 'pending',
                        'paid_amount' => $paidAmount ?? 0.0,
                    ],
                    (int) ($existingCustomer?->id ?? 0),
                    (int) ($product?->id ?? 0),
                    $warehouse?->id,
                    $resolvedAssets->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
                    $quantity
                )
            );

            $matchAction = $previewMatch['action'] ?? 'create';
            $matchMessage = $previewMatch['message'] ?? null;

            if ($matchAction === 'review' && $matchMessage) {
                $errors[] = $matchMessage;
            }
        }

        if ($product instanceof Product) {
            if (!$product->canRent()) {
                $errors[] = $product->name . ' is not configured as a rentable product.';
            } elseif ($matchAction !== 'update') {
                if ($product->tracksRentalStock()) {
                    $availableAssets = Asset::query()
                        ->where('organization_id', $organizationId)
                        ->where('product_id', $product->id)
                        ->where('asset_stage', Asset::STAGE_RENTAL_STOCK)
                        ->where('asset_status', Asset::STATUS_AVAILABLE)
                        ->when($warehouse, fn ($query) => $query->where('warehouse_id', $warehouse->id))
                        ->count();

                    if ($availableAssets < $quantity) {
                        $errors[] = 'Tracked rental stock is not sufficient for this row.';
                    }
                } elseif ((int) ($product->available_quantity ?? 0) < $quantity) {
                    $errors[] = 'Untracked available quantity is not sufficient for this row.';
                }
            }
        }

        $rawNotes = $this->cleanText($mapped['notes'] ?? null);
        $rawPaymentDate = $this->cleanText($mapped['payment_date'] ?? null);
        $notes = collect([
            $rawNotes,
            $missingSerials->isNotEmpty() ? 'Imported serial references pending match: ' . $missingSerials->implode(', ') : null,
            empty($serials) ? 'Imported in serial-pending mode.' : null,
        ])->filter()->implode(' | ');

        $payload = [
            'customer' => $normalizedCustomer,
            'product_id' => $product?->id,
            'dispatch_warehouse_id' => $warehouse?->id,
            'quantity' => $quantity,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'rental_amount' => $rentalAmountValue,
            'deposit_amount' => $depositAmountValue,
            'transport_amount' => $transportAmountValue,
            'other_amount' => $otherAmountValue,
            'status' => $status,
            'returned_at' => $status === 'returned' ? ($endDate ? Carbon::parse($endDate)->endOfDay()->toDateTimeString() : now()->toDateTimeString()) : null,
            'asset_ids' => $resolvedAssets->pluck('id')->map(fn ($id) => (int) $id)->values()->all(),
            'payment_status' => $paymentStatus ?? 'pending',
            'paid_amount' => $paidAmount ?? 0.0,
            'payment_date' => $paymentDate ? Carbon::parse($paymentDate)->toDateString() : null,
            'payment_method' => $this->cleanText($mapped['payment_method'] ?? null) ?: null,
            'invoice_status' => $invoiceStatus ?? '',
            'delivery_status' => $deliveryStatus ?? '',
            'delivery_date' => $deliveryDate ? Carbon::parse($deliveryDate)->toDateString() : null,
            'pickup_status' => $pickupStatus ?? '',
            'pickup_date' => $pickupDate ? Carbon::parse($pickupDate)->toDateString() : null,
            'notes' => $notes ?: null,
            'rental_amount_provided' => $rawRentalAmount !== '',
            'deposit_amount_provided' => $rawDepositAmount !== '',
            'transport_amount_provided' => $rawTransportAmount !== '',
            'other_amount_provided' => $rawOtherAmount !== '',
            'payment_status_provided' => $rawPaymentStatus !== '',
            'paid_amount_provided' => $this->cleanText($mapped['paid_amount'] ?? null) !== '',
            'payment_date_provided' => $rawPaymentDate !== '',
            'invoice_status_provided' => $rawInvoiceStatus !== '',
            'delivery_status_provided' => $rawDeliveryStatus !== '',
            'pickup_status_provided' => $rawPickupStatus !== '',
            'notes_provided' => $rawNotes !== '',
        ];

        if (empty($errors)) {
            $payload['import_action'] = $matchAction;
            $mapped['import_action'] = ucfirst((string) $matchAction);
        }

        return [$payload, $errors];
    }

    private function normalizeImportedPaymentStatus(?string $value): ?string
    {
        return match (Str::lower(trim((string) $value))) {
            '' => 'pending',
            'paid', 'full', 'fully_paid' => 'paid',
            'partial', 'partially_paid' => 'partial',
            'pending', 'unpaid' => 'pending',
            default => null,
        };
    }

    private function normalizeImportedInvoiceStatus(?string $value): ?string
    {
        return match (Str::lower(trim((string) $value))) {
            '' => '',
            'generated' => 'generated',
            'not_generated' => 'not_generated',
            'paid' => 'paid',
            'unpaid' => 'unpaid',
            'partial', 'partially_paid' => 'partial',
            'pending' => 'pending',
            default => null,
        };
    }

    private function normalizeImportedTaskStatus(?string $value, array $aliases = []): ?string
    {
        $normalized = Str::lower(trim((string) $value));
        $normalized = $aliases[$normalized] ?? $normalized;

        return match ($normalized) {
            '' => '',
            'not_assigned' => 'not_assigned',
            'assigned' => 'assigned',
            'pending' => 'pending',
            'completed' => 'completed',
            default => null,
        };
    }

    private function parseSpreadsheet(UploadedFile $file): array
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());

        return match ($extension) {
            'csv', 'txt' => $this->parseCsv($file->getRealPath()),
            'xlsx' => $this->parseXlsx($file->getRealPath()),
            default => throw new RuntimeException('Only CSV and XLSX files are supported in this importer.'),
        };
    }

    private function parseCsv(string $path): array
    {
        $handle = fopen($path, 'rb');

        if (!$handle) {
            throw new RuntimeException('Unable to read uploaded CSV file.');
        }

        $headers = [];
        $rows = [];

        while (($row = fgetcsv($handle)) !== false) {
            if ($headers === []) {
                $headers = $this->normalizeHeaders($row);
                continue;
            }

            if ($this->rowIsEmpty($row)) {
                continue;
            }

            $rows[] = $this->associateRow($headers, $row);
        }

        fclose($handle);

        return ['headers' => $headers, 'rows' => $rows];
    }

    private function parseXlsx(string $path): array
    {
        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            throw new RuntimeException('Unable to open uploaded XLSX file.');
        }

        $sharedStrings = $this->readSharedStrings($zip);
        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');

        if ($sheetXml === false) {
            $zip->close();
            throw new RuntimeException('The XLSX file does not contain a readable first worksheet.');
        }

        $worksheet = simplexml_load_string($sheetXml);
        $headers = [];
        $rows = [];

        foreach ($worksheet->sheetData->row ?? [] as $row) {
            $cells = [];

            foreach ($row->c as $cell) {
                $reference = (string) $cell['r'];
                $columnIndex = $this->columnIndexFromReference($reference);
                $cells[$columnIndex] = $this->xlsxCellValue($cell, $sharedStrings);
            }

            if ($headers === []) {
                ksort($cells);
                $headers = $this->normalizeHeaders(array_values($cells));
                continue;
            }

            if ($this->rowIsEmpty($cells)) {
                continue;
            }

            $ordered = [];
            for ($index = 0; $index < count($headers); $index++) {
                $ordered[$index] = $cells[$index] ?? null;
            }

            $rows[] = $this->associateRow($headers, $ordered);
        }

        $zip->close();

        return ['headers' => $headers, 'rows' => $rows];
    }

    private function readSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');

        if ($xml === false) {
            return [];
        }

        $document = simplexml_load_string($xml);

        return collect($document->si ?? [])
            ->map(function ($stringItem) {
                if (isset($stringItem->t)) {
                    return (string) $stringItem->t;
                }

                return collect($stringItem->r ?? [])
                    ->map(fn ($run) => (string) ($run->t ?? ''))
                    ->implode('');
            })
            ->all();
    }

    private function xlsxCellValue(\SimpleXMLElement $cell, array $sharedStrings): ?string
    {
        $type = (string) ($cell['t'] ?? '');

        if ($type === 'inlineStr') {
            return trim((string) ($cell->is->t ?? ''));
        }

        $value = isset($cell->v) ? (string) $cell->v : '';

        if ($type === 's') {
            return isset($sharedStrings[(int) $value]) ? trim((string) $sharedStrings[(int) $value]) : '';
        }

        return trim($value);
    }

    private function applyMapping(array $row, array $mapping): array
    {
        $mapped = [];

        foreach ($mapping as $field => $header) {
            $mapped[$field] = $header && array_key_exists($header, $row)
                ? $row[$header]
                : null;
        }

        return $mapped;
    }

    private function associateRow(array $headers, array $row): array
    {
        $assoc = [];

        foreach ($headers as $index => $header) {
            $assoc[$header] = isset($row[$index]) ? trim((string) $row[$index]) : null;
        }

        return $assoc;
    }

    private function normalizeHeaders(array $headers): array
    {
        return collect($headers)
            ->values()
            ->map(function ($header, int $index) {
                $value = trim((string) $header);
                return $value !== '' ? $value : 'Column ' . ($index + 1);
            })
            ->all();
    }

    private function rowIsEmpty(iterable $row): bool
    {
        foreach ($row as $value) {
            if (trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    private function snapshotPath(string $key): string
    {
        return self::STORAGE_DIR . '/' . $key . '.json';
    }

    private function saveSnapshot(array $payload): string
    {
        $key = (string) Str::uuid();
        $this->overwriteSnapshot($key, $payload);
        return $key;
    }

    private function overwriteSnapshot(string $key, array $payload): void
    {
        Storage::disk('local')->put($this->snapshotPath($key), json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function slugKey(?string $value): string
    {
        return Str::of((string) $value)->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->toString();
    }

    private function cleanText(?string $value): string
    {
        return trim((string) $value);
    }

    private function normalizePhone(?string $value): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        return PhoneNumber::normalize($value);
    }

    private function normalizeEmail(?string $value): ?string
    {
        $value = Str::lower(trim((string) $value));
        if ($value === '') {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : null;
    }

    private function normalizeDecimal(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return round((float) $value, 2);
        }

        $normalized = preg_replace('/[^0-9.\-]+/', '', (string) $value);
        return $normalized === '' || !is_numeric($normalized) ? null : round((float) $normalized, 2);
    }

    private function normalizeInteger(mixed $value): int
    {
        if ($value === null || $value === '') {
            return 0;
        }

        return (int) round((float) $value);
    }

    private function normalizeDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value) && (float) $value > 1000) {
            return Carbon::create(1899, 12, 30)->addDays((int) $value)->toDateString();
        }

        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeCustomerType(?string $value, ?string $companyName = null): string
    {
        $normalized = Str::lower(trim((string) $value));

        return match (true) {
            in_array($normalized, ['business', 'company', 'corporate'], true) => 'Business',
            filled($companyName) => 'Business',
            default => 'Individual',
        };
    }

    private function normalizeProductType(?string $value): ?string
    {
        return match (Str::lower(trim((string) $value))) {
            'sellable', 'sale', 'sales' => Product::TYPE_SELLABLE,
            'rentable', 'rental', 'rent' => Product::TYPE_RENTABLE,
            default => null,
        };
    }

    private function normalizeStockMode(?string $value): ?string
    {
        return match (Str::lower(trim((string) $value))) {
            'untracked', 'manual' => Product::STOCK_MODE_UNTRACKED,
            'tracked_sale', 'tracked sale', 'sale' => Product::STOCK_MODE_TRACKED_SALE,
            'tracked_rental', 'tracked rental', 'rental' => Product::STOCK_MODE_TRACKED_RENTAL,
            'tracked_both', 'tracked both', 'both', 'mixed' => Product::STOCK_MODE_TRACKED_BOTH,
            default => null,
        };
    }

    private function normalizeAssetStage(?string $value): ?string
    {
        return match (Str::lower(trim((string) $value))) {
            'new_stock', 'new stock', 'sale', 'sale_unit', 'sale units', 'sellable' => Asset::STAGE_NEW_STOCK,
            'rental_stock', 'rental stock', 'rental', 'rental_asset', 'rental assets' => Asset::STAGE_RENTAL_STOCK,
            default => null,
        };
    }

    private function normalizeAssetStatus(?string $value, ?string $stage): ?string
    {
        if ($stage === null) {
            return null;
        }

        $normalized = Str::lower(trim((string) $value));

        if ($normalized === '') {
            return Asset::defaultStatusForStage($stage);
        }

        $alias = match ($normalized) {
            'available', 'ready' => $stage === Asset::STAGE_NEW_STOCK ? Asset::STATUS_AVAILABLE_FOR_SALE : Asset::STATUS_AVAILABLE,
            'available_for_sale' => Asset::STATUS_AVAILABLE_FOR_SALE,
            'reserved_for_sale' => Asset::STATUS_RESERVED_FOR_SALE,
            'reserved' => $stage === Asset::STAGE_NEW_STOCK ? Asset::STATUS_RESERVED_FOR_SALE : Asset::STATUS_RESERVED,
            'sold' => Asset::STATUS_SOLD,
            'rented' => Asset::STATUS_RENTED,
            'awaiting_verification', 'awaiting verification' => Asset::STATUS_AWAITING_VERIFICATION,
            'maintenance', 'repair' => Asset::STATUS_MAINTENANCE,
            'retired', 'scrap' => Asset::STATUS_RETIRED,
            'converted_to_rental' => Asset::STATUS_CONVERTED_TO_RENTAL,
            default => $normalized,
        };

        return in_array($alias, Asset::statusesForStage($stage), true) ? $alias : null;
    }

    private function normalizeConditionStatus(?string $value): ?string
    {
        $normalized = Str::lower(trim((string) $value));

        return match ($normalized) {
            '', null => null,
            'good', 'ok' => 'good',
            'repair', 'needs repair' => 'repair',
            'damaged', 'damage' => 'damaged',
            'inactive', 'scrap', 'retired' => 'inactive',
            default => null,
        };
    }

    private function normalizeImportBoolean(mixed $value): ?bool
    {
        $normalized = Str::lower(trim((string) $value));

        return match ($normalized) {
            '', 'n/a', 'na', '-' => null,
            'yes', 'y', 'true', '1' => true,
            'no', 'n', 'false', '0' => false,
            default => null,
        };
    }

    private function normalizeRentalStatus(?string $value): ?string
    {
        return match (Str::lower(trim((string) $value))) {
            'active', 'live', 'open' => 'active',
            'completed', 'closed', 'returned' => 'returned',
            default => null,
        };
    }

    private function resolveProductMatch(int $organizationId, array $mapped): array
    {
        if (!isset($this->productCache[$organizationId])) {
            $products = Product::query()->where('organization_id', $organizationId)->get();
            $this->productCache[$organizationId] = [
                'all' => $products,
                'code' => $products->filter(fn ($product) => filled($product->product_code))->keyBy(fn ($product) => Str::lower((string) $product->product_code)),
                'sku' => $products->filter(fn ($product) => filled($product->sku))->keyBy(fn ($product) => Str::lower((string) $product->sku)),
            ];
        }

        $cache = $this->productCache[$organizationId];
        $code = Str::lower($this->cleanText($mapped['product_code'] ?? null));
        $sku = Str::lower($this->cleanText($mapped['sku'] ?? null));
        $name = Str::lower($this->cleanText($mapped['product_name'] ?? $mapped['name'] ?? null));
        $brand = Str::lower($this->cleanText($mapped['brand'] ?? null));
        $modelName = Str::lower($this->cleanText($mapped['model_name'] ?? $mapped['model'] ?? null));

        if ($code !== '' && isset($cache['code'][$code])) {
            return ['product' => $cache['code'][$code], 'error' => null];
        }

        if ($sku !== '' && isset($cache['sku'][$sku])) {
            return ['product' => $cache['sku'][$sku], 'error' => null];
        }

        if ($name === '') {
            return ['product' => null, 'error' => 'Product lookup failed. Product name is required.'];
        }

        $nameMatches = $cache['all']->filter(fn ($product) => Str::lower((string) $product->name) === $name)->values();

        if ($nameMatches->isEmpty()) {
            return ['product' => null, 'error' => 'Product lookup failed. No matching product found.'];
        }

        if ($brand !== '' && $modelName !== '') {
            $fullMatch = $nameMatches
                ->first(fn ($product) => Str::lower((string) ($product->brand ?? '')) === $brand
                    && Str::lower((string) ($product->model_name ?? '')) === $modelName);

            if ($fullMatch) {
                return ['product' => $fullMatch, 'error' => null];
            }
        }

        if ($nameMatches->count() === 1) {
            return ['product' => $nameMatches->first(), 'error' => null];
        }

        return ['product' => null, 'error' => 'Multiple products found. Please specify Brand and Model.'];
    }

    private function resolveWarehouse(int $organizationId, array $mapped, bool $required = true): ?Warehouse
    {
        if (!$required && blank(($mapped['warehouse_code'] ?? null)) && blank(($mapped['warehouse_name'] ?? null))) {
            return null;
        }

        if (!isset($this->warehouseCache[$organizationId])) {
            $warehouses = Warehouse::query()
                ->where('organization_id', $organizationId)
                ->where('is_active', true)
                ->get();
            $this->warehouseCache[$organizationId] = [
                'code' => $warehouses->filter(fn ($warehouse) => filled($warehouse->code))->keyBy(fn ($warehouse) => Str::lower((string) $warehouse->code)),
                'name' => $warehouses->keyBy(fn ($warehouse) => Str::lower((string) $warehouse->name)),
            ];
        }

        $cache = $this->warehouseCache[$organizationId];
        $code = Str::lower($this->cleanText($mapped['warehouse_code'] ?? null));
        $name = Str::lower($this->cleanText($mapped['warehouse_name'] ?? null));

        return $cache['code'][$code] ?? $cache['name'][$name] ?? null;
    }

    private function resolveAssetsBySerials(int $organizationId, int $productId, array $serials): Collection
    {
        if (empty($serials)) {
            return collect();
        }

        return Asset::query()
            ->where('organization_id', $organizationId)
            ->where('product_id', $productId)
            ->whereIn('serial_number', $serials)
            ->get(['id', 'serial_number']);
    }

    private function splitSerials(?string $value): array
    {
        return collect(preg_split('/[\r\n,;|]+/', (string) $value))
            ->map(fn ($serial) => trim((string) $serial))
            ->filter()
            ->values()
            ->all();
    }

    private function findCustomerForImport(array $payload, int $organizationId): ?Customer
    {
        $phone = $this->normalizePhone($payload['phone'] ?? null);
        $email = $this->normalizeEmail($payload['email'] ?? null);
        $name = Str::lower($this->cleanText($payload['name'] ?? null));

        $query = Customer::query()->where('organization_id', $organizationId);

        if ($phone) {
            $customer = (clone $query)->where('phone', $phone)->first();
            if ($customer) {
                return $customer;
            }
        }

        if ($email) {
            $customer = (clone $query)->where('email', $email)->first();
            if ($customer) {
                return $customer;
            }
        }

        if ($name !== '') {
            $matches = (clone $query)->whereRaw('LOWER(name) = ?', [$name])->get();

            return $matches->count() === 1 ? $matches->first() : null;
        }

        return null;
    }

    private function findProductForImportUpsert(int $organizationId, array $payload): ?Product
    {
        $query = Product::query()->where('organization_id', $organizationId);
        $code = $this->cleanText($payload['product_code'] ?? null);
        $sku = $this->cleanText($payload['sku'] ?? null);
        $name = $this->cleanText($payload['name'] ?? null);
        $brand = $this->cleanText($payload['brand'] ?? null);
        $modelName = $this->cleanText($payload['model_name'] ?? null);

        if ($code !== '') {
            return (clone $query)->where('product_code', $code)->first();
        }

        if ($sku !== '') {
            return (clone $query)->where('sku', $sku)->first();
        }

        if ($name === '') {
            return null;
        }

        $nameMatches = (clone $query)
            ->whereRaw('LOWER(name) = ?', [Str::lower($name)])
            ->get();

        if ($nameMatches->isEmpty()) {
            return null;
        }

        if ($brand !== '' && $modelName !== '') {
            return $nameMatches->first(function ($product) use ($brand, $modelName) {
                return Str::lower((string) ($product->brand ?? '')) === Str::lower($brand)
                    && Str::lower((string) ($product->model_name ?? '')) === Str::lower($modelName);
            });
        }

        return $nameMatches->count() === 1 ? $nameMatches->first() : null;
    }

    private function pendingSerialPrefix(Product $product): string
    {
        $code = trim((string) ($product->product_code ?: $product->sku ?: ('PRODUCT' . $product->id)));
        $code = strtoupper(preg_replace('/[^A-Z0-9]+/', '-', $code) ?: ('PRODUCT' . $product->id));

        return Asset::SERIAL_PENDING_PREFIX . trim($code, '-');
    }

    private function generatePendingSerial(Product $product, int $organizationId): string
    {
        $prefix = $this->pendingSerialPrefix($product);

        do {
            $candidate = $prefix . '-' . strtoupper(Str::random(8));
        } while (Asset::query()
            ->where('organization_id', $organizationId)
            ->where('serial_number', $candidate)
            ->exists());

        return $candidate;
    }

    private function columnIndexFromReference(string $reference): int
    {
        $letters = preg_replace('/[^A-Z]/i', '', strtoupper($reference));
        $index = 0;

        for ($i = 0; $i < strlen($letters); $i++) {
            $index = ($index * 26) + (ord($letters[$i]) - 64);
        }

        return max($index - 1, 0);
    }
}
