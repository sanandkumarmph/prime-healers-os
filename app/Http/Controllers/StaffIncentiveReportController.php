<?php

namespace App\Http\Controllers;

use App\Models\{Enquiry, Invoice, OrderIncentiveAttribution, User};
use App\Services\Sales\{OrderIncentiveCredit, OrderStaffFilter};
use App\Support\ActivityLogger;
use App\Support\XlsxWorkbook;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

class StaffIncentiveReportController extends Controller
{
    private function authorizeReport(User $user): void
    {
        abort_unless($user->canViewStaffIncentives(), 403);
    }

    private function canAssign(User $user): bool
    {
        return $user->canViewFinance('finance.view_revenue');
    }

    private function users(int $organization): Collection
    {
        return User::where('organization_id', $organization)->with('assignedRole')->orderBy('name')->get()->keyBy('id');
    }

    private function classify($row, Collection $users): void
    {
        $row->eligible_source = Enquiry::incentiveSource($row->source);
        $row->known_source = $row->eligible_source ?? (isset(Enquiry::SOURCES[$row->source]) ? $row->source : null);
        $row->staff_id = $row->attribution_id && $users->has($row->recorded_staff_id) ? (int) $row->recorded_staff_id : null;
        $row->booked_by_name = $users->get($row->creator_id)?->name;

        $row->suggested_staff_id = collect([$row->staff_id, $row->enquiry_owner_id, $row->converted_by_user_id, $row->creator_id])
            ->first(fn ($id) => in_array($users->get($id)?->effective_role, User::SALES_ROLE_SLUGS, true));
        if (!$row->attribution_id && $row->quotation_id) {
            $row->staff_id = $row->suggested_staff_id;
        }
    }

    private function needsReview($row): bool
    {
        return !$row->excluded_from_incentives && (!$row->known_source || ($row->eligible_source && !$row->staff_id));
    }

    public function index(Request $request)
    {
        $user = $request->user();
        $this->authorizeReport($user);
        $organization = (int) $user->organization_id;
        $filters = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'staff' => ['nullable', 'integer', Rule::exists('users', 'id')->where('organization_id', $organization)],
            'source' => ['nullable', Rule::in(array_keys(Enquiry::INCENTIVE_SOURCES))],
            'credit_kind' => ['nullable', Rule::in(['sale', 'rental'])],
            'download' => ['nullable', Rule::in(['xlsx'])],
        ]);
        if (($filters['download'] ?? null) === 'xlsx') {
            Gate::authorize('manage-bulk-records');
        }
        $from = $filters['from'] ?? today()->subYearNoOverflow()->addDay()->toDateString();
        $to = $filters['to'] ?? today()->toDateString();
        abort_if($from > $to, 422, 'The start date must be on or before the end date.');
        $staff = (int) ($filters['staff'] ?? 0);
        $source = $filters['source'] ?? '';
        $creditKind = $filters['credit_kind'] ?? '';

        $users = $this->users($organization);
        $salesStaff = $users->filter(fn (User $member) => $member->isSales())->pluck('name', 'id');
        $canAssign = $this->canAssign($user);
        $candidates = $this->invoicedOrders($organization, 'rental', $from, $to)
            ->merge($this->invoicedOrders($organization, 'sale', $from, $to))
            ->filter(fn ($row) => $row->eligible_amount > 0)
            ->each(fn ($row) => $this->classify($row, $users))
            ->sortByDesc('invoice_date')->values();
        $review = $candidates->filter(fn ($row) => $this->needsReview($row))->values();
        $filteredReview = $staff ? $review->filter(fn ($row) => (int) $row->suggested_staff_id === $staff) : $review;
        $reviewPage = LengthAwarePaginator::resolveCurrentPage('review_page');
        $reviewOrders = new LengthAwarePaginator($filteredReview->forPage($reviewPage, 15), $filteredReview->count(), 15, $reviewPage, [
            'path' => $request->url(), 'query' => $request->query(), 'pageName' => 'review_page', 'fragment' => 'attribution',
        ]);
        $eligible = $candidates->filter(fn ($row) => !$row->excluded_from_incentives && $row->eligible_source && $row->staff_id)
            ->flatMap(fn ($row) => $this->credits($row, $users));
        $staffNames = $salesStaff->union($users->only($eligible->pluck('staff_id')->all())->pluck('name', 'id'))->sort();
        $eligible = $eligible->filter(fn ($row) => (!$staff || (int) $row->staff_id === $staff)
            && (!$source || $row->eligible_source === $source))
            ->sortByDesc('invoice_date')->values();
        $salesRoster = $users->filter(fn (User $member) => in_array($member->effective_role, User::SALES_ROLE_SLUGS, true));
        $bookedSales = $this->bookingCounts($organization, 'sale', $from, $to, $salesRoster->keys()->all());
        $bookedRentals = $this->bookingCounts($organization, 'rental', $from, $to, $salesRoster->keys()->all());
        $pendingByStaff = $review->pluck('suggested_staff_id')->filter()->countBy();
        $staffNames = $staffNames->union($users->only($bookedSales->keys()->merge($bookedRentals->keys())->all())->pluck('name', 'id'))->sort();
        $staffTotals = $eligible->groupBy(fn ($row) => (int) $row->staff_id)
            ->map(fn ($rows) => [
                'sales_credited' => $rows->where('kind', 'sale')->count(),
                'rentals_credited' => $rows->where('kind', 'rental')->count(),
                'sale_revenue' => $rows->where('kind', 'sale')->sum('credit_paise') / 100,
                'rental_revenue' => $rows->where('kind', 'rental')->sum('credit_paise') / 100,
                'revenue' => $rows->sum('credit_paise') / 100,
            ])->sortByDesc('revenue');
        foreach ($staffNames as $id => $name) {
            if ((!$staff || (int) $id === $staff) && !$staffTotals->has($id)) {
                $staffTotals->put($id, ['sales_credited' => 0, 'rentals_credited' => 0, 'sale_revenue' => 0, 'rental_revenue' => 0, 'revenue' => 0]);
            }
        }
        $sourceTotals = $eligible->groupBy('eligible_source')->map(fn ($rows) => [
            'orders' => $rows->unique(fn ($row) => $row->kind.':'.$row->order_id)->count(),
            'revenue' => $rows->sum('credit_paise') / 100,
        ]);
        $page = LengthAwarePaginator::resolveCurrentPage();
        $orderDetails = $creditKind ? $eligible->where('kind', $creditKind)->values() : $eligible;
        if (($filters['download'] ?? null) === 'xlsx') {
            $summary = $staffTotals->map(fn ($row, $id) => [
                $staffNames[$id],
                (int) $bookedSales->get($id, 0),
                (int) $bookedRentals->get($id, 0),
                (int) $pendingByStaff->get($id, 0),
                $row['sales_credited'],
                $row['rentals_credited'],
                $row['sale_revenue'],
                $row['rental_revenue'],
                $row['revenue'],
            ])->values();
            $summary->push(['Total eligible credit', '', '', '', '', '', '', '', $eligible->sum('credit_paise') / 100]);
            $detail = $orderDetails->map(fn ($row) => [
                $row->invoice_date,
                $staffNames[$row->staff_id],
                Enquiry::INCENTIVE_SOURCES[$row->eligible_source],
                ucfirst($row->kind).' #'.$row->order_id,
                $row->invoice_number,
                $row->credit_label,
                $row->credit_paise / 100,
            ]);
            $content = app(XlsxWorkbook::class)->build([
                'Staff summary' => $summary->prepend([
                    'Staff', 'Sales booked', 'Rentals booked', 'Orders in review', 'Sales credited',
                    'Rentals credited', 'Sale credit (INR)', 'Rental credit (INR)', 'Eligible total (INR)',
                ])->all(),
                'Order credits' => $detail->prepend([
                    'Invoice date', 'Staff', 'Source', 'Order', 'Invoice', 'Share', 'Revenue credit (INR)',
                ])->all(),
                'Report filters' => [
                    ['Filter', 'Value'],
                    ['From', $from],
                    ['Through', $to],
                    ['Staff', $staff ? $users->get($staff)->name : 'All staff'],
                    ['Credit source', Enquiry::INCENTIVE_SOURCES[$source] ?? 'All eligible sources'],
                    ['Order credit details', $creditKind ? ucfirst($creditKind) : 'Sales and rentals'],
                ],
            ], 'PHOS Staff Incentives', numericCells: true);

            return response($content, 200, [
                'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'Content-Disposition' => 'attachment; filename="staff-incentives-'.$from.'-to-'.$to.'.xlsx"',
                'Cache-Control' => 'private, no-store',
            ]);
        }
        $orders = new LengthAwarePaginator($orderDetails->forPage($page, 25), $orderDetails->count(), 25, $page, [
            'path' => $request->url(), 'query' => $request->query(), 'fragment' => 'order-credits',
        ]);
        $total = $eligible->sum('credit_paise') / 100;

        return response()->view('reports.staff-incentives', compact(
            'from', 'to', 'staff', 'source', 'staffNames', 'staffTotals', 'sourceTotals', 'orders', 'total',
            'reviewOrders', 'salesStaff', 'canAssign', 'bookedSales', 'bookedRentals', 'pendingByStaff', 'creditKind'
        ))->header('Cache-Control', 'private, no-store');
    }

    public function assign(Request $request)
    {
        $user = $request->user();
        $this->authorizeReport($user);
        abort_unless($this->canAssign($user), 403);
        $organization = (int) $user->organization_id;
        $users = $this->users($organization);
        $source = $request->input('source');
        $data = $request->validate([
            'kind' => ['required', Rule::in(['rental', 'sale'])],
            'order' => ['required', 'integer', 'min:1'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'source' => ['required', Rule::in(array_keys(Enquiry::SOURCES))],
            'staff' => ['nullable', 'integer', Rule::requiredIf(is_string($source) && isset(Enquiry::INCENTIVE_SOURCES[$source])),
                Rule::in($users->filter(fn (User $member) => $member->isSales())->keys()->all())],
        ]);
        $salesUser = isset(Enquiry::INCENTIVE_SOURCES[$data['source']]) ? (int) $data['staff'] : null;
        DB::transaction(function () use ($organization, $data, $users, $salesUser, $user) {
            $table = $data['kind'] === 'rental' ? 'rentals' : 'sales';
            abort_unless(DB::table($table)->where('organization_id', $organization)->where('id', $data['order'])
                ->lockForUpdate()->first(['id']), 404);
            $candidate = $this->invoicedOrders($organization, $data['kind'], $data['from'], $data['to'], (int) $data['order'])->first();
            abort_unless($candidate && $candidate->eligible_amount > 0, 404);
            $this->classify($candidate, $users);
            abort_unless($this->needsReview($candidate), 409, 'This order already has a source or an incentive assignment. Reload the report.');
            abort_if($candidate->known_source && $candidate->known_source !== $data['source'], 422, 'Use the source already recorded for this order.');

            OrderIncentiveAttribution::record($organization, $data['kind'], (int) $data['order'], $data['source'],
                $salesUser, $user->id, $candidate->closer_id === null ? null : (int) $candidate->closer_id);
            ActivityLogger::log('incentives.attribution_recorded', null, [
                'order_type' => $data['kind'], 'order_id' => (int) $data['order'],
                'source' => $data['source'], 'sales_user_id' => $salesUser,
            ], 'Sales incentive attribution recorded.');
        });

        return redirect()->route('reports.staff-incentives', ['from' => $data['from'], 'to' => $data['to']])
            ->with('success', $salesUser ? 'Incentive attribution saved.' : 'Source recorded. This order is outside the incentive rules.');
    }

    private function credits($row, Collection $users): array
    {
        $paise = (int) round((float) $row->eligible_amount * 100);
        $owner = clone $row;
        $owner->credit_paise = $paise;
        $owner->credit_label = 'Owner · 100%';
        $closer = (int) $row->closer_id;
        if ($closer === (int) $row->staff_id || !$users->has($closer)) {
            return [$owner];
        }

        $owner->credit_paise = intdiv($paise * OrderIncentiveCredit::OWNER_PERCENT + 99, 100);
        $owner->credit_label = 'Owner · '.OrderIncentiveCredit::OWNER_PERCENT.'%';
        $handoff = clone $row;
        $handoff->staff_id = $closer;
        $handoff->credit_paise = $paise - $owner->credit_paise;
        $handoff->credit_label = 'Closer · '.(100 - OrderIncentiveCredit::OWNER_PERCENT).'%';

        return [$owner, $handoff];
    }

    private function bookingCounts(int $organization, string $kind, string $from, string $to, array $staff): Collection
    {
        [$orders, $creator] = app(OrderStaffFilter::class)->bookedOrders($organization, $kind, $from, $to);
        $orders->selectRaw($creator.' as creator_id');

        return DB::query()->fromSub($orders, 'bookings')->whereIn('creator_id', $staff)
            ->selectRaw('creator_id, COUNT(*) as booked')->groupBy('creator_id')->pluck('booked', 'creator_id');
    }

    private function invoicedOrders(int $organization, string $kind, string $from, string $to, ?int $orderId = null)
    {
        $staffFilter = app(OrderStaffFilter::class);
        [$orders, $creator] = $staffFilter->bookedOrders($organization, $kind, '', '');
        $orderKey = $kind.'_id';
        $types = $kind === 'rental' ? ['rental', 'rental_sale'] : ['sale'];
        $excluded = ['draft', 'cancelled', 'void'];

        // Select the original qualifying invoice before applying the reporting window.
        $invoices = Invoice::where('invoices.organization_id', $organization)
            ->whereNotIn('status', $excluded)->whereNotIn('payment_status', ['cancelled', 'void'])
            ->whereHas('items', fn ($query) => $query->whereIn('source_type', $types));
        if ($kind === 'rental') {
            $invoices->whereNull('sale_id')->whereDoesntHave('rentalRenewal');
        }
        $linked = (clone $invoices)->whereNotNull($orderKey)->selectRaw("invoices.id as invoice_id, $orderKey as order_id")->toBase();
        if ($kind === 'sale') {
            $legacySales = DB::table('invoice_items')->where('source_type', 'sale')->whereNotNull('source_id')
                ->selectRaw('invoice_id, MIN(source_id) as order_id')->groupBy('invoice_id')->havingRaw('COUNT(DISTINCT source_id) = 1');
            $linked->unionAll((clone $invoices)->whereNull('sale_id')->whereNull('rental_id')
                ->joinSub($legacySales, 'legacy_sale', 'legacy_sale.invoice_id', '=', 'invoices.id')
                ->selectRaw('invoices.id as invoice_id, legacy_sale.order_id')->toBase());
        }
        $firstInvoice = DB::query()->fromSub($linked, 'linked')->selectRaw('MIN(invoice_id) as invoice_id, order_id')->groupBy('order_id');
        $lineTotals = DB::table('invoice_items')->whereIn('source_type', $types)
            ->selectRaw('invoice_id, SUM(taxable_amount) as eligible_amount')->groupBy('invoice_id');
        $convertedQuotes = DB::table('quotations')->where('organization_id', $organization)
            ->where('status', 'converted')->where('order_type', $kind)
            ->selectRaw('MIN(id) as quote_id, order_id')->groupBy('order_id');

        return $orders
            ->when($orderId, fn ($query) => $query->where('order_record.id', $orderId))
            ->leftJoinSub($convertedQuotes, 'conversion', 'conversion.order_id', '=', 'order_record.id')
            ->leftJoin('quotations as quote', 'quote.id', '=', 'conversion.quote_id')
            ->leftJoin('enquiries as enquiry', function ($join) {
                $join->on('enquiry.id', '=', 'quote.enquiry_id')->on('enquiry.organization_id', '=', 'quote.organization_id');
            })->leftJoin('order_incentive_attributions as attribution', function ($join) use ($kind) {
                $join->on('attribution.order_id', '=', 'order_record.id')
                    ->on('attribution.organization_id', '=', 'order_record.organization_id')->where('attribution.order_type', $kind);
            })->joinSub($firstInvoice, 'first_invoice', 'first_invoice.order_id', '=', 'order_record.id')
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
            ->orderBy('order_record.id')
            ->select([
                'order_record.id as order_id', 'quote.id as quotation_id', 'enquiry.owner_id as enquiry_owner_id',
                'quote.converted_by_user_id', 'attribution.id as attribution_id', 'attribution.sales_user_id as recorded_staff_id',
                'attribution.closer_user_id',
                'attribution.excluded_from_incentives',
                'invoice.id as invoice_id', 'invoice.invoice_number', 'invoice.invoice_date', 'line_totals.eligible_amount',
                DB::raw('COALESCE(attribution.source, quote.conversion_source, enquiry.source) as source'),
                DB::raw($creator.' as creator_id'),
            ])->selectSub($staffFilter->bookingSalesperson($organization, $creator), 'booking_salesperson_id')
            ->get()->each(function ($row) use ($kind) {
                $row->kind = $kind;
                $row->closer_id = $row->closer_user_id ?? $row->booking_salesperson_id;
            });
    }
}
