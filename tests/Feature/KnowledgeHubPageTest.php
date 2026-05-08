<?php

namespace Tests\Feature;

use Database\Seeders\KnowledgeHubSeeder;
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
        $this->seed(KnowledgeHubSeeder::class);
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
            ->assertSee('Quick summary')
            ->assertSee('Rental process flow')
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
