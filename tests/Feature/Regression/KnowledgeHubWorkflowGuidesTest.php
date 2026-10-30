<?php

namespace Tests\Feature\Regression;

use App\Models\KnowledgeArticle;
use App\Models\KnowledgeCategory;
use App\Models\Role;
use App\Models\User;
use App\Services\WorkflowGuides;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Support\TestData;
use Tests\TestCase;

class KnowledgeHubWorkflowGuidesTest extends TestCase
{
    use RefreshDatabase;

    public function test_empty_knowledge_database_has_real_permission_aware_guides_without_seeding_records(): void
    {
        $owner = TestData::user(null, ['name' => 'Synthetic Knowledge Owner']);
        $this->actingAs($owner);
        $this->assertDatabaseCount('knowledge_articles', 0);
        $this->assertDatabaseCount('knowledge_categories', 0);

        $response = $this->get(route('knowledge.index'))->assertOk()
            ->assertSeeText('Workflow guides')
            ->assertSeeText('Prepare and share a quotation')
            ->assertSeeText('Create a rental')
            ->assertSeeText('Verify returned equipment')
            ->assertSeeText('Delivery completion does not change this billing policy.')
            ->assertSee('data-knowledge-workflows', false);
        $guides = $response->viewData('workflowGuides');
        $this->assertNotEmpty($guides);
        $this->assertSame(app(WorkflowGuides::class)->forUser($owner), $guides);
        foreach ($guides as $guide) {
            $response->assertSee('id="'.$guide['id'].'"', false)->assertSee('href="'.route($guide['route']).'"', false);
        }
        $this->assertDatabaseCount('knowledge_articles', 0);
        $this->assertDatabaseCount('knowledge_categories', 0);
        $this->exportKnowledgeFixture($response, count($guides));
    }

    public function test_restricted_roles_do_not_receive_guides_or_action_links_they_cannot_use(): void
    {
        $organization = TestData::organization();
        $customerCreator = $this->roleUser($organization, 'knowledge_customer_creator', ['customers' => ['read', 'create']]);
        $response = $this->actingAs($customerCreator)->get(route('knowledge.index'))->assertOk();
        $this->assertSame(['Add a customer'], array_column($response->viewData('workflowGuides'), 'title'));
        $response->assertDontSeeText('Create a rental')->assertDontSeeText('Create a sale')
            ->assertDontSeeText('Record and correct a receipt')->assertDontSeeText('Verify returned equipment');

        $cases = [
            'knowledge_reader' => ['customers' => ['read']],
            'receipt_without_invoice_read' => ['payments' => ['create']],
            'quotation_without_enquiry_update' => ['quotations' => ['create'], 'enquiries' => ['read']],
        ];
        foreach ($cases as $slug => $permissions) {
            $reader = $this->roleUser($organization, $slug, $permissions);
            $response = $this->actingAs($reader)->get(route('knowledge.index'))->assertOk();
            $this->assertSame([], $response->viewData('workflowGuides'), "{$slug} should not receive action guides.");
            $response->assertSeeText('No workflow guides are available for the actions assigned to your role.')
                ->assertDontSee('data-knowledge-guide=', false);
        }
    }

    public function test_search_matches_authorized_guide_steps_and_existing_published_articles(): void
    {
        $owner = TestData::user();
        $this->actingAs($owner);
        $matching = $this->article($owner->organization_id, 'Signature approval notes', ['type' => 'policy']);
        $this->article($owner->organization_id, 'Unrelated billing notes');

        $response = $this->get(route('knowledge.index', ['search' => 'SIGNATURE']))->assertOk();
        $this->assertSame(['Complete delivery or pickup'], array_column($response->viewData('workflowGuides'), 'title'));
        $this->assertSame([$matching->id], $response->viewData('recentArticles')->pluck('id')->all());
        $response->assertSeeText('Signature approval notes')->assertDontSeeText('Unrelated billing notes');

        $empty = $this->get(route('knowledge.index', ['search' => 'no-such-knowledge-topic']))->assertOk();
        $this->assertSame([], $empty->viewData('workflowGuides'));
        $this->assertTrue($empty->viewData('recentArticles')->isEmpty());
        $empty->assertSeeText('No workflow guides match the current search and content type.');
    }

    public function test_content_type_filter_includes_workflows_as_guides_and_keeps_existing_article_filtering(): void
    {
        $owner = TestData::user();
        $this->actingAs($owner);
        $guide = $this->article($owner->organization_id, 'Published equipment guide');
        $sop = $this->article($owner->organization_id, 'Published safety SOP', ['type' => 'sop']);

        $response = $this->get(route('knowledge.index', ['type' => 'guide']))->assertOk();
        $this->assertSame(app(WorkflowGuides::class)->forUser($owner), $response->viewData('workflowGuides'));
        $this->assertSame([$guide->id], $response->viewData('recentArticles')->pluck('id')->all());

        $response = $this->get(route('knowledge.index', ['type' => 'sop']))->assertOk();
        $this->assertSame([], $response->viewData('workflowGuides'));
        $this->assertSame([$sop->id], $response->viewData('recentArticles')->pluck('id')->all());

        $invalid = $this->get(route('knowledge.index', ['type' => 'not-a-known-type']))->assertOk();
        $this->assertSame(app(WorkflowGuides::class)->forUser($owner), $invalid->viewData('workflowGuides'));
        $this->assertCount(2, $invalid->viewData('recentArticles'));
    }

    public function test_published_tenant_and_global_articles_categories_and_detail_routes_keep_their_existing_visibility(): void
    {
        $organization = TestData::organization();
        $owner = TestData::user($organization);
        $other = TestData::organization(['name' => 'Other knowledge tenant']);
        $this->actingAs($owner);
        $localCategory = $this->category($organization->id, 'Local guidance');
        $globalCategory = $this->category(null, 'Shared guidance');
        $otherCategory = $this->category($other->id, 'Private other category');
        $this->category($organization->id, 'Inactive local category', ['is_active' => false]);
        $local = $this->article($organization->id, 'Local published instructions', ['knowledge_category_id' => $localCategory->id]);
        $global = $this->article(null, 'Shared published instructions', ['knowledge_category_id' => $globalCategory->id]);
        $private = $this->article($other->id, 'Private other instructions', ['knowledge_category_id' => $otherCategory->id]);
        $draft = $this->article($organization->id, 'Unpublished draft instructions', ['status' => 'draft']);
        $before = [KnowledgeArticle::count(), KnowledgeCategory::count()];

        $response = $this->get(route('knowledge.index'))->assertOk();
        $this->assertEqualsCanonicalizing([$local->id, $global->id], $response->viewData('recentArticles')->pluck('id')->all());
        $this->assertEqualsCanonicalizing([$localCategory->id, $globalCategory->id], $response->viewData('categories')->pluck('id')->all());
        $this->assertSame(1, (int) $response->viewData('categories')->firstWhere('id', $localCategory->id)->published_articles_count);
        $response->assertDontSeeText('Private other instructions')->assertDontSeeText('Private other category')->assertDontSeeText('Unpublished draft instructions');
        $this->get(route('knowledge.articles.show', $local))->assertOk()->assertSeeText('Local published instructions');
        $this->get(route('knowledge.categories.show', $localCategory))->assertOk()->assertSeeText('Local published instructions');
        $this->get(route('knowledge.articles.show', $private))->assertNotFound();
        $this->get(route('knowledge.articles.show', $draft))->assertNotFound();
        $this->get(route('knowledge.categories.show', $otherCategory))->assertNotFound();
        $this->assertSame($before, [KnowledgeArticle::count(), KnowledgeCategory::count()]);
    }

    public function test_existing_workflow_guide_bookmarks_still_render_the_original_content(): void
    {
        $this->actingAs(TestData::user());
        $this->get(route('workflow-guides.index'))->assertOk()
            ->assertSee('data-workflow-guides', false)
            ->assertSeeText('One step at a time.')
            ->assertSeeText('Prepare and share a quotation')
            ->assertSee('href="'.route('knowledge.index').'"', false);
    }

    private function roleUser($organization, string $slug, array $permissions): User
    {
        $role = Role::create([
            'organization_id' => $organization->id, 'name' => $slug, 'slug' => $slug,
            'permissions' => Role::normalizePermissions($permissions), 'is_active' => true,
        ]);

        return TestData::user($organization, ['role_id' => $role->id, 'role' => $slug]);
    }

    private function article(?int $organizationId, string $title, array $attributes = []): KnowledgeArticle
    {
        return KnowledgeArticle::create(array_merge([
            'organization_id' => $organizationId, 'title' => $title, 'slug' => Str::slug($title),
            'excerpt' => $title.' excerpt', 'content' => 'Published operational instructions.',
            'type' => 'guide', 'status' => 'published', 'is_featured' => true, 'published_at' => now(),
        ], $attributes));
    }

    private function category(?int $organizationId, string $name, array $attributes = []): KnowledgeCategory
    {
        return KnowledgeCategory::create(array_merge([
            'organization_id' => $organizationId, 'name' => $name, 'slug' => Str::slug($name), 'is_active' => true,
        ], $attributes));
    }

    private function exportKnowledgeFixture(TestResponse $response, int $guideCount): void
    {
        $directory = getenv('PHOS_DASHBOARD_PREVIEW_DIR');
        if (! is_string($directory) || $directory === '') return;
        $this->assertTrue(app()->environment('testing'));
        if (! is_dir($directory)) mkdir($directory, 0777, true);
        file_put_contents($directory.'/non-dashboard-knowledge.html', $response->getContent());
        $manifestPath = $directory.'/dashboard-fixtures.json';
        $manifest = is_file($manifestPath) ? json_decode(file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR) : [];
        $manifest['non-dashboard-knowledge'] = [
            'synthetic' => true, 'html' => 'non-dashboard-knowledge.html', 'kind' => 'non-dashboard',
            'available_tabs' => [], 'role' => 'super_admin', 'active_tab' => null,
            'route' => 'knowledge.index', 'workflow_guide_count' => $guideCount,
        ];
        file_put_contents($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
