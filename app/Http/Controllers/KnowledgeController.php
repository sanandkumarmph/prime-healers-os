<?php

namespace App\Http\Controllers;

use App\Models\KnowledgeArticle;
use App\Models\KnowledgeCategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

class KnowledgeController extends Controller
{
    private function orgId(): int
    {
        return (int) auth()->user()->organization_id;
    }

    private function categoryBaseQuery()
    {
        return KnowledgeCategory::query()
            ->visibleToOrganization($this->orgId())
            ->withCount([
                'articles as published_articles_count' => fn (Builder $query) => $query->visibleToOrganization($this->orgId()),
            ])
            ->orderBy('sort_order')
            ->orderBy('name');
    }

    private function articleBaseQuery()
    {
        return KnowledgeArticle::query()
            ->visibleToOrganization($this->orgId())
            ->with(['category', 'createdBy', 'updatedBy'])
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->orderByDesc('published_at')
            ->orderBy('title');
    }

    private function applySearch($query, ?string $search)
    {
        $search = trim((string) $search);

        if ($search === '') {
            return $query;
        }

        return $query->where(function (Builder $searchQuery) use ($search) {
            $searchQuery
                ->where('title', 'like', '%' . $search . '%')
                ->orWhere('excerpt', 'like', '%' . $search . '%')
                ->orWhere('content', 'like', '%' . $search . '%')
                ->orWhereHas('category', function (Builder $categoryQuery) use ($search) {
                    $categoryQuery->where('name', 'like', '%' . $search . '%');
                });
        });
    }

    private function articleEnhancements(): array
    {
        return [
            'how-to-create-a-rental' => [
                'summary' => [
                    'purpose' => 'Create a rental order with the right customer, asset availability, dates, and billing values.',
                    'when_to_use' => 'Use this when a customer is taking equipment on rent for a defined period.',
                    'who_should_use' => 'Rental desk staff, coordinators, and branch operations teams.',
                    'common_mistakes' => [
                        'Selecting a sale-only product instead of a rental-ready product.',
                        'Skipping the dispatch warehouse before checking tracked asset availability.',
                        'Assuming delivery is complete before the field team confirms handover.',
                    ],
                ],
                'flow' => [
                    'label' => 'Rental process flow',
                    'steps' => ['Lead', 'Customer', 'Rental', 'Invoice', 'Payment', 'Delivery', 'Return'],
                ],
                'steps' => [
                    ['title' => 'Select the customer', 'description' => 'Start with the correct customer profile so the invoice, phone number, and follow-up history stay linked.'],
                    ['title' => 'Choose the rental product', 'description' => 'Pick the right product variant and confirm the warehouse has rental-ready availability.'],
                    ['title' => 'Confirm dates and charges', 'description' => 'Enter start date, end date, rental amount, deposit, and transport carefully before saving.'],
                    ['title' => 'Review invoice and delivery', 'description' => 'After saving, confirm the invoice status and the delivery task so operations can act immediately.'],
                ],
                'checklists' => [
                    [
                        'title' => 'Before creating a rental',
                        'items' => [
                            'Customer phone and address are correct.',
                            'Product is rental-enabled, not sale-only.',
                            'Warehouse is selected before checking tracked availability.',
                            'Deposit and transport are verified with the customer.',
                        ],
                    ],
                ],
                'infographics' => [
                    'title' => 'Rental lifecycle at a glance',
                    'items' => [
                        ['title' => 'Active', 'value' => 'Order created', 'description' => 'Rental is live and operational tracking starts.'],
                        ['title' => 'Delivery', 'value' => 'Pending or completed', 'description' => 'Delivery status controls whether the rental has truly started.'],
                        ['title' => 'Pickup', 'value' => 'After return', 'description' => 'Pickup records the physical return of equipment.'],
                        ['title' => 'Verification', 'value' => 'Condition check', 'description' => 'Returned assets move to verification before becoming available again.'],
                    ],
                ],
                'callouts' => [
                    ['tone' => 'important', 'title' => 'Tracked rental stock only', 'body' => 'Rental creation should always use rental assets only. Sale units must never appear as rentable stock.'],
                    ['tone' => 'tip', 'title' => 'Client-friendly explanation', 'body' => 'Tell the client: “We are reserving your equipment, scheduling delivery, and keeping your billing linked in one order.”'],
                ],
            ],
            'how-to-collect-rental-payment' => [
                'summary' => [
                    'purpose' => 'Collect payments against the correct rental invoice so balance and dashboard values stay accurate.',
                    'when_to_use' => 'Use this whenever a customer is paying fully or partially against a rental invoice.',
                    'who_should_use' => 'Billing staff, front desk teams, and collections users.',
                    'common_mistakes' => [
                        'Recording payment without checking the actual invoice balance.',
                        'Marking a rental as paid without creating a payment row.',
                        'Collecting cash against the wrong invoice or customer.',
                    ],
                ],
                'flow' => [
                    'label' => 'Payment collection flow',
                    'steps' => ['Rental', 'Invoice', 'Balance Check', 'Payment Entry', 'Status Update', 'Receipt Confirmation'],
                ],
                'steps' => [
                    ['title' => 'Open the invoice', 'description' => 'Always collect payment from the linked invoice or rental page so the payment attaches correctly.'],
                    ['title' => 'Confirm outstanding balance', 'description' => 'Check whether the invoice is unpaid, partial, or already settled before entering an amount.'],
                    ['title' => 'Record payment amount', 'description' => 'Enter full or partial payment using the correct date and method.'],
                    ['title' => 'Recheck status', 'description' => 'After saving, confirm the invoice and rental lists show the new payment status.'],
                ],
                'checklists' => [
                    [
                        'title' => 'Before collecting payment',
                        'items' => [
                            'Customer and invoice number match.',
                            'Outstanding amount is confirmed.',
                            'Payment date and method are ready.',
                            'Partial amount is less than the total due.',
                        ],
                    ],
                ],
                'infographics' => [
                    'title' => 'Payment status meanings',
                    'items' => [
                        ['title' => 'Pending', 'value' => '₹ balance full', 'description' => 'No payment has been collected yet.'],
                        ['title' => 'Partial', 'value' => '₹ balance remaining', 'description' => 'Some payment is recorded, but the invoice is not settled.'],
                        ['title' => 'Paid', 'value' => '₹0.00 balance', 'description' => 'Invoice is fully collected and closed financially.'],
                    ],
                ],
                'callouts' => [
                    ['tone' => 'warning', 'title' => 'Do not force paid status', 'body' => 'Use a payment entry instead of changing only the badge or status field. Financial dashboards read payment records.'],
                    ['tone' => 'example', 'title' => 'Client-friendly wording', 'body' => '“Your payment is recorded against invoice {number}. Your remaining balance, if any, will still show until fully settled.”'],
                ],
            ],
            'how-to-complete-delivery' => [
                'summary' => [
                    'purpose' => 'Complete delivery without losing operational tracking or confusing pickup status later.',
                    'when_to_use' => 'Use this when the field team has handed over equipment or sale items to the customer.',
                    'who_should_use' => 'Delivery coordinators, field staff, and rental/sales operations users.',
                    'common_mistakes' => [
                        'Marking delivery completed before customer handover.',
                        'Skipping serial confirmation for tracked items.',
                        'Using pickup status to represent delivery progress.',
                    ],
                ],
                'flow' => [
                    'label' => 'Delivery process flow',
                    'steps' => ['Assignment', 'Dispatch', 'Handover', 'Completion', 'List Update'],
                ],
                'steps' => [
                    ['title' => 'Open the assigned delivery task', 'description' => 'Use the task linked to the rental or sale so progress stays connected to the order.'],
                    ['title' => 'Verify item details', 'description' => 'Confirm quantity, serials, customer, and destination before marking anything complete.'],
                    ['title' => 'Update handover status', 'description' => 'If only some items were delivered, use partial progress instead of full completion.'],
                    ['title' => 'Finish the task', 'description' => 'Mark completed only after the field team or third party confirms the handover.'],
                ],
                'checklists' => [
                    [
                        'title' => 'Before delivery completion',
                        'items' => [
                            'Correct order is opened.',
                            'Serials or quantities match what was sent.',
                            'Customer handover is confirmed.',
                            'Third-party completion note is captured if applicable.',
                        ],
                    ],
                ],
                'infographics' => [
                    'title' => 'Delivery status meanings',
                    'items' => [
                        ['title' => 'Not assigned', 'value' => 'No task yet', 'description' => 'Operations has not created a delivery task yet.'],
                        ['title' => 'Assigned', 'value' => 'Task ready', 'description' => 'A task exists and is waiting to be executed.'],
                        ['title' => 'Completed', 'value' => 'Handover done', 'description' => 'The delivery is operationally closed.'],
                    ],
                ],
                'callouts' => [
                    ['tone' => 'important', 'title' => 'Completed overrides assignment', 'body' => 'If delivery is completed, the list should say completed even if no internal staff member was assigned because a third party handled it.'],
                ],
            ],
            'how-to-return-rental-equipment' => [
                'summary' => [
                    'purpose' => 'Bring rented equipment back into the system with the correct pickup and verification flow.',
                    'when_to_use' => 'Use this when the customer has returned or is returning rental equipment.',
                    'who_should_use' => 'Pickup teams, operations coordinators, and service desk staff.',
                    'common_mistakes' => [
                        'Closing the rental before pickup is recorded.',
                        'Making assets available again before verification.',
                        'Skipping damage or repair notes.',
                    ],
                ],
                'flow' => [
                    'label' => 'Return process flow',
                    'steps' => ['Pickup Task', 'Pickup Complete', 'Awaiting Verification', 'Condition Check', 'Available or Maintenance'],
                ],
                'steps' => [
                    ['title' => 'Open the pickup task', 'description' => 'Use the linked pickup task so the rental progress stays accurate.'],
                    ['title' => 'Confirm returned items', 'description' => 'Record the actual returned quantity and tracked serials.'],
                    ['title' => 'Complete pickup', 'description' => 'Completing pickup should move tracked rental assets to awaiting verification.'],
                    ['title' => 'Run return verification', 'description' => 'Set the final asset state to available, maintenance, or retired after physical inspection.'],
                ],
                'checklists' => [
                    [
                        'title' => 'Before closing a rental return',
                        'items' => [
                            'Pickup task is completed.',
                            'Returned serials match the dispatched serials.',
                            'Condition notes are captured.',
                            'Verification outcome is updated.',
                        ],
                    ],
                ],
                'callouts' => [
                    ['tone' => 'warning', 'title' => 'Do not skip verification', 'body' => 'A returned tracked asset should move to awaiting verification first, not directly back to available, unless the verification step has already been completed.'],
                ],
            ],
            'how-to-create-a-sale' => [
                'summary' => [
                    'purpose' => 'Create a sale correctly with the right product variant, stock source, invoice, and payment status.',
                    'when_to_use' => 'Use this for direct product sales or rental-linked consumable sales.',
                    'who_should_use' => 'Sales desk users, coordinators, and billing teams.',
                    'common_mistakes' => [
                        'Selecting the wrong product variant when names are similar.',
                        'Using rental assets to fulfill a sale.',
                        'Confusing sale payment status with invoice payment status.',
                    ],
                ],
                'flow' => [
                    'label' => 'Sales process flow',
                    'steps' => ['Customer', 'Product', 'Stock Check', 'Sale', 'Invoice', 'Payment', 'Delivery'],
                ],
                'steps' => [
                    ['title' => 'Select customer and product', 'description' => 'Choose the right customer and product variant using brand and model if names repeat.'],
                    ['title' => 'Verify sale stock', 'description' => 'Tracked sale and tracked-both products must consume sale units only.'],
                    ['title' => 'Enter amount and tax mode', 'description' => 'Set pricing, tax mode, and shipping carefully before saving.'],
                    ['title' => 'Review invoice and payment state', 'description' => 'Confirm the created sale shows the expected invoice and payment badges.'],
                ],
                'infographics' => [
                    'title' => 'Sales lifecycle',
                    'items' => [
                        ['title' => 'Order', 'value' => 'Sale created', 'description' => 'Customer, amount, and stock are committed.'],
                        ['title' => 'Invoice', 'value' => 'Generated if needed', 'description' => 'Finance visibility starts when the invoice exists.'],
                        ['title' => 'Payment', 'value' => 'Pending, partial, paid', 'description' => 'Use payment records to move financial status.'],
                        ['title' => 'Delivery', 'value' => 'Optional', 'description' => 'Delivery status is separate from payment collection.'],
                    ],
                ],
            ],
            'how-to-upload-csv-safely' => [
                'summary' => [
                    'purpose' => 'Import master and transactional data safely without duplicating records or mismatching products.',
                    'when_to_use' => 'Use this before importing customers, products, assets, rentals, or sales through CSV or XLSX.',
                    'who_should_use' => 'Data migration users, branch admins, and implementation teams.',
                    'common_mistakes' => [
                        'Uploading a corrected file without clearing the previous preview session.',
                        'Matching products by name only when variants share the same name.',
                        'Ignoring invalid-row messages and importing anyway.',
                    ],
                ],
                'flow' => [
                    'label' => 'Safe CSV process',
                    'steps' => ['Download Template', 'Fill Required Fields', 'Upload', 'Preview', 'Fix Errors', 'Import Valid Rows'],
                ],
                'steps' => [
                    ['title' => 'Download the latest template', 'description' => 'Always start from the in-app template so field names match the current importer.'],
                    ['title' => 'Fill identity columns carefully', 'description' => 'Use product name, brand, and model together when variants share the same name.'],
                    ['title' => 'Review preview results', 'description' => 'Check valid rows, invalid rows, and guidance banners before executing anything.'],
                    ['title' => 'Upload a fresh file after fixes', 'description' => 'Use Upload New File or Clear Upload so old preview data does not affect the new import.'],
                ],
                'checklists' => [
                    [
                        'title' => 'Before CSV upload',
                        'items' => [
                            'Correct template downloaded from Rentnexis.',
                            'Required columns are filled.',
                            'Brand and model are present for duplicate product names.',
                            'Old upload preview has been cleared before re-uploading.',
                        ],
                    ],
                ],
                'infographics' => [
                    'title' => 'CSV safety rules',
                    'items' => [
                        ['title' => 'Preview first', 'value' => 'No DB write', 'description' => 'Preview must be clean before import execution.'],
                        ['title' => 'Valid rows only', 'value' => 'Safe import', 'description' => 'Invalid rows should be skipped and fixed.'],
                        ['title' => 'Import once', 'value' => 'Idempotent', 'description' => 'Do not re-run the same batch after success.'],
                    ],
                ],
                'callouts' => [
                    ['tone' => 'tip', 'title' => 'Client-friendly explanation', 'body' => '“Preview checks your file before saving anything, so you can fix mistakes safely first.”'],
                ],
            ],
            'how-to-add-a-new-asset' => [
                'summary' => [
                    'purpose' => 'Register one physical unit at a time with the correct tracked stock stage and warehouse.',
                    'when_to_use' => 'Use this when a new tracked unit arrives or an imported tracked asset needs to be verified.',
                    'who_should_use' => 'Inventory staff, warehouse teams, and implementation users.',
                    'common_mistakes' => [
                        'Adding assets to an untracked product.',
                        'Using the wrong stage between sale unit and rental asset.',
                        'Leaving serial identity unclear for tracked stock.',
                    ],
                ],
                'flow' => [
                    'label' => 'Asset setup flow',
                    'steps' => ['Product Master', 'Tracked Mode', 'Asset Register', 'Serial Identity', 'Warehouse', 'Available Status'],
                ],
                'steps' => [
                    ['title' => 'Confirm Product Master first', 'description' => 'The product should already exist with the correct stock mode before you create the asset.'],
                    ['title' => 'Choose the right asset stage', 'description' => 'Pick sale unit for sale stock or rental asset for rental operations.'],
                    ['title' => 'Capture serial and warehouse', 'description' => 'These fields drive future dispatch, return, and verification workflows.'],
                    ['title' => 'Save and verify stock summary', 'description' => 'Check that the product summary moves in the correct stock bucket after save.'],
                ],
                'callouts' => [
                    ['tone' => 'warning', 'title' => 'Tracked mode required', 'body' => 'If a product is untracked, switch the product to tracked mode before importing or creating assets.'],
                ],
            ],
            'how-to-check-outstanding-payments' => [
                'summary' => [
                    'purpose' => 'Understand where unpaid amounts are coming from without mixing orders, invoices, and collections.',
                    'when_to_use' => 'Use this when finance or operations needs to follow up on dues.',
                    'who_should_use' => 'Finance teams, branch managers, and business owners.',
                    'common_mistakes' => [
                        'Treating uninvoiced orders as unpaid invoices.',
                        'Assuming collection totals and invoice totals should always match.',
                        'Reviewing only dashboards without checking invoices.',
                    ],
                ],
                'flow' => [
                    'label' => 'Receivables review flow',
                    'steps' => ['Dashboard', 'Outstanding Invoices', 'Unbilled Orders', 'Invoice List', 'Collections Review'],
                ],
                'infographics' => [
                    'title' => 'Receivables meanings',
                    'items' => [
                        ['title' => 'Outstanding Invoices', 'value' => 'Unpaid invoice balance', 'description' => 'Only generated invoices with pending balance belong here.'],
                        ['title' => 'Unbilled Rentals', 'value' => 'Order value', 'description' => 'Rental orders without invoices yet.'],
                        ['title' => 'Unbilled Sales', 'value' => 'Order value', 'description' => 'Sales orders without invoices yet.'],
                        ['title' => 'Collections', 'value' => 'Payments received', 'description' => 'Actual payment rows recorded in the system.'],
                    ],
                ],
                'callouts' => [
                    ['tone' => 'important', 'title' => 'Read labels carefully', 'body' => 'Outstanding Invoices, Unbilled Rentals, and Unbilled Sales intentionally come from different sources and should not always match each other.'],
                ],
            ],
        ];
    }

    private function articleVisuals(KnowledgeArticle $article): array
    {
        return $this->articleEnhancements()[$article->slug] ?? [];
    }

    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $typeFilter = trim((string) $request->query('type', ''));

        $categories = $this->categoryBaseQuery()->get();

        $articlesQuery = $this->articleBaseQuery();
        $this->applySearch($articlesQuery, $search);

        if ($typeFilter !== '' && in_array($typeFilter, ['sop', 'guide', 'faq', 'tutorial', 'policy'], true)) {
            $articlesQuery->where('type', $typeFilter);
        }

        $featuredArticles = (clone $articlesQuery)
            ->where('is_featured', true)
            ->limit(6)
            ->get();

        $recentArticles = (clone $articlesQuery)
            ->orderByDesc('published_at')
            ->orderByDesc('updated_at')
            ->limit(10)
            ->get();

        return view('knowledge.index', [
            'search' => $search,
            'typeFilter' => $typeFilter,
            'categories' => $categories,
            'featuredArticles' => $featuredArticles,
            'recentArticles' => $recentArticles,
            'typeOptions' => ['sop', 'guide', 'faq', 'tutorial', 'policy'],
        ]);
    }

    public function category(Request $request, string $category)
    {
        $category = $this->categoryBaseQuery()
            ->where('slug', $category)
            ->firstOrFail();

        $search = trim((string) $request->query('search', ''));
        $typeFilter = trim((string) $request->query('type', ''));

        $articlesQuery = $category->articles()
            ->visibleToOrganization($this->orgId())
            ->with(['category', 'createdBy', 'updatedBy'])
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->orderByDesc('published_at')
            ->orderBy('title');

        $this->applySearch($articlesQuery, $search);

        if ($typeFilter !== '' && in_array($typeFilter, ['sop', 'guide', 'faq', 'tutorial', 'policy'], true)) {
            $articlesQuery->where('type', $typeFilter);
        }

        return view('knowledge.category', [
            'category' => $category,
            'search' => $search,
            'typeFilter' => $typeFilter,
            'articles' => $articlesQuery->paginate(12)->withQueryString(),
            'typeOptions' => ['sop', 'guide', 'faq', 'tutorial', 'policy'],
        ]);
    }

    public function article(string $article)
    {
        $article = $this->articleBaseQuery()
            ->where('slug', $article)
            ->orderByRaw('organization_id is null')
            ->firstOrFail();

        return view('knowledge.article', [
            'article' => $article,
            'visuals' => $this->articleVisuals($article),
        ]);
    }
}
