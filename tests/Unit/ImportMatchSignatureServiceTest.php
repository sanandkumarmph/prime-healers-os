<?php

namespace Tests\Unit;

use App\Services\Imports\ImportMatchSignatureService;
use Tests\TestCase;

class ImportMatchSignatureServiceTest extends TestCase
{
    public function test_rental_signature_excludes_mutable_fields_but_keeps_payment_review_fields(): void
    {
        $service = app(ImportMatchSignatureService::class);

        $signature = $service->rental([
            'start_date' => '2026-05-01',
            'end_date' => '2026-05-15',
            'payment_status' => 'partial',
            'paid_amount' => 2500,
            'invoice_status' => 'generated',
            'delivery_status' => 'completed',
            'pickup_status' => 'not_assigned',
            'notes' => 'changed note',
        ], 10, 20, 30, [1001], 1);

        $this->assertSame(10, $signature['customer_id']);
        $this->assertSame(20, $signature['product_id']);
        $this->assertSame('partial', $signature['payment_status']);
        $this->assertSame(2500, $signature['paid_amount']);
        $this->assertArrayNotHasKey('invoice_status', $signature);
        $this->assertArrayNotHasKey('delivery_status', $signature);
        $this->assertArrayNotHasKey('pickup_status', $signature);
        $this->assertArrayNotHasKey('notes', $signature);
    }

    public function test_sale_signature_excludes_mutable_fields_but_keeps_payment_review_fields(): void
    {
        $service = app(ImportMatchSignatureService::class);

        $signature = $service->sale([
            'sale_date' => '2026-05-01',
            'payment_status' => 'paid',
            'paid_amount' => 45000,
            'invoice_status' => 'generated',
            'delivery_status' => 'completed',
            'notes' => 'changed note',
        ], 11, 21, 1, 31);

        $this->assertSame(11, $signature['customer_id']);
        $this->assertSame(21, $signature['product_id']);
        $this->assertSame('paid', $signature['payment_status']);
        $this->assertSame(45000, $signature['paid_amount']);
        $this->assertArrayNotHasKey('invoice_status', $signature);
        $this->assertArrayNotHasKey('delivery_status', $signature);
        $this->assertArrayNotHasKey('notes', $signature);
    }
}
