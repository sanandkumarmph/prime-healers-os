<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Services\Inventory\AssetStateService;
use App\Services\Inventory\InventoryIntelligenceService;
use App\Services\Inventory\StockMovementRecorder;
use App\Support\ActivityLogger;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class InventoryIntelligenceController extends Controller
{
    public function __construct(
        private readonly InventoryIntelligenceService $service,
        private readonly AssetStateService $assetStateService,
        private readonly StockMovementRecorder $stockMovementRecorder,
    ) {
    }

    private function deny(Request $request, string $message)
    {
        if ($request->expectsJson()) {
            abort(403, $message);
        }

        return redirect()->route('dashboard')->with('error', $message);
    }

    private function authorizeIntelligence(Request $request, bool $export = false)
    {
        $user = $request->user();

        if (!$user->hasPermission('stock_history.view')) {
            return $this->deny($request, 'You are not authorized to access inventory intelligence.');
        }

        if ($export && !$user->hasPermission('stock_history.export')) {
            return $this->deny($request, 'You are not authorized to export inventory intelligence.');
        }

        return null;
    }

    private function authorizeReconciliation(Request $request): ?RedirectResponse
    {
        $user = $request->user();

        if (!$user?->isSuperAdmin()) {
            return $this->deny($request, 'Only super admin can perform reconciliation actions.');
        }

        return null;
    }

    public function index(Request $request)
    {
        if ($response = $this->authorizeIntelligence($request)) {
            return $response;
        }

        $filters = $this->filters($request);
        $report = $this->service->build((int) $request->user()->organization_id, $filters);

        return view('inventory.intelligence', [
            'report' => $report,
            'filters' => $filters,
            'canExport' => $request->user()->hasPermission('stock_history.export'),
            'canReconcile' => $request->user()->isSuperAdmin(),
        ]);
    }

    public function exportMatrixCsv(Request $request)
    {
        if ($response = $this->authorizeIntelligence($request, true)) {
            return $response;
        }

        $filters = $this->filters($request);
        $report = $this->service->build((int) $request->user()->organization_id, $filters);

        return response()->streamDownload(function () use ($report) {
            $handle = fopen('php://output', 'w');

            $header = ['Product', 'Category'];
            foreach ($report['dates'] as $date) {
                $header[] = ($report['bucket_mode'] ?? 'daily') === 'daily'
                    ? $date['start']->format('Y-m-d')
                    : $date['label'];
            }
            fputcsv($handle, $header);

            foreach ($report['rows'] as $row) {
                $record = [
                    $row['product']->name,
                    $row['product']->category,
                ];

                foreach ($report['dates'] as $date) {
                    $record[] = (string) ($row['daily'][$date['key']]['closing_stock'] ?? 0);
                }

                fputcsv($handle, $record);
            }

            fclose($handle);
        }, 'inventory-intelligence-matrix.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function exportMovementBreakdownCsv(Request $request)
    {
        if ($response = $this->authorizeIntelligence($request, true)) {
            return $response;
        }

        $filters = $this->filters($request);
        $movements = $this->service->movementBreakdown((int) $request->user()->organization_id, $filters);

        return response()->streamDownload(function () use ($movements) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'Movement At',
                'Product',
                'Movement Type',
                'Quantity',
                'From Status',
                'To Status',
                'From Warehouse',
                'To Warehouse',
                'Rental',
                'Sale',
                'Delivery',
                'Customer',
                'Performed By',
                'Notes',
            ]);

            foreach ($movements as $movement) {
                $customer = $movement->rental?->customer?->name
                    ?: $movement->sale?->customer?->name
                    ?: $movement->rental?->customer_name
                    ?: null;

                fputcsv($handle, [
                    optional($movement->movement_at)->format('Y-m-d H:i:s'),
                    $movement->product?->name,
                    $movement->movement_type,
                    $movement->quantity,
                    $movement->from_status,
                    $movement->to_status,
                    $movement->fromWarehouse?->name,
                    $movement->toWarehouse?->name,
                    $movement->rental ? ('Rental #' . $movement->rental->id) : ($movement->rental_id ? ('Rental #' . $movement->rental_id) : '—'),
                    $movement->sale_id,
                    $movement->delivery_id,
                    $customer,
                    $movement->performedBy?->name,
                    $movement->notes,
                ]);
            }

            fclose($handle);
        }, 'inventory-intelligence-movements.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function exportReconciliationCsv(Request $request)
    {
        if ($response = $this->authorizeIntelligence($request, true)) {
            return $response;
        }

        $filters = $this->filters($request);
        $baseAssetsQuery = Asset::query()
            ->where('organization_id', (int) $request->user()->organization_id);

        if (($filters['product_id'] ?? null) !== null) {
            $baseAssetsQuery->where('product_id', (int) $filters['product_id']);
        }

        if (($filters['category'] ?? '') !== '') {
            $category = (string) $filters['category'];
            $baseAssetsQuery->whereHas('product', fn ($query) => $query->where('category', $category));
        }

        if (($filters['warehouse_id'] ?? null) !== null) {
            $baseAssetsQuery->where('warehouse_id', (int) $filters['warehouse_id']);
        } elseif (($filters['city'] ?? '') !== '') {
            $city = strtolower(trim((string) $filters['city']));
            $baseAssetsQuery->whereHas('warehouse', fn ($query) => $query->whereRaw('LOWER(city) = ?', [$city]));
        }

        $reconciliation = $this->assetStateService->rentalReconciliation((int) $request->user()->organization_id, $baseAssetsQuery, $filters);

        return response()->streamDownload(function () use ($reconciliation) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'Asset ID',
                'Serial / Barcode',
                'Product',
                'Status',
                'Condition',
                'Warehouse',
                'Linked Rental',
                'Rental Status',
                'Delivery Status',
                'Pickup Status',
                'Last Stock Movement',
                'Last Stock Movement At',
                'Updated At',
                'Severity',
                'Recommended Action',
                'Unclassified For Days',
                'Reason',
            ]);

            foreach (($reconciliation['unclassified_assets'] ?? []) as $asset) {
                fputcsv($handle, [
                    $asset['asset_id'],
                    $asset['serial_number'] ?: ($asset['barcode_value'] ?: null),
                    $asset['product'],
                    $asset['asset_status'],
                    $asset['condition_status'],
                    $asset['warehouse'],
                    $asset['linked_rental_reference'] ?: ($asset['linked_rental_id'] ? ('Rental #' . $asset['linked_rental_id']) : '—'),
                    $asset['linked_rental_status'],
                    $asset['linked_delivery_status'],
                    $asset['linked_pickup_status'],
                    $asset['last_stock_movement'],
                    $asset['last_stock_movement_at'],
                    $asset['updated_at'],
                    $asset['severity'],
                    $asset['recommended_action'],
                    $asset['unclassified_for_days'],
                    $asset['reason'],
                ]);
            }

            fclose($handle);
        }, 'inventory-intelligence-reconciliation.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }

    public function bulkResolve(Request $request)
    {
        if ($response = $this->authorizeIntelligence($request)) {
            return $response;
        }

        if ($response = $this->authorizeReconciliation($request)) {
            return $response;
        }

        $validated = $request->validate([
            'action' => ['required', 'in:mark_available,send_to_review,classify_maintenance,mark_retired'],
            'asset_ids' => ['required', 'array', 'min:1'],
            'asset_ids.*' => ['integer'],
            'reason' => ['required', 'string', 'max:255'],
            'remarks' => ['required', 'string', 'max:1000'],
        ]);

        $assets = Asset::query()
            ->where('organization_id', (int) $request->user()->organization_id)
            ->whereIn('id', $validated['asset_ids'])
            ->get();

        $updated = 0;

        DB::transaction(function () use ($assets, $validated, $request, &$updated) {
            foreach ($assets as $asset) {
                $resolution = match ($validated['action']) {
                    'mark_available' => ['status' => Asset::STATUS_AVAILABLE, 'movement_type' => \App\Models\StockMovement::TYPE_CORRECTION_ADD],
                    'send_to_review' => ['status' => Asset::STATUS_AWAITING_VERIFICATION, 'movement_type' => \App\Models\StockMovement::TYPE_CORRECTION_TRANSFER],
                    'classify_maintenance' => ['status' => Asset::STATUS_MAINTENANCE, 'movement_type' => \App\Models\StockMovement::TYPE_CORRECTION_REMOVE],
                    'mark_retired' => ['status' => Asset::STATUS_RETIRED, 'movement_type' => \App\Models\StockMovement::TYPE_CORRECTION_REMOVE],
                };

                $fromStatus = (string) $asset->asset_status;
                $asset->asset_status = $resolution['status'];
                $asset->save();

                $this->stockMovementRecorder->recordForAsset($asset, $resolution['movement_type'], 1, [
                    'from_status' => $fromStatus,
                    'to_status' => $resolution['status'],
                    'from_warehouse_id' => $asset->warehouse_id,
                    'to_warehouse_id' => $asset->warehouse_id,
                    'performed_by_user_id' => $request->user()->id,
                    'notes' => sprintf(
                        'Inventory reconciliation bulk action: %s. Reason: %s. Remarks: %s.',
                        $validated['action'],
                        $validated['reason'],
                        $validated['remarks']
                    ),
                ]);

                ActivityLogger::log('inventory.reconciliation.corrected', $asset, [
                    'organization_id' => $asset->organization_id,
                    'asset_id' => $asset->id,
                    'from_status' => $fromStatus,
                    'to_status' => $resolution['status'],
                    'reason' => $validated['reason'],
                    'remarks' => $validated['remarks'],
                    'performed_by_user_id' => $request->user()->id,
                ], 'Inventory reconciliation correction applied.');

                $updated++;
            }
        });

        return redirect()
            ->route('inventory-intelligence.index', $request->query())
            ->with('success', sprintf('%d asset reconciliation correction(s) applied.', $updated));
    }

    private function filters(Request $request): array
    {
        return [
            'time_scope' => (string) $request->query('time_scope', 'monthly'),
            'mode' => (string) $request->query('mode', 'all'),
            'month' => (int) $request->query('month', now()->month),
            'year' => (int) $request->query('year', now()->year),
            'from_date' => (string) $request->query('from_date', ''),
            'to_date' => (string) $request->query('to_date', ''),
            'city' => (string) $request->query('city', ''),
            'warehouse_id' => $request->filled('warehouse_id') ? (int) $request->query('warehouse_id') : null,
            'product_id' => $request->filled('product_id') ? (int) $request->query('product_id') : null,
            'category' => (string) $request->query('category', ''),
            'drill_product_id' => $request->filled('drill_product_id') ? (int) $request->query('drill_product_id') : null,
            'drill_date' => (string) $request->query('drill_date', ''),
            'drill_bucket_start' => (string) $request->query('drill_bucket_start', ''),
            'drill_bucket_end' => (string) $request->query('drill_bucket_end', ''),
            'drill_bucket_label' => (string) $request->query('drill_bucket_label', ''),
        ];
    }
}
