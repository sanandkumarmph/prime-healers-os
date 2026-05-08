<?php

namespace App\Http\Controllers;

use Illuminate\Http\Response;

class ImportTemplateController extends Controller
{
    private function canAccessDataImport(): bool
    {
        $user = auth()->user();

        return $user?->isSuperAdmin() ?? false;
    }

    private function templates(): array
    {
        return [
            'customers' => [
                'label' => 'Customer Import',
                'download_label' => 'Download Customer Template',
                'fields' => ['Name', 'Customer Type', 'Phone', 'WhatsApp Number', 'Email', 'Company Name', 'Contact Name', 'GSTIN', 'Address', 'City', 'State', 'Pincode', 'Patient Name', 'Notes'],
                'note' => null,
                'upload_available' => true,
                'upload_href' => route('imports.module', 'customers'),
                'filename' => 'customers-import-template.csv',
                'rows' => [
                    ['name', 'customer_type', 'phone', 'whatsapp_number', 'email', 'company_name', 'contact_name', 'gst_number', 'address', 'city', 'state', 'pincode', 'patient_name', 'notes'],
                    ['Mr. Ganesha S', 'Individual', '8627835757', '8627835757', 'ganesh@example.com', '', '', 'N/A', '#36, 14th Cross, Bengaluru', 'Bengaluru', 'Karnataka', '560078', '', 'Home-care oxygen customer'],
                    ['Care Plus Clinic', 'Business', '9810012345', '9810012345', 'ops@careplus.example', 'Care Plus Clinic', 'Ananya Rao', '29ABCDE1234F1Z5', '12, Sector 18', 'Noida', 'Uttar Pradesh', '201301', 'Rahul Verma', 'Corporate respiratory account'],
                ],
            ],
            'products' => [
                'label' => 'Product Master Import',
                'download_label' => 'Download Product Template',
                'fields' => ['Product Name', 'Category', 'Brand', 'Model Name', 'SKU', 'Product Code', 'Sellable', 'Rentable', 'Stock Mode', 'Sale Price', 'Rental Price', 'Deposit'],
                'note' => null,
                'upload_available' => true,
                'upload_href' => route('imports.module', 'products'),
                'filename' => 'product-master-import-template.csv',
                'rows' => [
                    ['product_name', 'category', 'brand', 'model_name', 'sku', 'product_code', 'sellable', 'rentable', 'stock_mode', 'sale_price', 'rental_price', 'deposit'],
                    ['Oxygen Concentrator 5 LPM', 'Respiratory', 'Philips', 'SimplyGo', 'OC5L-SG', 'OXY-5L', 'No', 'Yes', 'tracked_rental', '', '4500', '5000'],
                    ['BiPAP Disposable Filter', 'Consumables', 'ResMed', 'Filter Pack', 'BF-180', 'BIPAP-FLTR', 'Yes', 'No', 'untracked', '180', '', '0'],
                ],
            ],
            'assets' => [
                'label' => 'Asset Register Import',
                'download_label' => 'Download Asset Template',
                'fields' => ['Product Name', 'Brand', 'Model Name', 'Asset Type', 'Serial Number', 'Barcode', 'Warehouse', 'Condition', 'Status', 'Purchase Date', 'Purchase Cost'],
                'note' => 'Serial can be blank if unavailable; system will generate pending serial during import later.',
                'upload_available' => true,
                'upload_href' => route('imports.module', 'assets'),
                'filename' => 'asset-register-import-template.csv',
                'rows' => [
                    ['product_name', 'brand', 'model_name', 'asset_type', 'serial_number', 'barcode', 'warehouse', 'condition', 'status', 'purchase_date', 'purchase_cost'],
                    ['BiPAP Machine', 'ResMed', 'AirCurve 10', 'rental_stock', '', '', 'Main Warehouse', 'good', 'available', '2025-01-10', '45000'],
                    ['Oxygen Concentrator 5 LPM', 'Oxymed', 'Mini', 'new_stock', 'OXYMINI-001', 'OXYMINI-001', 'Main Warehouse', 'good', 'available_for_sale', '2025-02-01', '32000'],
                ],
            ],
            'rentals' => [
                'label' => 'Rental Import',
                'download_label' => 'Download Rental Template',
                'fields' => ['Customer Phone', 'Customer Name', 'Product Name', 'Brand', 'Model', 'Quantity', 'Start Date', 'End Date', 'Rental Amount', 'Deposit', 'Transport', 'Status', 'Payment Status', 'Paid Amount', 'Invoice Status', 'Delivery Status', 'Delivery Date', 'Pickup Status', 'Pickup Date', 'Warehouse', 'Notes'],
                'note' => null,
                'upload_available' => true,
                'upload_href' => route('imports.module', 'rentals'),
                'filename' => 'rental-import-template.csv',
                'rows' => [
                    ['customer_phone', 'customer_name', 'product_name', 'brand', 'model', 'quantity', 'start_date', 'end_date', 'rental_amount', 'deposit', 'transport', 'status', 'payment_status', 'paid_amount', 'invoice_status', 'delivery_status', 'delivery_date', 'pickup_status', 'pickup_date', 'warehouse', 'notes'],
                    ['9876543210', 'Aarav Sharma', 'Oxygen Concentrator 5 LPM', 'Philips', 'SimplyGo', '1', '2026-05-01', '2026-05-15', '4500', '5000', '350', 'Active', 'pending', '', 'generated', 'assigned', '2026-05-01', 'not_assigned', '', 'Main Warehouse', 'Imported active rental'],
                ],
            ],
            'sales' => [
                'label' => 'Sales Import',
                'download_label' => 'Download Sales Template',
                'fields' => ['Customer Phone', 'Customer Name', 'Product Name', 'Brand', 'Model', 'Quantity', 'Sale Amount', 'Tax Type', 'Payment Status', 'Paid Amount', 'Invoice Status', 'Delivery Status', 'Delivery Date', 'Sale Date', 'Warehouse', 'Notes'],
                'note' => null,
                'upload_available' => true,
                'upload_href' => route('imports.sales.upload'),
                'filename' => 'sales-import-template.csv',
                'rows' => [
                    ['customer_phone', 'customer_name', 'product_name', 'brand', 'model', 'quantity', 'sale_amount', 'tax_type', 'payment_status', 'paid_amount', 'invoice_status', 'delivery_status', 'delivery_date', 'sale_date', 'warehouse', 'notes'],
                    ['9810012345', 'Care Plus Clinic', 'BiPAP Disposable Filter', 'ResMed', 'Filter Pack', '5', '900', 'exclusive', 'pending', '', 'generated', 'not_assigned', '', '2026-05-01', 'Main Warehouse', 'Imported pending sale'],
                ],
            ],
            'opening-balances' => [
                'label' => 'Opening Balance Import',
                'download_label' => 'Download Opening Balance Template',
                'fields' => ['Customer Phone', 'Opening Balance', 'Balance Type', 'Notes'],
                'note' => null,
                'upload_available' => true,
                'upload_href' => route('imports.opening-balances.upload'),
                'filename' => 'opening-balance-import-template.csv',
                'rows' => [
                    ['customer_phone', 'opening_balance', 'balance_type', 'notes'],
                    ['9876543210', '3200', 'receivable', 'Legacy opening balance as of 2026-04-30'],
                ],
            ],
        ];
    }

    public function index()
    {
        $this->authorize('access', ImportController::class);
        abort_unless($this->canAccessDataImport(), 403);

        $cards = collect($this->templates())
            ->map(function (array $template, string $key) {
                $template['key'] = $key;
                $template['download_href'] = route('imports.templates.' . $key);

                return $template;
            })
            ->values();

        return view('import.index', compact('cards'));
    }

    public function download(string $template)
    {
        $this->authorize('access', ImportController::class);
        abort_unless($this->canAccessDataImport(), 403);

        $definition = $this->templates()[$template] ?? null;
        abort_unless($definition, 404);

        return response()->streamDownload(function () use ($definition) {
            $output = fopen('php://output', 'w');

            foreach ($definition['rows'] as $row) {
                fputcsv($output, $row);
            }

            fclose($output);
        }, $definition['filename'], [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
