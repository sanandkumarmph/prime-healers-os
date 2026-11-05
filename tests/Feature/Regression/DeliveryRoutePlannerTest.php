<?php

namespace Tests\Feature\Regression;

use App\Models\{Customer, Delivery, Product, Rental, User, Warehouse};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class DeliveryRoutePlannerTest extends TestCase
{
    use RefreshDatabase;

    public function test_route_groups_appointments_orders_pinned_stops_and_keeps_unmapped_work_visible(): void
    {
        $organization = TestData::organization();
        $driver = TestData::user($organization, ['role' => User::ROLE_DELIVERY]);
        $other = TestData::user($organization, ['role' => User::ROLE_DELIVERY]);
        $admin = TestData::user($organization, ['role' => User::ROLE_ADMIN_OPERATIONS]);
        $sales = TestData::user($organization, ['role' => User::ROLE_SALES]);
        $product = Product::create([
            'organization_id' => $organization->id, 'name' => 'Route fixture',
            'product_type' => Product::TYPE_RENTABLE, 'stock_mode' => Product::STOCK_MODE_TRACKED_RENTAL,
            'total_quantity' => 0, 'available_quantity' => 0, 'price_per_day' => 0, 'rental_price' => 0, 'sale_price' => 0,
        ]);
        $date = '2026-10-10';
        $make = function (User $assignee, string $name, ?string $pin, string $time = '10:00:00', string $status = 'pending', string $type = 'delivery') use ($organization, $product, $date) {
            $customer = Customer::create([
                'organization_id' => $organization->id, 'name' => $name, 'address' => 'Example Street',
                'map_location_url' => $pin,
            ]);
            $rental = Rental::create([
                'organization_id' => $organization->id, 'customer_id' => $customer->id, 'customer_name' => $name,
                'phone' => '9000000000', 'product_id' => $product->id, 'quantity' => 1,
                'start_date' => $date, 'end_date' => '2026-11-01', 'rental_amount' => 100, 'status' => 'active',
            ]);

            return Delivery::create([
                'organization_id' => $organization->id, 'rental_id' => $rental->id,
                'type' => $type, 'status' => $status, 'assignment_type' => 'delivery_team',
                'assigned_user_id' => $assignee->id, 'scheduled_at' => $date.' '.$time,
            ]);
        };
        $start = $make($driver, 'First appointment', 'https://maps.google.com/?q=12.9,77.5', '09:00:00');
        $far = $make($driver, 'Far delivery', 'https://www.google.com/maps/search/?api=1&query=12.9%2C78.9');
        $unpinned = $make($driver, 'Address only fixture', 'https://maps.app.goo.gl/shortlink');
        $near = $make($driver, 'Nearby pickup', 'https://www.google.com/maps/place/example/data=!3d12.91!4d77.51', type: 'pickup');
        $later = $make($driver, 'Later appointment', 'https://maps.google.com/?q=12.90,77.51', '11:00:00');
        $make($driver, 'Completed fixture', null, status: 'completed');
        $otherTask = $make($other, 'Different driver fixture', null);

        $this->withoutVite()->actingAs($driver)->get(route('deliveries.route-plan', ['date' => $date, 'driver' => $other->id]))
            ->assertOk()
            ->assertViewHas('stops', fn ($stops) => array_map(fn ($stop) => $stop['task']->id, $stops)
                === [$start->id, $near->id, $unpinned->id, $far->id, $later->id])
            ->assertViewHas('maps', fn ($maps) => count($maps) === 2 && str_contains($maps[0], 'waypoints='))
            ->assertSee('Address only. Save a map pin')
            ->assertDontSee('Completed fixture')->assertDontSee('Different driver fixture');

        $warehouse = Warehouse::create([
            'organization_id' => $organization->id, 'name' => 'Route warehouse', 'is_active' => true,
            'latitude' => 12.9, 'longitude' => 77.5,
        ]);
        $foreignWarehouse = Warehouse::create([
            'organization_id' => TestData::organization()->id, 'name' => 'Other warehouse', 'is_active' => true,
        ]);
        $filters = ['date' => $date, 'warehouse' => $warehouse->id];
        $response = $this->get(route('deliveries.route-plan', $filters))->assertOk()
            ->assertSee('data-route-drag-handle', false)->assertSee('phos-route-order.js')
            ->assertSee('1.6 km')->assertSee('≈ 5 min')
            ->assertViewHas('stops', fn ($stops) => $stops[2]['distance_km'] === null
                && str_contains($stops[2]['navigation'], 'origin=12.9%2C77.5'));
        if ($fixture = getenv('PHOS_ROUTE_PLANNER_BROWSER_FIXTURE')) {
            file_put_contents($fixture, $response->getContent());
        }

        $move = $this->get(route('deliveries.route-plan', $filters + ['move' => 'up:'.$far->id]))->assertRedirect();
        $this->assertStringEndsWith('#route-stop-'.$far->id, $move->headers->get('Location'));
        $this->get($move->headers->get('Location'))->assertOk()
            ->assertSee('Reset to suggested order')
            ->assertViewHas('stops', fn ($stops) => array_map(fn ($stop) => $stop['task']->id, $stops)
                === [$start->id, $near->id, $far->id, $unpinned->id, $later->id])
            ->assertViewHas('maps', fn ($maps) => str_contains($maps[0], 'origin=12.9%2C77.5')
                && str_contains($maps[0], 'destination=Example%20Street'));
        $this->get(route('deliveries.route-plan', $filters + ['move' => 'up:'.$otherTask->id]))->assertNotFound();
        $this->get(route('deliveries.route-plan', ['date' => $date, 'warehouse' => $foreignWarehouse->id]))->assertNotFound();
        $this->get(route('deliveries.route-plan', $filters + ['order' => $otherTask->id.','.$far->id.','.$far->id]))->assertOk()
            ->assertViewHas('stops', fn ($stops) => count($stops) === 5 && $stops[0]['task']->id === $far->id)
            ->assertDontSee('Different driver fixture');

        $this->actingAs($admin)->get(route('deliveries.route-plan', ['date' => $date, 'driver' => $other->id]))
            ->assertOk()->assertViewHas('stops', fn ($stops) => count($stops) === 1 && $stops[0]['task']->id === $otherTask->id);
        $this->actingAs($sales)->get(route('deliveries.route-plan', ['date' => $date]))->assertForbidden();
    }

    public function test_warehouse_pin_can_be_saved_and_requires_both_coordinates(): void
    {
        $owner = TestData::user();
        $data = ['name' => 'Route warehouse', 'is_active' => true];
        $this->actingAs($owner)->postJson(route('warehouses.store'), $data + ['latitude' => 12.9])
            ->assertUnprocessable()->assertJsonValidationErrors('longitude');
        $this->post(route('warehouses.store'), $data + ['latitude' => 12.9, 'longitude' => 77.5])
            ->assertSessionHasNoErrors();
        $warehouse = Warehouse::sole();
        $this->assertSame(12.9, $warehouse->latitude);
        $this->assertSame(77.5, $warehouse->longitude);
    }
}
