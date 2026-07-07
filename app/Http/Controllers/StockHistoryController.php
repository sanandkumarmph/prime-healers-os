<?php

namespace App\Http\Controllers;

use App\Models\Asset;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
class StockHistoryController extends Controller
{
    private function deny(Request $request, string $message)
    {
        if ($request->expectsJson()) {
            abort(403, $message);
        }

        return redirect()
            ->to($request->user()?->defaultRedirectPath() ?: route('login'))
            ->with('error', $message);
    }

    private function authorizeHistory(Request $request, bool $export = false)
    {
        $user = $request->user();

        if (!$user) {
            return $this->deny($request, 'Unauthorized.');
        }

        $hasGeneral = $user->hasPermission('stock_history.view');
        $hasProduct = $hasGeneral || $user->hasPermission('stock_history.product');
        $hasAsset = $hasGeneral || $user->hasPermission('stock_history.asset');

        if ($export && !$user->hasPermission('stock_history.export')) {
            return $this->deny($request, 'You are not authorized to export stock history.');
        }

        $hasProductFilter = filled($request->query('product_id'));
        $hasAssetFilter = filled($request->query('asset_id'));

        if (!$hasProductFilter && !$hasAssetFilter && !$hasGeneral) {
            return $this->deny($request, 'You are not authorized to access stock history.');
        }

        if ($hasProductFilter && !$hasProduct) {
            return $this->deny($request, 'You are not authorized to access product stock history.');
        }

        if ($hasAssetFilter && !$hasAsset) {
            return $this->deny($request, 'You are not authorized to access asset stock history.');
        }

        return null;
    }

    private function baseQuery(Request $request)
    {
        $organizationId = (int) $request->user()->organization_id;
        $query = StockMovement::query()
            ->with([
                'product:id,name,brand,model_name,product_image_path',
                'asset:id,product_id,serial_number,barcode_value,warehouse_id',
                'fromWarehouse:id,name',
                'toWarehouse:id,name',
                'performedBy:id,name',
                'rental:id',
                'sale:id',
                'delivery:id,type',
                'invoice:id,invoice_number',
                'payment:id,amount,payment_date',
            ])
            ->forOrganization($organizationId);

        if ($request->filled('product_id')) {
            $query->where('product_id', (int) $request->query('product_id'));
        }

        if ($request->filled('asset_id')) {
            $query->where('asset_id', (int) $request->query('asset_id'));
        }

        if ($request->filled('warehouse_id')) {
            $warehouseId = (int) $request->query('warehouse_id');
            $query->where(function ($innerQuery) use ($warehouseId) {
                $innerQuery
                    ->where('from_warehouse_id', $warehouseId)
                    ->orWhere('to_warehouse_id', $warehouseId);
            });
        }

        if ($request->filled('movement_type')) {
            $query->where('movement_type', (string) $request->query('movement_type'));
        }

        $fromDate = trim((string) $request->query('from_date', ''));
        if ($fromDate !== '') {
            $query->whereDate('movement_at', '>=', Carbon::parse($fromDate)->toDateString());
        }

        $toDate = trim((string) $request->query('to_date', ''));
        if ($toDate !== '') {
            $query->whereDate('movement_at', '<=', Carbon::parse($toDate)->toDateString());
        }

        return $query->orderByDesc('movement_at')->orderByDesc('id');
    }

    public function index(Request $request)
    {
        if ($response = $this->authorizeHistory($request)) {
            return $response;
        }

        $organizationId = (int) $request->user()->organization_id;
        $movements = $this->baseQuery($request)->paginate(25)->withQueryString();
        $products = Product::query()
            ->where('organization_id', $organizationId)
            ->orderBy('name')
            ->get(['id', 'name', 'brand', 'model_name', 'product_image_path']);
        $warehouses = Warehouse::query()
            ->where('organization_id', $organizationId)
            ->orderBy('name')
            ->get(['id', 'name']);
        $assets = Asset::query()
            ->where('organization_id', $organizationId)
            ->orderBy('serial_number')
            ->limit(200)
            ->get(['id', 'serial_number', 'barcode_value', 'product_id']);

        return view('stock-history.index', [
            'movements' => $movements,
            'products' => $products,
            'warehouses' => $warehouses,
            'assets' => $assets,
            'movementTypes' => StockMovement::MOVEMENT_TYPES,
            'canExportStockHistory' => $request->user()->hasPermission('stock_history.export'),
        ]);
    }

    public function exportCsv(Request $request)
    {
        if ($response = $this->authorizeHistory($request, true)) {
            return $response;
        }

        $movements = $this->baseQuery($request)->get();

        return response()->streamDownload(function () use ($movements) {
            $output = fopen('php://output', 'w');
            fputcsv($output, [
                'Movement At',
                'Type',
                'Product',
                'Asset Serial',
                'Quantity',
                'From Status',
                'To Status',
                'From Warehouse',
                'To Warehouse',
                'Rental',
                'Sale',
                'Delivery',
                'Invoice',
                'Payment',
                'Performed By',
                'Notes',
            ]);

            foreach ($movements as $movement) {
                fputcsv($output, [
                    optional($movement->movement_at)->format('Y-m-d H:i:s'),
                    $movement->movement_type,
                    trim(collect([
                        $movement->product?->name,
                        $movement->product?->brand,
                        $movement->product?->model_name,
                    ])->filter()->implode(' - ')),
                    $movement->asset?->serial_number,
                    $movement->quantity,
                    $movement->from_status,
                    $movement->to_status,
                    $movement->fromWarehouse?->name,
                    $movement->toWarehouse?->name,
                    $movement->rental_id,
                    $movement->sale_id,
                    $movement->delivery_id,
                    $movement->invoice?->invoice_number,
                    $movement->payment_id,
                    $movement->performedBy?->name,
                    $movement->notes,
                ]);
            }

            fclose($output);
        }, 'stock-history.csv', [
            'Content-Type' => 'text/csv',
        ]);
    }
}
