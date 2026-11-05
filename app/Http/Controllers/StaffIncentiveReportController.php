<?php

namespace App\Http\Controllers;

use App\Models\{Enquiry, Invoice, User};
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class StaffIncentiveReportController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();
        abort_unless($user->canAccessGeneralReports() && $user->canViewFinance('finance.view_revenue'), 403);
        $organization = (int) $user->organization_id;
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'staff' => ['nullable', 'integer', Rule::exists('users', 'id')->where('organization_id', $organization)],
            'source' => ['nullable', Rule::in(array_keys(Enquiry::INCENTIVE_SOURCES))],
        ]);
        $from = $filters['from'] ?? today()->startOfMonth()->toDateString();
        $to = $filters['to'] ?? today()->toDateString();
        abort_if($from > $to, 422, 'The start date must be on or before the end date.');
        $staff = (int) ($filters['staff'] ?? 0);
        $source = $filters['source'] ?? '';

        $eligible = $this->invoicedOrders($organization, 'rental', $from, $to)
            ->merge($this->invoicedOrders($organization, 'sale', $from, $to))
            ->unique(fn ($row) => $row->kind.':'.$row->order_id)
            ->each(fn ($row) => $row->eligible_source = Enquiry::incentiveSource($row->source))
            ->filter(fn ($row) => $row->eligible_source && $row->eligible_amount > 0);
        $staffNames = User::where('organization_id', $organization)
            ->whereIn('id', $eligible->pluck('staff_id')->filter()->unique())
            ->orderBy('name')->pluck('name', 'id');
        $eligible = $eligible->filter(fn ($row) => (!$staff || (int) $row->staff_id === $staff)
            && (!$source || $row->eligible_source === $source))
            ->sortByDesc('invoice_date')->values();
        $staffTotals = $eligible->groupBy(fn ($row) => (int) $row->staff_id)
            ->map(fn ($rows) => [
                'sales' => $rows->where('kind', 'sale')->count(),
                'sale_revenue' => $rows->where('kind', 'sale')->sum('eligible_amount'),
                'rentals' => $rows->where('kind', 'rental')->count(),
                'rental_revenue' => $rows->where('kind', 'rental')->sum('eligible_amount'),
                'revenue' => $rows->sum('eligible_amount'),
            ])->sortByDesc('revenue');
        $sourceTotals = $eligible->groupBy('eligible_source')->map(fn ($rows) => [
            'orders' => $rows->count(), 'revenue' => $rows->sum('eligible_amount'),
        ]);
        $page = LengthAwarePaginator::resolveCurrentPage();
        $orders = new LengthAwarePaginator($eligible->forPage($page, 25), $eligible->count(), 25, $page, [
            'path' => $request->url(), 'query' => $request->query(),
        ]);
        $total = $eligible->sum('eligible_amount');

        return response()->view('reports.staff-incentives', compact(
            'from', 'to', 'staff', 'source', 'staffNames', 'staffTotals', 'sourceTotals', 'orders', 'total'
        ))->header('Cache-Control', 'private, no-store');
    }

    private function invoicedOrders(int $organization, string $kind, string $from, string $to)
    {
        $orderTable = $kind === 'rental' ? 'rentals' : 'sales';
        $orderKey = $kind.'_id';
        $types = $kind === 'rental' ? ['rental', 'rental_sale'] : ['sale'];
        $excluded = ['draft', 'cancelled', 'void'];
        $staffColumns = ['quote.converted_by_user_id'];
        foreach (['created_by_user_id', 'created_by'] as $column) {
            if (Schema::hasColumn($orderTable, $column)) {
                $staffColumns[] = 'order_record.'.$column;
            }
        }
        $staffColumns[] = 'NULL';
        $staffExpression = 'COALESCE('.implode(', ', $staffColumns).') as staff_id';

        // Select the original qualifying invoice before applying the reporting window.
        $firstInvoice = Invoice::where('organization_id', $organization)->whereNotNull($orderKey)
            ->whereNotIn('status', $excluded)->whereNotIn('payment_status', ['cancelled', 'void'])
            ->whereHas('items', fn ($query) => $query->whereIn('source_type', $types))
            ->selectRaw("MIN(id) as invoice_id, $orderKey as order_id")->groupBy($orderKey);
        if ($kind === 'rental') {
            $firstInvoice->whereNull('sale_id')->whereDoesntHave('rentalRenewal');
        }
        $lineTotals = DB::table('invoice_items')->whereIn('source_type', $types)
            ->selectRaw('invoice_id, SUM(taxable_amount) as eligible_amount')->groupBy('invoice_id');

        return DB::table('quotations as quote')->where('quote.organization_id', $organization)
            ->where('quote.status', 'converted')->where('quote.order_type', $kind)
            ->join('enquiries as enquiry', function ($join) {
                $join->on('enquiry.id', '=', 'quote.enquiry_id')->on('enquiry.organization_id', '=', 'quote.organization_id');
            })->join($orderTable.' as order_record', function ($join) {
                $join->on('order_record.id', '=', 'quote.order_id')->on('order_record.organization_id', '=', 'quote.organization_id');
            })->joinSub($firstInvoice, 'first_invoice', 'first_invoice.order_id', '=', 'quote.order_id')
            ->join('invoices as invoice', 'invoice.id', '=', 'first_invoice.invoice_id')
            ->joinSub($lineTotals, 'line_totals', 'line_totals.invoice_id', '=', 'invoice.id')
            ->where('invoice.invoice_date', '>=', $from)
            ->where('invoice.invoice_date', '<', Carbon::parse($to)->addDay()->toDateString())
            ->where(fn ($query) => $query->whereNull('order_record.customer_type')->orWhere('order_record.customer_type', 'direct_customer'))
            ->whereNull('order_record.business_partner_id')->whereNull('order_record.partner_client_id')
            ->where(fn ($query) => $query->whereNull('order_record.referral_source_type')
                ->orWhere('order_record.referral_source_type', '!=', 'business_partner'))
            ->whereNotExists(function ($query) {
                $query->selectRaw('1')->from('referral_sources as referral')
                    ->whereColumn('referral.id', 'order_record.referral_source_id')
                    ->whereColumn('referral.organization_id', 'order_record.organization_id')
                    ->where('referral.source_type', 'business_partner');
            })
            ->when($kind === 'rental', fn ($query) => $query->whereNotIn('order_record.status', $excluded))
            ->when($kind === 'sale', fn ($query) => $query->whereNotIn('order_record.payment_status', $excluded))
            ->orderBy('quote.id')
            ->get([
                'quote.order_type as kind', 'quote.order_id', 'quote.id as quotation_id',
                'invoice.id as invoice_id', 'invoice.invoice_number', 'invoice.invoice_date', 'line_totals.eligible_amount',
                DB::raw('COALESCE(quote.conversion_source, enquiry.source) as source'),
                DB::raw($staffExpression),
            ]);
    }
}
