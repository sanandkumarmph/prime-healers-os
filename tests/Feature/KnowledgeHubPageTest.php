<?php

namespace Tests\Feature;

use App\Models\KnowledgeArticle;
use App\Models\KnowledgeCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestData;
use Tests\TestCase;

class KnowledgeHubPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $organization = TestData::organization();
        $user = TestData::user($organization);

        $this->actingAs($user);

        $rentals = KnowledgeCategory::create([
            'organization_id' => $organization->id,
            'name' => 'Rentals',
            'slug' => 'rentals',
            'description' => 'Rental order workflows, dispatch steps, returns, and renewal guidance.',
            'icon' => 'rentals',
            'sort_order' => 10,
            'is_active' => true,
        ]);

        $deliveries = KnowledgeCategory::create([
            'organization_id' => $organization->id,
            'name' => 'Deliveries',
            'slug' => 'deliveries',
            'description' => 'Delivery and pickup task handling for field and operations teams.',
            'icon' => 'deliveries',
            'sort_order' => 20,
            'is_active' => true,
        ]);

        $csvImport = KnowledgeCategory::create([
            'organization_id' => $organization->id,
            'name' => 'CSV Import',
            'slug' => 'csv-import',
            'description' => 'Import templates, validation preview, and safe data migration help.',
            'icon' => 'csv',
            'sort_order' => 30,
            'is_active' => true,
        ]);

        KnowledgeArticle::create([
            'organization_id' => $organization->id,
            'knowledge_category_id' => $rentals->id,
            'title' => 'How to create a rental',
            'slug' => 'how-to-create-a-rental',
            'excerpt' => 'Step-by-step guide to create a rental with the right warehouse, quantity, and asset availability checks.',
            'content' => "1. Open Rentals and choose New Rental.\n2. Select the customer and dispatch warehouse.\n3. Pick the product and confirm rental-only availability.",
            'type' => 'guide',
            'status' => 'published',
            'sort_order' => 10,
            'is_featured' => true,
            'published_at' => now()->subDay(),
            'created_by_user_id' => $user->id,
            'updated_by_user_id' => $user->id,
        ]);

        KnowledgeArticle::create([
            'organization_id' => $organization->id,
            'knowledge_category_id' => $deliveries->id,
            'title' => 'How to complete delivery',
            'slug' => 'how-to-complete-delivery',
            'excerpt' => 'Operational checklist for marking a rental or sale delivery complete without losing progress.',
            'content' => "1. Open the assigned delivery task.\n2. Confirm customer, quantity, and asset serials.\n3. Mark completed only after the field team confirms handover.",
            'type' => 'tutorial',
            'status' => 'published',
            'sort_order' => 20,
            'is_featured' => true,
            'published_at' => now()->subHours(12),
            'created_by_user_id' => $user->id,
            'updated_by_user_id' => $user->id,
        ]);

        KnowledgeArticle::create([
            'organization_id' => $organization->id,
            'knowledge_category_id' => $csvImport->id,
            'title' => 'How to upload CSV safely',
            'slug' => 'how-to-upload-csv-safely',
            'excerpt' => 'Pre-import checklist to avoid duplicate data and incorrect product matching.',
            'content' => "1. Download the latest template from Data Import.\n2. Fill Brand and Model for products with shared names.\n3. Preview and fix invalid rows before import.",
            'type' => 'policy',
            'status' => 'published',
            'sort_order' => 30,
            'is_featured' => true,
            'published_at' => now()->subHours(6),
            'created_by_user_id' => $user->id,
            'updated_by_user_id' => $user->id,
        ]);
    }

    public function test_authenticated_user_can_open_knowledge_hub_page(): void
    {
        $this->get(route('knowledge.index'))
            ->assertOk()
            ->assertSee('Knowledge Hub')
            ->assertSee('Featured articles')
            ->assertSee('How to create a rental');
    }

    public function test_authenticated_user_can_open_category_page(): void
    {
        $this->get(route('knowledge.categories.show', 'rentals'))
            ->assertOk()
            ->assertSee('Rentals')
            ->assertSee('How to create a rental');
    }

    public function test_authenticated_user_can_open_article_page(): void
    {
        $this->get(route('knowledge.articles.show', 'how-to-create-a-rental'))
            ->assertOk()
            ->assertSee('How to create a rental')
            ->assertSee('Step-by-step guide')
            ->assertSee('Rental process flow')
            ->assertSee('Before creating a rental')
            ->assertSee('Rental lifecycle at a glance')
            ->assertSee('Step-by-step');
    }

    public function test_search_filters_knowledge_articles(): void
    {
        $this->get(route('knowledge.index', ['search' => 'CSV']))
            ->assertOk()
            ->assertSee('How to upload CSV safely')
            ->assertDontSee('How to complete delivery');
    }
}
