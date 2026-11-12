<?php

namespace App\Http\Controllers;

use App\Models\{Delivery, Payment};
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class VendorCollectionReportController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user->canAccessGeneralReports() && $user->hasPermission('vendor_reports.view'), 403);
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'vendor' => ['nullable', 'regex:/^(vendor|staff):[1-9][0-9]*$/', 'max:40'],
        ]);
        $from = $filters['from'] ?? today()->startOfMonth()->toDateString();
        $to = $filters['to'] ?? today()->toDateString();
        abort_if($from > $to, 422, 'The start date must be on or before the end date.');
        $bounds = [Carbon::parse($from)->startOfDay(), Carbon::parse($to)->endOfDay()];
        $vendor = $filters['vendor'] ?? '';

        $posted = Payment::where('organization_id', $user->organization_id)
            ->where('collection_recipient', 'vendor')->whereNotNull('collection_vendor_key')
            ->where('amount', '>', 0)->whereNull('reversal_of_payment_id')->whereDoesntHave('reversal')
            ->where('payment_date', '>=', $from)->where('payment_date', '<', Carbon::parse($to)->addDay()->toDateString());
        $reported = Delivery::where('organization_id', $user->organization_id)
            ->where('collection_recipient', 'vendor')->whereNotNull('collection_vendor_key')
            ->where('status', 'completed')->where('collection_amount_collected', '>', 0)
            ->whereBetween('completed_at', $bounds);

        $options = (clone $posted)->selectRaw('collection_vendor_key, MAX(collection_vendor_name) as name')
            ->groupBy('collection_vendor_key')->pluck('name', 'collection_vendor_key')
            ->union((clone $reported)->selectRaw('collection_vendor_key, MAX(collection_vendor_name) as name')
                ->groupBy('collection_vendor_key')->pluck('name', 'collection_vendor_key'))->sort();

        if ($vendor !== '') {
            $posted->where('collection_vendor_key', $vendor);
            $reported->where('collection_vendor_key', $vendor);
        }
        $postedTotals = (clone $posted)->selectRaw('collection_vendor_key, COUNT(*) as entries, SUM(amount) as amount')
            ->groupBy('collection_vendor_key')->get()->keyBy('collection_vendor_key');
        $reportedTotals = (clone $reported)->selectRaw('collection_vendor_key, COUNT(*) as entries, SUM(collection_amount_collected) as amount')
            ->groupBy('collection_vendor_key')->get()->keyBy('collection_vendor_key');
        $rows = $options->filter(fn ($name, $key) => $vendor === '' || $key === $vendor);
        $receipts = $posted->latest('payment_date')->latest('id')->paginate(25, ['*'], 'receipts')->withQueryString();
        $reports = $reported->latest('completed_at')->latest('id')->paginate(25, ['*'], 'reports')->withQueryString();

        return response()->view('reports.vendor-collections', compact(
            'from', 'to', 'vendor', 'options', 'rows', 'postedTotals', 'reportedTotals', 'receipts', 'reports'
        ))->header('Cache-Control', 'private, no-store');
    }
}
