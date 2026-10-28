<?php

namespace App\Services\Sales;

use App\Models\{Quotation, Rental, Sale};
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class QuotationConversion
{
    public function __construct(private SalesWorkspace $workspace) {}

    public function prepare(Request $request, string $kind): void
    {
        $id = $request->input('quotation_id', $request->old('quotation_id'));
        if (!$id) return;
        $quote = $this->workspace->quote((int) $id, 'update');
        $this->eligible($quote, $kind);
        if ((int) $request->old('quotation_id') !== $quote->id) $request->session()->flashInput($this->prefill($quote));
        $request->attributes->set('conversion_quote', $quote);
    }

    public function prefill(Quotation $quote): array
    {
        $s = $quote->snapshot;
        $data = ['quotation_id' => $quote->id, 'quotation_version' => $quote->lock_version, 'customer_type' => 'direct_customer',
            'customer_id' => $s['customer_id'], 'customer_name' => $s['recipient']['name'],
            'phone' => $s['recipient']['phone'], 'address' => $s['recipient']['address'],
            'tax_type' => $s['tax_type'], 'fulfilment_source' => 'in_house'];
        if ($quote->kind === 'sale') {
            return $data + ['sale_date' => now($s['timezone'])->toDateString(), 'shipping_charges' => $s['transport'], 'payment_status' => 'pending',
                'sale_items' => array_map(fn ($l) => ['product_id' => $l['product_id'], 'quantity' => $l['quantity'], 'unit_price' => $l['rate'],
                    'discount_amount' => 0, 'tax_percentage' => $l['tax_rate'], 'tax_calculation_mode' => $l['tax_mode'], 'tax_type' => $s['tax_type']], $s['lines'])];
        }
        $days = (int) Carbon::parse($s['start_date'])->diffInDays(Carbon::parse($s['end_date'])) + 1;
        $primary = $s['lines'][0];
        return $data + ['product_id' => $primary['product_id'], 'quantity' => $primary['quantity'],
            'start_date' => $s['start_date'], 'end_date' => $s['end_date'], 'duration_days' => $days,
            'duration_unit' => 'days', 'duration_preset' => 'custom', 'deposit_amount' => $s['deposit'],
            'rental_amount' => number_format(round($primary['quantity'] * $primary['rate'], 2), 2, '.', ''),
            'gst_rate' => $primary['tax_rate'], 'gst_mode' => $primary['tax_mode'],
            'transport_amount' => $s['transport'], 'other_amount' => 0,
            'rental_items' => array_map(fn ($l) => ['product_id' => $l['product_id'], 'quantity' => $l['quantity'],
                'unit_rental_amount' => number_format($l['rate'], 2, '.', ''), 'gst_rate' => $l['tax_rate'],
                'gst_mode' => $l['tax_mode'], 'tax_type' => $s['tax_type'], 'start_date' => $s['start_date'],
                'end_date' => $s['end_date'], 'duration_days' => $days, 'duration_unit' => 'days'], array_slice($s['lines'], 1))];
    }

    private function eligible(Quotation $quote, string $kind): void
    {
        $this->workspace->current($quote);
        $this->workspace->assertRegion($quote);
        if ($quote->kind !== $kind || $quote->status !== 'accepted' || $quote->expired() || $quote->enquiry->closed()) {
            throw ValidationException::withMessages(['quotation_id' => 'Only the current, accepted and unexpired quotation can become an order.']);
        }
    }

    public function run(Request $request, string $kind, callable $create): mixed
    {
        if (!$request->filled('quotation_id')) return $create();
        $request->validate(['quotation_id' => 'required|integer|min:1', 'quotation_version' => 'required|integer|min:1']);
        $quote = $this->workspace->quote($request->integer('quotation_id'), 'update');
        abort_unless($request->user()->canAccessModule($kind.'s', 'create'), 403);
        return $this->workspace->atomic($quote->organization_id, function () use ($quote, $request, $kind, $create) {
            $this->workspace->locked($quote->enquiry); $quote = $quote->fresh();
            if ($quote->status === 'converted' && $quote->order_type === $kind) {
                $class = $kind === 'rental' ? Rental::class : Sale::class;
                $order = $class::where('organization_id', $quote->organization_id)->findOrFail($quote->order_id);
                Gate::authorize('view', $order);
                return redirect()->route($kind.'s.show', $order)->with('success', 'This quotation already has an order.');
            }
            $this->eligible($quote, $kind);
            if ((int) $quote->lock_version !== $request->integer('quotation_version')) $this->mismatch();
            $s = $quote->snapshot;
            if ($request->input('customer_type', 'direct_customer') !== 'direct_customer'
                || (int) $request->input('customer_id') !== (int) $s['customer_id']
                || $request->filled('rental_id') || $request->filled('business_partner_id') || $request->filled('partner_client_id')) $this->mismatch();
            if ($kind === 'rental') {
                if ((float) $request->input('deposit_amount', 0) !== (float) $s['deposit']
                    || (float) $request->input('transport_amount', 0) !== (float) $s['transport']
                    || (float) $request->input('other_amount', 0) !== 0.0 || $request->input('sale_items', [])) $this->mismatch();
            } elseif ((float) $request->input('shipping_charges', 0) !== (float) $s['transport'] || $request->input('payment_status') !== 'pending') $this->mismatch();
            $request->attributes->set('conversion_quote', $quote);
            try {
                $response = $create();
                if (!$request->attributes->get('conversion_finished')) throw new \LogicException('Quotation conversion did not attach an order.');
                return $response;
            } finally {
                $request->attributes->remove('conversion_quote');
                $request->attributes->remove('conversion_lines_checked');
                $request->attributes->remove('conversion_finished');
            }
        });
    }

    public function assertLines(Request $request, string $kind, array $lines): void
    {
        $quote = $request->attributes->get('conversion_quote');
        if (!$quote) return;
        $s = $quote->snapshot;
        if (count($lines) !== count($s['lines'])) $this->mismatch();
        foreach (array_values($lines) as $i => $line) {
            $expected = $s['lines'][$i];
            $actual = ['product_id' => (int) $line['product_id'], 'quantity' => (int) $line['quantity'],
                'rate' => (float) $line[$kind === 'rental' ? 'unit_rental_amount' : 'unit_price'],
                'tax_rate' => (float) $line[$kind === 'rental' ? 'gst_rate' : 'tax_percentage'],
                'tax_mode' => $line[$kind === 'rental' ? 'gst_mode' : 'tax_calculation_mode']];
            foreach ($actual as $key => $value) if ($value != $expected[$key]) $this->mismatch();
            if ($line['tax_type'] !== $s['tax_type'] || (float) ($line['discount_amount'] ?? 0) !== 0.0) $this->mismatch();
            if ($kind === 'rental' && ($line['start_date'] !== $s['start_date'] || $line['end_date'] !== $s['end_date']
                || (int) $line['duration_days'] !== (int) Carbon::parse($s['start_date'])->diffInDays(Carbon::parse($s['end_date'])) + 1)) $this->mismatch();
        }
        $request->attributes->set('conversion_lines_checked', true);
    }

    public function finish(Request $request, Rental|Sale $order): void
    {
        $quote = $request->attributes->get('conversion_quote');
        if (!$quote) return;
        if (!$request->attributes->get('conversion_lines_checked')) throw new \LogicException('Quotation lines were not checked.');
        $quote->update(['status' => 'converted', 'order_type' => $quote->kind, 'order_id' => $order->id, 'lock_version' => $quote->lock_version + 1]);
        $e = $quote->enquiry;
        $e->update(['stage' => 'won', 'lock_version' => $e->lock_version + 1]);
        $e->followUps()->whereNull('completed_at')->update(['completed_at' => now(), 'outcome' => 'cancelled', 'completion_note' => 'Order created from accepted quotation.']);
        $this->workspace->event($e, 'quotation.converted', $quote->number().' converted to '.$quote->kind.' #'.$order->id.'.');
        $request->attributes->set('conversion_finished', true);
    }

    public function terms(Rental|Sale $order, ?string $fallback): ?string
    {
        $quote = request()->attributes->get('conversion_quote');
        if (!$quote) $quote = Quotation::where('organization_id', $order->organization_id)
            ->where('order_type', $order instanceof Rental ? 'rental' : 'sale')->where('order_id', $order->id)->first();
        if (!$quote) return $fallback;
        return $quote->snapshot['terms'] ?: 'No additional terms specified in quotation '.$quote->number().'.';
    }

    private function mismatch(): never
    {
        throw ValidationException::withMessages(['quotation_id' => 'The order must match the accepted quotation. Create and accept a new revision to change the customer, products, dates, prices or tax.']);
    }
}
