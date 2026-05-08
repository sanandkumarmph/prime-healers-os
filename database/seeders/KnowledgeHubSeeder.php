<?php

namespace Database\Seeders;

use App\Models\KnowledgeArticle;
use App\Models\KnowledgeCategory;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class KnowledgeHubSeeder extends Seeder
{
    public function run(): void
    {
        $categories = [
            ['name' => 'Rentals', 'slug' => 'rentals', 'description' => 'Rental order workflows, dispatch steps, returns, and renewal guidance.', 'icon' => 'rentals', 'sort_order' => 10],
            ['name' => 'Sales', 'slug' => 'sales', 'description' => 'Sales order creation, invoicing, and stock handling help.', 'icon' => 'sales', 'sort_order' => 20],
            ['name' => 'Payments', 'slug' => 'payments', 'description' => 'Payment collection, pending dues, and reconciliation steps.', 'icon' => 'payments', 'sort_order' => 30],
            ['name' => 'Deliveries', 'slug' => 'deliveries', 'description' => 'Delivery and pickup task handling for field and operations teams.', 'icon' => 'deliveries', 'sort_order' => 40],
            ['name' => 'Inventory', 'slug' => 'inventory', 'description' => 'Product Master, Asset Register, stock mode, and verification references.', 'icon' => 'inventory', 'sort_order' => 50],
            ['name' => 'Customers', 'slug' => 'customers', 'description' => 'Customer onboarding, quick create, and duplicate-prevention guidance.', 'icon' => 'customers', 'sort_order' => 60],
            ['name' => 'CSV Import', 'slug' => 'csv-import', 'description' => 'Import templates, validation preview, and safe data migration help.', 'icon' => 'csv', 'sort_order' => 70],
            ['name' => 'Reports', 'slug' => 'reports', 'description' => 'Dashboards, overdue visibility, and operational reporting notes.', 'icon' => 'reports', 'sort_order' => 80],
        ];

        foreach ($categories as $category) {
            KnowledgeCategory::query()->updateOrCreate(
                ['organization_id' => null, 'slug' => $category['slug']],
                $category + ['is_active' => true]
            );
        }

        $authorId = User::query()->orderBy('id')->value('id');
        $publishedAt = Carbon::now()->subDays(3);

        $articles = [
            ['category' => 'rentals', 'title' => 'How to create a rental', 'type' => 'guide', 'featured' => true, 'excerpt' => 'Step-by-step guide to create a rental with the right warehouse, quantity, and asset availability checks.', 'content' => "1. Open Rentals and choose New Rental.\n2. Select the customer and dispatch warehouse.\n3. Pick the product and confirm rental-only availability.\n4. Set dates, charges, and assign assets if the product is tracked.\n5. Save the rental and confirm the delivery task and invoice details."],
            ['category' => 'payments', 'title' => 'How to collect rental payment', 'type' => 'sop', 'featured' => true, 'excerpt' => 'Collect invoice-linked rental payments safely so balances and dashboards remain correct.', 'content' => "1. Open the rental or invoice.\n2. Confirm the outstanding invoice balance before collecting.\n3. Record the payment against the invoice.\n4. Verify the payment status updates to paid or partial.\n5. Recheck the rental list and payments report after saving."],
            ['category' => 'deliveries', 'title' => 'How to complete delivery', 'type' => 'tutorial', 'featured' => true, 'excerpt' => 'Operational checklist for marking a rental or sale delivery complete without losing progress.', 'content' => "1. Open the assigned delivery task.\n2. Confirm customer, quantity, and asset serials.\n3. Mark partial delivery first if not all units were delivered.\n4. Mark completed only after the field team confirms handover.\n5. Verify the rental or sale list reflects completed delivery."],
            ['category' => 'deliveries', 'title' => 'How to return rental equipment', 'type' => 'guide', 'featured' => false, 'excerpt' => 'Pickup completion and return verification steps for rental assets.', 'content' => "1. Create or open the pickup task.\n2. Record returned quantity and serials.\n3. Complete the pickup.\n4. Move to Return Verification for condition review.\n5. Mark assets as available, maintenance, or retired based on condition."],
            ['category' => 'sales', 'title' => 'How to create a sale', 'type' => 'guide', 'featured' => true, 'excerpt' => 'Create a sale using the current sales workflow and keep invoice/payment status consistent.', 'content' => "1. Open Sales and choose New Sale.\n2. Select the customer and product variant.\n3. Confirm tracked sale stock or untracked quantity.\n4. Enter pricing, tax mode, and payment status.\n5. Save and verify the invoice link and delivery action if needed."],
            ['category' => 'csv-import', 'title' => 'How to upload CSV safely', 'type' => 'policy', 'featured' => true, 'excerpt' => 'Pre-import checklist to avoid duplicate data and incorrect product matching.', 'content' => "1. Download the latest template from Data Import.\n2. Fill Brand and Model for products with shared names.\n3. Preview and fix invalid rows before import.\n4. Use Upload New File after correcting master data.\n5. Import only after the preview counts and errors look correct."],
            ['category' => 'inventory', 'title' => 'How to add a new asset', 'type' => 'sop', 'featured' => false, 'excerpt' => 'Asset Register entry rules for serials, stages, warehouses, and tracked stock.', 'content' => "1. Confirm the product stock mode is tracked.\n2. Open Asset Register and create one asset per physical unit.\n3. Choose sale unit or rental asset stage correctly.\n4. Capture serial/barcode and warehouse.\n5. Save and verify the product stock summary updates as expected."],
            ['category' => 'reports', 'title' => 'How to check outstanding payments', 'type' => 'faq', 'featured' => false, 'excerpt' => 'Where to find unpaid invoices, unbilled orders, and pending collections.', 'content' => "Use the dashboard widgets and invoice list together.\nOutstanding Invoices shows unpaid invoice balance.\nUnbilled Rentals and Unbilled Sales show order value not yet invoiced.\nPayments screens show actual collections received.\nIf values look mismatched, verify invoice generation first."],
        ];

        foreach ($articles as $index => $article) {
            $category = KnowledgeCategory::query()
                ->whereNull('organization_id')
                ->where('slug', $article['category'])
                ->first();

            if (!$category) {
                continue;
            }

            KnowledgeArticle::query()->updateOrCreate(
                ['organization_id' => null, 'slug' => Str::slug($article['title'])],
                [
                    'knowledge_category_id' => $category->id,
                    'title' => $article['title'],
                    'excerpt' => $article['excerpt'],
                    'content' => $article['content'],
                    'type' => $article['type'],
                    'status' => 'published',
                    'sort_order' => ($index + 1) * 10,
                    'is_featured' => $article['featured'],
                    'published_at' => $publishedAt->copy()->addHours($index),
                    'created_by_user_id' => $authorId,
                    'updated_by_user_id' => $authorId,
                ]
            );
        }
    }
}
