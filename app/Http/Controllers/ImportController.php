<?php

namespace App\Http\Controllers;

use App\Services\ImportService;
use App\Http\Controllers\SaleController;
use App\Models\Asset;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use ZipArchive;
use Carbon\Carbon;
use RuntimeException;

class ImportController extends Controller
{
    private const SALES_PREVIEW_SESSION_KEY = 'imports.sales.preview_custom';

    private function canAccessDataImport(): bool
    {
        return auth()->user()?->isSuperAdmin() ?? false;
    }

    private function orgId(): int
    {
        return (int) auth()->user()->organization_id;
    }

    private function currentUploadKey(string $module): ?string
    {
        return session('imports.' . $module . '.upload');
    }

    private function currentPreviewKey(string $module): ?string
    {
        return session('imports.' . $module . '.preview');
    }

    private function clearModuleImportState(string $module, ImportService $service): void
    {
        $uploadKey = $this->currentUploadKey($module);
        $previewKey = $this->currentPreviewKey($module);

        $service->deleteSnapshot($uploadKey);
        $service->deleteSnapshot($previewKey);

        session()->forget([
            'imports.' . $module . '.upload',
            'imports.' . $module . '.preview',
        ]);
    }

    private function authorizeModule(string $module, ImportService $service): array
    {
        abort_unless($this->canAccessDataImport(), 403);

        $config = $service->module($module);
        abort_unless(auth()->user()?->canAccessModule($config['module_permission'], 'create'), 403);

        return $config;
    }

    public function index(ImportService $service)
    {
        $this->authorize('access', self::class);
        abort_unless($this->canAccessDataImport(), 403);

        $modules = collect($service->modules())
            ->map(fn ($config, $key) => $config + [
                'key' => $key,
                'href' => route('imports.module', $key),
                'template_href' => route('imports.template', $key),
                'allowed' => $this->canAccessDataImport() && (auth()->user()?->canAccessModule($config['module_permission'], 'create') ?? false),
            ])
            ->filter(fn ($module) => $module['allowed'])
            ->sortBy('priority')
            ->values();

        return view('import.index', compact('modules'));
    }

    public function show(string $module, ImportService $service)
    {
        $this->authorize('access', self::class);
        $moduleConfig = $this->authorizeModule($module, $service);
        $upload = $this->currentUploadKey($module) ? $service->loadSnapshot($this->currentUploadKey($module)) : null;
        $preview = $this->currentPreviewKey($module) ? $service->loadSnapshot($this->currentPreviewKey($module)) : null;

        return view('import.show', compact('moduleConfig', 'module', 'upload', 'preview'));
    }

    public function upload(Request $request, string $module, ImportService $service)
    {
        $this->authorize('access', self::class);
        $moduleConfig = $this->authorizeModule($module, $service);
        $validated = $request->validate([
            'import_file' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:10240'],
        ]);

        $this->clearModuleImportState($module, $service);

        $snapshot = $service->storeUpload($module, $validated['import_file'], $this->orgId(), (int) auth()->id());
        session([
            'imports.' . $module . '.upload' => $snapshot['key'],
            'imports.' . $module . '.preview' => null,
        ]);

        return redirect()->route('imports.mapping', $module)->with('success', $moduleConfig['label'] . ' file uploaded. Map columns to continue.');
    }

    public function mapping(string $module, ImportService $service)
    {
        $this->authorize('access', self::class);
        $moduleConfig = $this->authorizeModule($module, $service);
        $uploadKey = $this->currentUploadKey($module);
        abort_unless($uploadKey, 404);

        $upload = $service->loadSnapshot($uploadKey);
        $fields = $service->fieldOptions($module);
        $suggestedMapping = $service->suggestMapping($module, $upload['headers'] ?? []);

        return view('import.mapping', compact('moduleConfig', 'module', 'upload', 'fields', 'suggestedMapping'));
    }

    public function buildPreview(Request $request, string $module, ImportService $service)
    {
        $this->authorize('access', self::class);
        $this->authorizeModule($module, $service);
        $uploadKey = $this->currentUploadKey($module);
        abort_unless($uploadKey, 404);

        $mapping = collect($request->input('mapping', []))
            ->map(fn ($value) => filled($value) ? trim((string) $value) : null)
            ->filter(fn ($value) => $value !== null)
            ->all();

        if (count($mapping) !== count(array_unique($mapping))) {
            return back()->withErrors(['mapping' => 'Each uploaded column can only be mapped once.'])->withInput();
        }

        $preview = $service->buildPreview($module, $uploadKey, $mapping, $this->orgId());
        session(['imports.' . $module . '.preview' => $preview['key']]);

        return redirect()->route('imports.preview', $module)->with('success', 'Preview generated. Review valid and invalid rows before importing.');
    }

    public function preview(string $module, ImportService $service)
    {
        $this->authorize('access', self::class);
        $moduleConfig = $this->authorizeModule($module, $service);
        $previewKey = $this->currentPreviewKey($module);
        abort_unless($previewKey, 404);

        $preview = $service->loadSnapshot($previewKey);
        $lastResult = $preview['last_result'] ?? null;
        $errorReportAvailable = !empty($service->errorReportRows($previewKey));

        return view('import.preview', compact('moduleConfig', 'module', 'preview', 'lastResult', 'errorReportAvailable'));
    }

    public function execute(string $module, ImportService $service)
    {
        $this->authorize('access', self::class);
        $moduleConfig = $this->authorizeModule($module, $service);
        $previewKey = $this->currentPreviewKey($module);
        abort_unless($previewKey, 404);

        $preview = $service->loadSnapshot($previewKey);

        if (!empty($preview['imported_at'])) {
            return redirect()
                ->route('imports.preview', $module)
                ->with('success', Str::headline($module) . ' import was already completed. Duplicate import was prevented.');
        }

        $result = $service->executePreview($module, $previewKey, $this->orgId(), (int) auth()->id());

        if (!empty($result['already_imported'])) {
            return redirect()
                ->route('imports.preview', $module)
                ->with('success', Str::headline($module) . ' import was already completed. Duplicate import was prevented.');
        }

        return redirect()
            ->route('imports.preview', $module)
            ->with(
                'success',
                Str::headline($module)
                . ' import finished. Processed ' . ($result['processed'] ?? 0)
                . ', created ' . ($result['created'] ?? 0)
                . ', updated ' . ($result['updated'] ?? 0)
                . ', skipped ' . ($result['skipped'] ?? 0)
                . ', failed ' . ($result['failed'] ?? 0) . '.'
            );
    }

    public function template(string $module, ImportService $service)
    {
        $this->authorize('access', self::class);
        $moduleConfig = $this->authorizeModule($module, $service);
        $rows = $service->templateRows($module);

        return response()->streamDownload(function () use ($rows) {
            $output = fopen('php://output', 'w');
            foreach ($rows as $row) {
                fputcsv($output, $row);
            }
            fclose($output);
        }, Str::slug($moduleConfig['label']) . '-template.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function errorReport(string $module, ImportService $service)
    {
        $this->authorize('access', self::class);
        $this->authorizeModule($module, $service);
        $previewKey = $this->currentPreviewKey($module);
        abort_unless($previewKey, 404);

        $rows = $service->errorReportRows($previewKey);
        abort_if(empty($rows), 404);

        return response()->streamDownload(function () use ($rows) {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Row Number', 'Status', 'Identifier', 'Reason Type', 'Errors', 'Mapped Data']);
            foreach ($rows as $row) {
                fputcsv($output, [
                    $row['row_number'],
                    $row['status'],
                    $row['identifier'] ?? '',
                    $row['reason_category'] ?? '',
                    $row['errors'],
                    $row['mapped_data'],
                ]);
            }
            fclose($output);
        }, 'import-error-report.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function reset(string $module, ImportService $service)
    {
        $this->authorize('access', self::class);
        $moduleConfig = $this->authorizeModule($module, $service);
        $this->clearModuleImportState($module, $service);

        return redirect()
            ->route('imports.module', $module)
            ->with('success', $moduleConfig['label'] . ' reset. Upload a fresh file to continue.');
    }

    public function salesUpload()
    {
        $this->authorize('access', self::class);
        abort_unless(auth()->user()?->canAccessModule('sales', 'create'), 403);

        return view('imports.sales-upload');
    }

    public function salesPreviewPage(ImportService $service)
    {
        $this->authorize('access', self::class);
        abort_unless(auth()->user()?->canAccessModule('sales', 'create'), 403);

        $previewKey = session(self::SALES_PREVIEW_SESSION_KEY);
        abort_unless($previewKey, 404);

        $preview = $service->loadSnapshot($previewKey);

        return $this->renderSalesPreview($preview);
    }

    public function salesPreview(Request $request, ImportService $service)
    {
        $this->authorize('access', self::class);
        abort_unless(auth()->user()?->canAccessModule('sales', 'create'), 403);

        $validated = $request->validate([
            'import_file' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:10240'],
        ]);

        if ($existingPreviewKey = session(self::SALES_PREVIEW_SESSION_KEY)) {
            $service->deleteSnapshot($existingPreviewKey);
        }

        $dataset = $this->parseSpreadsheet($validated['import_file']);
        $preview = $this->buildSalesPreview($dataset, $service);
        $previewKey = $service->createSnapshot([
            'kind' => 'sales_preview',
            'module' => 'sales',
            'organization_id' => $this->orgId(),
            'user_id' => (int) auth()->id(),
            'original_name' => $validated['import_file']->getClientOriginalName(),
            'created_at' => now()->toIso8601String(),
            'import_token' => (string) Str::uuid(),
            'imported_at' => null,
            'import_result' => null,
            ...$preview,
        ]);
        session([self::SALES_PREVIEW_SESSION_KEY => $previewKey]);

        return redirect()->route('imports.sales.preview.page')->with('success', 'Sales preview generated. Review valid and invalid rows before importing.');
    }

    public function salesExecute(ImportService $service, SaleController $saleController)
    {
        $this->authorize('access', self::class);
        abort_unless(auth()->user()?->canAccessModule('sales', 'create'), 403);

        $previewKey = session(self::SALES_PREVIEW_SESSION_KEY);
        abort_unless($previewKey, 404);

        $preview = $service->loadSnapshot($previewKey);

        if (!empty($preview['imported_at'])) {
            return redirect()
                ->route('imports.sales.preview.page')
                ->with('success', 'This sales preview was already imported. Duplicate import was prevented.');
        }

        $result = [
            'processed' => 0,
            'imported' => 0,
            'updated' => 0,
            'skipped' => count($preview['invalid_rows'] ?? []),
            'runtime_errors' => [],
        ];

        foreach (($preview['valid_rows'] ?? []) as $row) {
            try {
                $payload = $row['payload'] ?? [];
                $customer = $this->resolveSalesImportCustomer($payload);
                $importResult = $saleController->importSaleFromPayload([
                    'customer_id' => $customer->id,
                    'product_id' => (int) $payload['product_id'],
                    'warehouse_id' => $payload['warehouse_id'] ?? null,
                    'quantity' => (int) $payload['quantity'],
                    'unit_price' => (float) $payload['unit_price'],
                    'discount_amount' => (float) ($payload['discount_amount'] ?? 0),
                    'shipping_charges' => (float) ($payload['shipping_charges'] ?? 0),
                    'tax_percentage' => (float) ($payload['tax_percentage'] ?? 0),
                    'tax_calculation_mode' => 'exclusive',
                    'sale_date' => $payload['sale_date'],
                    'payment_status' => $payload['payment_status'],
                    'paid_amount' => $payload['paid_amount'] ?? 0,
                    'payment_date' => $payload['payment_date'] ?? null,
                    'payment_method' => $payload['payment_method'] ?? null,
                    'invoice_status' => $payload['invoice_status'] ?? '',
                    'delivery_status' => $payload['delivery_status'] ?? '',
                    'delivery_date' => $payload['delivery_date'] ?? null,
                    'notes' => $payload['notes'] ?? null,
                    'discount_amount_provided' => !empty($payload['discount_amount_provided']),
                    'shipping_charges_provided' => !empty($payload['shipping_charges_provided']),
                    'tax_percentage_provided' => !empty($payload['tax_percentage_provided']),
                    'payment_status_provided' => !empty($payload['payment_status_provided']),
                    'paid_amount_provided' => !empty($payload['paid_amount_provided']),
                    'payment_date_provided' => !empty($payload['payment_date_provided']),
                    'invoice_status_provided' => !empty($payload['invoice_status_provided']),
                    'delivery_status_provided' => !empty($payload['delivery_status_provided']),
                    'notes_provided' => !empty($payload['notes_provided']),
                ]);

                $result['processed']++;
                if (($importResult['action'] ?? 'created') === 'updated') {
                    $result['updated']++;
                } else {
                    $result['imported']++;
                }
            } catch (\Throwable $exception) {
                logger()->error('Sales import execution row failed.', [
                    'row_number' => (int) ($row['row_number'] ?? 0),
                    'message' => $exception->getMessage(),
                ]);
                $result['processed']++;
                $result['skipped']++;
                $result['runtime_errors'][] = [
                    'row_number' => (int) ($row['row_number'] ?? 0),
                    'errors' => [$exception->getMessage()],
                    'values' => $row['values'] ?? [],
                ];
            }
        }

        $preview['imported_at'] = now()->toIso8601String();
        $preview['import_result'] = $result;
        $preview['runtime_errors'] = $result['runtime_errors'];
        $service->replaceSnapshot($previewKey, $preview);

        return redirect()
            ->route('imports.sales.preview.page')
            ->with('success', 'Sales import finished. Imported '.$result['imported'].' row(s), skipped '.$result['skipped'].' row(s).');
    }

    public function openingBalanceUpload()
    {
        $this->authorize('access', self::class);
        abort_unless(
            (auth()->user()?->canAccessModule('invoices', 'create') ?? false)
            || (auth()->user()?->canAccessModule('customers', 'create') ?? false),
            403
        );

        return view('imports.opening-balance-upload');
    }

    public function openingBalancePreview(Request $request)
    {
        $this->authorize('access', self::class);
        abort_unless(
            (auth()->user()?->canAccessModule('invoices', 'create') ?? false)
            || (auth()->user()?->canAccessModule('customers', 'create') ?? false),
            403
        );

        $validated = $request->validate([
            'import_file' => ['required', 'file', 'mimes:csv,txt,xlsx', 'max:10240'],
        ]);

        $dataset = $this->parseSpreadsheet($validated['import_file']);
        $preview = $this->buildOpeningBalancePreview($dataset);

        return view('imports.preview', [
            'title' => 'Opening Balance Preview',
            'subtitle' => 'Review the first 20 rows and fix validation errors before import is enabled.',
            'headers' => $preview['headers'],
            'previewRows' => $preview['preview_rows'],
            'validCount' => $preview['valid_count'],
            'invalidCount' => $preview['invalid_count'],
            'totalRows' => $preview['total_rows'],
            'requiredFields' => ['Customer Phone', 'Opening Balance'],
            'backUrl' => route('imports.opening-balances.upload'),
            'uploadNewUrl' => route('imports.opening-balances.upload'),
            'templateUrl' => route('imports.template', 'opening-balances'),
            'importButtonLabel' => 'Import Valid Rows',
            'importHelperText' => 'Import execution will be enabled after validation testing.',
            'previewColumns' => [
                'customer_phone',
                'opening_balance',
                'balance_type',
                'notes',
            ],
            'fieldLabels' => [
                'customer_phone' => 'Customer Phone',
                'opening_balance' => 'Opening Balance',
                'balance_type' => 'Balance Type',
                'notes' => 'Notes',
            ],
        ]);
    }

    private function buildSalesPreview(array $dataset, ImportService $service): array
    {
        $headerMap = $this->headerMap($dataset['headers']);
        $previewRows = [];
        $validCount = 0;
        $invalidCount = 0;

        foreach ($dataset['rows'] as $index => $row) {
            $errors = [];
            $customerPhone = $this->mappedValue($row, $headerMap, ['customer_phone', 'phone']);
            $customerName = $this->mappedValue($row, $headerMap, ['customer_name', 'customer', 'name']);
            $productName = $this->mappedValue($row, $headerMap, ['product_name', 'product']);
            $brand = $this->mappedValue($row, $headerMap, ['brand']);
            $model = $this->mappedValue($row, $headerMap, ['model', 'model_name']);
            $quantity = $this->mappedValue($row, $headerMap, ['quantity', 'qty']);
            $saleAmount = $this->mappedValue($row, $headerMap, ['sale_amount', 'amount']);
            $saleDate = $this->mappedValue($row, $headerMap, ['sale_date', 'date']);
            $warehouseName = $this->mappedValue($row, $headerMap, ['warehouse', 'warehouse_name']);
            $paymentStatus = $this->normalizeImportedPaymentStatus($this->mappedValue($row, $headerMap, ['payment_status']));
            $invoiceStatus = $this->normalizeImportedInvoiceStatus($this->mappedValue($row, $headerMap, ['invoice_status']));
            $deliveryStatus = $this->normalizeImportedDeliveryStatus($this->mappedValue($row, $headerMap, ['delivery_status']));
            $paidAmount = $this->mappedValue($row, $headerMap, ['paid_amount']);
            $deliveryDate = $this->mappedValue($row, $headerMap, ['delivery_date']);
            $notes = $this->mappedValue($row, $headerMap, ['notes']);
            $mapped = [
                'customer_phone' => $customerPhone,
                'customer_name' => $customerName,
                'product_name' => $productName,
                'brand' => $brand,
                'model' => $model,
                'model_name' => $model,
                'quantity' => $quantity,
                'sale_amount' => $saleAmount,
                'tax_type' => $this->mappedValue($row, $headerMap, ['tax_type']),
                'payment_status' => $paymentStatus,
                'paid_amount' => $paidAmount,
                'payment_date' => $this->mappedValue($row, $headerMap, ['payment_date']),
                'invoice_status' => $invoiceStatus,
                'delivery_status' => $deliveryStatus,
                'delivery_date' => $deliveryDate,
                'sale_date' => $saleDate,
                'warehouse' => $warehouseName,
                'warehouse_name' => $warehouseName,
                'shipping_charges' => $this->mappedValue($row, $headerMap, ['transportation_charge', 'shipping_charges', 'transport_charge']),
                'discount_amount' => $this->mappedValue($row, $headerMap, ['discount', 'discount_amount']),
                'tax_percentage' => $this->mappedValue($row, $headerMap, ['tax', 'tax_percentage']),
                'notes' => $notes,
            ];

            if ($customerPhone === '') {
                $errors[] = 'Customer Phone is required.';
            }

            $existingCustomer = $customerPhone !== ''
                ? $service->findCustomerForImportPayload(['phone' => $customerPhone, 'name' => $customerName], $this->orgId())
                : null;

            if (!$existingCustomer && trim($customerName) === '') {
                $errors[] = 'Customer Name is required when customer does not already exist.';
            }

            if ($productName === '') {
                $errors[] = 'Product Name is required.';
            }

            if ($quantity === '' || !is_numeric($quantity) || (float) $quantity <= 0) {
                $errors[] = 'Quantity must be a number greater than 0.';
            }

            if ($saleAmount === '' || !is_numeric($this->numericString($saleAmount))) {
                $errors[] = 'Sale Amount must be numeric.';
            }

            if ($saleDate === '' || !$this->isValidDateValue($saleDate)) {
                $errors[] = 'Sale Date is required and must be a valid date.';
            }

            $rawPaymentStatus = trim($this->mappedValue($row, $headerMap, ['payment_status']));
            if ($rawPaymentStatus !== '' && $paymentStatus === null) {
                $errors[] = 'Payment Status must be paid, partial, pending, or unpaid.';
            }

            $rawInvoiceStatus = trim($this->mappedValue($row, $headerMap, ['invoice_status']));
            if ($rawInvoiceStatus !== '' && $invoiceStatus === null) {
                $errors[] = 'Invoice Status must be generated, not_generated, paid, unpaid, partial, or pending.';
            }

            $rawDeliveryStatus = trim($this->mappedValue($row, $headerMap, ['delivery_status']));
            if ($rawDeliveryStatus !== '' && $deliveryStatus === null) {
                $errors[] = 'Delivery Status must be not_assigned, assigned, pending, or completed.';
            }

            $normalizedPaidAmount = $paidAmount === '' ? null : (float) $this->numericString($paidAmount);

            if ($paymentStatus === 'partial') {
                if ($normalizedPaidAmount === null || !is_numeric((string) $normalizedPaidAmount) || $normalizedPaidAmount <= 0) {
                    $errors[] = 'Paid Amount is required and must be greater than 0 when Payment Status is partial.';
                } elseif ($normalizedPaidAmount >= (float) $this->numericString($saleAmount)) {
                    $errors[] = 'Paid Amount must be less than Sale Amount when Payment Status is partial.';
                }
            }

            if ($paymentStatus === 'paid' && $normalizedPaidAmount !== null && abs($normalizedPaidAmount - (float) $this->numericString($saleAmount)) > 0.01) {
                $errors[] = 'Paid Amount must match Sale Amount when Payment Status is paid.';
            }

            if (in_array($paymentStatus, ['pending'], true) && $normalizedPaidAmount !== null && $normalizedPaidAmount > 0) {
                $errors[] = 'Paid Amount must be blank or 0 when Payment Status is pending.';
            }

            if ($deliveryDate !== '' && !$this->isValidDateValue($deliveryDate)) {
                $errors[] = 'Delivery Date must be a valid date when provided.';
            }

            $paymentDate = trim((string) ($mapped['payment_date'] ?? ''));
            if ($paymentDate !== '' && !$this->isValidDateValue($paymentDate)) {
                $errors[] = 'Payment Date must be a valid date when provided.';
            }

            $productMatch = $service->matchProductForImport($this->orgId(), [
                'product_name' => $productName,
                'brand' => $brand,
                'model_name' => $model,
            ]);
            $product = $productMatch['product'] ?? null;

            if ($productMatch['error']) {
                $errors[] = $productMatch['error'];
            }

            $warehouse = null;
            if ($warehouseName !== '') {
                $warehouse = $service->resolveWarehouseForImport($this->orgId(), ['warehouse_name' => $warehouseName], false);
                if (!$warehouse) {
                    $errors[] = 'Warehouse lookup failed. Use an existing active warehouse name.';
                }
            }

            $matchAction = 'create';
            if (empty($errors)) {
                $previewMatch = app(SaleController::class)->describeImportedSaleMatch([
                    'customer_id' => $existingCustomer?->id,
                    'product_id' => $product?->id,
                    'quantity' => (int) $quantity,
                    'sale_date' => $saleDate ? $this->normalizeImportDate($saleDate) : null,
                    'warehouse_id' => $warehouse?->id,
                    'payment_status' => $paymentStatus ?? 'pending',
                    'paid_amount' => $normalizedPaidAmount ?? 0.0,
                    'sale_amount' => (float) $this->numericString($saleAmount),
                ]);

                $matchAction = $previewMatch['action'] ?? 'create';

                if ($matchAction === 'review' && !empty($previewMatch['message'])) {
                    $errors[] = $previewMatch['message'];
                }
            }

            if ($product instanceof Product) {
                if (!$product->canSell()) {
                    $errors[] = $product->name.' is not configured as a sellable product.';
                } elseif ($matchAction !== 'update') {
                    if ($product->tracksSaleStock()) {
                        $availableUnits = Asset::query()
                            ->where('organization_id', $this->orgId())
                            ->where('product_id', $product->id)
                            ->where('asset_stage', Asset::STAGE_NEW_STOCK)
                            ->where('asset_status', 'available_for_sale')
                            ->when($warehouse, fn ($query) => $query->where('warehouse_id', $warehouse->id))
                            ->count();

                        if ($availableUnits < (int) $quantity) {
                            $errors[] = 'Tracked sale stock is not sufficient for this row.';
                        }
                    } elseif ((int) $product->available_quantity < (int) $quantity) {
                        $errors[] = 'Untracked available quantity is not sufficient for this row.';
                    }
                }
            }

            $normalizedDiscountAmount = trim((string) ($mapped['discount_amount'] ?? '')) === '' ? 0.0 : (float) $this->numericString((string) $mapped['discount_amount']);
            $normalizedShippingCharges = trim((string) ($mapped['shipping_charges'] ?? '')) === '' ? 0.0 : (float) $this->numericString((string) $mapped['shipping_charges']);
            $normalizedTaxPercentage = trim((string) ($mapped['tax_percentage'] ?? '')) === '' ? 0.0 : (float) $this->numericString((string) $mapped['tax_percentage']);

            $normalizedPayload = [
                'customer_phone' => $customerPhone,
                'customer_name' => $customerName,
                'product_id' => $product?->id,
                'product_name' => $productName,
                'brand' => $brand,
                'model_name' => $model,
                'warehouse_id' => $warehouse?->id,
                'warehouse_name' => $warehouseName,
                'quantity' => (int) $quantity,
                'sale_amount' => (float) $this->numericString($saleAmount),
                'unit_price' => ((int) $quantity > 0) ? round((((float) $this->numericString($saleAmount)) - $normalizedShippingCharges) / (int) $quantity, 2) : 0,
                'discount_amount' => $normalizedDiscountAmount,
                'shipping_charges' => $normalizedShippingCharges,
                'tax_percentage' => $normalizedTaxPercentage,
                'tax_calculation_mode' => 'exclusive',
                'payment_status' => $paymentStatus ?? 'pending',
                'paid_amount' => $normalizedPaidAmount ?? 0,
                'payment_date' => $paymentDate !== '' ? $this->normalizeImportDate($paymentDate) : null,
                'payment_method' => $this->mappedValue($row, $headerMap, ['payment_method']),
                'invoice_status' => $invoiceStatus ?? '',
                'delivery_status' => $deliveryStatus ?? '',
                'delivery_date' => $deliveryDate !== '' ? $this->normalizeImportDate($deliveryDate) : null,
                'sale_date' => $this->normalizeImportDate($saleDate),
                'notes' => trim($notes) !== '' ? $notes : 'Imported via Sales Import',
                'discount_amount_provided' => trim((string) ($mapped['discount_amount'] ?? '')) !== '',
                'shipping_charges_provided' => trim((string) ($mapped['shipping_charges'] ?? '')) !== '',
                'tax_percentage_provided' => trim((string) ($mapped['tax_percentage'] ?? '')) !== '',
                'payment_status_provided' => $rawPaymentStatus !== '',
                'paid_amount_provided' => trim((string) $paidAmount) !== '',
                'payment_date_provided' => $paymentDate !== '',
                'invoice_status_provided' => $rawInvoiceStatus !== '',
                'delivery_status_provided' => $rawDeliveryStatus !== '',
                'notes_provided' => trim((string) $notes) !== '',
                'tax_calculation_mode_provided' => false,
            ];

            if (empty($errors)) {
                $normalizedPayload['import_action'] = $matchAction;
                $mapped['import_action'] = ucfirst((string) $matchAction);
            }

            if (empty($errors)) {
                $validCount++;
            } else {
                $invalidCount++;
            }

            $previewRows[] = [
                'row_number' => $index + 2,
                'values' => $mapped,
                'payload' => $normalizedPayload,
                'errors' => $errors,
                'valid' => empty($errors),
            ];
        }

        return [
            'headers' => $dataset['headers'],
            'preview_rows' => array_slice($previewRows, 0, 20),
            'valid_rows' => array_values(array_filter($previewRows, fn ($row) => !empty($row['valid']))),
            'invalid_rows' => array_values(array_filter($previewRows, fn ($row) => empty($row['valid']))),
            'valid_count' => $validCount,
            'invalid_count' => $invalidCount,
            'total_rows' => count($previewRows),
        ];
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

    private function normalizeImportedDeliveryStatus(?string $value): ?string
    {
        return match (Str::lower(trim((string) $value))) {
            '' => '',
            'not_assigned' => 'not_assigned',
            'assigned' => 'assigned',
            'pending' => 'pending',
            'completed', 'delivered' => 'completed',
            default => null,
        };
    }

    private function normalizeImportDate(string $value): string
    {
        if (is_numeric($value) && (float) $value > 1000) {
            return Carbon::createFromTimestampUTC(((int) $value - 25569) * 86400)->toDateString();
        }

        return Carbon::parse($value)->toDateString();
    }

    private function resolveSalesImportCustomer(array $payload): Customer
    {
        $phone = preg_replace('/[^0-9]+/', '', (string) ($payload['customer_phone'] ?? '')) ?: null;
        $name = trim((string) ($payload['customer_name'] ?? ''));

        $existing = Customer::query()
            ->where('organization_id', $this->orgId())
            ->where('phone', $phone)
            ->first();

        if ($existing) {
            return $existing;
        }

        if ($phone === null || $name === '') {
            throw ValidationException::withMessages([
                'customer' => 'Customer could not be created because phone or customer name is missing.',
            ]);
        }

        return Customer::create([
            'organization_id' => $this->orgId(),
            'name' => $name,
            'phone' => $phone,
        ]);
    }

    private function renderSalesPreview(array $preview)
    {
        $result = $preview['import_result'] ?? null;

        return view('imports.preview', [
            'title' => 'Sales Import Preview',
            'subtitle' => 'Review the first 20 rows and fix validation errors before import is enabled.',
            'headers' => array_keys($preview['preview_rows'][0]['values'] ?? []),
            'previewRows' => $preview['preview_rows'] ?? [],
            'validCount' => $preview['valid_count'] ?? 0,
            'invalidCount' => $preview['invalid_count'] ?? 0,
            'totalRows' => $preview['total_rows'] ?? 0,
            'requiredFields' => ['Customer Phone', 'Product Name', 'Quantity', 'Sale Amount', 'Sale Date'],
            'backUrl' => route('imports.sales.upload'),
            'uploadNewUrl' => route('imports.sales.upload'),
            'templateUrl' => route('imports.template', 'sales'),
            'importButtonLabel' => !empty($preview['imported_at']) ? 'Import Completed' : 'Import Valid Rows',
            'importHelperText' => !empty($preview['imported_at'])
                ? 'This preview has already been imported. Duplicate import is blocked.'
                : 'Only valid rows will be imported. Invalid rows will be skipped.',
            'previewColumns' => [
                'import_action',
                'customer_phone',
                'customer_name',
                'product_name',
                'brand',
                'model',
                'quantity',
                'sale_amount',
                'tax_type',
                'payment_status',
                'paid_amount',
                'payment_date',
                'invoice_status',
                'delivery_status',
                'delivery_date',
                'sale_date',
                'warehouse',
                'notes',
            ],
            'fieldLabels' => [
                'import_action' => 'Action',
                'customer_phone' => 'Customer Phone',
                'customer_name' => 'Customer Name',
                'product_name' => 'Product',
                'brand' => 'Brand',
                'model' => 'Model',
                'quantity' => 'Quantity',
                'sale_amount' => 'Sale Amount',
                'tax_type' => 'Tax Type',
                'payment_status' => 'Payment Status',
                'paid_amount' => 'Paid Amount',
                'payment_date' => 'Payment Date',
                'invoice_status' => 'Invoice Status',
                'delivery_status' => 'Delivery Status',
                'delivery_date' => 'Delivery Date',
                'sale_date' => 'Sale Date',
                'warehouse' => 'Warehouse',
                'notes' => 'Notes',
            ],
            'importAction' => route('imports.sales.execute'),
            'importEnabled' => !empty($preview['valid_count']) && empty($preview['imported_at']),
            'runtimeErrors' => $preview['runtime_errors'] ?? [],
            'lastResult' => $result ? [
                'processed' => $result['processed'] ?? 0,
                'created' => $result['imported'] ?? 0,
                'updated' => $result['updated'] ?? 0,
                'skipped' => $result['skipped'] ?? 0,
            ] : null,
        ]);
    }

    private function buildOpeningBalancePreview(array $dataset): array
    {
        $headerMap = $this->headerMap($dataset['headers']);
        $previewRows = [];
        $validCount = 0;
        $invalidCount = 0;

        foreach ($dataset['rows'] as $index => $row) {
            $errors = [];
            $customerPhone = $this->mappedValue($row, $headerMap, ['customer_phone', 'phone']);
            $openingBalance = $this->mappedValue($row, $headerMap, ['opening_balance', 'balance']);

            if ($customerPhone === '') {
                $errors[] = 'Customer Phone is required.';
            }

            if ($openingBalance === '' || !is_numeric($this->numericString($openingBalance))) {
                $errors[] = 'Opening Balance is required and must be numeric.';
            }

            if (empty($errors)) {
                $validCount++;
            } else {
                $invalidCount++;
            }

            $previewRows[] = [
                'row_number' => $index + 2,
                'values' => $row,
                'errors' => $errors,
                'valid' => empty($errors),
            ];
        }

        return [
            'headers' => $dataset['headers'],
            'preview_rows' => array_slice($previewRows, 0, 20),
            'valid_count' => $validCount,
            'invalid_count' => $invalidCount,
            'total_rows' => count($previewRows),
        ];
    }

    private function parseSpreadsheet(UploadedFile $file): array
    {
        $extension = strtolower((string) $file->getClientOriginalExtension());

        return match ($extension) {
            'csv', 'txt' => $this->parseCsv($file->getRealPath()),
            'xlsx' => $this->parseXlsx($file->getRealPath()),
            default => throw new RuntimeException('Only CSV and XLSX files are supported.'),
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

    private function columnIndexFromReference(string $reference): int
    {
        $letters = preg_replace('/[^A-Z]/i', '', strtoupper($reference));
        $index = 0;

        for ($i = 0; $i < strlen($letters); $i++) {
            $index = ($index * 26) + (ord($letters[$i]) - 64);
        }

        return max($index - 1, 0);
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

    private function associateRow(array $headers, array $row): array
    {
        $assoc = [];

        foreach ($headers as $index => $header) {
            $assoc[$header] = isset($row[$index]) ? trim((string) $row[$index]) : null;
        }

        return $assoc;
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

    private function headerMap(array $headers): array
    {
        $map = [];

        foreach ($headers as $header) {
            $map[$this->slugKey($header)] = $header;
        }

        return $map;
    }

    private function mappedValue(array $row, array $headerMap, array $aliases): string
    {
        foreach ($aliases as $alias) {
            $key = $this->slugKey($alias);

            if (isset($headerMap[$key])) {
                return trim((string) ($row[$headerMap[$key]] ?? ''));
            }
        }

        return '';
    }

    private function slugKey(?string $value): string
    {
        return Str::of((string) $value)->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->toString();
    }

    private function numericString(string $value): string
    {
        return preg_replace('/[^0-9.\-]+/', '', $value) ?: '';
    }

    private function isValidDateValue(string $value): bool
    {
        if (trim($value) === '') {
            return false;
        }

        if (is_numeric($value) && (float) $value > 1000) {
            return true;
        }

        try {
            Carbon::parse($value);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }
}
