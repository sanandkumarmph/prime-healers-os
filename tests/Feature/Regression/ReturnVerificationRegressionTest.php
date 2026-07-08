<?php

namespace Tests\Feature\Regression;

use App\Models\Asset;
use App\Models\Product;
use App\Models\ReturnVerificationAccessory;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Models\Organization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TestData;
use Tests\TestCase;

class ReturnVerificationRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_verify_return_page_loads_backend_accessories_from_equipment_type(): void
    {
        [$asset] = $this->verificationAsset('Oxygen Concentrator 5 LPM');

        $this->actingAs(TestData::user($asset->organization));

        $this->get(route('assets.verify-return', $asset))
            ->assertOk()
            ->assertSee('Power cable')
            ->assertSee('Humidifier bottle')
            ->assertSee('Verify Returned Asset');
    }

    public function test_missing_accessory_requires_remarks(): void
    {
        [$asset] = $this->verificationAsset('Oxygen Concentrator 5 LPM');

        $this->actingAs(TestData::user($asset->organization));

        $this->from(route('assets.verify-return', $asset))
            ->put(route('assets.verify-return.store', $asset), [
                'serial_number' => $asset->serial_number,
                'barcode_value' => $asset->barcode_value,
                'condition_status' => Asset::CONDITION_STATUS_GOOD,
                'verification_outcome' => 'return_to_stock',
                'accessories' => [
                    ['name' => 'Power cable', 'status' => ReturnVerificationAccessory::STATUS_MISSING],
                ],
            ])
            ->assertRedirect(route('assets.verify-return', $asset))
            ->assertSessionHasErrors('remarks');
    }

    public function test_damaged_condition_requires_photo(): void
    {
        [$asset] = $this->verificationAsset('Suction Machine Portable');

        $this->actingAs(TestData::user($asset->organization));

        $this->from(route('assets.verify-return', $asset))
            ->put(route('assets.verify-return.store', $asset), [
                'serial_number' => $asset->serial_number,
                'barcode_value' => $asset->barcode_value,
                'condition_status' => Asset::CONDITION_STATUS_DAMAGED,
                'verification_outcome' => 'damaged',
                'remarks' => 'Body cracked after return.',
            ])
            ->assertRedirect(route('assets.verify-return', $asset))
            ->assertSessionHasErrors('photos.overall');
    }

    public function test_return_to_stock_records_verification_and_movement(): void
    {
        Storage::fake('public');
        [$asset] = $this->verificationAsset('BiPAP Machine');

        $this->actingAs(TestData::user($asset->organization));

        $this->put(route('assets.verify-return.store', $asset), [
            'serial_number' => $asset->serial_number,
            'barcode_value' => $asset->barcode_value,
            'condition_status' => Asset::CONDITION_STATUS_GOOD,
            'verification_outcome' => 'return_to_stock',
            'accessories' => [
                ['name' => 'Mask', 'status' => ReturnVerificationAccessory::STATUS_RETURNED],
            ],
            'photos' => [
                'overall' => UploadedFile::fake()->create('asset.jpg', 16, 'image/jpeg'),
            ],
        ])->assertRedirect(route('assets.pending-verification'));

        $asset->refresh();

        $this->assertSame(Asset::STATUS_AVAILABLE, $asset->asset_status);
        $this->assertSame(Asset::CONDITION_STATUS_GOOD, $asset->condition_status);
        $this->assertDatabaseHas('return_verifications', [
            'asset_id' => $asset->id,
            'outcome' => 'return_to_stock',
            'condition_after' => Asset::CONDITION_STATUS_GOOD,
        ]);
        $this->assertDatabaseHas('return_verification_accessories', [
            'accessory_name' => 'Mask',
            'status' => ReturnVerificationAccessory::STATUS_RETURNED,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'asset_id' => $asset->id,
            'movement_type' => StockMovement::TYPE_RETURN_VERIFICATION,
        ]);
    }

    public function test_repair_retire_and_missing_components_keep_assets_out_of_available_stock(): void
    {
        $organization = TestData::organization();
        [$repairAsset] = $this->verificationAsset('Suction Machine Repair', 'RET-REPAIR-001', $organization);
        [$retiredAsset] = $this->verificationAsset('Wheelchair Retire', 'RET-RETIRE-001', $organization);
        [$missingAsset] = $this->verificationAsset('Hospital Bed Missing', 'RET-MISSING-001', $organization);

        $this->actingAs(TestData::user($repairAsset->organization));

        $this->put(route('assets.verify-return.store', $repairAsset), [
            'serial_number' => $repairAsset->serial_number,
            'condition_status' => Asset::CONDITION_STATUS_GOOD,
            'verification_outcome' => 'repair',
            'remarks' => 'Needs service.',
        ])->assertRedirect(route('assets.pending-verification'));

        $this->put(route('assets.verify-return.store', $retiredAsset), [
            'serial_number' => $retiredAsset->serial_number,
            'condition_status' => Asset::CONDITION_STATUS_GOOD,
            'verification_outcome' => 'retire',
            'remarks' => 'Beyond usable life.',
        ])->assertRedirect(route('assets.pending-verification'));

        $this->put(route('assets.verify-return.store', $missingAsset), [
            'serial_number' => $missingAsset->serial_number,
            'condition_status' => Asset::CONDITION_STATUS_GOOD,
            'verification_outcome' => 'missing_components',
            'remarks' => 'Remote not returned.',
            'accessories' => [
                ['name' => 'Remote', 'status' => ReturnVerificationAccessory::STATUS_MISSING],
            ],
        ])->assertRedirect(route('assets.pending-verification'));

        $this->assertSame(Asset::STATUS_MAINTENANCE, $repairAsset->fresh()->asset_status);
        $this->assertSame(Asset::STATUS_RETIRED, $retiredAsset->fresh()->asset_status);
        $this->assertSame(Asset::STATUS_AWAITING_RESOLUTION, $missingAsset->fresh()->asset_status);
        $this->assertDatabaseHas('follow_ups', [
            'organization_id' => $missingAsset->organization_id,
            'rental_id' => null,
            'source' => 'return_verification',
            'status' => 'pending',
            'priority' => 'high',
        ]);
    }

    private function verificationAsset(string $productName, string $serial = 'RET-VERIFY-001', ?Organization $organization = null): array
    {
        $organization ??= TestData::organization();
        $warehouse = Warehouse::create([
            'organization_id' => $organization->id,
            'name' => 'Verification Warehouse',
            'code' => 'VER-' . substr(md5($serial), 0, 8),
            'is_active' => true,
        ]);
        $product = Product::create([
            'organization_id' => $organization->id,
            'name' => $productName,
            'category' => $productName,
            'product_type' => Product::TYPE_RENTABLE,
            'stock_mode' => Product::STOCK_MODE_TRACKED_RENTAL,
            'price_per_day' => 100,
            'available_quantity' => 0,
            'total_quantity' => 1,
        ]);
        $asset = Asset::create([
            'organization_id' => $organization->id,
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'asset_name' => $productName,
            'serial_number' => $serial,
            'barcode_value' => $serial,
            'asset_stage' => Asset::STAGE_RENTAL_STOCK,
            'condition_status' => Asset::CONDITION_STATUS_GOOD,
            'asset_status' => Asset::STATUS_AWAITING_VERIFICATION,
        ]);

        return [$asset, $product, $warehouse];
    }
}
