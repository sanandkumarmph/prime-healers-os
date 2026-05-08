<?php

namespace Tests\Feature\Regression;

use App\Models\Customer;
use App\Models\KnowledgeArticle;
use App\Models\KnowledgeCategory;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\TestData;
use Tests\TestCase;

class UatDataResetCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_uat_data_dry_run_does_not_mutate_records_or_import_snapshots(): void
    {
        Storage::fake('local');

        $organization = TestData::organization();
        $user = TestData::user($organization);

        Customer::create([
            'organization_id' => $organization->id,
            'name' => 'Aarav Sharma',
            'phone' => '9876543210',
        ]);

        Product::create([
            'organization_id' => $organization->id,
            'name' => 'BiPAP Machine',
            'brand' => 'Oxymed',
            'model_name' => 'ST 25',
            'product_type' => Product::TYPE_SELLABLE,
            'stock_mode' => Product::STOCK_MODE_UNTRACKED,
            'available_quantity' => 5,
            'total_quantity' => 5,
            'sale_price' => 25000,
            'price_per_day' => 0,
        ]);

        $category = KnowledgeCategory::create([
            'name' => 'Rentals',
            'slug' => 'rentals',
            'is_active' => true,
        ]);

        KnowledgeArticle::create([
            'knowledge_category_id' => $category->id,
            'title' => 'How to create a rental',
            'slug' => 'how-to-create-a-rental',
            'content' => 'Guide content',
            'type' => 'guide',
            'status' => 'published',
            'created_by_user_id' => $user->id,
            'updated_by_user_id' => $user->id,
        ]);

        Storage::disk('local')->put('imports/test-preview.json', json_encode(['ok' => true]));

        $this->artisan('rentnexis:reset-uat-data')
            ->expectsOutputToContain('Mode: DRY RUN')
            ->expectsOutputToContain('Dry run only. No data was deleted.')
            ->assertExitCode(0);

        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('products', 1);
        $this->assertDatabaseCount('knowledge_categories', 1);
        $this->assertDatabaseCount('knowledge_articles', 1);
        Storage::disk('local')->assertExists('imports/test-preview.json');
    }
}
