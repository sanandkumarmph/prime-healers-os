<?php

namespace App\Services;

use App\Http\Controllers\RentalController;
use App\Http\Controllers\SaleController;
use App\Models\Asset;
use App\Models\AssetMovement;
use App\Models\BusinessPartner;
use App\Models\City;
use App\Models\Customer;
use App\Models\PartnerClient;
use App\Models\Product;
use App\Models\Rental;
use App\Models\RentalItem;
use App\Models\SaleInventory;
use App\Models\Staff;
use App\Models\StockMovement;
use App\Models\Vendor;
use App\Models\VendorOrderDetail;
use App\Models\Warehouse;
use App\Services\Inventory\StockMovementRecorder;
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
use Illuminate\Validation\ValidationException;
use RuntimeException;
use ZipArchive;

class ImportService
{
    private const STORAGE_DIR = 'imports';
    private const CUSTOMER = 'customers';
    private const PRODUCT = 'products';
    private const ASSET = 'assets';
    private const RENTAL = 'rentals';
    private const VENDOR = 'vendors';
    private const STAFF = 'staff';

    private array $productCache = [];
    private array $warehouseCache = [];
    private array $customerCache = [];
    private array $cityCache = [];
    private array $vendorCache = [];
    private array $partnerCache = [];

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
                'description' => 'Import direct customers, business partners, and actual clients through one city-aware customer workbook.',
                'priority' => 2,
                'fields' => [
                    'name' => ['label' => 'Customer Name', 'required' => true, 'aliases' => ['name', 'customer', 'customer_name', 'customer_name*', 'full_name', 'patient_name', 'patient_name*']],
                    'customer_type' => ['label' => 'Customer Type', 'aliases' => ['customer_type', 'type']],
                    'contact_name' => ['label' => 'Contact Name', 'aliases' => ['contact_name', 'contact_person']],
                    'phone' => ['label' => 'Phone', 'aliases' => ['phone', 'mobile', 'contact_number']],
                    'whatsapp_number' => ['label' => 'WhatsApp Number', 'aliases' => ['whatsapp', 'whatsapp_number']],
                    'email' => ['label' => 'Email', 'aliases' => ['email', 'mail']],
                    'company_name' => ['label' => 'Company Name', 'aliases' => ['company', 'company_name']],
                    'city_name' => ['label' => 'City', 'aliases' => ['city', 'city_name']],
                    'state' => ['label' => 'State', 'aliases' => ['state']],
                    'pincode' => ['label' => 'Pincode', 'aliases' => ['pincode', 'postal_code', 'zip']],
                    'address' => ['label' => 'Address', 'aliases' => ['address', 'street_address']],
                    'gst_number' => ['label' => 'GST Number', 'aliases' => ['gst', 'gst_number', 'gstin']],
                    'gst_registered' => ['label' => 'GST Registered', 'aliases' => ['gst_registered', 'gst_flag']],
                    'google_map_link' => ['label' => 'Google Map Link', 'aliases' => ['google_map_link', 'map_link', 'map_url', 'google_map_url']],
                    'business_partner_name' => ['label' => 'Business Partner Name', 'aliases' => ['business_partner_name', 'business_partner']],
                    'business_partner_code' => ['label' => 'Business Partner Code', 'aliases' => ['business_partner_code', 'partner_code']],
                    'parent_business_partner' => ['label' => 'Parent Business Partner', 'aliases' => ['parent_business_partner', 'parent_partner', 'parent_business_partner_name']],
                    'credit_terms' => ['label' => 'Credit Terms', 'aliases' => ['credit_terms', 'credit_days', 'payment_terms']],
                    'referral_percentage' => ['label' => 'Referral Percentage', 'aliases' => ['referral_percentage', 'referral_percent']],
                    'account_manager' => ['label' => 'Account Manager', 'aliases' => ['account_manager', 'relationship_manager']],
                    'notes' => ['label' => 'Notes', 'aliases' => ['notes', 'remarks']],
                ],
                'template' => [
                    ['Customer Name', 'Customer Type', 'Contact Name', 'Phone', 'WhatsApp Number', 'Email', 'Company Name', 'City', 'State', 'Pincode', 'Address', 'GST Number', 'GST Registered', 'Google Map Link', 'Business Partner Name', 'Business Partner Code', 'Parent Business Partner', 'Credit Terms', 'Referral Percentage', 'Account Manager', 'Notes'],
                    ['Aarav Sharma', 'direct_customer', '', '9876543210', '9876543210', 'aarav@example.com', '', 'Bengaluru', 'Karnataka', '560001', '221B MG Road', '', 'No', 'https://maps.google.com/?q=221B+MG+Road+Bengaluru', '', '', '', '', '', 'Priya Shah', 'Existing oxygen customer'],
                    ['Care Plus Clinic', 'business_partner', 'Ananya Rao', '9810012345', '9810012345', 'procurement@careplus.test', 'Care Plus Clinic', 'Noida', 'Uttar Pradesh', '201301', '12, Sector 18', '29ABCDE1234F1Z5', 'Yes', 'https://maps.google.com/?q=Care+Plus+Clinic+Noida', 'Care Plus Clinic', 'BP-CAREPLUS', '', '30 days', '7.50', 'Rahul S', 'Corporate account'],
                    ['Rahul Verma', 'actual_client', 'Rahul Verma', '9810012346', '9810012346', 'rahul.verma@example.com', '', 'Noida', 'Uttar Pradesh', '201301', 'Tower 2, Sector 18', '', 'No', 'https://maps.google.com/?q=Tower+2+Sector+18+Noida', '', '', 'Care Plus Clinic', '', '', 'Rahul S', 'Client under Care Plus Clinic'],
                ],
                'chunk_size' => 100,
            ],
            self::PRODUCT => [
                'label' => 'Product Master Import',
                'module_permission' => 'products',
                'description' => 'Import product master rows with sellable, rentable, or both product structures.',
                'priority' => 3,
                'fields' => [
                    'name' => ['label' => 'Product Name', 'required' => true, 'aliases' => ['name', 'product_name', 'product', 'product name']],
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
                    'gst_tax_type' => ['label' => 'GST Tax Type', 'aliases' => ['gst_tax_type', 'tax_type']],
                    'gst_calculation_mode' => ['label' => 'GST Calculation Mode', 'aliases' => ['gst_calculation_mode', 'tax_calculation_mode']],
                    'cgst_rate' => ['label' => 'CGST Rate', 'aliases' => ['cgst_rate']],
                    'sgst_rate' => ['label' => 'SGST Rate', 'aliases' => ['sgst_rate']],
                    'igst_rate' => ['label' => 'IGST Rate', 'aliases' => ['igst_rate']],
                ],
                'template' => [
                    ['Product Name', 'Product Type', 'Stock Mode', 'Quantity', 'Category', 'Brand', 'Model Name', 'Product Code', 'SKU', 'Price Per Day', 'Rental Price 30 Days', 'Sale Price', 'Deposit', 'GST Tax Type', 'GST Calculation Mode', 'CGST Rate', 'SGST Rate', 'IGST Rate'],
                    ['Oxygen Concentrator 5 LPM', 'rentable', 'tracked_rental', '0', 'Respiratory', 'Philips', 'SimplyGo', 'OXY-5L', 'OXY5L', '450', '4500', '', '5000', 'cgst_sgst', 'exclusive', '9', '9', '0'],
                    ['Hospital Bed Electric', 'both', 'tracked_both', '0', 'Furniture', 'Kraft', '2 Function', 'BED-E2', 'BED-E2', '550', '12000', '45000', '5000', 'cgst_sgst', 'exclusive', '9', '9', '0'],
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
                    'city_name' => ['label' => 'City', 'aliases' => ['city', 'city_name']],
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
                    ['Product Code', 'Product Name', 'Brand', 'Model Name', 'City', 'Warehouse Code', 'Asset Type / Stage', 'Serial Number', 'Barcode', 'Condition Status', 'Asset Status', 'Purchase Date', 'Purchase Cost', 'Notes'],
                    ['OXY-5L', 'Oxygen Concentrator 5 LPM', 'Philips', 'SimplyGo', 'Bengaluru', 'MAIN', 'rental_stock', 'OC5L-001', 'OC5L-001', 'good', 'available', '2025-01-10', '32000', 'Imported from legacy sheet'],
                    ['BED-E2', 'Hospital Bed Electric', 'Kraft', '2 Function', 'Bengaluru', 'MAIN', 'new_stock', '', '', 'good', 'available_for_sale', '2025-02-01', '45000', 'Blank serial will generate PENDING serial'],
                ],
                'chunk_size' => 100,
            ],
            self::RENTAL => [
                'label' => 'Rental Import',
                'module_permission' => 'rentals',
                'description' => 'Import rentals with city-first fulfilment, direct customers or business partners, and vendor fulfilment.',
                'priority' => 4,
                'fields' => [
                    'customer_type' => ['label' => 'Customer Type', 'aliases' => ['customer_type']],
                    'business_partner_name' => ['label' => 'Business Partner', 'aliases' => ['business_partner', 'business_partner_name', 'partner_name']],
                    'customer_phone' => ['label' => 'Customer Phone', 'aliases' => ['customer_phone', 'phone']],
                    'customer_email' => ['label' => 'Customer Email', 'aliases' => ['customer_email', 'email']],
                    'customer_name' => ['label' => 'Customer Name', 'required' => true, 'aliases' => ['customer_name', 'customer_name*', 'customer', 'name', 'patient_name', 'patient_name*', 'actual_client', 'actual_client_name', 'actual_client_name*']],
                    'actual_client_name' => ['label' => 'Actual Client', 'aliases' => ['actual_client', 'actual_client_name', 'actual_client_name*', 'partner_client_name']],
                    'product_code' => ['label' => 'Product Code', 'aliases' => ['product_code', 'code']],
                    'sku' => ['label' => 'SKU', 'aliases' => ['sku']],
                    'product_name' => ['label' => 'Product Name', 'required' => true, 'aliases' => ['product_name', 'product']],
                    'brand' => ['label' => 'Brand', 'required' => true, 'aliases' => ['brand']],
                    'model_name' => ['label' => 'Model Name', 'required' => true, 'aliases' => ['model', 'model_name']],
                    'city_name' => ['label' => 'City', 'required' => true, 'aliases' => ['city', 'city_name']],
                    'fulfilment_source' => ['label' => 'Fulfilment Source', 'aliases' => ['fulfilment_source']],
                    'vendor_name' => ['label' => 'Vendor', 'aliases' => ['vendor', 'vendor_name']],
                    'delivery_responsibility' => ['label' => 'Delivery Responsibility', 'aliases' => ['delivery_responsibility', 'delivery_assignment_type']],
                    'pickup_responsibility' => ['label' => 'Pickup Responsibility', 'aliases' => ['pickup_responsibility']],
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
                    ['Customer Type', 'Business Partner', 'Actual Client', 'Customer Name', 'Customer Phone', 'Product Name', 'Brand', 'Model Name', 'City', 'Fulfilment Source', 'Vendor', 'Delivery Responsibility', 'Pickup Responsibility', 'Dispatch Warehouse Code', 'Asset Serials', 'Quantity', 'Start Date', 'End Date', 'Rental Amount', 'Deposit Amount', 'Transport Amount', 'Other Amount', 'Status', 'Payment Status', 'Paid Amount', 'Invoice Status', 'Delivery Status', 'Delivery Date', 'Pickup Status', 'Pickup Date', 'Notes'],
                    ['direct_customer', '', '', 'Aarav Sharma', '9876543210', 'Oxygen Concentrator 5 LPM', 'Philips', 'SimplyGo', 'Bengaluru', 'in_house', '', 'ph_internal_delivery', 'ph_internal_pickup', 'MAIN', 'OC5L-001', '1', '2026-04-01', '2026-04-15', '4500', '5000', '350', '0', 'Active', 'pending', '', 'generated', 'assigned', '2026-04-01', 'not_assigned', '', 'Migrated active rental'],
                    ['business_partner', 'Care Plus Clinic', 'Rahul Verma', 'Rahul Verma', '9810012345', 'Hospital Bed Electric', 'Kraft', '2 Function', 'Bengaluru', 'vendor_supplied', 'KR Healthcare', 'vendor_delivery', 'customer_return', '', '', '1', '2026-05-01', '2026-05-30', '12000', '5000', '0', '0', 'Active', 'pending', '', 'generated', 'assigned', '2026-05-01', 'not_assigned', '', 'Vendor fulfilled partner rental'],
                ],
                'chunk_size' => 100,
            ],
            self::VENDOR => [
                'label' => 'Vendor Import',
                'module_permission' => 'vendors',
                'description' => 'Import vendor master with city-first availability and fulfilment support.',
                'priority' => 5,
                'fields' => [
                    'name' => ['label' => 'Vendor Name', 'required' => true, 'aliases' => ['name', 'vendor_name', 'vendor', 'supplier_name']],
                    'contact_person' => ['label' => 'Contact Name', 'aliases' => ['contact_person', 'contact_name', 'primary_contact']],
                    'phone' => ['label' => 'Phone', 'aliases' => ['phone', 'mobile', 'contact_number']],
                    'whatsapp' => ['label' => 'WhatsApp Number', 'aliases' => ['whatsapp', 'whatsapp_number']],
                    'email' => ['label' => 'Email', 'aliases' => ['email']],
                    'vendor_type' => ['label' => 'Vendor Type', 'aliases' => ['vendor_type', 'type']],
                    'city_name' => ['label' => 'City', 'aliases' => ['city', 'city_name']],
                    'state' => ['label' => 'State', 'aliases' => ['state']],
                    'pincode' => ['label' => 'Pincode', 'aliases' => ['pincode', 'pin_code']],
                    'gst_number' => ['label' => 'GST Number', 'aliases' => ['gst_number', 'gstin']],
                    'delivery_supported' => ['label' => 'Delivery Supported', 'aliases' => ['delivery_supported']],
                    'pickup_supported' => ['label' => 'Pickup Supported', 'aliases' => ['pickup_supported']],
                    'gst_registration_type' => ['label' => 'GST Registration Type', 'aliases' => ['gst_registration_type']],
                    'payment_terms' => ['label' => 'Payment Terms', 'aliases' => ['payment_terms']],
                    'address' => ['label' => 'Address', 'aliases' => ['address']],
                    'is_active' => ['label' => 'Active', 'aliases' => ['is_active', 'active']],
                    'notes' => ['label' => 'Notes', 'aliases' => ['notes', 'remarks']],
                ],
                'template' => [
                    ['Vendor Name', 'Contact Name', 'Phone', 'WhatsApp Number', 'Email', 'City', 'Address', 'State', 'Pincode', 'GST Number', 'Delivery Supported', 'Pickup Supported', 'Vendor Type', 'GST Registration Type', 'Payment Terms', 'Active', 'Notes'],
                    ['KR Healthcare', 'Kiran Rao', '9000001111', '9000001111', 'ops@krhealthcare.test', 'Bengaluru', 'Indiranagar, Bengaluru', 'Karnataka', '560001', '29ABCDE1234F1Z5', 'Yes', 'Yes', 'supplier', 'regular', '7 days', 'Yes', 'Supports vendor supplied rentals'],
                ],
                'chunk_size' => 100,
            ],
            self::STAFF => [
                'label' => 'Staff Import',
                'module_permission' => 'users',
                'description' => 'Import staff directory with city, role, and assignment role support.',
                'priority' => 6,
                'fields' => [
                    'name' => ['label' => 'Name', 'required' => true, 'aliases' => ['name', 'staff_name']],
                    'email' => ['label' => 'Email', 'aliases' => ['email']],
                    'phone' => ['label' => 'Phone', 'aliases' => ['phone', 'mobile']],
                    'role' => ['label' => 'Role', 'aliases' => ['role']],
                    'assignment_role' => ['label' => 'Assignment Role', 'aliases' => ['assignment_role']],
                    'city_name' => ['label' => 'City', 'aliases' => ['city', 'city_name']],
                    'status' => ['label' => 'Status', 'aliases' => ['status']],
                    'joining_date' => ['label' => 'Joining Date', 'aliases' => ['joining_date']],
                    'salary' => ['label' => 'Salary', 'aliases' => ['salary']],
                    'address' => ['label' => 'Address', 'aliases' => ['address']],
                    'notes' => ['label' => 'Notes', 'aliases' => ['notes']],
                ],
                'template' => [
                    ['Name', 'Email', 'Phone', 'Role', 'Assignment Role', 'City', 'Status', 'Joining Date', 'Salary', 'Address', 'Notes'],
                    ['Delivery Bengaluru', 'deliverybng@primehealers.com', '9000002222', 'delivery', 'delivery', 'Bengaluru', 'active', '2026-01-01', '22000', 'Bengaluru', 'Delivery staff for Bengaluru'],
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

    public function templateCatalog(): array
    {
        $cards = collect($this->modules())
            ->map(function (array $config, string $key) {
                return [
                    'key' => $key,
                    'label' => $config['label'],
                    'download_label' => 'Download ' . Str::headline($config['label']) . ' Template',
                    'fields' => array_values(array_map(fn ($field) => $field['label'] ?? '', $config['fields'] ?? [])),
                    'note' => 'Includes template rows plus a guidance sheet with required fields, accepted values, and sample data.',
                    'upload_available' => true,
                    'filename' => Str::slug($config['label']) . '-template.xlsx',
                ];
            })
            ->all();

        $cards['sales'] = [
            'key' => 'sales',
            'label' => 'Sales Import',
            'download_label' => 'Download Sales Import Template',
            'fields' => ['Customer Type', 'Business Partner', 'Actual Client', 'City', 'Fulfilment Source', 'Vendor', 'Delivery Responsibility', 'Warehouse', 'Quantity', 'Sale Amount', 'Payment Status'],
            'note' => 'Includes city-first fulfilment and business partner guidance.',
            'upload_available' => true,
            'filename' => 'sales-import-template.xlsx',
        ];

        $cards['opening-balances'] = [
            'key' => 'opening-balances',
            'label' => 'Opening Balance Import',
            'download_label' => 'Download Opening Balance Template',
            'fields' => ['Customer Phone', 'Opening Balance', 'Balance Type', 'Notes'],
            'note' => 'Legacy opening balance import with guidance sheet.',
            'upload_available' => true,
            'filename' => 'opening-balance-import-template.xlsx',
        ];

        return $cards;
    }

    public function templateDefinition(string $module): array
    {
        $module = strtolower(trim($module));

        if (isset($this->modules()[$module])) {
            $config = $this->module($module);
            $rows = $config['template'];
            $headers = $rows[0] ?? [];
            $fieldLookup = collect($config['fields'] ?? [])->mapWithKeys(fn ($field, $key) => [
                Str::lower((string) ($field['label'] ?? $key)) => ['key' => $key] + $field,
            ])->all();

            $guidanceRows = [
                ['Field', 'Required', 'Sample Values', 'Accepted Values', 'Description'],
            ];

            foreach ($headers as $header) {
                $fieldMeta = $fieldLookup[Str::lower((string) $header)] ?? null;
                $guidanceRows[] = [
                    $header,
                    !empty($fieldMeta['required']) ? 'Required' : 'Optional',
                    $this->templateSampleValue($module, $fieldMeta['key'] ?? null),
                    $this->templateAcceptedValues($module, $fieldMeta['key'] ?? null),
                    $this->templateFieldDescription($module, $fieldMeta['key'] ?? null),
                ];
            }

            return [
                'filename' => Str::slug($config['label']) . '-template.xlsx',
                'rows' => $rows,
                'guidance_rows' => $guidanceRows,
            ];
        }

        return match ($module) {
            'sales' => [
                'filename' => 'sales-import-template.xlsx',
                'rows' => [
                    ['Customer Type', 'Business Partner', 'Actual Client', 'Customer Name', 'Customer Phone', 'Product Name', 'Brand', 'Model', 'City', 'Fulfilment Source', 'Vendor', 'Delivery Responsibility', 'Warehouse', 'Quantity', 'Sale Amount', 'Tax Type', 'Payment Status', 'Paid Amount', 'Invoice Status', 'Delivery Status', 'Delivery Date', 'Sale Date', 'Notes'],
                    ['direct_customer', '', '', 'Aarav Sharma', '9876543210', 'Oxygen Concentrator 5 LPM', 'Philips', 'SimplyGo', 'Bengaluru', 'in_house', '', 'ph_internal_delivery', 'Main Warehouse', '1', '45000', 'exclusive', 'paid', '45000', 'paid', 'completed', '2026-05-03', '2026-05-01', 'Paid import'],
                    ['business_partner', 'Care Plus Clinic', 'Rahul Verma', 'Rahul Verma', '9810012345', 'Hospital Bed Electric', 'Kraft', '2 Function', 'Bengaluru', 'vendor_supplied', 'KR Healthcare', 'vendor_delivery', '', '1', '45000', 'exclusive', 'pending', '', 'generated', 'assigned', '2026-05-03', '2026-05-01', 'Vendor supplied partner sale'],
                ],
                'guidance_rows' => [
                    ['Field', 'Required', 'Sample Values', 'Accepted Values', 'Description'],
                    ['Customer Type', 'Optional', 'direct_customer', 'direct_customer, business_partner', 'Defaults to direct_customer when blank.'],
                    ['Business Partner', 'Optional', 'Care Plus Clinic', 'Existing business partner name', 'Required when Customer Type is business_partner.'],
                    ['Actual Client', 'Optional', 'Rahul Verma', 'Existing partner client name', 'Partner client / actual client under the business partner.'],
                    ['City', 'Optional', 'Bengaluru', 'Existing PHOS city', 'Used for city-first warehouse and vendor fulfilment alignment.'],
                    ['Fulfilment Source', 'Optional', 'vendor_supplied', 'in_house, vendor_supplied', 'Defaults to in_house.'],
                    ['Vendor', 'Optional', 'KR Healthcare', 'Existing active vendor name', 'Required when Fulfilment Source is vendor_supplied.'],
                    ['Delivery Responsibility', 'Optional', 'vendor_delivery', 'ph_internal_delivery, vendor_delivery, customer_pickup', 'Operational delivery assignment source.'],
                    ['Warehouse', 'Optional', 'Main Warehouse', 'Existing active warehouse', 'Required for in_house stock validation.'],
                ],
            ],
            'opening-balances' => [
                'filename' => 'opening-balance-import-template.xlsx',
                'rows' => [
                    ['Customer Phone', 'Opening Balance', 'Balance Type', 'Notes'],
                    ['9876543210', '3200', 'receivable', 'Legacy opening balance as of 2026-04-30'],
                ],
                'guidance_rows' => [
                    ['Field', 'Required', 'Sample Values', 'Accepted Values', 'Description'],
                    ['Customer Phone', 'Required', '9876543210', 'Existing customer phone', 'Used to match the customer.'],
                    ['Opening Balance', 'Required', '3200', 'Numeric', 'Opening balance amount.'],
                    ['Balance Type', 'Optional', 'receivable', 'receivable, payable', 'Defaults to receivable.'],
                    ['Notes', 'Optional', 'Legacy opening balance as of 2026-04-30', '', 'Optional context for the imported balance.'],
                ],
            ],
            default => throw new RuntimeException('Unsupported import module.'),
        };
    }

    public function templateWorkbook(string $module): array
    {
        $definition = $this->templateDefinition($module);

        return [
            'filename' => $definition['filename'],
            'content' => $this->buildXlsxWorkbook([
                'Template' => $definition['rows'],
                'Guidance' => $definition['guidance_rows'],
            ]),
        ];
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
            'file_extension' => strtolower((string) $file->getClientOriginalExtension()),
            'headers' => $parsed['headers'],
            'rows' => $parsed['rows'],
            'row_count' => count($parsed['rows']),
            'raw_row_count' => (int) ($parsed['raw_row_count'] ?? count($parsed['rows'])),
            'non_empty_row_count' => (int) ($parsed['non_empty_row_count'] ?? count($parsed['rows'])),
            'mapped_row_count' => count($parsed['rows']),
            'blank_row_count' => (int) ($parsed['blank_row_count'] ?? 0),
            'sheet_name' => $parsed['sheet_name'] ?? null,
            'header_row_number' => (int) ($parsed['header_row_number'] ?? 1),
            'highest_row' => (int) ($parsed['highest_row'] ?? 0),
            'highest_column' => $parsed['highest_column'] ?? null,
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
        $fields = $this->fieldOptions($module);
        $validRows = [];
        $invalidRows = [];
        $noDataError = null;
        $assetPrecheck = [
            'untracked_products' => [],
        ];
        $productIdentityRows = [];
        $customerPreviewPartners = [];

        if (empty($upload['rows'] ?? [])) {
            $noDataError = 'No data rows found. Please check header row and file format.';
        }

        foreach (($upload['rows'] ?? []) as $index => $row) {
            $rowNumber = $index + 2;
            $mapped = $this->applyMapping($row, $mapping);
            [$normalized, $errors] = $this->normalizeRow($module, $mapped, $organizationId, $rowNumber, $mapping, $fields, [
                'pending_business_partners' => $customerPreviewPartners,
            ]);
            $errorDetails = $this->normalizeErrorDetails($errors);
            $errorMessages = array_map(fn (array $detail) => $detail['reason'], $errorDetails);
            $guidance = $module === self::ASSET
                ? $this->assetStockModeGuidance($mapped, $organizationId)
                : null;

            if (!empty($errorMessages)) {
                $invalidRows[] = [
                    'row_number' => $rowNumber,
                    'source' => $row,
                    'mapped' => $mapped,
                    'errors' => $errorMessages,
                    'error_details' => $errorDetails,
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

            if ($module === self::PRODUCT) {
                $identityKey = $this->productImportIdentityKey($normalized);

                if ($identityKey !== null) {
                    $existingRowNumber = $productIdentityRows[$identityKey] ?? null;

                    if ($existingRowNumber !== null) {
                        $invalidRows[] = [
                            'row_number' => $rowNumber,
                            'source' => $row,
                            'mapped' => $mapped,
                            'errors' => ['This file contains another row with the same Product Name, Brand, and Model (row '.$existingRowNumber.').'],
                            'error_details' => [$this->errorDetail('product_name', 'This file contains another row with the same Product Name, Brand, and Model (row '.$existingRowNumber.').')],
                            'guidance' => $guidance,
                        ];
                        continue;
                    }

                    $productIdentityRows[$identityKey] = $rowNumber;
                }
            }

            if (!empty($normalized['import_action'])) {
                $mapped['import_action'] = ucfirst((string) $normalized['import_action']);
            }

            $validRows[] = [
                'row_number' => $rowNumber,
                'payload' => $normalized,
                'mapped' => $mapped,
            ];

            if ($module === self::CUSTOMER && ($normalized['import_entity'] ?? null) === 'business_partner') {
                $partnerName = Str::lower(trim((string) ($normalized['business_name'] ?? '')));
                $partnerCode = Str::lower(trim((string) ($normalized['partner_code'] ?? '')));

                if ($partnerName !== '') {
                    $customerPreviewPartners[$partnerName] = true;
                }

                if ($partnerCode !== '') {
                    $customerPreviewPartners[$partnerCode] = true;
                }
            }
        }

        $key = $this->saveSnapshot([
            'kind' => 'preview',
            'module' => $module,
            'organization_id' => $organizationId,
            'upload_key' => $uploadKey,
            'mapping' => $mapping,
            'original_name' => $upload['original_name'] ?? null,
            'file_extension' => $upload['file_extension'] ?? null,
            'headers' => $upload['headers'] ?? [],
            'row_count' => count($upload['rows'] ?? []),
            'raw_row_count' => (int) ($upload['raw_row_count'] ?? count($upload['rows'] ?? [])),
            'non_empty_row_count' => (int) ($upload['non_empty_row_count'] ?? count($upload['rows'] ?? [])),
            'mapped_row_count' => (int) ($upload['mapped_row_count'] ?? count($upload['rows'] ?? [])),
            'valid_rows' => $validRows,
            'invalid_rows' => $invalidRows,
            'asset_precheck' => [
                'untracked_products' => array_values($assetPrecheck['untracked_products']),
            ],
            'blank_row_count' => (int) ($upload['blank_row_count'] ?? 0),
            'sheet_name' => $upload['sheet_name'] ?? null,
            'header_row_number' => (int) ($upload['header_row_number'] ?? 1),
            'highest_row' => (int) ($upload['highest_row'] ?? 0),
            'highest_column' => $upload['highest_column'] ?? null,
            'no_data_error' => $noDataError,
            'created_at' => now()->toIso8601String(),
        ]);

        return [
            'key' => $key,
            'valid_count' => count($validRows),
            'invalid_count' => count($invalidRows),
            'valid_rows' => $validRows,
            'invalid_rows' => $invalidRows,
            'original_name' => $upload['original_name'] ?? null,
            'file_extension' => $upload['file_extension'] ?? null,
            'headers' => $upload['headers'] ?? [],
            'row_count' => count($upload['rows'] ?? []),
            'raw_row_count' => (int) ($upload['raw_row_count'] ?? count($upload['rows'] ?? [])),
            'non_empty_row_count' => (int) ($upload['non_empty_row_count'] ?? count($upload['rows'] ?? [])),
            'mapped_row_count' => (int) ($upload['mapped_row_count'] ?? count($upload['rows'] ?? [])),
            'blank_row_count' => (int) ($upload['blank_row_count'] ?? 0),
            'sheet_name' => $upload['sheet_name'] ?? null,
            'header_row_number' => (int) ($upload['header_row_number'] ?? 1),
            'highest_row' => (int) ($upload['highest_row'] ?? 0),
            'highest_column' => $upload['highest_column'] ?? null,
            'no_data_error' => $noDataError,
        ];
    }

    public function executePreview(string $module, string $previewKey, int $organizationId, int $userId): array
    {
        $preview = $this->loadSnapshot($previewKey);

        if (!empty($preview['imported_at'])) {
            $lastResult = $preview['last_result'] ?? $this->initialExecutionResult($preview);

            return $this->finalizeExecutionResult($lastResult) + [
                'already_imported' => true,
                'error_report_available' => !empty($preview['invalid_rows']) || !empty($lastResult['skipped_rows'] ?? []) || !empty($lastResult['failed_rows'] ?? []),
            ];
        }

        $rows = collect($preview['valid_rows'] ?? []);
        $chunkSize = (int) ($this->module($module)['chunk_size'] ?? 100);
        $productUpsertWhitelistIds = $module === self::PRODUCT
            ? Product::query()->where('organization_id', $organizationId)->pluck('id')->map(fn ($id) => (int) $id)->all()
            : null;
        $result = $this->initialExecutionResult($preview);

        $rows->chunk($chunkSize)->each(function (Collection $chunk) use ($module, $organizationId, $userId, $productUpsertWhitelistIds, &$result) {
            DB::transaction(function () use ($chunk, $module, $organizationId, $userId, $productUpsertWhitelistIds, &$result) {
                $touchedProductIds = [];

                foreach ($chunk as $row) {
                    try {
                        $payload = $row['payload'] ?? [];
                        $rowNumber = (int) ($row['row_number'] ?? 0);

                        $action = match ($module) {
                            self::CUSTOMER => $this->importCustomerRow($payload, $organizationId),
                            self::PRODUCT => $this->importProductRow($payload, $organizationId, $productUpsertWhitelistIds),
                            self::ASSET => $this->importAssetRow($payload, $organizationId, $userId, $touchedProductIds),
                            self::RENTAL => $this->importRentalRow($payload, $organizationId, $userId),
                            self::VENDOR => $this->importVendorRow($payload, $organizationId),
                            self::STAFF => $this->importStaffRow($payload, $organizationId),
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
                        $issue = $this->buildRuntimeIssue($row, $exception);

                        if ($issue['kind'] === 'failed') {
                            $result['failed']++;
                            $result['failed_rows'][] = $issue;
                        } else {
                            $result['skipped']++;
                            $result['skipped_rows'][] = $issue;
                        }

                        $this->accumulateIssueCounts($result, $issue);
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

        $result = $this->finalizeExecutionResult($result) + [
            'executed_at' => now()->toIso8601String(),
        ];
        $preview['last_result'] = $result;
        $preview['imported_at'] = now()->toIso8601String();
        $preview['runtime_errors'] = array_map(function (array $issue) {
            return [
                'row_number' => $issue['row_number'] ?? 0,
                'identifier' => $issue['identifier'] ?? '',
                'reason_category' => $issue['reason_category'] ?? '',
                'errors' => $issue['errors'] ?? [],
                'error_details' => $issue['error_details'] ?? [],
                'mapped' => $issue['mapped'] ?? [],
            ];
        }, array_merge($result['skipped_rows'] ?? [], $result['failed_rows'] ?? []));
        $this->overwriteSnapshot($previewKey, $preview);
        $result['error_report_available'] = !empty($preview['invalid_rows']) || !empty($result['skipped_rows']) || !empty($result['failed_rows']);

        return $result;
    }

    public function errorReportRows(string $previewKey): array
    {
        $preview = $this->loadSnapshot($previewKey);
        $lastResult = $preview['last_result'] ?? null;
        $rows = [];

        foreach (($lastResult['preview_invalid_rows'] ?? []) as $row) {
            foreach (($row['error_details'] ?? []) as $detail) {
                $rows[] = [
                    'row_number' => $row['row_number'] ?? '',
                    'status' => $row['status'] ?? 'preview_invalid',
                    'identifier' => $row['identifier'] ?? '',
                    'reason_category' => $row['reason_category'] ?? '',
                    'field' => $detail['field'] ?? '',
                    'reason' => $detail['reason'] ?? '',
                    'errors' => implode(' | ', $row['errors'] ?? []),
                    'mapped_data' => json_encode($row['mapped'] ?? [], JSON_UNESCAPED_UNICODE),
                ];
            }
        }

        if (empty($rows)) {
            foreach (($preview['invalid_rows'] ?? []) as $row) {
                $issue = $this->buildPreviewInvalidIssue($row);
                foreach (($issue['error_details'] ?? []) as $detail) {
                    $rows[] = [
                        'row_number' => $issue['row_number'] ?? '',
                        'status' => $issue['status'] ?? 'preview_invalid',
                        'identifier' => $issue['identifier'] ?? '',
                        'reason_category' => $issue['reason_category'] ?? '',
                        'field' => $detail['field'] ?? '',
                        'reason' => $detail['reason'] ?? '',
                        'errors' => implode(' | ', $issue['errors'] ?? []),
                        'mapped_data' => json_encode($issue['mapped'] ?? [], JSON_UNESCAPED_UNICODE),
                    ];
                }
            }
        }

        foreach (array_merge($lastResult['skipped_rows'] ?? [], $lastResult['failed_rows'] ?? []) as $row) {
            foreach (($row['error_details'] ?? []) as $detail) {
                $rows[] = [
                    'row_number' => $row['row_number'] ?? '',
                    'status' => $row['status'] ?? 'import_skipped',
                    'identifier' => $row['identifier'] ?? '',
                    'reason_category' => $row['reason_category'] ?? '',
                    'field' => $detail['field'] ?? '',
                    'reason' => $detail['reason'] ?? '',
                    'errors' => implode(' | ', $row['errors'] ?? []),
                    'mapped_data' => json_encode($row['mapped'] ?? [], JSON_UNESCAPED_UNICODE),
                ];
            }
        }

        return $rows;
    }

    private function initialExecutionResult(array $preview): array
    {
        $previewInvalidRows = collect($preview['invalid_rows'] ?? [])
            ->map(fn (array $row) => $this->buildPreviewInvalidIssue($row))
            ->values()
            ->all();

        $result = [
            'processed' => 0,
            'created' => 0,
            'updated' => 0,
            'skipped' => count($previewInvalidRows),
            'failed' => 0,
            'duplicate_rows_skipped' => 0,
            'validation_errors_count' => 0,
            'lookup_failures_count' => 0,
            'business_rule_skips_count' => 0,
            'already_imported_rows_count' => 0,
            'ignored_blank_rows_count' => (int) ($preview['blank_row_count'] ?? 0),
            'preview_invalid_rows' => $previewInvalidRows,
            'skipped_rows' => [],
            'failed_rows' => [],
            'reason_groups' => [],
        ];

        foreach ($previewInvalidRows as $issue) {
            $this->accumulateIssueCounts($result, $issue);
        }

        return $this->finalizeExecutionResult($result);
    }

    private function buildPreviewInvalidIssue(array $row): array
    {
        $errors = array_values($row['errors'] ?? []);

        return [
            'row_number' => (int) ($row['row_number'] ?? 0),
            'status' => 'preview_invalid',
            'kind' => 'preview_invalid',
            'identifier' => $this->rowIdentifier($row['mapped'] ?? [], $row['payload'] ?? []),
            'reason_category' => $this->classifyIssueCategory($errors),
            'reason' => $errors[0] ?? 'Preview validation failed.',
            'errors' => $errors,
            'error_details' => $row['error_details'] ?? $this->normalizeErrorDetails($errors),
            'mapped' => $row['mapped'] ?? [],
        ];
    }

    private function buildRuntimeIssue(array $row, \Throwable $exception): array
    {
        $errors = $this->exceptionMessages($exception);
        $category = $this->classifyIssueCategory($errors, $exception);
        $kind = $this->isRuntimeFailure($exception, $category) ? 'failed' : 'skipped';

        return [
            'row_number' => (int) ($row['row_number'] ?? 0),
            'status' => $kind === 'failed' ? 'import_failed' : 'import_skipped',
            'kind' => $kind,
            'identifier' => $this->rowIdentifier($row['mapped'] ?? [], $row['payload'] ?? []),
            'reason_category' => $category,
            'reason' => $errors[0] ?? 'Import execution failed.',
            'errors' => $errors,
            'error_details' => $this->normalizeErrorDetails($errors),
            'mapped' => $row['mapped'] ?? [],
        ];
    }

    private function accumulateIssueCounts(array &$result, array $issue): void
    {
        $category = $issue['reason_category'] ?? 'validation';

        switch ($category) {
            case 'duplicate':
                $result['duplicate_rows_skipped']++;
                break;
            case 'lookup_failure':
                $result['lookup_failures_count']++;
                break;
            case 'business_rule':
                $result['business_rule_skips_count']++;
                break;
            case 'already_imported':
                $result['already_imported_rows_count']++;
                break;
            case 'blank_row':
                $result['ignored_blank_rows_count']++;
                break;
            case 'validation':
            default:
                $result['validation_errors_count']++;
                break;
        }
    }

    private function finalizeExecutionResult(array $result): array
    {
        $issues = collect(array_merge(
            $result['preview_invalid_rows'] ?? [],
            $result['skipped_rows'] ?? [],
            $result['failed_rows'] ?? []
        ));

        $result['reason_groups'] = $issues
            ->groupBy(fn (array $issue) => ($issue['reason_category'] ?? 'validation') . '|' . ($issue['reason'] ?? ''))
            ->map(function (Collection $group) {
                $first = $group->first();

                return [
                    'reason_category' => $first['reason_category'] ?? 'validation',
                    'reason' => $first['reason'] ?? '',
                    'count' => $group->count(),
                ];
            })
            ->sortByDesc('count')
            ->values()
            ->all();

        return $result;
    }

    private function rowIdentifier(array $mapped = [], array $payload = []): string
    {
        $source = array_merge($payload, $mapped);

        $parts = collect([
            $source['customer_name'] ?? $source['name'] ?? null,
            $source['product_name'] ?? null,
            $source['brand'] ?? null,
            $source['model_name'] ?? $source['model'] ?? null,
            $source['product_code'] ?? null,
            $source['sku'] ?? null,
            $source['serial_number'] ?? null,
            $source['asset_serials'] ?? null,
            $source['start_date'] ?? null,
            $source['sale_date'] ?? null,
        ])
            ->map(fn ($value) => $this->cleanText(is_array($value) ? implode(', ', $value) : $value))
            ->filter()
            ->unique()
            ->take(3)
            ->values()
            ->all();

        return $parts === [] ? 'Row data' : implode(' • ', $parts);
    }

    private function exceptionMessages(\Throwable $exception): array
    {
        if ($exception instanceof ValidationException) {
            return collect($exception->errors())
                ->flatten()
                ->map(fn ($message) => trim((string) $message))
                ->filter()
                ->values()
                ->all();
        }

        $message = trim($exception->getMessage());

        return [$message !== '' ? $message : 'Import execution failed.'];
    }

    private function isRuntimeFailure(\Throwable $exception, string $category): bool
    {
        if ($exception instanceof ValidationException) {
            return false;
        }

        return $category === 'unknown';
    }

    private function classifyIssueCategory(array $errors, ?\Throwable $exception = null): string
    {
        $haystack = Str::lower(implode(' | ', $errors));

        if ($haystack === '') {
            return $exception instanceof ValidationException ? 'validation' : 'unknown';
        }

        if (str_contains($haystack, 'blank row')) {
            return 'blank_row';
        }

        if (
            str_contains($haystack, 'duplicate')
            || str_contains($haystack, 'already exists')
            || str_contains($haystack, 'another row with the same')
        ) {
            return 'duplicate';
        }

        if (
            str_contains($haystack, 'already imported')
            || str_contains($haystack, 'duplicate import was prevented')
        ) {
            return 'already_imported';
        }

        if (
            str_contains($haystack, 'lookup failed')
            || str_contains($haystack, 'not found')
            || str_contains($haystack, 'no matching')
            || str_contains($haystack, 'could not map')
        ) {
            return 'lookup_failure';
        }

        if (
            str_contains($haystack, 'already assigned')
            || str_contains($haystack, 'not available')
            || str_contains($haystack, 'no rental asset is available')
            || str_contains($haystack, 'not sufficient')
            || str_contains($haystack, 'cannot be rented')
            || str_contains($haystack, 'requires manual review')
            || str_contains($haystack, 'review this row manually')
            || str_contains($haystack, 'must be less than')
            || str_contains($haystack, 'must be greater than')
            || str_contains($haystack, 'completed first')
        ) {
            return 'business_rule';
        }

        if (
            str_contains($haystack, 'required')
            || str_contains($haystack, 'must be')
            || str_contains($haystack, 'is not valid')
            || str_contains($haystack, 'fix the row before importing')
            || str_contains($haystack, 'select exactly')
            || str_contains($haystack, 'do not match the product or warehouse')
        ) {
            return 'validation';
        }

        return $exception instanceof ValidationException ? 'validation' : 'unknown';
    }

    public function suggestMapping(string $module, array $headers): array
    {
        $fields = $this->fieldOptions($module);
        $mapping = [];
        $usedHeaders = [];

        foreach ($fields as $fieldKey => $field) {
            $aliases = collect($field['aliases'] ?? [])->prepend($fieldKey)->map(fn ($value) => $this->slugKey($value))->values();
            $matchedHeader = null;

            foreach ($aliases as $alias) {
                $matchedHeader = collect($headers)->first(function ($header) use ($alias, $usedHeaders) {
                    return !in_array($header, $usedHeaders, true)
                        && $this->slugKey($header) === $alias;
                });

                if ($matchedHeader !== null) {
                    break;
                }
            }

            $mapping[$fieldKey] = $matchedHeader;

            if ($matchedHeader !== null) {
                $usedHeaders[] = $matchedHeader;
            }
        }

        return $mapping;
    }

    private function importCustomerRow(array $payload, int $organizationId): string
    {
        $entity = $payload['import_entity'] ?? 'direct_customer';

        if ($entity === 'business_partner') {
            return $this->importBusinessPartnerCustomerRow($payload, $organizationId);
        }

        if ($entity === 'actual_client') {
            return $this->importActualClientCustomerRow($payload, $organizationId);
        }

        $customer = $this->findCustomerForImport($payload, $organizationId);

        if ($customer) {
            $customer->fill($payload)->save();
            return 'updated';
        }

        Customer::create($payload + ['organization_id' => $organizationId]);
        return 'created';
    }

    private function importBusinessPartnerCustomerRow(array $payload, int $organizationId): string
    {
        $query = BusinessPartner::query()->where('organization_id', $organizationId);
        $partner = null;

        if (!empty($payload['partner_code']) && Schema::hasColumn('business_partners', 'partner_code')) {
            $partner = (clone $query)->where('partner_code', $payload['partner_code'])->first();
        }

        if (!$partner && !empty($payload['phone'])) {
            $partner = (clone $query)->where('phone', $payload['phone'])->first();
        }

        if (!$partner && !empty($payload['email'])) {
            $partner = (clone $query)->where('email', $payload['email'])->first();
        }

        if (!$partner) {
            $partner = (clone $query)
                ->whereRaw('LOWER(business_name) = ?', [Str::lower((string) $payload['business_name'])])
                ->first();
        }

        if ($partner) {
            $partner->fill($payload)->save();
            unset($this->partnerCache[$organizationId]);

            return 'updated';
        }

        BusinessPartner::create($payload + ['organization_id' => $organizationId]);
        unset($this->partnerCache[$organizationId]);

        return 'created';
    }

    private function importActualClientCustomerRow(array $payload, int $organizationId): string
    {
        $partnerId = (int) ($payload['business_partner_id'] ?? 0);

        if ($partnerId <= 0) {
            $partner = $this->resolveBusinessPartnerForCustomerImport($organizationId, [
                'parent_business_partner' => $payload['parent_business_partner'] ?? null,
                'business_partner_code' => $payload['business_partner_code'] ?? null,
            ]);

            if (!$partner) {
                throw ValidationException::withMessages([
                    'parent_business_partner' => 'Parent Business Partner was not found in PHOS.',
                ]);
            }

            $partnerId = (int) $partner->id;
            $payload['business_partner_id'] = $partnerId;
        }

        $query = PartnerClient::query()
            ->where('organization_id', $organizationId)
            ->where('business_partner_id', $partnerId);
        $client = null;

        if (!empty($payload['phone'])) {
            $client = (clone $query)->where('phone', $payload['phone'])->first();
        }

        if (!$client && !empty($payload['alternate_phone'])) {
            $client = (clone $query)->where('alternate_phone', $payload['alternate_phone'])->first();
        }

        if (!$client) {
            $client = (clone $query)
                ->whereRaw('LOWER(client_name) = ?', [Str::lower((string) $payload['client_name'])])
                ->first();
        }

        if ($client) {
            $client->fill($payload)->save();

            return 'updated';
        }

        PartnerClient::create($payload + ['organization_id' => $organizationId]);

        return 'created';
    }

    private function importProductRow(array $payload, int $organizationId, ?array $upsertWhitelistIds = null): string
    {
        $product = $this->findProductForImportUpsert($organizationId, $payload, $upsertWhitelistIds);
        $previousQuantity = (int) ($product?->available_quantity ?? 0);

        if ($product) {
            $product->fill($payload)->save();
            if (($payload['stock_mode'] ?? null) !== Product::STOCK_MODE_UNTRACKED) {
                $product->syncLegacyStockFields();
            }

            $newQuantity = (int) ($product->available_quantity ?? 0);
            $quantityDelta = abs($newQuantity - $previousQuantity);

            if (($payload['stock_mode'] ?? null) === Product::STOCK_MODE_UNTRACKED && $quantityDelta > 0) {
                app(StockMovementRecorder::class)->recordForProduct(
                    $product,
                    StockMovement::TYPE_IMPORT,
                    $quantityDelta,
                    [
                        'from_status' => 'available',
                        'to_status' => 'available',
                        'notes' => 'Updated untracked stock quantity through product import.',
                    ]
                );
            }

            return 'updated';
        }

        $product = Product::create($payload + ['organization_id' => $organizationId]);
        if (($payload['stock_mode'] ?? null) !== Product::STOCK_MODE_UNTRACKED) {
            $product->syncLegacyStockFields();
        }

        if (($payload['stock_mode'] ?? null) === Product::STOCK_MODE_UNTRACKED && (int) ($product->available_quantity ?? 0) > 0) {
            app(StockMovementRecorder::class)->recordForProduct(
                $product,
                StockMovement::TYPE_IMPORT,
                (int) $product->available_quantity,
                [
                    'to_status' => 'available',
                    'notes' => 'Imported untracked stock quantity through product import.',
                ]
            );
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
            app(StockMovementRecorder::class)->recordForAsset(
                $asset,
                StockMovement::TYPE_IMPORT,
                1,
                [
                    'to_status' => $asset->asset_status,
                    'to_warehouse_id' => $asset->warehouse_id,
                    'performed_by_user_id' => $userId,
                    'notes' => 'Imported asset via data import.',
                ]
            );
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

    private function importVendorRow(array $payload, int $organizationId): string
    {
        $query = Vendor::query()->where('organization_id', $organizationId);
        $vendor = null;

        if (!empty($payload['phone'])) {
            $vendor = (clone $query)->where('phone', $payload['phone'])->first();
        }

        if (!$vendor && !empty($payload['email'])) {
            $vendor = (clone $query)->where('email', $payload['email'])->first();
        }

        if (!$vendor) {
            $vendor = (clone $query)->whereRaw('LOWER(name) = ?', [Str::lower((string) $payload['name'])])->first();
        }

        if ($vendor) {
            $vendor->fill($payload)->save();

            return 'updated';
        }

        Vendor::create($payload + ['organization_id' => $organizationId]);

        return 'created';
    }

    private function importStaffRow(array $payload, int $organizationId): string
    {
        $query = Staff::query()->where('organization_id', $organizationId);
        $staff = null;

        if (!empty($payload['email'])) {
            $staff = (clone $query)->where('email', $payload['email'])->first();
        }

        if (!$staff && !empty($payload['phone'])) {
            $staff = (clone $query)->where('phone', $payload['phone'])->first();
        }

        if (!$staff) {
            $staff = (clone $query)->whereRaw('LOWER(name) = ?', [Str::lower((string) $payload['name'])])->first();
        }

        if ($staff) {
            $staff->fill($payload)->save();

            return 'updated';
        }

        Staff::create($payload + ['organization_id' => $organizationId]);

        return 'created';
    }

    private function normalizeRow(string $module, array $mapped, int $organizationId, int $rowNumber, array $mapping = [], array $fields = [], array $context = []): array
    {
        return match ($module) {
            self::CUSTOMER => $this->normalizeCustomerRow($mapped, $organizationId, $context),
            self::PRODUCT => $this->normalizeProductRow($mapped, $organizationId, $mapping, $fields),
            self::ASSET => $this->normalizeAssetRow($mapped, $organizationId),
            self::RENTAL => $this->normalizeRentalRow($mapped, $organizationId),
            self::VENDOR => $this->normalizeVendorRow($mapped, $organizationId),
            self::STAFF => $this->normalizeStaffRow($mapped, $organizationId),
            default => [[], ['Unsupported import module for row ' . $rowNumber . '.']],
        };
    }

    private function normalizeCustomerRow(array $mapped, ?int $organizationId = null, array $context = []): array
    {
        $customerType = $this->normalizeUnifiedCustomerImportType($mapped['customer_type'] ?? null, $mapped['company_name'] ?? null);
        $name = $this->cleanText($mapped['name'] ?? '')
            ?: $this->cleanText($mapped['company_name'] ?? '')
            ?: $this->cleanText($mapped['contact_name'] ?? '');
        $phone = $this->normalizePhone($mapped['phone'] ?? null);
        $whatsApp = $this->normalizePhone($mapped['whatsapp_number'] ?? null);
        $email = $this->normalizeEmail($mapped['email'] ?? null);
        $city = $this->cleanText($mapped['city_name'] ?? $mapped['city'] ?? null);
        $parentPartnerName = $this->cleanText($mapped['parent_business_partner'] ?? null)
            ?: $this->cleanText($mapped['business_partner_name'] ?? $mapped['business_partner'] ?? null);
        $businessPartnerCode = $this->cleanText($mapped['business_partner_code'] ?? $mapped['partner_code'] ?? null);
        $referralPercentage = $this->normalizeDecimal($mapped['referral_percentage'] ?? null);
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

        if ($referralPercentage !== null && $referralPercentage < 0) {
            $errors[] = 'Referral percentage cannot be negative.';
        }

        if ($organizationId && $name !== '' && !$phone && !$email && $customerType === 'direct_customer') {
            $existingNameMatches = Customer::query()
                ->where('organization_id', $organizationId)
                ->whereRaw('LOWER(name) = ?', [Str::lower($name)])
                ->count();

            if ($existingNameMatches > 1) {
                $errors[] = 'Multiple customers already use this name. Provide phone or email so the importer can match the correct customer.';
            }
        }

        if ($customerType === 'actual_client' && $parentPartnerName === '' && $businessPartnerCode === '') {
            $errors[] = 'Parent Business Partner is required when Customer Type is actual_client.';
        }

        $businessPartner = null;

        if ($organizationId && $customerType === 'actual_client' && ($parentPartnerName !== '' || $businessPartnerCode !== '')) {
            $businessPartner = $this->resolveBusinessPartnerForCustomerImport($organizationId, [
                'parent_business_partner' => $parentPartnerName,
                'business_partner_name' => $mapped['business_partner_name'] ?? null,
                'business_partner_code' => $businessPartnerCode,
            ]);

            $pendingPartnerMatched = $businessPartner === null
                && $this->matchesPendingCustomerImportPartner($context, $parentPartnerName, $businessPartnerCode);

            if (!$businessPartner && !$pendingPartnerMatched) {
                $errors[] = 'Parent Business Partner was not found in PHOS.';
            }
        }

        $commonNotes = $this->cleanText($mapped['notes'] ?? null);

        if ($customerType === 'business_partner') {
            return [[
                'import_entity' => 'business_partner',
                'business_name' => $this->cleanText($mapped['company_name'] ?? null) ?: $name,
                'partner_code' => $businessPartnerCode,
                'contact_person' => $this->cleanText($mapped['contact_name'] ?? null),
                'phone' => $phone,
                'whatsapp' => $whatsApp,
                'email' => $email,
                'gst_registered' => $this->normalizeImportBoolean($mapped['gst_registered'] ?? null) ?? false,
                'gstin' => $this->cleanText($mapped['gst_number'] ?? null),
                'legal_name' => $this->cleanText($mapped['company_name'] ?? null) ?: $name,
                'billing_address' => $this->cleanText($mapped['address'] ?? null),
                'billing_city' => $city,
                'billing_state' => $this->cleanText($mapped['state'] ?? null),
                'billing_pincode' => $this->cleanText($mapped['pincode'] ?? null),
                'address' => $this->cleanText($mapped['address'] ?? null),
                'city' => $city,
                'state' => $this->cleanText($mapped['state'] ?? null),
                'pincode' => $this->cleanText($mapped['pincode'] ?? null),
                'credit_terms' => $this->cleanText($mapped['credit_terms'] ?? null),
                'referral_percentage' => $referralPercentage,
                'account_manager' => $this->cleanText($mapped['account_manager'] ?? null),
                'notes' => $commonNotes,
                'location' => $this->cleanText($mapped['google_map_link'] ?? null),
                'status' => 'active',
            ], $errors];
        }

        if ($customerType === 'actual_client') {
            return [[
                'import_entity' => 'actual_client',
                'business_partner_id' => $businessPartner?->id,
                'parent_business_partner' => $parentPartnerName,
                'business_partner_code' => $businessPartnerCode,
                'client_name' => $name,
                'phone' => $phone,
                'alternate_phone' => $whatsApp && $whatsApp !== $phone ? $whatsApp : null,
                'address' => $this->cleanText($mapped['address'] ?? null),
                'city' => $city,
                'state' => $this->cleanText($mapped['state'] ?? null),
                'pincode' => $this->cleanText($mapped['pincode'] ?? null),
                'location' => $this->cleanText($mapped['google_map_link'] ?? null),
                'delivery_notes' => $commonNotes,
                'account_manager' => $this->cleanText($mapped['account_manager'] ?? null),
                'notes' => $commonNotes,
                'status' => 'active',
            ], $errors];
        }

        return [[
            'import_entity' => 'direct_customer',
            'name' => $name,
            'customer_type' => $this->normalizeCustomerType($mapped['customer_type'] ?? null, $mapped['company_name'] ?? null),
            'phone' => $phone,
            'whatsapp_number' => $whatsApp,
            'email' => $email,
            'company_name' => $this->cleanText($mapped['company_name'] ?? null),
            'contact_name' => $this->cleanText($mapped['contact_name'] ?? null),
            'gst_number' => $this->cleanText($mapped['gst_number'] ?? null),
            'gst_registered' => $this->normalizeImportBoolean($mapped['gst_registered'] ?? null) ?? false,
            'place_of_supply' => $this->cleanText($mapped['state'] ?? null),
            'address' => $this->cleanText($mapped['address'] ?? null),
            'city' => $city,
            'state' => $this->cleanText($mapped['state'] ?? null),
            'pincode' => $this->cleanText($mapped['pincode'] ?? null),
            'map_location_text' => $city,
            'map_location_url' => $this->cleanText($mapped['google_map_link'] ?? null),
            'notes' => $commonNotes,
        ], $errors];
    }

    private function normalizeProductRow(array $mapped, ?int $organizationId = null, array $mapping = [], array $fields = []): array
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
            $errors[] = $this->requiredFieldMappingError('name', 'Product name is required.', $mapping, $fields);
        }

        if (!$productType) {
            $productType = match (true) {
                $sellable === true && $rentable === true => Product::TYPE_BOTH,
                $rentable === true => Product::TYPE_RENTABLE,
                $sellable === true => Product::TYPE_SELLABLE,
                $stockMode === Product::STOCK_MODE_TRACKED_BOTH => Product::TYPE_BOTH,
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
        $rentalPrice = $this->normalizeDecimal($mapped['rental_price'] ?? null);
        $salePrice = $this->normalizeDecimal($mapped['sale_price'] ?? null);

        if ($productType === Product::TYPE_SELLABLE && $pricePerDay === null) {
            $pricePerDay = 0.0;
        }

        if (in_array($productType, [Product::TYPE_RENTABLE, Product::TYPE_BOTH], true) && $pricePerDay === null) {
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
            'sale_price' => $salePrice,
            'rental_price' => $rentalPrice,
            'gst_tax_type' => $this->normalizeGstTaxType($mapped['gst_tax_type'] ?? null),
            'gst_calculation_mode' => $this->normalizeGstCalculationMode($mapped['gst_calculation_mode'] ?? null),
            'cgst_rate' => $this->normalizeDecimal($mapped['cgst_rate'] ?? null) ?? 0.0,
            'sgst_rate' => $this->normalizeDecimal($mapped['sgst_rate'] ?? null) ?? 0.0,
            'igst_rate' => $this->normalizeDecimal($mapped['igst_rate'] ?? null) ?? 0.0,
        ], $errors];
    }

    private function normalizeAssetRow(array $mapped, int $organizationId): array
    {
        $productMatch = $this->resolveProductMatch($organizationId, $mapped);
        $product = $productMatch['product'];
        $warehouse = $this->resolveWarehouse($organizationId, $mapped);
        $errors = [];
        $city = $this->resolveCity($organizationId, $mapped, false);

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
            'city' => $city?->name,
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
        $customerType = $this->normalizeImportCustomerPartyType($mapped['customer_type'] ?? null);
        $city = $this->resolveCity($organizationId, $mapped, false);
        $businessPartner = $this->resolveBusinessPartner($organizationId, $mapped);
        $partnerClient = $businessPartner
            ? $this->resolvePartnerClient($organizationId, (int) $businessPartner->id, $mapped)
            : null;
        $vendor = $this->resolveVendor($organizationId, $mapped);
        $fulfilmentSource = $this->normalizeFulfilmentSource($mapped['fulfilment_source'] ?? null, $vendor?->id);
        $deliveryResponsibility = $this->normalizeDeliveryResponsibility($mapped['delivery_responsibility'] ?? null, $fulfilmentSource);
        $pickupResponsibility = $this->normalizePickupResponsibility($mapped['pickup_responsibility'] ?? null, $fulfilmentSource);
        $customerPayload = [
            'name' => $this->cleanText($mapped['actual_client_name'] ?? null) !== ''
                ? $mapped['actual_client_name']
                : ($mapped['customer_name'] ?? null),
            'phone' => $mapped['customer_phone'] ?? null,
            'email' => $mapped['customer_email'] ?? null,
            'city_name' => $mapped['city_name'] ?? $mapped['city'] ?? null,
        ];
        [$normalizedCustomer, $customerErrors] = $this->normalizeCustomerRow($customerPayload);
        $productMatch = $this->resolveProductMatch($organizationId, $mapped);
        $product = $productMatch['product'];
        $warehouse = $fulfilmentSource === VendorOrderDetail::FULFILMENT_SOURCE_IN_HOUSE
            ? $this->resolveWarehouse($organizationId, $mapped, false)
            : null;
        $status = $this->normalizeRentalStatus($mapped['status'] ?? null);
        $startDate = $this->normalizeDate($mapped['start_date'] ?? null);
        $endDate = $this->normalizeDate($mapped['end_date'] ?? null);
        $quantity = $this->normalizeInteger($mapped['quantity'] ?? null);
        $serials = $this->splitSerials($mapped['asset_serials'] ?? null);
        $resolvedAssets = ($product && $fulfilmentSource === VendorOrderDetail::FULFILMENT_SOURCE_IN_HOUSE)
            ? $this->resolveAssetsBySerials($organizationId, $product->id, $serials)
            : collect();
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

        if ($customerType === 'business_partner' && !$businessPartner) {
            $errors[] = 'Business Partner could not be matched. Use an existing partner name.';
        }

        if ($customerType === 'business_partner' && $this->cleanText($mapped['actual_client_name'] ?? null) !== '' && !$partnerClient) {
            $errors[] = 'Actual Client could not be matched under the selected Business Partner.';
        }

        if ($city === null && $this->cleanText($mapped['city_name'] ?? $mapped['city'] ?? null) !== '') {
            $errors[] = 'City must match an existing active PHOS city.';
        }

        if ($fulfilmentSource === VendorOrderDetail::FULFILMENT_SOURCE_VENDOR_SUPPLIED && !$vendor) {
            $errors[] = 'Vendor is required when fulfilment source is vendor supplied.';
        }

        if ($vendor && $city) {
            $vendorCityId = (int) ($vendor->city_id ?? 0);
            $vendorCity = Str::lower(trim((string) ($vendor->city ?? '')));
            if (($vendorCityId > 0 && $vendorCityId !== (int) $city->id) || ($vendorCity !== '' && $vendorCity !== Str::lower($city->name))) {
                $errors[] = 'Vendor does not serve the selected city.';
            }
        }

        if ($fulfilmentSource === VendorOrderDetail::FULFILMENT_SOURCE_IN_HOUSE && !$warehouse) {
            $errors[] = 'Dispatch warehouse is required for in-house rentals.';
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
            } elseif ($fulfilmentSource === VendorOrderDetail::FULFILMENT_SOURCE_IN_HOUSE && $matchAction !== 'update') {
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
            'customer_type' => $customerType,
            'business_partner_id' => $businessPartner?->id,
            'partner_client_id' => $partnerClient?->id,
            'vendor_id' => $vendor?->id,
            'fulfilment_source' => $fulfilmentSource,
            'delivery_responsibility' => $deliveryResponsibility,
            'pickup_responsibility' => $pickupResponsibility,
            'city_id' => $city?->id,
            'city_name' => $city?->name,
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
            'stock_applied' => $fulfilmentSource === VendorOrderDetail::FULFILMENT_SOURCE_IN_HOUSE,
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

    private function normalizeVendorRow(array $mapped, int $organizationId): array
    {
        $name = $this->cleanText($mapped['name'] ?? null);
        $phone = $this->normalizePhone($mapped['phone'] ?? null);
        $email = $this->normalizeEmail($mapped['email'] ?? null);
        $city = $this->resolveCity($organizationId, $mapped, false);
        $deliverySupported = $this->normalizeImportBoolean($mapped['delivery_supported'] ?? null);
        $pickupSupported = $this->normalizeImportBoolean($mapped['pickup_supported'] ?? null);
        $errors = [];

        if ($name === '') {
            $errors[] = 'Vendor name is required.';
        }

        if (filled($mapped['phone'] ?? null) && !$phone) {
            $errors[] = 'Vendor phone number is not valid.';
        }

        if (filled($mapped['email'] ?? null) && !$email) {
            $errors[] = 'Vendor email address is not valid.';
        }

        $notes = collect([
            $this->cleanText($mapped['notes'] ?? null),
            $deliverySupported !== null ? 'Delivery Supported: ' . ($deliverySupported ? 'Yes' : 'No') : null,
            $pickupSupported !== null ? 'Pickup Supported: ' . ($pickupSupported ? 'Yes' : 'No') : null,
        ])->filter()->implode(' | ');

        return [[
            'name' => $name,
            'contact_person' => $this->cleanText($mapped['contact_person'] ?? null),
            'phone' => $phone,
            'whatsapp' => $this->normalizePhone($mapped['whatsapp'] ?? null),
            'email' => $email,
            'vendor_type' => $this->cleanText($mapped['vendor_type'] ?? null) ?: 'supplier',
            'city_id' => $city?->id,
            'city' => $city?->name ?: $this->cleanText($mapped['city_name'] ?? $mapped['city'] ?? null),
            'state' => $this->cleanText($mapped['state'] ?? null),
            'pincode' => $this->cleanText($mapped['pincode'] ?? null),
            'gst_number' => $this->cleanText($mapped['gst_number'] ?? null),
            'gst_registration_type' => $this->cleanText($mapped['gst_registration_type'] ?? null),
            'payment_terms' => $this->cleanText($mapped['payment_terms'] ?? null),
            'address' => $this->cleanText($mapped['address'] ?? null),
            'is_active' => $this->normalizeImportBoolean($mapped['is_active'] ?? null) ?? true,
            'notes' => $notes !== '' ? $notes : null,
        ], $errors];
    }

    private function normalizeStaffRow(array $mapped, int $organizationId): array
    {
        $name = $this->cleanText($mapped['name'] ?? null);
        $email = $this->normalizeEmail($mapped['email'] ?? null);
        $phone = $this->normalizePhone($mapped['phone'] ?? null);
        $city = $this->resolveCity($organizationId, $mapped, false);
        $errors = [];

        if ($name === '') {
            $errors[] = 'Staff name is required.';
        }

        if (filled($mapped['email'] ?? null) && !$email) {
            $errors[] = 'Staff email address is not valid.';
        }

        if (filled($mapped['phone'] ?? null) && !$phone) {
            $errors[] = 'Staff phone number is not valid.';
        }

        $role = Staff::normalizedRole($mapped['role'] ?? 'office');
        $assignmentRole = $this->cleanText($mapped['assignment_role'] ?? null);
        $assignmentRole = $assignmentRole !== '' ? Staff::normalizedRole($assignmentRole) : null;

        return [[
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'role' => $role,
            'assignment_role' => $assignmentRole,
            'is_assignment_enabled' => $assignmentRole !== null,
            'city' => $city?->name ?: $this->cleanText($mapped['city_name'] ?? $mapped['city'] ?? null),
            'status' => Str::lower($this->cleanText($mapped['status'] ?? null)) === 'inactive' ? 'inactive' : 'active',
            'joining_date' => $this->normalizeDate($mapped['joining_date'] ?? null),
            'salary' => $this->normalizeDecimal($mapped['salary'] ?? null),
            'address' => $this->cleanText($mapped['address'] ?? null),
            'notes' => $this->cleanText($mapped['notes'] ?? null),
        ], $errors];
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
        $blankRowCount = 0;
        $rawRowCount = 0;
        $nonEmptyRowCount = 0;
        $headerRowNumber = 0;
        $lineNumber = 0;
        $maxColumnCount = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $lineNumber++;
            $maxColumnCount = max($maxColumnCount, count($row));

            if ($headers === []) {
                if ($this->rowIsEmpty($row)) {
                    continue;
                }
                $headers = $this->normalizeHeaders($row);
                $headerRowNumber = $lineNumber;
                continue;
            }

            $rawRowCount++;

            if ($this->rowIsEmpty($row)) {
                $blankRowCount++;
                continue;
            }

            $nonEmptyRowCount++;
            $rows[] = $this->associateRow($headers, $row);
        }

        fclose($handle);

        return [
            'headers' => $headers,
            'rows' => $rows,
            'blank_row_count' => $blankRowCount,
            'raw_row_count' => $rawRowCount,
            'non_empty_row_count' => $nonEmptyRowCount,
            'sheet_name' => 'CSV',
            'header_row_number' => $headerRowNumber > 0 ? $headerRowNumber : 1,
            'highest_row' => $lineNumber,
            'highest_column' => $maxColumnCount > 0 ? $this->columnReference(max($maxColumnCount - 1, 0)) : null,
        ];
    }

    private function parseXlsx(string $path): array
    {
        $zip = new ZipArchive();

        if ($zip->open($path) !== true) {
            throw new RuntimeException('Unable to open uploaded XLSX file.');
        }

        $sharedStrings = $this->readSharedStrings($zip);
        $sheetInfo = $this->firstWorksheet($zip);
        $sheetXml = $sheetInfo['xml'] ?? false;

        if ($sheetXml === false) {
            $zip->close();
            throw new RuntimeException('The XLSX file does not contain a readable first worksheet.');
        }

        $worksheet = simplexml_load_string($sheetXml);
        $headers = [];
        $rows = [];
        $blankRowCount = 0;
        $rawRowCount = 0;
        $nonEmptyRowCount = 0;
        $headerRowNumber = 0;
        $worksheetRowIndex = 0;
        $highestRow = 0;
        $highestColumnIndex = -1;

        foreach ($worksheet?->xpath('/*[local-name()="worksheet"]/*[local-name()="sheetData"]/*[local-name()="row"]') ?? [] as $row) {
            $worksheetRowIndex++;
            $cells = [];

            foreach ($row->xpath('./*[local-name()="c"]') ?? [] as $cell) {
                $reference = (string) $cell['r'];
                $columnIndex = $this->columnIndexFromReference($reference);
                $cells[$columnIndex] = $this->xlsxCellValue($cell, $sharedStrings);
                $highestColumnIndex = max($highestColumnIndex, $columnIndex);
            }

            $rowNumber = (int) ((string) ($row['r'] ?? ''));
            if ($rowNumber <= 0) {
                $rowNumber = $worksheetRowIndex;
            }
            $highestRow = max($highestRow, $rowNumber);

            if ($headers === []) {
                if ($this->rowIsEmpty($cells)) {
                    continue;
                }
                ksort($cells);
                $headers = $this->normalizeHeaders(array_values($cells));
                $headerRowNumber = $rowNumber;
                continue;
            }

            $rawRowCount++;

            if ($this->rowIsEmpty($cells)) {
                $blankRowCount++;
                continue;
            }

            $nonEmptyRowCount++;
            $ordered = [];
            for ($index = 0; $index < count($headers); $index++) {
                $ordered[$index] = $cells[$index] ?? null;
            }

            $rows[] = $this->associateRow($headers, $ordered);
        }

        $zip->close();

        return [
            'headers' => $headers,
            'rows' => $rows,
            'blank_row_count' => $blankRowCount,
            'raw_row_count' => $rawRowCount,
            'non_empty_row_count' => $nonEmptyRowCount,
            'sheet_name' => $sheetInfo['name'] ?? 'Sheet 1',
            'header_row_number' => $headerRowNumber > 0 ? $headerRowNumber : 1,
            'highest_row' => $highestRow,
            'highest_column' => $highestColumnIndex >= 0 ? $this->columnReference($highestColumnIndex) : null,
        ];
    }

    private function firstWorksheet(ZipArchive $zip): array
    {
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relationshipsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');

        if ($workbookXml !== false && $relationshipsXml !== false) {
            $workbook = simplexml_load_string($workbookXml);
            $relationships = simplexml_load_string($relationshipsXml);

            $firstSheet = ($workbook?->xpath('/*[local-name()="workbook"]/*[local-name()="sheets"]/*[local-name()="sheet"][1]') ?? [])[0] ?? null;
            $sheetRelationshipId = '';
            $sheetName = '';

            if ($firstSheet) {
                $sheetName = trim((string) ($firstSheet['name'] ?? ''));
                $relationshipAttributes = $firstSheet->attributes('r', true);
                $sheetRelationshipId = trim((string) ($relationshipAttributes?->id ?? $firstSheet['id'] ?? ''));
            }

            if ($sheetRelationshipId !== '') {
                foreach ($relationships?->xpath('/*[local-name()="Relationships"]/*[local-name()="Relationship"]') ?? [] as $relationship) {
                    if ((string) $relationship['Id'] !== $sheetRelationshipId) {
                        continue;
                    }

                    $target = trim((string) $relationship['Target']);
                    if ($target === '') {
                        continue;
                    }

                    $normalizedTarget = str_starts_with($target, '/')
                        ? ltrim($target, '/')
                        : 'xl/' . ltrim(str_replace('\\', '/', $target), '/');

                    $sheetXml = $zip->getFromName($normalizedTarget);
                    if ($sheetXml !== false) {
                        return [
                            'xml' => $sheetXml,
                            'name' => $sheetName !== '' ? $sheetName : 'Sheet 1',
                        ];
                    }
                }
            }
        }

        $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');

        return [
            'xml' => $sheetXml,
            'name' => 'Sheet 1',
        ];
    }

    private function readSharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');

        if ($xml === false) {
            return [];
        }

        $document = simplexml_load_string($xml);

        return collect($document?->xpath('/*[local-name()="sst"]/*[local-name()="si"]') ?? [])
            ->map(function ($stringItem) {
                $directText = $stringItem->xpath('./*[local-name()="t"]');
                if (!empty($directText)) {
                    return (string) ($directText[0] ?? '');
                }

                return collect($stringItem->xpath('./*[local-name()="r"]') ?? [])
                    ->map(function ($run) {
                        $textNodes = $run->xpath('./*[local-name()="t"]');

                        return (string) ($textNodes[0] ?? '');
                    })
                    ->implode('');
            })
            ->all();
    }

    private function xlsxCellValue(\SimpleXMLElement $cell, array $sharedStrings): ?string
    {
        $type = (string) ($cell['t'] ?? '');

        if ($type === 'inlineStr') {
            $textNodes = $cell->xpath('./*[local-name()="is"]/*[local-name()="t"]');

            return trim((string) ($textNodes[0] ?? ''));
        }

        $valueNodes = $cell->xpath('./*[local-name()="v"]');
        $value = isset($valueNodes[0]) ? (string) $valueNodes[0] : '';

        if ($type === 's') {
            return isset($sharedStrings[(int) $value]) ? trim((string) $sharedStrings[(int) $value]) : '';
        }

        return trim($value);
    }

    private function applyMapping(array $row, array $mapping): array
    {
        $mapped = [];

        foreach ($mapping as $field => $header) {
            $value = null;

            if ($header && array_key_exists($header, $row)) {
                $value = $row[$header];
            } elseif ($header) {
                $headerSlug = $this->slugKey($header);
                foreach ($row as $rowHeader => $rowValue) {
                    if ($this->slugKey($rowHeader) === $headerSlug) {
                        $value = $rowValue;
                        break;
                    }
                }
            }

            $mapped[$field] = $value;
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
                $value = $this->cleanHeaderText($header);
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
        return Str::of($this->cleanHeaderText($value))->lower()->replaceMatches('/[^a-z0-9]+/', '_')->trim('_')->toString();
    }

    private function cleanHeaderText(?string $value): string
    {
        $value = (string) $value;
        $value = preg_replace('/^\xEF\xBB\xBF/u', '', $value) ?? $value;
        $value = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}\r\n\t]+/u', ' ', $value) ?? $value;
        $value = str_replace('*', '', $value);
        $value = str_replace(['_', '-'], ' ', $value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private function requiredFieldMappingError(string $fieldKey, string $fallbackMessage, array $mapping, array $fields): string
    {
        if (!filled($mapping[$fieldKey] ?? null)) {
            $label = $fields[$fieldKey]['label'] ?? Str::of($fieldKey)->replace('_', ' ')->title()->toString();

            return 'Could not map ' . $label . ' column.';
        }

        return $fallbackMessage;
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

    private function normalizeUnifiedCustomerImportType(?string $value, ?string $companyName = null): string
    {
        $normalized = Str::lower(trim((string) $value));

        return match (true) {
            in_array($normalized, ['business_partner', 'business partner', 'partner'], true) => 'business_partner',
            in_array($normalized, ['actual_client', 'actual client', 'partner_client', 'client'], true) => 'actual_client',
            in_array($normalized, ['direct_customer', 'direct customer', 'individual', 'customer', 'direct'], true) => 'direct_customer',
            filled($companyName) => 'business_partner',
            default => 'direct_customer',
        };
    }

    private function normalizeProductType(?string $value): ?string
    {
        return match (Str::lower(trim((string) $value))) {
            'sellable', 'sale', 'sales' => Product::TYPE_SELLABLE,
            'rentable', 'rental', 'rent' => Product::TYPE_RENTABLE,
            'both', 'sellable+rentable', 'sellable_rentable', 'sale+rental', 'sale_rental', 'mixed' => Product::TYPE_BOTH,
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

    private function normalizeImportCustomerPartyType(?string $value): string
    {
        $normalized = Str::lower(trim((string) $value));

        return match ($normalized) {
            'business_partner', 'business partner', 'partner' => 'business_partner',
            'actual_client', 'actual client', 'partner_client', 'client' => 'business_partner',
            default => 'direct_customer',
        };
    }

    private function normalizeFulfilmentSource(?string $value, ?int $vendorId = null): string
    {
        $normalized = Str::lower(trim((string) $value));

        return match ($normalized) {
            VendorOrderDetail::FULFILMENT_SOURCE_VENDOR_SUPPLIED, 'vendor supplied', 'vendor' => VendorOrderDetail::FULFILMENT_SOURCE_VENDOR_SUPPLIED,
            VendorOrderDetail::FULFILMENT_SOURCE_IN_HOUSE, 'in house', 'in-house' => VendorOrderDetail::FULFILMENT_SOURCE_IN_HOUSE,
            default => $vendorId ? VendorOrderDetail::FULFILMENT_SOURCE_VENDOR_SUPPLIED : VendorOrderDetail::FULFILMENT_SOURCE_IN_HOUSE,
        };
    }

    private function normalizeDeliveryResponsibility(?string $value, string $fulfilmentSource): string
    {
        $normalized = Str::lower(trim((string) $value));

        return match ($normalized) {
            'vendor_delivery', 'vendor delivery', 'vendor' => 'vendor_delivery',
            'customer_pickup', 'customer pickup' => 'customer_pickup',
            'ph_internal_delivery', 'ph internal delivery', 'ph_internal', 'internal' => 'ph_internal_delivery',
            default => $fulfilmentSource === VendorOrderDetail::FULFILMENT_SOURCE_VENDOR_SUPPLIED ? 'vendor_delivery' : 'ph_internal_delivery',
        };
    }

    private function normalizePickupResponsibility(?string $value, string $fulfilmentSource): string
    {
        $normalized = Str::lower(trim((string) $value));

        return match ($normalized) {
            'vendor_pickup', 'vendor pickup', 'vendor' => 'vendor_pickup',
            'customer_return', 'customer return', 'customer_pickup', 'customer pickup' => 'customer_return',
            'ph_internal_pickup', 'ph internal pickup', 'ph_internal', 'internal' => 'ph_internal_pickup',
            default => $fulfilmentSource === VendorOrderDetail::FULFILMENT_SOURCE_VENDOR_SUPPLIED ? 'customer_return' : 'ph_internal_pickup',
        };
    }

    private function normalizeGstTaxType(?string $value): string
    {
        $normalized = Str::lower(trim((string) $value));

        return match ($normalized) {
            'igst' => Product::GST_TAX_TYPE_IGST,
            default => Product::GST_TAX_TYPE_CGST_SGST,
        };
    }

    private function normalizeGstCalculationMode(?string $value): string
    {
        $normalized = Str::lower(trim((string) $value));

        return match ($normalized) {
            'inclusive' => 'inclusive',
            default => 'exclusive',
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
        $warehouse = $cache['code'][$code] ?? $cache['name'][$name] ?? null;

        if (!$warehouse) {
            return null;
        }

        $cityName = Str::lower($this->cleanText($mapped['city_name'] ?? $mapped['city'] ?? null));
        if ($cityName !== '') {
            $warehouseCity = Str::lower(trim((string) ($warehouse->city ?? '')));
            $warehouseCityId = (int) ($warehouse->city_id ?? 0);
            $city = $this->resolveCity($organizationId, $mapped, false);

            if ($city && $warehouseCityId > 0 && $warehouseCityId !== (int) $city->id) {
                return null;
            }

            if ($warehouseCity !== '' && $warehouseCity !== $cityName) {
                return null;
            }
        }

        return $warehouse;
    }

    private function resolveCity(int $organizationId, array $mapped, bool $required = false): ?City
    {
        $cityName = Str::lower($this->cleanText($mapped['city_name'] ?? $mapped['city'] ?? null));

        if (!$required && $cityName === '') {
            return null;
        }

        if (!isset($this->cityCache[$organizationId])) {
            $this->cityCache[$organizationId] = City::query()
                ->where('organization_id', $organizationId)
                ->where('is_active', true)
                ->get()
                ->keyBy(fn (City $city) => Str::lower((string) $city->name));
        }

        return $this->cityCache[$organizationId][$cityName] ?? null;
    }

    private function resolveVendor(int $organizationId, array $mapped): ?Vendor
    {
        $name = Str::lower($this->cleanText($mapped['vendor_name'] ?? $mapped['vendor'] ?? null));

        if ($name === '') {
            return null;
        }

        if (!isset($this->vendorCache[$organizationId])) {
            $this->vendorCache[$organizationId] = Vendor::query()
                ->where('organization_id', $organizationId)
                ->get()
                ->keyBy(fn (Vendor $vendor) => Str::lower((string) $vendor->name));
        }

        return $this->vendorCache[$organizationId][$name] ?? null;
    }

    private function resolveBusinessPartner(int $organizationId, array $mapped): ?BusinessPartner
    {
        $name = Str::lower($this->cleanText($mapped['business_partner_name'] ?? $mapped['business_partner'] ?? null));

        if ($name === '') {
            return null;
        }

        if (!isset($this->partnerCache[$organizationId])) {
            $this->partnerCache[$organizationId] = BusinessPartner::query()
                ->where('organization_id', $organizationId)
                ->get()
                ->keyBy(fn (BusinessPartner $partner) => Str::lower((string) $partner->business_name));
        }

        return $this->partnerCache[$organizationId][$name] ?? null;
    }

    private function resolveBusinessPartnerForCustomerImport(int $organizationId, array $mapped): ?BusinessPartner
    {
        $partnerCode = $this->cleanText($mapped['business_partner_code'] ?? $mapped['partner_code'] ?? null);

        if ($partnerCode !== '' && Schema::hasColumn('business_partners', 'partner_code')) {
            $partner = BusinessPartner::query()
                ->where('organization_id', $organizationId)
                ->where('partner_code', $partnerCode)
                ->first();

            if ($partner) {
                return $partner;
            }
        }

        $name = Str::lower($this->cleanText(
            $mapped['parent_business_partner']
            ?? $mapped['business_partner_name']
            ?? $mapped['business_partner']
            ?? null
        ));

        if ($name === '') {
            return null;
        }

        if (!isset($this->partnerCache[$organizationId])) {
            $this->partnerCache[$organizationId] = BusinessPartner::query()
                ->where('organization_id', $organizationId)
                ->get()
                ->keyBy(fn (BusinessPartner $partner) => Str::lower((string) $partner->business_name));
        }

        return $this->partnerCache[$organizationId][$name] ?? null;
    }

    private function matchesPendingCustomerImportPartner(array $context, string $parentPartnerName, string $partnerCode): bool
    {
        $pendingPartners = $context['pending_business_partners'] ?? [];

        if (!is_array($pendingPartners)) {
            return false;
        }

        $partnerCodeKey = Str::lower(trim($partnerCode));
        if ($partnerCodeKey !== '' && !empty($pendingPartners[$partnerCodeKey])) {
            return true;
        }

        $partnerNameKey = Str::lower(trim($parentPartnerName));

        return $partnerNameKey !== '' && !empty($pendingPartners[$partnerNameKey]);
    }

    private function resolvePartnerClient(int $organizationId, int $partnerId, array $mapped): ?PartnerClient
    {
        $name = Str::lower($this->cleanText($mapped['actual_client_name'] ?? $mapped['actual_client'] ?? null));

        if ($name === '') {
            return null;
        }

        return PartnerClient::query()
            ->where('organization_id', $organizationId)
            ->where('business_partner_id', $partnerId)
            ->whereRaw('LOWER(client_name) = ?', [$name])
            ->first();
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

    private function findProductForImportUpsert(int $organizationId, array $payload, ?array $upsertWhitelistIds = null): ?Product
    {
        $query = Product::query()->where('organization_id', $organizationId);

        if (is_array($upsertWhitelistIds)) {
            $query->whereIn('id', $upsertWhitelistIds);
        }

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

    private function productImportIdentityKey(array $payload): ?string
    {
        $name = Str::lower($this->cleanText($payload['name'] ?? null));

        if ($name === '') {
            return null;
        }

        return implode('|', [
            $name,
            Str::lower($this->cleanText($payload['brand'] ?? null)),
            Str::lower($this->cleanText($payload['model_name'] ?? null)),
        ]);
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

    private function normalizeErrorDetails(array $errors): array
    {
        if ($errors === []) {
            return [];
        }

        $details = collect($errors)
            ->map(function ($error) {
                if (is_array($error)) {
                    return [
                        'field' => (string) ($error['field'] ?? 'general'),
                        'reason' => (string) ($error['reason'] ?? ''),
                    ];
                }

                $reason = trim((string) $error);

                return [
                    'field' => $this->inferErrorField($reason),
                    'reason' => $reason,
                ];
            })
            ->filter(fn (array $detail) => $detail['reason'] !== '')
            ->values()
            ->all();

        return $details !== [] ? $details : [$this->errorDetail('general', 'Unknown import validation error.')];
    }

    private function errorDetail(?string $field, string $reason): array
    {
        return [
            'field' => $field ?: 'general',
            'reason' => $reason,
        ];
    }

    private function inferErrorField(string $reason): string
    {
        $normalized = Str::lower($reason);

        return match (true) {
            str_contains($normalized, 'vendor phone') => 'phone',
            str_contains($normalized, 'vendor email') => 'email',
            str_contains($normalized, 'customer') => 'customer',
            str_contains($normalized, 'parent business partner') => 'parent_business_partner',
            str_contains($normalized, 'partner code') => 'business_partner_code',
            str_contains($normalized, 'business partner') => 'business_partner_name',
            str_contains($normalized, 'actual client') => 'actual_client_name',
            str_contains($normalized, 'vendor') => 'vendor_name',
            str_contains($normalized, 'warehouse') => 'warehouse_name',
            str_contains($normalized, 'city') => 'city_name',
            str_contains($normalized, 'delivery') => 'delivery_responsibility',
            str_contains($normalized, 'pickup') => 'pickup_responsibility',
            str_contains($normalized, 'product') => 'product_name',
            str_contains($normalized, 'serial') || str_contains($normalized, 'asset') => 'asset_serials',
            str_contains($normalized, 'phone') => 'phone',
            str_contains($normalized, 'email') => 'email',
            str_contains($normalized, 'payment') => 'payment_status',
            str_contains($normalized, 'invoice') => 'invoice_status',
            str_contains($normalized, 'date') => 'date',
            default => 'general',
        };
    }

    private function templateSampleValue(string $module, ?string $field): string
    {
        return match ($module . ':' . $field) {
            'customers:city_name', 'rentals:city_name', 'assets:city_name', 'vendors:city_name', 'staff:city_name' => 'Bengaluru',
            'customers:business_partner_name', 'customers:parent_business_partner', 'rentals:business_partner_name', 'sales:business_partner_name' => 'Care Plus Clinic',
            'customers:business_partner_code' => 'BP-CAREPLUS',
            'customers:google_map_link' => 'https://maps.google.com/?q=Care+Plus+Clinic+Noida',
            'rentals:fulfilment_source', 'sales:fulfilment_source' => 'in_house',
            'rentals:delivery_responsibility', 'sales:delivery_responsibility' => 'ph_internal_delivery',
            'rentals:pickup_responsibility' => 'ph_internal_pickup',
            'rentals:actual_client_name', 'sales:actual_client_name' => 'Rahul Verma',
            'vendors:name' => 'KR Healthcare',
            'staff:role' => 'delivery',
            default => '',
        };
    }

    private function templateAcceptedValues(string $module, ?string $field): string
    {
        return match ($field) {
            'customer_type' => match ($module) {
                self::CUSTOMER => 'direct_customer, business_partner, actual_client',
                self::RENTAL => 'direct_customer, business_partner',
                default => 'individual, business',
            },
            'product_type' => 'sellable, rentable, both',
            'stock_mode' => 'untracked, tracked_sale, tracked_rental, tracked_both',
            'asset_stage' => 'new_stock, rental_stock',
            'status' => $module === self::RENTAL ? 'active, completed' : ($module === self::STAFF ? 'active, inactive' : ''),
            'payment_status' => 'pending, partial, paid',
            'invoice_status' => 'generated, not_generated, paid, unpaid, partial, pending',
            'delivery_status', 'pickup_status' => 'not_assigned, assigned, pending, completed',
            'fulfilment_source' => 'in_house, vendor_supplied',
            'delivery_responsibility' => 'ph_internal_delivery, vendor_delivery, customer_pickup',
            'pickup_responsibility' => 'ph_internal_pickup, vendor_pickup, customer_return',
            'role' => implode(', ', Staff::ROLE_OPTIONS),
            'assignment_role' => implode(', ', Staff::ASSIGNMENT_ROLES),
            'gst_tax_type' => 'cgst_sgst, igst',
            'gst_calculation_mode' => 'exclusive, inclusive',
            default => '',
        };
    }

    private function templateFieldDescription(string $module, ?string $field): string
    {
        return match ($field) {
            'city_name' => 'Use an existing PHOS city name so warehouses, vendors, and assignments align to the city-first setup.',
            'business_partner_name' => $module === self::CUSTOMER
                ? 'For business_partner rows this can match the partner name. For actual_client rows it can help identify the parent partner.'
                : 'Required when importing a partner-managed rental or sale.',
            'business_partner_code' => 'Optional partner code for business partner creation and parent partner matching.',
            'parent_business_partner' => 'Required when Customer Type is actual_client. Must match an existing imported or existing business partner.',
            'google_map_link' => 'Customer or partner location link. Stored as map URL where supported.',
            'credit_terms' => 'Accepted for business partner rows to store commercial terms.',
            'referral_percentage' => 'Accepted for business partner rows as a numeric referral percentage.',
            'account_manager' => 'Optional account owner / relationship manager for imported customer records.',
            'actual_client_name' => 'Optional actual end-client under the selected Business Partner.',
            'fulfilment_source' => 'Defaults to in_house. Set vendor_supplied to skip PH stock and asset reservation.',
            'delivery_responsibility' => 'Controls delivery execution ownership for imported orders.',
            'pickup_responsibility' => 'Controls pickup execution ownership for imported rental returns.',
            'warehouse_name', 'warehouse_code' => 'Use an existing active warehouse. Required for in-house stock-backed imports.',
            'role', 'assignment_role' => 'Role support for staff directory and assignment routing.',
            default => 'Optional import field.',
        };
    }

    private function buildXlsxWorkbook(array $sheets): string
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'phos-import-template-');
        $zip = new ZipArchive();

        if ($tmpPath === false || $zip->open($tmpPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Unable to create import template workbook.');
        }

        $zip->addFromString('[Content_Types].xml', $this->xlsxContentTypes(count($sheets)));
        $zip->addFromString('_rels/.rels', $this->xlsxRootRelationships());
        $zip->addFromString('xl/workbook.xml', $this->xlsxWorkbook($sheets));
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->xlsxWorkbookRelationships(count($sheets)));
        $zip->addFromString('docProps/core.xml', $this->xlsxCoreProperties());
        $zip->addFromString('docProps/app.xml', $this->xlsxAppProperties($sheets));

        $sheetIndex = 1;
        foreach ($sheets as $title => $rows) {
            $zip->addFromString('xl/worksheets/sheet' . $sheetIndex . '.xml', $this->xlsxWorksheet($rows));
            $sheetIndex++;
        }

        $zip->close();
        $content = (string) file_get_contents($tmpPath);
        @unlink($tmpPath);

        return $content;
    }

    private function xlsxContentTypes(int $sheetCount): string
    {
        $sheetOverrides = '';
        for ($i = 1; $i <= $sheetCount; $i++) {
            $sheetOverrides .= '<Override PartName="/xl/worksheets/sheet'.$i.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . $sheetOverrides
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . '</Types>';
    }

    private function xlsxRootRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>';
    }

    private function xlsxWorkbook(array $sheets): string
    {
        $sheetNodes = '';
        $sheetId = 1;
        foreach (array_keys($sheets) as $title) {
            $sheetNodes .= '<sheet name="' . e($title) . '" sheetId="' . $sheetId . '" r:id="rId' . $sheetId . '"/>';
            $sheetId++;
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets>' . $sheetNodes . '</sheets>'
            . '</workbook>';
    }

    private function xlsxWorkbookRelationships(int $sheetCount): string
    {
        $rels = '';
        for ($i = 1; $i <= $sheetCount; $i++) {
            $rels .= '<Relationship Id="rId'.$i.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$i.'.xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $rels
            . '</Relationships>';
    }

    private function xlsxCoreProperties(): string
    {
        $timestamp = now()->toAtomString();

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>PHOS Import Template</dc:title>'
            . '<dc:creator>Prime Healers OS</dc:creator>'
            . '<cp:lastModifiedBy>Prime Healers OS</cp:lastModifiedBy>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">'.$timestamp.'</dcterms:created>'
            . '<dcterms:modified xsi:type="dcterms:W3CDTF">'.$timestamp.'</dcterms:modified>'
            . '</cp:coreProperties>';
    }

    private function xlsxAppProperties(array $sheets): string
    {
        $titles = implode('', array_map(fn ($title) => '<vt:lpstr>'.e($title).'</vt:lpstr>', array_keys($sheets)));
        $count = count($sheets);

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            . '<Application>Prime Healers OS</Application>'
            . '<TitlesOfParts><vt:vector size="'.$count.'" baseType="lpstr">'.$titles.'</vt:vector></TitlesOfParts>'
            . '</Properties>';
    }

    private function xlsxWorksheet(array $rows): string
    {
        $xmlRows = '';
        foreach ($rows as $rowIndex => $row) {
            $cellXml = '';
            foreach (array_values($row) as $columnIndex => $value) {
                $ref = $this->columnReference($columnIndex) . ($rowIndex + 1);
                $safeValue = htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $cellXml .= '<c r="'.$ref.'" t="inlineStr"><is><t>'.$safeValue.'</t></is></c>';
            }
            $xmlRows .= '<row r="'.($rowIndex + 1).'">'.$cellXml.'</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0"/></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="18"/>'
            . '<sheetData>'.$xmlRows.'</sheetData>'
            . '</worksheet>';
    }

    private function columnReference(int $index): string
    {
        $index++;
        $reference = '';

        while ($index > 0) {
            $mod = ($index - 1) % 26;
            $reference = chr(65 + $mod) . $reference;
            $index = intdiv($index - 1, 26);
        }

        return $reference;
    }
}
