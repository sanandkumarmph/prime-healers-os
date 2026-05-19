<?php

use App\Http\Controllers\AssetController;
use App\Http\Controllers\CityController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\BusinessPartnerController;
use App\Http\Controllers\DeliveryController;
use App\Http\Controllers\GlobalSearchController;
use App\Http\Controllers\InventoryDashboardController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\ImportTemplateController;
use App\Http\Controllers\KnowledgeController;
use App\Http\Controllers\OrganizationSettingsController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PartnerClientController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RentalController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\StaffController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VendorController;
use App\Http\Controllers\WarehouseController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
});

Route::get('/dashboard', [RentalController::class, 'dashboard'])
    ->middleware([
        'auth',
        'verified',
        'permission:dashboard.main',
    ])
    ->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/knowledge', [KnowledgeController::class, 'index'])->name('knowledge.index');
    Route::get('/knowledge/articles/{article}', [KnowledgeController::class, 'article'])->name('knowledge.articles.show');
    Route::get('/knowledge/categories/{category}', [KnowledgeController::class, 'category'])->name('knowledge.categories.show');

    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::post('/products/{product}/convert-to-rental', [ProductController::class, 'convertToRental'])
        ->middleware('module:products,update')
        ->name('products.convert-to-rental');
    Route::post('/products/{product}/convert-to-sellable', [ProductController::class, 'convertToSellable'])
        ->middleware('module:products,update')
        ->name('products.convert-to-sellable');
    Route::get('/products/export/csv', [ProductController::class, 'exportCsv'])
        ->middleware('module:products,read')
        ->name('products.export.csv');
    Route::get('/assets/export/csv', [AssetController::class, 'exportCsv'])
        ->middleware('module:assets,read')
        ->name('assets.export.csv');

    Route::get('/customers/export/csv', [CustomerController::class, 'exportCsv'])
        ->middleware('permission:customers.export')
        ->name('customers.export.csv');
    Route::get('/search', [GlobalSearchController::class, 'index'])
        ->name('search.global');
    Route::get('/customers/{id}/id-proof', [CustomerController::class, 'downloadIdProof'])
        ->middleware('permission:customers.proof.download')
        ->name('customers.id-proof.download');
    Route::post('/customers/bulk/export-csv', [CustomerController::class, 'bulkExportCsv'])
        ->middleware('permission:customers.export')
        ->name('customers.bulk.export.csv');
    Route::delete('/customers/bulk/delete', [CustomerController::class, 'bulkDelete'])
        ->middleware('module:customers,delete')
        ->name('customers.bulk.delete');
    Route::post('/customers/quick-store', [CustomerController::class, 'quickStore'])
        ->middleware('module:customers,create')
        ->name('customers.quick-store');
    Route::post('/rentals/customers/quick-store', [CustomerController::class, 'quickStore'])
        ->middleware('module:customers,create')
        ->name('rentals.customers.quick-store');
    Route::post('/sales/customers/quick-store', [CustomerController::class, 'quickStore'])
        ->middleware('module:customers,create')
        ->name('sales.customers.quick-store');
    Route::post('/invoices/customers/quick-store', [CustomerController::class, 'quickStore'])
        ->middleware('module:customers,create')
        ->name('invoices.customers.quick-store');

    Route::get('/rentals/available-assets', [RentalController::class, 'availableAssets'])
        ->middleware('module:rentals,read')
        ->name('rentals.available-assets');
    Route::get('/rentals/export/csv', [RentalController::class, 'exportCsv'])
        ->middleware('module:rentals,read')
        ->name('rentals.export.csv');
    Route::post('/rentals/{rental}/quick-renew', [RentalController::class, 'quickRenew'])
        ->middleware('module:rentals,update')
        ->name('rentals.quick-renew');
    Route::post('/rentals/{rental}/renew', [RentalController::class, 'storeRenewal'])
        ->middleware('module:rentals,update')
        ->name('rentals.renew');
    Route::put('/rentals/{rental}/renewals/{renewal}', [RentalController::class, 'updateRenewal'])
        ->middleware('module:rentals,update')
        ->name('rentals.renewals.update');
    Route::delete('/rentals/{rental}/renewals/{renewal}', [RentalController::class, 'destroyRenewal'])
        ->middleware('module:rentals,update')
        ->name('rentals.renewals.destroy');
    Route::post('/rentals/{rental}/renewals/{renewal}/invoice', [RentalController::class, 'generateRenewalInvoice'])
        ->middleware('module:rentals,update')
        ->name('rentals.renewals.invoice');
    Route::get('/rentals/{rental}/reminders/{type}', [RentalController::class, 'openReminder'])
        ->middleware('module:rentals,read')
        ->name('rentals.reminders.open');
    Route::get('/dashboard/export/csv', [RentalController::class, 'exportDashboardCsv'])
        ->middleware('permission:dashboard.main')
        ->name('dashboard.export.csv');
    Route::put('/rentals/{rental}/return', [RentalController::class, 'returnRental'])
        ->middleware('module:rentals,update')
        ->name('rentals.return');
    Route::put('/rentals/{rental}/cancel', [RentalController::class, 'cancel'])
        ->middleware('module:rentals,update')
        ->name('rentals.cancel');
    Route::delete('/rentals/{rental}/delivery-assignment', [RentalController::class, 'clearDeliveryAssignment'])
        ->middleware('module:rentals,update')
        ->name('rentals.delivery-assignment.clear');

    Route::get('/payments/export/csv', [PaymentController::class, 'exportCsv'])
        ->middleware('permission:payments.export')
        ->name('payments.export.csv');
    Route::get('/rentals/{rental}/payments/create', [PaymentController::class, 'create'])
        ->middleware('module:payments,create')
        ->name('payments.create');
    Route::post('/rentals/{rental}/payments', [PaymentController::class, 'store'])
        ->middleware('module:payments,create')
        ->name('payments.store');
    Route::post('/invoices/{invoice}/payments', [PaymentController::class, 'storeForInvoice'])
        ->middleware('module:payments,create')
        ->name('invoices.payments.store');
    Route::delete('/payments/{payment}', [PaymentController::class, 'destroy'])
        ->middleware('module:payments,delete')
        ->name('payments.destroy');

    Route::get('/deliveries/assigned', [DeliveryController::class, 'assigned'])
        ->middleware('module:deliveries,read')
        ->name('deliveries.assigned');
    Route::get('/pickups', function () {
        $query = request()->query();
        $query['tab'] = $query['tab'] ?? 'pickups';
        $query['task_type'] = $query['task_type'] ?? 'pickup';

        return redirect()->route('deliveries.index', array_filter($query, fn ($value) => $value !== null && $value !== ''));
    })
        ->middleware('module:deliveries,read')
        ->name('pickups.index');
    Route::get('/pickups/assigned', [DeliveryController::class, 'assignedPickups'])
        ->middleware('module:deliveries,read')
        ->name('pickups.assigned');
    Route::put('/deliveries/{delivery}/in-progress', [DeliveryController::class, 'markInProgress'])
        ->middleware('module:deliveries,update')
        ->name('deliveries.in_progress');
    Route::put('/deliveries/{delivery}/partial-delivery', [DeliveryController::class, 'recordPartialDelivery'])
        ->middleware('module:deliveries,update')
        ->name('deliveries.partial_delivery');
    Route::put('/deliveries/{delivery}/partial-pickup', [DeliveryController::class, 'recordPartialPickup'])
        ->middleware('module:deliveries,update')
        ->name('deliveries.partial_pickup');
    Route::put('/deliveries/{delivery}/complete', [DeliveryController::class, 'markCompleted'])
        ->middleware('module:deliveries,update')
        ->name('deliveries.complete');
    Route::put('/deliveries/{delivery}/cancel', [DeliveryController::class, 'cancel'])
        ->middleware('module:deliveries,update')
        ->name('deliveries.cancel');
    Route::get('/deliveries/{delivery}/proofs/{proof}', [DeliveryController::class, 'viewProof'])
        ->middleware('module:deliveries,read')
        ->name('deliveries.proofs.view');

    Route::get('/staff/export/csv', [StaffController::class, 'exportCsv'])
        ->middleware('module:users,read')
        ->name('staff.export.csv');
    Route::get('/reports', [ReportController::class, 'index'])
        ->middleware('module:reports,read')
        ->name('reports.index');
    Route::get('/reports/export/csv', [ReportController::class, 'exportCsv'])
        ->middleware('module:reports,read')
        ->name('reports.export.csv');

    Route::middleware('data_import')->group(function () {
        Route::get('/imports', [ImportTemplateController::class, 'index'])
            ->name('imports.index');
        Route::get('/imports/templates/customers', [ImportTemplateController::class, 'download'])
            ->defaults('template', 'customers')
            ->name('imports.templates.customers');
        Route::get('/imports/templates/products', [ImportTemplateController::class, 'download'])
            ->defaults('template', 'products')
            ->name('imports.templates.products');
        Route::get('/imports/templates/assets', [ImportTemplateController::class, 'download'])
            ->defaults('template', 'assets')
            ->name('imports.templates.assets');
        Route::get('/imports/templates/rentals', [ImportTemplateController::class, 'download'])
            ->defaults('template', 'rentals')
            ->name('imports.templates.rentals');
        Route::get('/imports/templates/sales', [ImportTemplateController::class, 'download'])
            ->defaults('template', 'sales')
            ->name('imports.templates.sales');
        Route::get('/imports/templates/opening-balances', [ImportTemplateController::class, 'download'])
            ->defaults('template', 'opening-balances')
            ->name('imports.templates.opening-balances');
        Route::get('/imports/sales/upload', [ImportController::class, 'salesUpload'])
            ->name('imports.sales.upload');
        Route::get('/imports/sales/preview', [ImportController::class, 'salesPreviewPage'])
            ->name('imports.sales.preview.page');
        Route::post('/imports/sales/preview', [ImportController::class, 'salesPreview'])
            ->name('imports.sales.preview');
        Route::post('/imports/sales/execute', [ImportController::class, 'salesExecute'])
            ->name('imports.sales.execute');
        Route::get('/imports/opening-balances/upload', [ImportController::class, 'openingBalanceUpload'])
            ->name('imports.opening-balances.upload');
        Route::post('/imports/opening-balances/preview', [ImportController::class, 'openingBalancePreview'])
            ->name('imports.opening-balances.preview');
        Route::get('/imports/{module}', [ImportController::class, 'show'])
            ->name('imports.module');
        Route::post('/imports/{module}/reset', [ImportController::class, 'reset'])
            ->name('imports.reset');
        Route::post('/imports/{module}/upload', [ImportController::class, 'upload'])
            ->name('imports.upload');
        Route::get('/imports/{module}/mapping', [ImportController::class, 'mapping'])
            ->name('imports.mapping');
        Route::post('/imports/{module}/preview', [ImportController::class, 'buildPreview'])
            ->name('imports.preview.build');
        Route::get('/imports/{module}/preview', [ImportController::class, 'preview'])
            ->name('imports.preview');
        Route::post('/imports/{module}/execute', [ImportController::class, 'execute'])
            ->name('imports.execute');
        Route::get('/imports/{module}/template', [ImportController::class, 'template'])
            ->name('imports.template');
        Route::get('/imports/{module}/error-report', [ImportController::class, 'errorReport'])
            ->name('imports.error-report');
    });

    Route::get('/organization/settings', [OrganizationSettingsController::class, 'edit'])
        ->middleware('module:settings,read')
        ->name('organization.settings.edit');
    Route::put('/organization/settings', [OrganizationSettingsController::class, 'update'])
        ->middleware('module:settings,update')
        ->name('organization.settings.update');

    Route::get('/inventory', [InventoryDashboardController::class, 'index'])
        ->middleware('permission:dashboard.inventory')
        ->name('inventory.dashboard');
    Route::get('/assets/scan-lookup', [AssetController::class, 'scanLookup'])
        ->middleware('module:assets,read')
        ->name('assets.scan-lookup');
    Route::get('/assets/pending-verification', [AssetController::class, 'pendingVerification'])
        ->middleware('module:assets,read')
        ->name('assets.pending-verification');
    Route::get('/assets/{asset}/verify-return', [AssetController::class, 'verifyReturn'])
        ->middleware('module:assets,update')
        ->name('assets.verify-return');
    Route::put('/assets/{asset}/verify-return', [AssetController::class, 'storeReturnVerification'])
        ->middleware('module:assets,update')
        ->name('assets.verify-return.store');
    Route::get('/assets/{asset}/transfer', [AssetController::class, 'transfer'])
        ->middleware('module:assets,update')
        ->name('assets.transfer');
    Route::post('/assets/{asset}/transfer', [AssetController::class, 'storeTransfer'])
        ->middleware('module:assets,update')
        ->name('assets.transfer.store');

    Route::get('/invoices/export/csv', [InvoiceController::class, 'exportCsv'])
        ->middleware('permission:invoices.export')
        ->name('invoices.export.csv');
    Route::post('/invoices/bulk/export-csv', [InvoiceController::class, 'bulkExportCsv'])
        ->middleware('permission:invoices.export')
        ->name('invoices.bulk.export.csv');
    Route::match(['get', 'post'], '/invoices/bulk/print', [InvoiceController::class, 'bulkPrint'])
        ->middleware('permission:invoices.print')
        ->name('invoices.bulk.print');
    Route::post('/invoices/bulk/action', [InvoiceController::class, 'bulkAction'])
        ->middleware('module:invoices,update')
        ->name('invoices.bulk.action');
    Route::post('/invoices/bulk/delete', [InvoiceController::class, 'bulkDelete'])
        ->middleware('module:invoices,delete')
        ->name('invoices.bulk.delete');
    Route::get('/invoices/{id}/print', [InvoiceController::class, 'print'])
        ->middleware('permission:invoices.print')
        ->name('invoices.print');
    Route::post('/invoices/{id}/mark-paid', [InvoiceController::class, 'markPaid'])
        ->middleware('module:invoices,update')
        ->name('invoices.markPaid');
    Route::put('/invoices/{id}/void', [InvoiceController::class, 'voidInvoice'])
        ->middleware('module:invoices,update')
        ->name('invoices.void');

    $products = Route::resource('products', ProductController::class)
        ->middleware('module:products,read');
    $products->middlewareFor(['create', 'store'], 'module:products,create');
    $products->middlewareFor(['edit', 'update'], 'module:products,update');
    $products->middlewareFor('destroy', 'module:products,delete');

    $customers = Route::resource('customers', CustomerController::class)
        ->middleware('module:customers,read');
    $customers->middlewareFor(['create', 'store'], 'module:customers,create');
    $customers->middlewareFor(['edit', 'update'], 'module:customers,update');
    $customers->middlewareFor('destroy', 'module:customers,delete');

    $businessPartners = Route::resource('business-partners', BusinessPartnerController::class)
        ->middleware('module:customers,read');
    $businessPartners->middlewareFor(['create', 'store'], 'module:customers,create');
    $businessPartners->middlewareFor(['edit', 'update'], 'module:customers,update');
    $businessPartners->middlewareFor('destroy', 'module:customers,delete');
    Route::get('/business-partners/{business_partner}/clients/create', [PartnerClientController::class, 'create'])
        ->middleware('module:customers,create')
        ->name('business-partners.clients.create');
    Route::post('/business-partners/{business_partner}/clients', [PartnerClientController::class, 'store'])
        ->middleware('module:customers,create')
        ->name('business-partners.clients.store');
    Route::get('/business-partners/{business_partner}/clients/{partner_client}/edit', [PartnerClientController::class, 'edit'])
        ->middleware('module:customers,update')
        ->name('business-partners.clients.edit');
    Route::put('/business-partners/{business_partner}/clients/{partner_client}', [PartnerClientController::class, 'update'])
        ->middleware('module:customers,update')
        ->name('business-partners.clients.update');
    Route::delete('/business-partners/{business_partner}/clients/{partner_client}', [PartnerClientController::class, 'destroy'])
        ->middleware('module:customers,delete')
        ->name('business-partners.clients.destroy');

    $rentals = Route::resource('rentals', RentalController::class)
        ->middleware('module:rentals,read');
    $rentals->middlewareFor(['create', 'store'], 'module:rentals,create');
    $rentals->middlewareFor(['edit', 'update'], 'module:rentals,update');
    $rentals->middlewareFor('destroy', 'module:rentals,delete');
    Route::post('/rentals/{rental}/invoice', [RentalController::class, 'generateInvoice'])
        ->middleware('module:rentals,update')
        ->name('rentals.invoice');
    Route::post('/rentals/{rental}/mark-paid', [RentalController::class, 'markPaid'])
        ->middleware('module:payments,create')
        ->name('rentals.markPaid');
    Route::post('/rentals/{rental}/record-payment', [RentalController::class, 'recordPayment'])
        ->middleware('module:payments,create')
        ->name('rentals.recordPayment');

    $deliveries = Route::resource('deliveries', DeliveryController::class)
        ->middleware('module:deliveries,read');
    $deliveries->middlewareFor(['create', 'store'], 'module:deliveries,create');
    $deliveries->middlewareFor(['edit', 'update'], 'module:deliveries,update');
    $deliveries->middlewareFor('destroy', 'module:deliveries,delete');

    $staff = Route::resource('staff', StaffController::class)
        ->parameters(['staff' => 'id'])
        ->middleware('module:users,read');
    $staff->middlewareFor(['create', 'store'], 'module:users,create');
    $staff->middlewareFor(['edit', 'update'], 'module:users,update');
    $staff->middlewareFor('destroy', 'module:users,delete');

    $sales = Route::resource('sales', SaleController::class)
        ->middleware('module:sales,read');
    $sales->middlewareFor(['create', 'store'], 'module:sales,create');
    $sales->middlewareFor(['edit', 'update'], 'module:sales,update');
    $sales->middlewareFor('destroy', 'module:sales,delete');
    Route::post('/sales/{sale}/invoice', [SaleController::class, 'generateInvoice'])
        ->middleware('module:sales,update')
        ->name('sales.invoice');
    Route::post('/sales/{sale}/mark-paid', [SaleController::class, 'markPaid'])
        ->middleware('module:payments,create')
        ->name('sales.markPaid');
    Route::post('/sales/{sale}/record-payment', [SaleController::class, 'recordPayment'])
        ->middleware('module:payments,create')
        ->name('sales.recordPayment');
    Route::put('/sales/{sale}/void', [SaleController::class, 'voidSale'])
        ->middleware('module:sales,update')
        ->name('sales.void');

    $users = Route::resource('/organization/users', UserController::class)
        ->parameters(['users' => 'user'])
        ->names('users')
        ->middleware('module:users,read');
    $users->middlewareFor(['create', 'store'], 'module:users,create');
    $users->middlewareFor(['edit', 'update'], 'module:users,update');
    $users->middlewareFor('destroy', 'module:users,delete');

    $roles = Route::resource('/organization/roles', RoleController::class)
        ->parameters(['roles' => 'role'])
        ->names('roles')
        ->middleware('module:roles,read');
    $roles->middlewareFor(['create', 'store'], 'module:roles,create');
    $roles->middlewareFor(['edit', 'update'], 'module:roles,update');
    $roles->middlewareFor('destroy', 'module:roles,delete');

    $cities = Route::resource('/organization/cities', CityController::class)
        ->parameters(['cities' => 'city'])
        ->names('cities')
        ->middleware('module:cities,read');
    $cities->middlewareFor(['create', 'store'], 'module:cities,create');
    $cities->middlewareFor(['edit', 'update'], 'module:cities,update');
    $cities->middlewareFor('destroy', 'module:cities,delete');

    $vendors = Route::resource('/organization/vendors', VendorController::class)
        ->parameters(['vendors' => 'vendor'])
        ->names('vendors')
        ->middleware('module:vendors,read');
    $vendors->middlewareFor(['create', 'store'], 'module:vendors,create');
    $vendors->middlewareFor(['edit', 'update'], 'module:vendors,update');
    $vendors->middlewareFor('destroy', 'module:vendors,delete');

    $assets = Route::resource('assets', AssetController::class)
        ->middleware('module:assets,read');
    $assets->middlewareFor(['create', 'store'], 'module:assets,create');
    $assets->middlewareFor(['edit', 'update'], 'module:assets,update');
    $assets->middlewareFor('destroy', 'module:assets,delete');

    $warehouses = Route::resource('warehouses', WarehouseController::class)
        ->middleware('module:warehouses,read');
    $warehouses->middlewareFor(['create', 'store'], 'module:warehouses,create');
    $warehouses->middlewareFor(['edit', 'update'], 'module:warehouses,update');
    $warehouses->middlewareFor('destroy', 'module:warehouses,delete');

    $invoices = Route::resource('invoices', InvoiceController::class)
        ->parameters(['invoices' => 'id'])
        ->only(['index', 'create', 'store', 'show', 'edit', 'update', 'destroy'])
        ->middleware('module:invoices,read');
    $invoices->middlewareFor(['create', 'store'], 'module:invoices,create');
    $invoices->middlewareFor(['edit', 'update'], 'module:invoices,update');
    $invoices->middlewareFor('destroy', 'module:invoices,delete');
});

require __DIR__ . '/auth.php';
