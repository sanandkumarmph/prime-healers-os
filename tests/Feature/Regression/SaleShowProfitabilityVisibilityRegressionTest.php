<?php

namespace Tests\Feature\Regression;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorOrderDetail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class SaleShowProfitabilityVisibilityRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_see_sale_profitability_card(): void
    {
        [$sale, $organization] = $this->saleFixture();
        $admin = TestData::user($organization, [
            'role' => User::ROLE_ADMIN_OPERATIONS,
        ]);

        $this->actingAs($admin)
            ->get(route('sales.show', $sale))
            ->assertOk()
            ->assertSee('Profitability')
            ->assertSee('Vendor Cost')
            ->assertSee('Gross Margin')
            ->assertSee('Margin %');
    }

    public function test_sales_user_sees_record_finance_but_not_profitability_or_sensitive_margin_values(): void
    {
        [$sale, $organization] = $this->saleFixture();
        $salesUser = TestData::user($organization, [
            'role' => User::ROLE_SALES,
        ]);

        $this->assertFalse($salesUser->canViewFinance());
        $this->assertTrue($salesUser->canSeeSalesFinance());

        $this->actingAs($salesUser)
            ->get(route('sales.show', $sale))
            ->assertOk()
            ->assertSee('Sale Amount')
            ->assertSee('13,000.00')
            ->assertSee('Due Amount')
            ->assertDontSee('Profitability')
            ->assertDontSee('Vendor Cost')
            ->assertDontSee('Gross Margin')
            ->assertDontSee('Margin %');
    }

    public function test_delivery_user_cannot_see_sale_profitability_or_sensitive_margin_values(): void
    {
        [$sale, $organization] = $this->saleFixture();
        $deliveryRole = Role::create([
            'organization_id' => $organization->id,
            'name' => 'Delivery Sale Reader',
            'slug' => User::ROLE_DELIVERY,
            'permissions' => [
                'sales' => ['read'],
            ],
            'is_active' => true,
        ]);
        $deliveryUser = TestData::user($organization, [
            'role' => 'staff',
            'role_id' => $deliveryRole->id,
        ]);

        $this->actingAs($deliveryUser)
            ->get(route('sales.show', $sale))
            ->assertOk()
            ->assertDontSee('Profitability')
            ->assertDontSee('Vendor Cost')
            ->assertDontSee('Gross Margin')
            ->assertDontSee('Margin %');
    }
    public function test_vendor_sales_reader_cannot_see_sale_record_finance_amounts(): void
    {
        [$sale, $organization] = $this->saleFixture();
        $vendorRole = Role::create([
            'organization_id' => $organization->id,
            'name' => 'Vendor Sale Reader',
            'slug' => User::ROLE_VENDOR,
            'permissions' => [
                'sales' => ['read'],
            ],
            'is_active' => true,
        ]);
        $vendorUser = TestData::user($organization, [
            'role' => 'staff',
            'role_id' => $vendorRole->id,
        ]);

        $this->assertFalse($vendorUser->canSeeSalesFinance());

        $this->actingAs($vendorUser)
            ->get(route('sales.show', $sale))
            ->assertOk()
            ->assertSee('Hidden')
            ->assertDontSee('13,000.00')
            ->assertDontSee('Vendor Cost');
    }

    private function saleFixture(): array
    {
        $organization = TestData::organization();
        $customer = Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Profit Guard Customer',
            'phone' => '9000000101',
        ]);
        $product = Product::create([
            'organization_id' => $organization->id,
            'name' => 'Profit Guard Product',
            'product_type' => Product::TYPE_SELLABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'is_sellable' => true,
            'is_rentable' => false,
            'available_quantity' => 5,
            'total_quantity' => 5,
            'sale_price' => 13000,
            'price_per_day' => 0,
        ]);
        $vendor = Vendor::create([
            'organization_id' => $organization->id,
            'name' => 'Profit Guard Vendor',
            'phone' => '9000000102',
            'is_active' => true,
        ]);
        $sale = Sale::create([
            'organization_id' => $organization->id,
            'customer_id' => $customer->id,
            'customer_type' => 'direct_customer',
            'product_id' => $product->id,
            'vendor_id' => $vendor->id,
            'fulfilment_source' => VendorOrderDetail::FULFILMENT_SOURCE_VENDOR_SUPPLIED,
            'delivery_responsibility' => 'vendor_delivery',
            'quantity' => 1,
            'unit_price' => 13000,
            'discount_amount' => 0,
            'shipping_charges' => 0,
            'tax_percentage' => 0,
            'tax_calculation_mode' => 'exclusive',
            'sale_date' => '2026-06-12',
            'sale_amount' => 13000,
            'payment_status' => 'pending',
        ]);

        VendorOrderDetail::create([
            'organization_id' => $organization->id,
            'vendor_id' => $vendor->id,
            'sale_id' => $sale->id,
            'order_type' => VendorOrderDetail::ORDER_TYPE_SALE,
            'fulfilment_source' => VendorOrderDetail::FULFILMENT_SOURCE_VENDOR_SUPPLIED,
            'delivery_responsibility' => 'vendor_delivery',
            'vendor_order_status' => 'confirmed',
            'procurement_cost' => 5000,
            'vendor_delivery_cost' => 500,
            'vendor_payment_status' => 'pending',
        ]);

        return [$sale->fresh(['vendor', 'vendorOrderDetail']), $organization];
    }
}
