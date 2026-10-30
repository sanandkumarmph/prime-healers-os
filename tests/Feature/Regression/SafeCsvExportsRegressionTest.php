<?php

namespace Tests\Feature\Regression;

use App\Models\{Customer, Product, Rental};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class SafeCsvExportsRegressionTest extends TestCase
{
    use RefreshDatabase;

    private function rows(string $csv): array
    {
        $stream = fopen('php://memory', 'w+');
        fwrite($stream, $csv);
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) $rows[] = $row;
        fclose($stream);

        return $rows;
    }

    public function test_authorized_customer_product_and_report_exports_neutralize_formulas_without_changing_records(): void
    {
        $owner = TestData::user();
        $this->actingAs($owner);
        $payloads = ['=HYPERLINK("https://example.test","open")', '+SUM(1,2)', '@SUM(A1:A2)', "\t=1+1"];
        $records = [];
        foreach ($payloads as $index => $payload) {
            $customer = Customer::create(['organization_id' => $owner->organization_id, 'name' => $payload,
                'phone' => '900000000' . $index, 'city' => 'Bengaluru']);
            $product = Product::create(['organization_id' => $owner->organization_id, 'name' => $payload,
                'product_type' => Product::TYPE_RENTABLE, 'stock_mode' => Product::STOCK_MODE_UNTRACKED,
                'available_quantity' => 5, 'total_quantity' => 5, 'price_per_day' => 10, 'sale_price' => 100.50]);
            $rental = Rental::create(['organization_id' => $owner->organization_id, 'customer_id' => $customer->id,
                'customer_name' => $payload, 'phone' => $customer->phone, 'product_id' => $product->id, 'quantity' => 1,
                'start_date' => today(), 'end_date' => today()->addDays(7), 'rental_amount' => 100.50, 'status' => 'active']);
            array_push($records, $customer, $product, $rental);
        }
        $other = TestData::organization();
        Customer::create(['organization_id' => $other->id, 'name' => '=FOREIGN_CSV_SENTINEL', 'phone' => '9000000099']);
        $originals = array_map(fn ($record) => $record->fresh()->getRawOriginal(), $records);
        $counts = [Customer::count(), Product::count(), Rental::count()];

        foreach ([['customers.export.csv', [], 'Customer Name'], ['products.export.csv', [], 'Product'],
            ['reports.export.csv', ['report' => 'overview'], 'Customer']] as [$route, $query, $label]) {
            $response = $this->get(route($route, $query))->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
            $csv = $response->streamedContent();
            $rows = $this->rows($csv);
            $headers = array_shift($rows);
            $column = array_search($label, $headers, true);
            $this->assertNotFalse($column);
            $this->assertCount(4, $rows, $route);
            $exportedNames = array_column($rows, $column);
            foreach ($payloads as $payload) $this->assertContains("'" . $payload, $exportedNames, $route);
            $this->assertStringNotContainsString('FOREIGN_CSV_SENTINEL', $csv);
            $this->assertFalse(str_starts_with($headers[0], "'"));
            foreach ($rows as $row) {
                $this->assertCount(count($headers), $row);
                if ($route === 'products.export.csv') $this->assertSame('100.50', $row[array_search('Sale Price', $headers, true)]);
                if ($route === 'reports.export.csv') $this->assertSame('100.50', $row[array_search('Amount', $headers, true)]);
            }
        }
        foreach ($records as $index => $record) $this->assertSame($originals[$index], $record->fresh()->getRawOriginal());
        $this->assertSame($counts, [Customer::count(), Product::count(), Rental::count()]);
    }
}
