<?php

namespace Tests\Feature\Regression;

use App\Models\{Customer, Enquiry, Product, Quotation, Rental, Warehouse};
use App\Services\Sales\{QuotationConversion, QuotationSharing};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\TestData;
use Tests\TestCase;

class QuotationSharingEnhancementTest extends TestCase
{
    use RefreshDatabase;

    private $owner;
    private $customer;
    private $equipment;
    private $accessory;
    private $enquiry;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->travelTo(now()->setDate(2026, 11, 2)->setTime(10, 0));
        $org = TestData::organization(['name' => 'Prime Healers', 'country_code' => 'IN', 'state' => 'Karnataka',
            'currency_code' => 'INR', 'phone' => '+918000000001']);
        $this->owner = TestData::user($org, ['name' => 'Ravi Sales']);
        $this->actingAs($this->owner);
        Warehouse::create(['organization_id' => $org->id, 'name' => 'UAT warehouse', 'is_active' => true]);
        $this->customer = Customer::create(['organization_id' => $org->id, 'name' => 'Synthetic quotation customer',
            'phone' => '+919876543210', 'state' => 'Karnataka']);
        $base = ['organization_id' => $org->id, 'stock_mode' => 'untracked', 'total_quantity' => 20,
            'available_quantity' => 20, 'gst_tax_type' => 'cgst_sgst', 'cgst_rate' => 9, 'sgst_rate' => 9, 'gst_calculation_mode' => 'inclusive'];
        $this->equipment = Product::create($base + ['name' => 'Oxygen concentrator', 'product_type' => 'rentable',
            'is_rentable' => true, 'is_sellable' => false, 'rental_price' => 1000, 'price_per_day' => 100, 'rental_price_15_days' => 1200, 'rental_price_30_days' => 2000]);
        $this->accessory = Product::create($base + ['name' => 'Nasal cannula', 'product_type' => 'sellable',
            'is_sellable' => true, 'is_rentable' => false, 'sale_price' => 100, 'price_per_day' => 0]);
        $this->post(route('enquiries.store'), ['name' => $this->customer->name, 'customer_id' => $this->customer->id,
            'phone' => $this->customer->phone, 'subject' => 'Concentrator rental and accessories', 'owner_id' => $this->owner->id, 'intent' => 'rental'])->assertSessionHasNoErrors();
        $this->enquiry = Enquiry::latest('id')->firstOrFail();
    }

    private function payload(): array
    {
        return ['kind' => 'rental', 'valid_until' => '2026-11-20', 'start_date' => '2026-11-03', 'end_date' => '2026-11-09',
            'tax_type' => 'cgst_sgst', 'transport' => 50, 'deposit' => 500, 'customer_id' => $this->customer->id,
            'enquiry_lock_version' => $this->enquiry->fresh()->lock_version, 'terms' => 'Confirm availability before dispatch.',
            'lines' => [
                ['product_id' => $this->accessory->id, 'line_type' => 'sale', 'quantity' => 2, 'rate' => 100, 'tax_rate' => 18, 'tax_mode' => 'inclusive'],
                ['product_id' => $this->equipment->id, 'line_type' => 'rental', 'quantity' => 1, 'rate' => 1000, 'tax_rate' => 18, 'tax_mode' => 'inclusive'],
            ]];
    }

    private function quote(): Quotation
    {
        $this->post(route('quotations.store', $this->enquiry), $this->payload())->assertSessionHasNoErrors();
        return $this->enquiry->quotations()->sole();
    }

    private function action(Quotation $q, string $action): Quotation
    {
        $this->post(route('quotations.action', $q), ['action' => $action, 'lock_version' => $q->fresh()->lock_version,
            'contact_name' => $this->customer->name, 'note' => 'Confirmed by phone.'])->assertSessionHasNoErrors();
        return $q->fresh();
    }

    public function test_mixed_quote_preserves_line_types_prices_totals_and_does_not_reserve_stock(): void
    {
        $before = [$this->equipment->fresh()->available_quantity, $this->accessory->fresh()->available_quantity];
        $q = $this->quote();
        $this->assertSame(['sale', 'rental'], array_column($q->snapshot['lines'], 'line_type'));
        $this->assertEquals(1250, $q->snapshot['total']);
        $this->assertEquals(1750, $q->snapshot['payable_with_deposit']);
        $this->assertSame($before, [$this->equipment->fresh()->available_quantity, $this->accessory->fresh()->available_quantity]);
        $this->assertDatabaseCount('rentals', 0);
        Mail::assertNothingSent();
    }

    public function test_final_sender_is_frozen_and_whatsapp_is_itemised_without_sending_or_marking_shared(): void
    {
        $q = $this->quote();
        $this->owner->update(['name' => 'Meera Sender']);
        $this->actingAs($this->owner->fresh());
        $q = $this->action($q, 'send');
        $this->owner->update(['name' => 'Later profile change']);
        $response = $this->get(route('quotations.show', $q))->assertOk()->assertSee('Meera Sender')->assertSee('data-quotation-signoff', false)
            ->assertSee('https://wa.me/919876543210?text=', false)->assertSee('Preview message and sender sign-off');
        $text = app(QuotationSharing::class)->message($q, 'https://example.test/private-quote');
        foreach (['Oxygen concentrator', 'Nasal cannula', 'One-time purchase', '7 days', '₹1,750.00', 'Refundable security deposit', "Regards,\nMeera Sender", '+918000000001'] as $fragment) $this->assertStringContainsString($fragment, $text);
        $this->assertStringNotContainsString('Later profile change', $text);
        $this->assertNull($q->fresh()->shared_at);
        Mail::assertNothingSent();
        if ($folder = getenv('PHOS_QUOTE_ENHANCEMENT_RENDER_DIR')) {
            if (!is_dir($folder)) mkdir($folder, 0777, true);
            file_put_contents($folder.'/shared.html', $response->getContent());
        }
    }

    public function test_mixed_quote_converts_once_with_accessory_even_when_first_line_is_a_purchase(): void
    {
        $q = $this->action($this->action($this->quote(), 'send'), 'accepted');
        $payload = app(QuotationConversion::class)->prefill($q) + ['delivery_assignment_type' => 'customer_pickup'];
        $this->assertSame($this->equipment->id, $payload['product_id']);
        $this->assertSame($this->accessory->id, $payload['sale_items'][0]['product_id']);
        $this->post(route('rentals.store'), $payload)->assertSessionHasNoErrors()->assertRedirect();
        $rental = Rental::sole();
        $this->assertEquals(200, $rental->saleItems()->sole()->line_total);
        $this->assertEquals(1000, $rental->rentalItems()->sole()->line_total);
        $this->assertEquals(500, $rental->deposit_amount);
        $this->assertSame('converted', $q->fresh()->status);
        $before = $this->accessory->fresh()->getRawOriginal();
        $this->post(route('rentals.store'), $payload)->assertRedirect(route('rentals.show', $rental));
        $this->assertDatabaseCount('rentals', 1);
        $this->assertDatabaseCount('rental_sale_items', 1);
        $this->assertSame($before, $this->accessory->fresh()->getRawOriginal());
    }

    public function test_missing_or_changed_accessory_and_added_unquoted_purchase_are_rejected(): void
    {
        $q = $this->action($this->action($this->quote(), 'send'), 'accepted');
        $base = app(QuotationConversion::class)->prefill($q) + ['delivery_assignment_type' => 'customer_pickup'];
        $missing = $base; $missing['sale_items'] = [];
        $changed = $base; $changed['sale_items'][0]['unit_price'] = '0.00';
        $extra = $base; $extra['sale_items'][] = $extra['sale_items'][0];
        foreach ([$missing, $changed, $extra] as $payload) {
            $this->post(route('rentals.store'), $payload)->assertSessionHasErrors('quotation_id');
            $this->assertDatabaseCount('rentals', 0);
        }
    }

    public function test_rental_requires_a_rental_line_and_a_sale_cannot_smuggle_rental_lines(): void
    {
        $payload = $this->payload(); $payload['lines'] = [$payload['lines'][0]];
        $this->post(route('quotations.store', $this->enquiry), $payload)->assertSessionHasErrors('lines');
        $payload = $this->payload(); $payload['kind'] = 'sale'; $payload['kind_confirmed'] = true;
        $this->post(route('quotations.store', $this->enquiry), $payload)->assertSessionHasErrors('lines.1.line_type');
        $this->assertDatabaseCount('quotations', 0);
    }

    public function test_new_editor_fixture_has_duration_and_mixed_charge_controls(): void
    {
        $q = $this->quote();
        $response = $this->get(route('quotations.edit', $q))->assertOk()->assertSee('id="quote-duration"', false)
            ->assertSee('One-time purchase')->assertSee('Use saved prices for these dates');
        if ($folder = getenv('PHOS_QUOTE_ENHANCEMENT_RENDER_DIR')) {
            if (!is_dir($folder)) mkdir($folder, 0777, true);
            file_put_contents($folder.'/editor.html', $response->getContent());
            file_put_contents($folder.'/fixture.json', json_encode(['url' => route('quotations.edit', $q),
                'rentalProduct' => $this->equipment->id, 'accessoryProduct' => $this->accessory->id], JSON_THROW_ON_ERROR));
        }
    }
}
