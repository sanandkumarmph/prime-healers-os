<?php

use App\Services\ImportService;

it('downloads every configured import template workbook with frozen headers', function () {
    if (! class_exists(ZipArchive::class)) {
        $this->markTestSkipped('ZipArchive is required to inspect generated template workbooks.');
    }

    $service = app(ImportService::class);

    foreach (array_keys($service->templateCatalog()) as $key) {
        $workbook = $service->templateWorkbook($key);

        expect($workbook['filename'])->toEndWith('.xlsx');
        expect(substr($workbook['content'], 0, 2))->toBe('PK');

        $tmp = tempnam(sys_get_temp_dir(), 'phos-template-');
        file_put_contents($tmp, $workbook['content']);

        $zip = new ZipArchive();
        expect($zip->open($tmp))->toBeTrue();

        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');

        $zip->close();
        @unlink($tmp);

        expect($sheet)->toContain('state="frozen"');
    }
});

it('suggests mappings with case space underscore and natural header variants', function () {
    $service = app(ImportService::class);

    $customer = $service->suggestMapping('customers', [
        'Customer Name *',
        'Phone Number',
        'GSTIN',
        'City Name',
    ]);

    expect($customer['name'])->toBe('Customer Name *');
    expect($customer['phone'])->toBe('Phone Number');
    expect($customer['gst_number'])->toBe('GSTIN');
    expect($customer['city_name'])->toBe('City Name');

    $product = $service->suggestMapping('products', [
        'product name',
        'Stock_Mode',
        'GST Tax Type',
    ]);

    expect($product['name'])->toBe('product name');
    expect($product['stock_mode'])->toBe('Stock_Mode');
    expect($product['gst_tax_type'])->toBe('GST Tax Type');
});

it('exposes field guidance for mapping and template panels', function () {
    $service = app(ImportService::class);

    $fields = $service->fieldOptions('customers');
    $catalog = $service->templateCatalog();

    expect($fields['name'])->toHaveKeys(['key', 'label', 'sample', 'accepted_values', 'description']);
    expect($catalog['customers']['field_details'][0])->toHaveKeys(['key', 'label', 'description']);
    expect($catalog['sales']['field_details'][0])->toHaveKeys(['key', 'label', 'description']);
    expect($catalog['opening-balances']['field_details'][0])->toHaveKeys(['key', 'label', 'description']);
});