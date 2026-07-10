<?php

namespace Tests\Feature\Regression;

use App\Models\Asset;
use App\Models\BusinessPartner;
use App\Models\City;
use App\Models\Customer;
use App\Models\PartnerClient;
use App\Models\Product;
use App\Models\Staff;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\ImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Support\TestData;
use Tests\TestCase;
use ZipArchive;

class GenericImportExecutionRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_import_preview_rejects_ambiguous_name_without_phone_or_email(): void
    {
        $organization = TestData::organization();
        $user = TestData::user($organization);
        $this->actingAs($user);

        Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Aarav Sharma',
            'phone' => '9000000001',
        ]);

        Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Aarav Sharma',
            'phone' => '9000000002',
        ]);

        [$service, $preview] = $this->buildPreview('customers', [
            'Name,Phone,Email,Address,City,GSTIN',
            'Aarav Sharma,,,,Bengaluru,',
        ]);

        $this->assertSame(0, count($preview['valid_rows']));
        $this->assertNotEmpty($preview['invalid_rows']);
        $this->assertStringContainsString(
            'Multiple customers already use this name. Provide phone or email so the importer can match the correct customer.',
            implode(' | ', $preview['invalid_rows'][0]['errors'] ?? [])
        );
    }

    public function test_product_import_preview_rejects_ambiguous_name_without_brand_and_model(): void
    {
        $organization = TestData::organization();
        $user = TestData::user($organization);
        $this->actingAs($user);

        Product::create([
            'organization_id' => $organization->id,
            'name' => 'Oxygen Concentrator 5 LPM',
            'brand' => 'Philips',
            'model_name' => 'SimplyGo',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_RENTAL,
            'price_per_day' => 4500,
            'rental_price' => 4500,
            'total_quantity' => 0,
            'available_quantity' => 0,
        ]);

        Product::create([
            'organization_id' => $organization->id,
            'name' => 'Oxygen Concentrator 5 LPM',
            'brand' => 'Oxymed',
            'model_name' => 'Mini',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_RENTAL,
            'price_per_day' => 4200,
            'rental_price' => 4200,
            'total_quantity' => 0,
            'available_quantity' => 0,
        ]);

        [$service, $preview] = $this->buildPreview('products', [
            'Product Name,Category,Brand,Model Name,SKU,Product Code,Sellable,Rentable,Stock Mode,Sale Price,Rental Price,Deposit',
            'Oxygen Concentrator 5 LPM,Respiratory,,,,,No,Yes,tracked_rental,,4500,5000',
        ]);

        $this->assertSame(0, count($preview['valid_rows']));
        $this->assertNotEmpty($preview['invalid_rows']);
        $this->assertStringContainsString(
            'Multiple products share this name. Specify Brand and Model.',
            implode(' | ', $preview['invalid_rows'][0]['errors'] ?? [])
        );
    }

    public function test_product_import_updates_exact_variant_without_touching_other_variants(): void
    {
        $organization = TestData::organization();
        $user = TestData::user($organization);
        $this->actingAs($user);

        $philips = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Oxygen Concentrator 5 LPM',
            'brand' => 'Philips',
            'model_name' => 'SimplyGo',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_RENTAL,
            'price_per_day' => 4500,
            'rental_price' => 4500,
            'total_quantity' => 0,
            'available_quantity' => 0,
        ]);

        $oxymed = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Oxygen Concentrator 5 LPM',
            'brand' => 'Oxymed',
            'model_name' => 'Mini',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_RENTAL,
            'price_per_day' => 4200,
            'rental_price' => 4200,
            'total_quantity' => 0,
            'available_quantity' => 0,
        ]);

        [$service, $preview] = $this->buildPreview('products', [
            'Product Name,Category,Brand,Model Name,SKU,Product Code,Sellable,Rentable,Stock Mode,Price Per Day,Rental Price,Sale Price,Deposit',
            'Oxygen Concentrator 5 LPM,Respiratory,Oxymed,Mini,,,No,Yes,tracked_rental,5000,5500,,',
        ]);

        $result = $service->executePreview('products', $preview['key'], $organization->id, $user->id);

        $this->assertSame(1, $result['updated']);
        $this->assertSame(5000.0, (float) $oxymed->fresh()->price_per_day);
        $this->assertSame(5500.0, (float) $oxymed->fresh()->rental_price);
        $this->assertSame(4500.0, (float) $philips->fresh()->rental_price);
    }

    public function test_product_import_preview_rejects_duplicate_product_identity_within_same_file(): void
    {
        $organization = TestData::organization();
        $user = TestData::user($organization);
        $this->actingAs($user);

        [$service, $preview] = $this->buildPreview('products', [
            'Product Name,Category,Brand,Model Name,SKU,Product Code,Sellable,Rentable,Stock Mode,Price Per Day,Rental Price,Sale Price,Deposit',
            'Oxygen Concentrator 5 LPM,Respiratory,,,,,No,Yes,tracked_rental,4500,4500,,',
            'Oxygen Concentrator 5 LPM,Respiratory,,,,,No,Yes,tracked_rental,4800,4800,,',
        ]);

        $this->assertSame(1, count($preview['valid_rows']));
        $this->assertSame(1, count($preview['invalid_rows']));
        $this->assertStringContainsString(
            'This file contains another row with the same Product Name, Brand, and Model',
            implode(' | ', $preview['invalid_rows'][0]['errors'] ?? [])
        );
    }

    public function test_product_import_creates_two_distinct_variants_from_same_file(): void
    {
        $organization = TestData::organization();
        $user = TestData::user($organization);
        $this->actingAs($user);

        [$service, $preview] = $this->buildPreview('products', [
            'Product Name,Category,Brand,Model Name,SKU,Product Code,Sellable,Rentable,Stock Mode,Price Per Day,Rental Price,Sale Price,Deposit',
            'Oxygen Concentrator 5 LPM,Respiratory,Philips,SimplyGo,,,No,Yes,tracked_rental,4500,4500,,',
            'Oxygen Concentrator 5 LPM,Respiratory,Oxymed,Mini,,,No,Yes,tracked_rental,4200,4200,,',
        ]);

        $this->assertSame(2, count($preview['valid_rows']));

        $result = $service->executePreview('products', $preview['key'], $organization->id, $user->id);

        $this->assertSame(2, $result['created']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(2, Product::query()->where('organization_id', $organization->id)->count());
        $this->assertDatabaseHas('products', [
            'organization_id' => $organization->id,
            'name' => 'Oxygen Concentrator 5 LPM',
            'brand' => 'Philips',
            'model_name' => 'SimplyGo',
        ]);
        $this->assertDatabaseHas('products', [
            'organization_id' => $organization->id,
            'name' => 'Oxygen Concentrator 5 LPM',
            'brand' => 'Oxymed',
            'model_name' => 'Mini',
        ]);
    }

    public function test_product_import_allows_sellable_bipap_row_with_blank_rental_price(): void
    {
        $organization = TestData::organization();
        $user = TestData::user($organization);
        $this->actingAs($user);

        [$service, $preview] = $this->buildPreview('products', [
            'Product Name,Category,Brand,Model Name,SKU,Product Code,Sellable,Rentable,Stock Mode,Sale Price,Rental Price,Deposit',
            'BiPAP Disposable Filter,Consumables,ResMed,Filter Pack,BF-180,BIPAP-FLTR,Yes,No,untracked,180,,0',
        ]);

        $this->assertSame(1, count($preview['valid_rows']));

        $result = $service->executePreview('products', $preview['key'], $organization->id, $user->id);

        $this->assertSame(1, $result['created']);
        $this->assertSame(0, $result['skipped']);
        $this->assertDatabaseHas('products', [
            'organization_id' => $organization->id,
            'name' => 'BiPAP Disposable Filter',
            'brand' => 'ResMed',
            'model_name' => 'Filter Pack',
            'sku' => 'BF-180',
            'product_code' => 'BIPAP-FLTR',
            'product_type' => Product::TYPE_SELLABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
        ]);
        $this->assertSame(
            0.0,
            (float) Product::query()
                ->where('organization_id', $organization->id)
                ->where('sku', 'BF-180')
                ->value('price_per_day')
        );
    }

    public function test_product_import_maps_common_human_readable_product_name_header_with_bom(): void
    {
        $organization = TestData::organization();
        $user = TestData::user($organization);
        $this->actingAs($user);

        [$service, $preview] = $this->buildPreview('products', [
            "\xEF\xBB\xBFProduct Name,Category,Brand,Model Name,SKU,Product Code,Sellable,Rentable,Stock Mode,Sale Price,Rental Price,Deposit",
            'BiPAP Disposable Filter,Consumables,ResMed,Filter Pack,BF-180,BIPAP-FLTR,Yes,No,untracked,180,,0',
        ]);

        $this->assertSame(1, count($preview['valid_rows']));
        $this->assertSame(0, count($preview['invalid_rows']));
    }

    public function test_customer_template_workbook_includes_guidance_sheet(): void
    {
        $organization = TestData::organization();
        $user = TestData::user($organization);
        $this->actingAs($user);

        $workbook = app(ImportService::class)->templateWorkbook('customers');

        $this->assertStringEndsWith('.xlsx', $workbook['filename']);

        $path = tempnam(sys_get_temp_dir(), 'phos-import-template-');
        file_put_contents($path, $workbook['content']);

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path) === true);
        $guidanceSheet = $zip->getFromName('xl/worksheets/sheet2.xml');
        $zip->close();
        @unlink($path);

        $this->assertIsString($guidanceSheet);
        $this->assertStringContainsString('Field', $guidanceSheet);
        $this->assertStringContainsString('Required', $guidanceSheet);
    }

    public function test_customer_import_can_create_business_partner_actual_clients_and_direct_customer_in_one_file(): void
    {
        $organization = TestData::organization();
        $user = TestData::user($organization);
        $this->actingAs($user);

        [$service, $preview] = $this->buildPreview('customers', [
            'Customer Name,Customer Type,Contact Name,Phone,WhatsApp Number,Email,Company Name,City,State,Pincode,Address,GST Number,GST Registered,Google Map Link,Business Partner Name,Business Partner Code,Parent Business Partner,Credit Terms,Referral Percentage,Account Manager,Notes',
            'Care Plus Clinic,business_partner,Ananya Rao,9810012345,9810012345,procurement@careplus.test,Care Plus Clinic,Noida,Uttar Pradesh,201301,12 Sector 18,29ABCDE1234F1Z5,Yes,https://maps.google.com/?q=Care+Plus+Clinic,Care Plus Clinic,BP-CAREPLUS,,30 days,7.50,Rahul S,Corporate account',
            'Rahul Verma,actual_client,Rahul Verma,9810012346,9810012346,rahul.verma@example.com,,Noida,Uttar Pradesh,201301,Tower 2 Sector 18,,,https://maps.google.com/?q=Tower+2+Noida,,,Care Plus Clinic,,,Rahul S,Client under Care Plus Clinic',
            'Neha Sharma,actual_client,Neha Sharma,9810012347,9810012347,neha.sharma@example.com,,Noida,Uttar Pradesh,201301,Tower 3 Sector 18,,,https://maps.google.com/?q=Tower+3+Noida,,,Care Plus Clinic,,,Rahul S,Second client under Care Plus Clinic',
            'Aarav Sharma,direct_customer,,9876543210,9876543210,aarav@example.com,,Bengaluru,Karnataka,560001,221B MG Road,,,https://maps.google.com/?q=MG+Road+Bengaluru,,,,,,Priya Shah,Existing oxygen customer',
        ]);

        $this->assertCount(4, $preview['valid_rows']);
        $this->assertCount(0, $preview['invalid_rows']);

        $result = $service->executePreview('customers', $preview['key'], $organization->id, $user->id);

        $this->assertSame(4, $result['created']);
        $this->assertDatabaseHas('business_partners', [
            'organization_id' => $organization->id,
            'business_name' => 'Care Plus Clinic',
            'partner_code' => 'BP-CAREPLUS',
            'credit_terms' => '30 days',
            'account_manager' => 'Rahul S',
        ]);
        $partner = BusinessPartner::query()->where('organization_id', $organization->id)->where('business_name', 'Care Plus Clinic')->firstOrFail();
        $this->assertSame('7.50', (string) $partner->referral_percentage);
        $this->assertSame(2, PartnerClient::query()->where('organization_id', $organization->id)->where('business_partner_id', $partner->id)->count());
        $this->assertDatabaseHas('partner_clients', [
            'organization_id' => $organization->id,
            'business_partner_id' => $partner->id,
            'client_name' => 'Rahul Verma',
        ]);
        $this->assertDatabaseHas('customers', [
            'organization_id' => $organization->id,
            'name' => 'Aarav Sharma',
            'phone' => '+919876543210',
        ]);
    }

    public function test_customer_import_skips_actual_client_when_parent_business_partner_is_missing(): void
    {
        $organization = TestData::organization();
        $user = TestData::user($organization);
        $this->actingAs($user);

        [$service, $preview] = $this->buildPreview('customers', [
            'Customer Name,Customer Type,Phone,City,Parent Business Partner,Notes',
            'Rahul Verma,actual_client,9810012346,Noida,Missing Partner,Client row without valid parent',
        ]);

        $this->assertCount(0, $preview['valid_rows']);
        $this->assertCount(1, $preview['invalid_rows']);
        $this->assertSame('parent_business_partner', $preview['invalid_rows'][0]['error_details'][0]['field'] ?? null);
        $this->assertStringContainsString(
            'Parent Business Partner',
            implode(' | ', $preview['invalid_rows'][0]['errors'] ?? [])
        );
    }

    public function test_customer_import_accepts_customer_name_header_with_asterisk(): void
    {
        $organization = TestData::organization();
        $user = TestData::user($organization);
        $this->actingAs($user);

        [$service, $preview] = $this->buildPreview('customers', [
            'Customer Name*,Customer Type,Phone,City,State,Pincode,Address',
            'Aarav Sharma,direct_customer,9876543210,Bengaluru,Karnataka,560001,221B MG Road',
        ]);

        $this->assertCount(1, $preview['valid_rows']);
        $this->assertCount(0, $preview['invalid_rows']);
        $this->assertSame('Aarav Sharma', $preview['valid_rows'][0]['payload']['name'] ?? null);
    }

    public function test_customer_import_with_three_valid_direct_customer_rows_creates_three_customers(): void
    {
        $organization = TestData::organization();
        $user = TestData::user($organization);
        $this->actingAs($user);

        [$service, $preview] = $this->buildPreview('customers', [
            'Customer Name,Customer Type,Phone,Email,City,State,Pincode,Address',
            'Aarav Sharma,direct_customer,9876503001,aarav@example.com,Bengaluru,Karnataka,560001,MG Road',
            'Deepa Raj,direct_customer,9876503034,deepa@example.com,Bengaluru,Karnataka,560002,Indiranagar',
            'Arjun Nair,direct_customer,9876503005,arjun@example.com,Bengaluru,Karnataka,560003,Jayanagar',
        ]);

        $this->assertSame(3, $preview['row_count']);
        $this->assertSame(3, $preview['valid_count']);
        $this->assertCount(3, $preview['valid_rows']);
        $this->assertCount(0, $preview['invalid_rows']);

        $result = $service->executePreview('customers', $preview['key'], $organization->id, $user->id);

        $this->assertSame(3, $result['processed']);
        $this->assertSame(3, $result['created']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(0, $result['skipped']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(3, Customer::query()->where('organization_id', $organization->id)->count());

        $response = $this->get(route('customers.index'));

        $response->assertOk();
        $response->assertSee('Aarav Sharma');
        $response->assertSee('Deepa Raj');
        $response->assertSee('Arjun Nair');
    }

    public function test_customer_import_duplicate_phone_rows_are_skipped_with_visible_reason(): void
    {
        $organization = TestData::organization();
        $user = TestData::user($organization);
        $this->actingAs($user);

        [$service, $preview] = $this->buildPreview('customers', [
            'Customer Name,Customer Type,Phone,Email,City,State,Pincode,Address',
            'Aarav Sharma,direct_customer,9876503001,aarav@example.com,Bengaluru,Karnataka,560001,MG Road',
            'Aarav Sharma Duplicate,direct_customer,9876503001,aarav.duplicate@example.com,Bengaluru,Karnataka,560001,MG Road',
            'Deepa Raj,direct_customer,9876503034,deepa@example.com,Bengaluru,Karnataka,560002,Indiranagar',
        ]);

        $this->assertSame(3, $preview['row_count']);
        $this->assertSame(2, $preview['valid_count']);
        $this->assertCount(2, $preview['valid_rows']);
        $this->assertCount(1, $preview['invalid_rows']);
        $this->assertSame(3, $preview['invalid_rows'][0]['row_number']);
        $this->assertStringContainsString(
            'Duplicate customer identity: this file contains another customer row with the same identity (row 2).',
            implode(' | ', $preview['invalid_rows'][0]['errors'] ?? [])
        );

        $result = $service->executePreview('customers', $preview['key'], $organization->id, $user->id);

        $this->assertSame(2, $result['processed']);
        $this->assertSame(2, $result['created']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(1, $result['duplicate_rows_skipped']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(2, Customer::query()->where('organization_id', $organization->id)->count());
        $this->assertSame(1, Customer::query()->where('organization_id', $organization->id)->where('phone', '+919876503001')->count());
    }

    public function test_customer_import_invalid_rows_show_row_level_errors(): void
    {
        $organization = TestData::organization();
        $user = TestData::user($organization);
        $this->actingAs($user);

        [$service, $preview] = $this->buildPreview('customers', [
            'Customer Name,Customer Type,Phone,Email,City,State,Pincode,Address',
            ',direct_customer,9876503001,missing-name@example.com,Bengaluru,Karnataka,560001,MG Road',
            'Deepa Raj,direct_customer,9876503034,deepa@example.com,Bengaluru,Karnataka,560002,Indiranagar',
        ]);

        $this->assertSame(2, $preview['row_count']);
        $this->assertSame(1, $preview['valid_count']);
        $this->assertCount(1, $preview['valid_rows']);
        $this->assertCount(1, $preview['invalid_rows']);
        $this->assertSame(2, $preview['invalid_rows'][0]['row_number']);
        $this->assertStringContainsString(
            'Customer name is required.',
            implode(' | ', $preview['invalid_rows'][0]['errors'] ?? [])
        );
    }
    public function test_vendor_import_creates_city_aware_vendor(): void
    {
        $organization = TestData::organization();
        $user = TestData::user($organization);
        $this->actingAs($user);

        City::create([
            'organization_id' => $organization->id,
            'name' => 'Bengaluru',
            'state' => 'Karnataka',
            'country' => 'India',
            'is_active' => true,
        ]);

        [$service, $preview] = $this->buildPreview('vendors', [
            'Vendor Name,Contact Person,Phone,WhatsApp,Email,Vendor Type,City,State,Pincode,GST Number,GST Registration Type,Payment Terms,Address,Active,Notes',
            'KR Healthcare,Kiran Rao,9000001111,9000001111,ops@krhealthcare.test,supplier,Bengaluru,Karnataka,560001,29ABCDE1234F1Z5,regular,7 days,Indiranagar, Bengaluru,Yes,Supports vendor supplied rentals',
        ]);

        $this->assertCount(1, $preview['valid_rows']);

        $result = $service->executePreview('vendors', $preview['key'], $organization->id, $user->id);

        $this->assertSame(1, $result['created']);
        $this->assertDatabaseHas('vendors', [
            'organization_id' => $organization->id,
            'name' => 'KR Healthcare',
            'city' => 'Bengaluru',
        ]);
    }

    public function test_vendor_import_with_three_valid_rows_creates_three_vendors(): void
    {
        $organization = TestData::organization();
        $user = TestData::user($organization);
        $this->actingAs($user);

        City::create([
            'organization_id' => $organization->id,
            'name' => 'Bengaluru',
            'state' => 'Karnataka',
            'country' => 'India',
            'is_active' => true,
        ]);

        [$service, $preview] = $this->buildPreview('vendors', [
            'Vendor Name,Contact Name,Phone,WhatsApp Number,Email,City,Address,State,Pincode,GST Number,Delivery Supported,Pickup Supported,Vendor Type,Notes',
            'KR Healthcare,Kiran Rao,9000001111,9000001111,ops@krhealthcare.test,Bengaluru,Indiranagar,Karnataka,560001,29ABCDE1234F1Z5,Yes,Yes,supplier,Supports vendor supplied rentals',
            'Metro Med,Arjun Das,9000001112,9000001112,ops@metromed.test,Bengaluru,Whitefield,Karnataka,560066,29ABCDE1234F1Z6,Yes,No,supplier,Delivery only',
            'Pulse Care,Neha Iyer,9000001113,9000001113,ops@pulsecare.test,Bengaluru,HSR Layout,Karnataka,560102,29ABCDE1234F1Z7,No,Yes,supplier,Pickup only',
        ]);

        $this->assertSame(3, $preview['row_count']);
        $this->assertSame(3, $preview['valid_count']);
        $this->assertCount(3, $preview['valid_rows']);
        $this->assertCount(0, $preview['invalid_rows']);

        $result = $service->executePreview('vendors', $preview['key'], $organization->id, $user->id);

        $this->assertSame(3, $result['processed']);
        $this->assertSame(3, $result['created']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(0, $result['skipped']);
        $this->assertSame(0, $result['failed']);
        $this->assertDatabaseHas('vendors', ['organization_id' => $organization->id, 'name' => 'KR Healthcare']);
        $this->assertDatabaseHas('vendors', ['organization_id' => $organization->id, 'name' => 'Metro Med']);
        $this->assertDatabaseHas('vendors', ['organization_id' => $organization->id, 'name' => 'Pulse Care']);
    }

    public function test_vendor_import_duplicate_rows_are_skipped_with_visible_reason(): void
    {
        $organization = TestData::organization();
        $user = TestData::user($organization);
        $this->actingAs($user);

        City::create([
            'organization_id' => $organization->id,
            'name' => 'Bengaluru',
            'state' => 'Karnataka',
            'country' => 'India',
            'is_active' => true,
        ]);

        [$service, $preview] = $this->buildPreview('vendors', [
            'Vendor Name,Contact Name,Phone,WhatsApp Number,Email,City,Address,State,Pincode,GST Number,Delivery Supported,Pickup Supported,Vendor Type,Notes',
            'KR Healthcare,Kiran Rao,9000001111,9000001111,ops@krhealthcare.test,Bengaluru,Indiranagar,Karnataka,560001,29ABCDE1234F1Z5,Yes,Yes,supplier,Primary row',
            'KR Healthcare Duplicate,Kiran Rao,9000001111,9000001111,ops.duplicate@krhealthcare.test,Bengaluru,Indiranagar,Karnataka,560001,29ABCDE1234F1Z5,Yes,Yes,supplier,Duplicate phone row',
            'Metro Med,Arjun Das,9000001112,9000001112,ops@metromed.test,Bengaluru,Whitefield,Karnataka,560066,29ABCDE1234F1Z6,Yes,No,supplier,Delivery only',
        ]);

        $this->assertSame(3, $preview['row_count']);
        $this->assertSame(2, $preview['valid_count']);
        $this->assertCount(2, $preview['valid_rows']);
        $this->assertCount(1, $preview['invalid_rows']);
        $this->assertSame(3, $preview['invalid_rows'][0]['row_number']);
        $this->assertStringContainsString(
            'Duplicate vendor identity: this file contains another vendor row with the same identity (row 2).',
            implode(' | ', $preview['invalid_rows'][0]['errors'] ?? [])
        );

        $result = $service->executePreview('vendors', $preview['key'], $organization->id, $user->id);

        $this->assertSame(2, $result['processed']);
        $this->assertSame(2, $result['created']);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame(1, $result['duplicate_rows_skipped']);
        $this->assertSame(0, $result['failed']);
        $this->assertSame(2, Vendor::query()->where('organization_id', $organization->id)->count());
        $this->assertSame(1, Vendor::query()->where('organization_id', $organization->id)->where('phone', '+919000001111')->count());
    }


    public function test_vendor_csv_with_three_rows_parses_as_three_valid_rows(): void
    {
        $organization = TestData::organization();
        $user = TestData::user($organization);
        $this->actingAs($user);

        City::create([
            'organization_id' => $organization->id,
            'name' => 'Bengaluru',
            'state' => 'Karnataka',
            'country' => 'India',
            'is_active' => true,
        ]);

        [$service, $preview] = $this->buildPreview('vendors', [
            'Vendor Name,Contact Name,Phone,WhatsApp Number,Email,City,Address,State,Pincode,GST Number,Delivery Supported,Pickup Supported,Vendor Type,Notes',
            'KR Healthcare,Kiran Rao,9000001111,9000001111,ops@krhealthcare.test,Bengaluru,Indiranagar,Karnataka,560001,29ABCDE1234F1Z5,Yes,Yes,supplier,Supports vendor supplied rentals',
            'Metro Med,Arjun Das,9000001112,9000001112,ops@metromed.test,Bengaluru,Whitefield,Karnataka,560066,29ABCDE1234F1Z6,Yes,No,supplier,Delivery only',
            'Pulse Care,Neha Iyer,9000001113,9000001113,ops@pulsecare.test,Bengaluru,HSR Layout,Karnataka,560102,29ABCDE1234F1Z7,No,Yes,supplier,Pickup only',
        ]);

        $this->assertSame('CSV', $preview['sheet_name']);
        $this->assertSame(1, $preview['header_row_number']);
        $this->assertSame(3, $preview['raw_row_count']);
        $this->assertSame(3, $preview['non_empty_row_count']);
        $this->assertSame(3, $preview['mapped_row_count']);
        $this->assertCount(3, $preview['valid_rows']);
        $this->assertCount(0, $preview['invalid_rows']);
        $this->assertNull($preview['no_data_error']);
    }

    public function test_vendor_xlsx_with_three_rows_parses_as_three_valid_rows(): void
    {
        $organization = TestData::organization();
        $user = TestData::user($organization);
        $this->actingAs($user);

        City::create([
            'organization_id' => $organization->id,
            'name' => 'Bengaluru',
            'state' => 'Karnataka',
            'country' => 'India',
            'is_active' => true,
        ]);

        $rows = [
            ['Vendor Name', 'Contact Name', 'Phone', 'WhatsApp Number', 'Email', 'City', 'Address', 'State', 'Pincode', 'GST Number', 'Delivery Supported', 'Pickup Supported', 'Vendor Type', 'Notes'],
            ['KR Healthcare', 'Kiran Rao', '9000001111', '9000001111', 'ops@krhealthcare.test', 'Bengaluru', 'Indiranagar', 'Karnataka', '560001', '29ABCDE1234F1Z5', 'Yes', 'Yes', 'supplier', 'Supports vendor supplied rentals'],
            ['Metro Med', 'Arjun Das', '9000001112', '9000001112', 'ops@metromed.test', 'Bengaluru', 'Whitefield', 'Karnataka', '560066', '29ABCDE1234F1Z6', 'Yes', 'No', 'supplier', 'Delivery only'],
            ['Pulse Care', 'Neha Iyer', '9000001113', '9000001113', 'ops@pulsecare.test', 'Bengaluru', 'HSR Layout', 'Karnataka', '560102', '29ABCDE1234F1Z7', 'No', 'Yes', 'supplier', 'Pickup only'],
        ];

        [$service, $preview] = $this->buildPreviewXlsx('vendors', $rows, 'Vendors', 'worksheets/vendors-sheet.xml');

        $this->assertSame('Vendors', $preview['sheet_name']);
        $this->assertSame(1, $preview['header_row_number']);
        $this->assertSame(3, $preview['raw_row_count']);
        $this->assertSame(3, $preview['non_empty_row_count']);
        $this->assertSame(3, $preview['mapped_row_count']);
        $this->assertCount(3, $preview['valid_rows']);
        $this->assertCount(0, $preview['invalid_rows']);
        $this->assertNull($preview['no_data_error']);
    }

    public function test_vendor_ui_template_workbook_with_three_data_rows_parses_as_three_valid_rows(): void
    {
        $organization = TestData::organization();
        $user = TestData::user($organization);
        $this->actingAs($user);

        City::create([
            'organization_id' => $organization->id,
            'name' => 'Bengaluru',
            'state' => 'Karnataka',
            'is_active' => true,
        ]);

        $rows = [
            ['Vendor Name', 'Contact Name', 'Phone', 'WhatsApp Number', 'Email', 'City', 'Address', 'State', 'Pincode', 'GST Number', 'Delivery Supported', 'Pickup Supported', 'Vendor Type', 'GST Registration Type', 'Payment Terms', 'Active', 'Notes'],
            ['KR Healthcare', 'Kiran Rao', '9000001111', '9000001111', 'ops@krhealthcare.test', 'Bengaluru', 'Indiranagar', 'Karnataka', '560001', '29ABCDE1234F1Z5', 'Yes', 'Yes', 'supplier', 'registered', 'Immediate', 'Yes', 'Supports vendor supplied rentals'],
            ['Metro Med', 'Arjun Das', '9000001112', '9000001112', 'ops@metromed.test', 'Bengaluru', 'Whitefield', 'Karnataka', '560066', '29ABCDE1234F1Z6', 'Yes', 'No', 'supplier', 'registered', '15 days', 'Yes', 'Delivery only'],
            ['Pulse Care', 'Neha Iyer', '9000001113', '9000001113', 'ops@pulsecare.test', 'Bengaluru', 'HSR Layout', 'Karnataka', '560102', '29ABCDE1234F1Z7', 'No', 'Yes', 'supplier', 'registered', '30 days', 'Yes', 'Pickup only'],
        ];

        [$service, $preview] = $this->buildPreviewFromUiTemplateWorkbook('vendors', $rows);

        $this->assertSame('Template', $preview['sheet_name']);
        $this->assertSame(1, $preview['header_row_number']);
        $this->assertSame(3, $preview['raw_row_count']);
        $this->assertSame(3, $preview['non_empty_row_count']);
        $this->assertSame(3, $preview['mapped_row_count']);
        $this->assertCount(3, $preview['valid_rows']);
        $this->assertCount(0, $preview['invalid_rows']);
        $this->assertNull($preview['no_data_error']);
    }

    public function test_vendor_prefixed_excel_workbook_with_three_data_rows_parses_as_three_valid_rows(): void
    {
        $organization = TestData::organization();
        $user = TestData::user($organization);
        $this->actingAs($user);

        City::create([
            'organization_id' => $organization->id,
            'name' => 'Bengaluru',
            'state' => 'Karnataka',
            'is_active' => true,
        ]);

        $rows = [
            ['Vendor Name', 'Contact Person', 'Phone', 'WhatsApp', 'Email', 'Vendor Type', 'City', 'State', 'Pincode', 'GST Number', 'GST Registration Type', 'Payment Terms', 'Address', 'Active', 'Notes'],
            ['KR Healthcare Bengaluru', 'Kiran Rao', '9000001111', '9000001111', 'ops.krblr@test.example', 'supplier', 'Bengaluru', 'Karnataka', '560001', '29VENDR0001A1Z5', 'regular', '7 days', '14, Indiranagar, Bengaluru', 'Yes', 'Bengaluru vendor for UAT vendor-supplied orders'],
            ['MedRent Solutions Bengaluru', 'Sneha Murthy', '9000002222', '9000002222', 'dispatch.medrent@test.example', 'supplier', 'Bengaluru', 'Karnataka', '560001', '29VENDR0002A1Z5', 'regular', '15 days', '22, HSR Layout, Bengaluru', 'Yes', 'Bengaluru vendor for UAT vendor-supplied orders'],
            ['LifeCare Equipment Supply', 'Farhan Ahmed', '9000003333', '9000003333', 'support.lifecare@test.example', 'supplier', 'Bengaluru', 'Karnataka', '560001', '29VENDR0003A1Z5', 'regular', '10 days', '31, Whitefield, Bengaluru', 'Yes', 'Bengaluru vendor for UAT vendor-supplied orders'],
        ];

        [$service, $preview] = $this->buildPreviewPrefixedXlsx('vendors', $rows, 'Template', 'worksheets/sheet1.xml');

        $this->assertSame('Template', $preview['sheet_name']);
        $this->assertSame(1, $preview['header_row_number']);
        $this->assertSame(3, $preview['raw_row_count']);
        $this->assertSame(3, $preview['non_empty_row_count']);
        $this->assertSame(3, $preview['mapped_row_count']);
        $this->assertCount(3, $preview['valid_rows']);
        $this->assertCount(0, $preview['invalid_rows']);
        $this->assertNull($preview['no_data_error']);
    }

    public function test_vendor_import_maps_supplier_name_alias_to_vendor_name(): void
    {
        $organization = TestData::organization();
        $user = TestData::user($organization);
        $this->actingAs($user);

        City::create([
            'organization_id' => $organization->id,
            'name' => 'Bengaluru',
            'state' => 'Karnataka',
            'country' => 'India',
            'is_active' => true,
        ]);

        [$service, $preview] = $this->buildPreview('vendors', [
            'Supplier Name,Primary Contact,Mobile,WhatsApp Number,Email,City,Address,State,PIN Code,GSTIN,Delivery Supported,Pickup Supported,Vendor Type,Notes',
            'KR Healthcare,Kiran Rao,9000001111,9000001111,ops@krhealthcare.test,Bengaluru,Indiranagar,Karnataka,560001,29ABCDE1234F1Z5,Yes,Yes,supplier,Supports vendor supplied rentals',
        ]);

        $this->assertCount(1, $preview['valid_rows']);
        $this->assertSame('KR Healthcare', $preview['valid_rows'][0]['payload']['name'] ?? null);
        $this->assertSame('Kiran Rao', $preview['valid_rows'][0]['payload']['contact_person'] ?? null);
    }

    public function test_vendor_import_ignores_blank_rows_and_reports_raw_vs_mapped_counts(): void
    {
        $organization = TestData::organization();
        $user = TestData::user($organization);
        $this->actingAs($user);

        City::create([
            'organization_id' => $organization->id,
            'name' => 'Bengaluru',
            'state' => 'Karnataka',
            'country' => 'India',
            'is_active' => true,
        ]);

        [$service, $preview] = $this->buildPreview('vendors', [
            'Vendor Name,Contact Name,Phone,City',
            'KR Healthcare,Kiran Rao,9000001111,Bengaluru',
            '',
            'Metro Med,Arjun Das,9000001112,Bengaluru',
        ]);

        $this->assertSame(3, $preview['raw_row_count']);
        $this->assertSame(2, $preview['non_empty_row_count']);
        $this->assertSame(2, $preview['mapped_row_count']);
        $this->assertSame(1, $preview['blank_row_count']);
        $this->assertCount(2, $preview['valid_rows']);
    }

    public function test_vendor_import_shows_real_validation_errors_instead_of_silent_zero_rows(): void
    {
        $organization = TestData::organization();
        $user = TestData::user($organization);
        $this->actingAs($user);

        City::create([
            'organization_id' => $organization->id,
            'name' => 'Bengaluru',
            'state' => 'Karnataka',
            'country' => 'India',
            'is_active' => true,
        ]);

        [$service, $preview] = $this->buildPreview('vendors', [
            'Vendor Name,Contact Name,Phone,Email,City',
            'KR Healthcare,Kiran Rao,invalid-phone,not-an-email,Bengaluru',
        ]);

        $this->assertSame(1, $preview['raw_row_count']);
        $this->assertSame(1, $preview['mapped_row_count']);
        $this->assertCount(0, $preview['valid_rows']);
        $this->assertCount(1, $preview['invalid_rows']);
        $this->assertSame('phone', $preview['invalid_rows'][0]['error_details'][0]['field'] ?? null);
    }

    public function test_vendor_import_with_header_only_file_shows_clear_no_data_error(): void
    {
        $organization = TestData::organization();
        $user = TestData::user($organization);
        $this->actingAs($user);

        [$service, $preview] = $this->buildPreview('vendors', [
            'Vendor Name,Contact Name,Phone,City',
        ]);

        $this->assertSame(0, $preview['raw_row_count']);
        $this->assertSame(0, $preview['non_empty_row_count']);
        $this->assertSame(0, $preview['mapped_row_count']);
        $this->assertCount(0, $preview['valid_rows']);
        $this->assertCount(0, $preview['invalid_rows']);
        $this->assertSame('No data rows found. Please check header row and file format.', $preview['no_data_error']);
    }

    public function test_staff_import_creates_assignment_enabled_staff_record(): void
    {
        $organization = TestData::organization();
        $user = TestData::user($organization);
        $this->actingAs($user);

        City::create([
            'organization_id' => $organization->id,
            'name' => 'Bengaluru',
            'state' => 'Karnataka',
            'country' => 'India',
            'is_active' => true,
        ]);

        [$service, $preview] = $this->buildPreview('staff', [
            'Name,Email,Phone,Role,Assignment Role,City,Status,Joining Date,Salary,Address,Notes',
            'Delivery Bengaluru,deliverybng@primehealers.com,9000002222,delivery,delivery,Bengaluru,active,2026-01-01,22000,Bengaluru,Delivery staff for Bengaluru',
        ]);

        $this->assertCount(1, $preview['valid_rows']);

        $result = $service->executePreview('staff', $preview['key'], $organization->id, $user->id);

        $this->assertSame(1, $result['created']);
        $this->assertDatabaseHas('staff', [
            'organization_id' => $organization->id,
            'email' => 'deliverybng@primehealers.com',
            'role' => 'delivery',
            'assignment_role' => 'delivery',
        ]);
    }

    public function test_product_import_shows_clear_error_when_product_name_column_cannot_be_mapped(): void
    {
        $organization = TestData::organization();
        $user = TestData::user($organization);
        $this->actingAs($user);

        [$service, $preview] = $this->buildPreview('products', [
            'Title,Category,Brand,Model Name,SKU,Product Code,Sellable,Rentable,Stock Mode,Sale Price,Rental Price,Deposit',
            'BiPAP Disposable Filter,Consumables,ResMed,Filter Pack,BF-180,BIPAP-FLTR,Yes,No,untracked,180,,0',
        ]);

        $this->assertSame(0, count($preview['valid_rows']));
        $this->assertNotEmpty($preview['invalid_rows']);
        $this->assertStringContainsString(
            'Could not map Product Name column.',
            implode(' | ', $preview['invalid_rows'][0]['errors'] ?? [])
        );
    }

    public function test_asset_import_allows_existing_serial_update_and_generic_preview_is_idempotent(): void
    {
        $organization = TestData::organization();
        $user = TestData::user($organization);
        $this->actingAs($user);

        $warehouse = Warehouse::create([
            'organization_id' => $organization->id,
            'name' => 'Main Warehouse',
            'code' => 'MAIN',
            'is_active' => true,
        ]);

        $product = Product::create([
            'organization_id' => $organization->id,
            'name' => 'BiPAP Machine',
            'brand' => 'ResMed',
            'model_name' => 'AirCurve 10',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_BOTH,
            'price_per_day' => 7000,
            'rental_price' => 7000,
            'sale_price' => 45000,
            'total_quantity' => 0,
            'available_quantity' => 0,
        ]);

        Asset::create([
            'organization_id' => $organization->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Original Asset',
            'serial_number' => 'BIPAP-001',
            'barcode_value' => 'BIPAP-001',
            'asset_stage' => Asset::STAGE_RENTAL_STOCK,
            'condition_status' => 'good',
            'asset_status' => Asset::STATUS_AVAILABLE,
            'purchase_cost' => 30000,
        ]);

        [$service, $preview] = $this->buildPreview('assets', [
            'Product Name,Brand,Model Name,Warehouse Code,Asset Type,Serial Number,Barcode,Condition,Status,Purchase Date,Purchase Cost',
            'BiPAP Machine,ResMed,AirCurve 10,MAIN,rental_stock,BIPAP-001,BIPAP-001,good,available,2026-05-01,35000',
        ]);

        $this->assertSame(1, count($preview['valid_rows']));

        $first = $service->executePreview('assets', $preview['key'], $organization->id, $user->id);
        $second = $service->executePreview('assets', $preview['key'], $organization->id, $user->id);

        $this->assertSame(1, $first['updated']);
        $this->assertTrue(!empty($second['already_imported']));
        $this->assertSame(1, Asset::count());
        $this->assertSame(35000.0, (float) Asset::firstOrFail()->fresh()->purchase_cost);
    }

    private function buildPreview(string $module, array $lines): array
    {
        $csv = implode("\n", $lines);
        $upload = UploadedFile::fake()->createWithContent($module . '-import.csv', $csv);
        $service = app(ImportService::class);
        $snapshot = $service->storeUpload($module, $upload, auth()->user()->organization_id, auth()->id());
        $mapping = $service->suggestMapping($module, $snapshot['headers']);
        $preview = $service->buildPreview($module, $snapshot['key'], $mapping, auth()->user()->organization_id);

        return [$service, $preview];
    }

    private function buildPreviewXlsx(string $module, array $rows, string $sheetName = 'Sheet 1', string $worksheetTarget = 'worksheets/sheet1.xml'): array
    {
        $upload = UploadedFile::fake()->createWithContent(
            $module . '-import.xlsx',
            $this->buildSimpleXlsxWorkbook($rows, $sheetName, $worksheetTarget)
        );

        $service = app(ImportService::class);
        $snapshot = $service->storeUpload($module, $upload, auth()->user()->organization_id, auth()->id());
        $mapping = $service->suggestMapping($module, $snapshot['headers']);
        $preview = $service->buildPreview($module, $snapshot['key'], $mapping, auth()->user()->organization_id);

        return [$service, $preview];
    }

    private function buildPreviewFromUiTemplateWorkbook(string $module, array $rows): array
    {
        $service = app(ImportService::class);
        $workbook = $service->templateWorkbook($module);
        $content = $this->replaceTemplateSheetRows((string) $workbook['content'], $rows);

        $upload = UploadedFile::fake()->createWithContent($module . '-ui-template.xlsx', $content);
        $snapshot = $service->storeUpload($module, $upload, auth()->user()->organization_id, auth()->id());
        $mapping = $service->suggestMapping($module, $snapshot['headers']);
        $preview = $service->buildPreview($module, $snapshot['key'], $mapping, auth()->user()->organization_id);

        return [$service, $preview];
    }

    private function buildPreviewPrefixedXlsx(string $module, array $rows, string $sheetName = 'Template', string $worksheetTarget = 'worksheets/sheet1.xml'): array
    {
        $upload = UploadedFile::fake()->createWithContent(
            $module . '-prefixed-import.xlsx',
            $this->buildPrefixedXlsxWorkbook($rows, $sheetName, $worksheetTarget)
        );

        $service = app(ImportService::class);
        $snapshot = $service->storeUpload($module, $upload, auth()->user()->organization_id, auth()->id());
        $mapping = $service->suggestMapping($module, $snapshot['headers']);
        $preview = $service->buildPreview($module, $snapshot['key'], $mapping, auth()->user()->organization_id);

        return [$service, $preview];
    }

    private function buildSimpleXlsxWorkbook(array $rows, string $sheetName, string $worksheetTarget): string
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'vendor-import-xlsx-');
        $zip = new ZipArchive();
        $zip->open($tmpPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $sheetPath = ltrim($worksheetTarget, '/');
        $sheetPath = str_starts_with($sheetPath, 'xl/') ? $sheetPath : 'xl/' . $sheetPath;

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/' . $sheetPath . '" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . '</Types>');

        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>');

        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . htmlspecialchars($sheetName, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '" sheetId="1" r:id="rId1"/></sheets>'
            . '</workbook>');

        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="' . htmlspecialchars($worksheetTarget, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '"/>'
            . '</Relationships>');

        $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>Test Workbook</dc:title>'
            . '</cp:coreProperties>');

        $zip->addFromString('docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            . '<Application>PHPUnit</Application>'
            . '</Properties>');

        $zip->addFromString($sheetPath, $this->buildSimpleWorksheetXml($rows));
        $zip->close();

        $content = file_get_contents($tmpPath);
        @unlink($tmpPath);

        return (string) $content;
    }

    private function replaceTemplateSheetRows(string $workbookContent, array $rows): string
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'vendor-template-workbook-');
        file_put_contents($tmpPath, $workbookContent);

        $zip = new ZipArchive();
        $zip->open($tmpPath);
        $zip->addFromString('xl/worksheets/sheet1.xml', $this->buildSimpleWorksheetXml($rows));
        $zip->close();

        $content = file_get_contents($tmpPath);
        @unlink($tmpPath);

        return (string) $content;
    }

    private function buildPrefixedXlsxWorkbook(array $rows, string $sheetName, string $worksheetTarget): string
    {
        $tmpPath = tempnam(sys_get_temp_dir(), 'vendor-prefixed-import-xlsx-');
        $zip = new ZipArchive();
        $zip->open($tmpPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $sheetPath = ltrim($worksheetTarget, '/');
        $sheetPath = str_starts_with($sheetPath, 'xl/') ? $sheetPath : 'xl/' . $sheetPath;

        $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/' . htmlspecialchars($sheetPath, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '</Types>');

        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>');

        $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="utf-8"?>'
            . '<x:workbook xmlns:x="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<x:sheets><x:sheet name="' . htmlspecialchars($sheetName, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '" sheetId="1" r:id="Rcf74295a04cc40c0" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" /></x:sheets>'
            . '</x:workbook>');

        $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="Rcf74295a04cc40c0" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="' . htmlspecialchars($worksheetTarget, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '"/>'
            . '</Relationships>');

        $zip->addFromString($sheetPath, $this->buildPrefixedWorksheetXml($rows));
        $zip->close();

        $content = file_get_contents($tmpPath);
        @unlink($tmpPath);

        return (string) $content;
    }

    private function buildPrefixedWorksheetXml(array $rows): string
    {
        $xmlRows = '';
        foreach ($rows as $rowIndex => $row) {
            $cells = '';
            foreach (array_values($row) as $columnIndex => $value) {
                $ref = $this->columnReference($columnIndex) . ($rowIndex + 1);
                $safeValue = htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $cells .= '<x:c r="' . $ref . '" t="str"><x:v>' . $safeValue . '</x:v></x:c>';
            }
            $xmlRows .= '<x:row r="' . ($rowIndex + 1) . '">' . $cells . '</x:row>';
        }

        return '<?xml version="1.0" encoding="utf-8"?>'
            . '<x:worksheet xmlns:x="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<x:sheetData>' . $xmlRows . '</x:sheetData>'
            . '</x:worksheet>';
    }

    private function buildSimpleWorksheetXml(array $rows): string
    {
        $xmlRows = '';
        foreach ($rows as $rowIndex => $row) {
            $cells = '';
            foreach (array_values($row) as $columnIndex => $value) {
                $ref = $this->columnReference($columnIndex) . ($rowIndex + 1);
                $safeValue = htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $cells .= '<c r="' . $ref . '" t="inlineStr"><is><t>' . $safeValue . '</t></is></c>';
            }
            $xmlRows .= '<row r="' . ($rowIndex + 1) . '">' . $cells . '</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0"/></sheetViews>'
            . '<sheetFormatPr defaultRowHeight="18"/>'
            . '<sheetData>' . $xmlRows . '</sheetData>'
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
