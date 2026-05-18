<?php

namespace Tests\Feature\Regression;

use App\Models\Customer;
use App\Models\Delivery;
use App\Models\Asset;
use App\Models\Product;
use App\Models\Rental;
use App\Models\RentalItem;
use App\Models\Sale;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class MobileActionBarRegressionTest extends TestCase
{
    use RefreshDatabase;

    private int $organizationId;
    private Customer $customer;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $organization = TestData::organization([
            'state' => 'Karnataka',
        ]);

        $this->organizationId = $organization->id;
        $this->actingAs(TestData::user($organization));

        $this->product = Product::create([
            'organization_id' => $this->organizationId,
            'name' => 'BiPAP Machine - Resmed Lumis 150',
            'brand' => 'Resmed',
            'model_name' => 'Lumis 150',
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'available_quantity' => 3,
            'total_quantity' => 3,
            'price_per_day' => 700,
            'rental_price' => 700,
            'sale_price' => 15000,
        ]);

        $this->customer = Customer::create([
            'organization_id' => $this->organizationId,
            'name' => 'Amit Iyer',
            'phone' => '+919739886843',
            'email' => 'amit.iyer@example.test',
            'address' => '249, Kengeri',
            'city' => 'Bengaluru',
            'state' => 'Karnataka',
            'pincode' => '560077',
        ]);
    }

    public function test_customer_rental_and_sale_pages_use_compact_mobile_action_bar_while_delivery_uses_inline_workflow_actions(): void
    {
        $customerPage = $this->get(route('customers.show', $this->customer));
        $customerPage->assertOk()
            ->assertSee('ph-mobile-action-bar', false)
            ->assertSeeText('Rental')
            ->assertSeeText('Sale')
            ->assertSeeText('More')
            ->assertDontSee('aria-label="Customer primary actions"', false);

        $rental = Rental::create([
            'organization_id' => $this->organizationId,
            'customer_id' => $this->customer->id,
            'product_id' => $this->product->id,
            'customer_name' => $this->customer->name,
            'phone' => '9739886843',
            'quantity' => 1,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
            'rental_amount' => 700,
            'deposit_amount' => 0,
            'transport_amount' => 0,
            'other_amount' => 0,
            'status' => 'active',
        ]);

        $rentalPage = $this->get(route('rentals.show', $rental));
        $rentalPage->assertOk()
            ->assertSee('ph-mobile-action-bar', false)
            ->assertSeeText('Invoice')
            ->assertSeeText('More')
            ->assertDontSee('aria-label="Rental bottom actions"', false);

        RentalItem::create([
            'organization_id' => $this->organizationId,
            'rental_id' => $rental->id,
            'product_id' => $this->product->id,
            'quantity' => 1,
            'ordered_quantity' => 1,
            'delivered_quantity' => 0,
            'returned_quantity' => 0,
            'unit_rental_amount' => 700,
            'line_total' => 700,
        ]);

        $sale = Sale::create([
            'organization_id' => $this->organizationId,
            'customer_id' => $this->customer->id,
            'product_id' => $this->product->id,
            'quantity' => 1,
            'unit_price' => 15000,
            'discount_amount' => 0,
            'shipping_charges' => 0,
            'tax_percentage' => 0,
            'tax_calculation_mode' => 'exclusive',
            'sale_date' => now()->toDateString(),
            'sale_amount' => 15000,
            'payment_status' => 'pending',
        ]);

        $salePage = $this->get(route('sales.show', $sale));
        $salePage->assertOk()
            ->assertSee('ph-mobile-action-bar', false)
            ->assertSeeText('Invoice')
            ->assertSeeText('Paid')
            ->assertSeeText('More')
            ->assertDontSee('aria-label="Sale primary actions"', false);

        $delivery = Delivery::create([
            'organization_id' => $this->organizationId,
            'rental_id' => $rental->id,
            'type' => 'delivery',
            'status' => 'pending',
            'assigned_user_id' => auth()->id(),
            'scheduled_at' => now(),
            'notes' => 'Mobile CTA workflow test',
        ]);

        $deliveryPage = $this->get(route('deliveries.show', $delivery));
        $deliveryPage->assertOk()
            ->assertDontSee('ph-mobile-action-bar', false)
            ->assertSee('aria-label="Delivery quick actions"', false)
            ->assertSeeText('Start Delivery')
            ->assertSeeText('Call')
            ->assertDontSee('aria-label="Delivery primary actions"', false);
    }

    public function test_product_asset_and_verify_return_pages_include_mobile_safe_stacked_layout_hooks(): void
    {
        $warehouse = Warehouse::create([
            'organization_id' => $this->organizationId,
            'name' => 'Main Warehouse',
            'code' => 'MAIN',
            'is_active' => true,
        ]);

        $asset = Asset::create([
            'organization_id' => $this->organizationId,
            'product_id' => $this->product->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => 'Returned BiPAP Unit',
            'serial_number' => 'SERIAL-001',
            'barcode_value' => 'BARCODE-001',
            'asset_stage' => Asset::STAGE_RENTAL_STOCK,
            'asset_status' => Asset::STATUS_AWAITING_VERIFICATION,
            'condition_status' => 'good',
        ]);

        $productPage = $this->get(route('products.show', $this->product));
        $productPage->assertOk()
            ->assertSee('.product-detail-master-grid', false)
            ->assertSee('.product-stock-grid', false)
            ->assertSee('padding-bottom: calc(112px + env(safe-area-inset-bottom, 0px));', false);

        $assetPage = $this->get(route('assets.show', $asset));
        $assetPage->assertOk()
            ->assertSee('.asset-detail-info-grid', false)
            ->assertSee('white-space:normal;', false)
            ->assertSee('padding-bottom: calc(112px + env(safe-area-inset-bottom, 0px));', false);

        $verifyReturnPage = $this->get(route('assets.verify-return', $asset));
        $verifyReturnPage->assertOk()
            ->assertSee('verify-return-summary-grid', false)
            ->assertSee('verify-return-form-grid', false)
            ->assertSee('verify-return-form-actions', false);
    }
}
