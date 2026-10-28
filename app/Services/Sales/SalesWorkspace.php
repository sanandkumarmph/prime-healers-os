<?php

namespace App\Services\Sales;

use App\Models\{Customer, Enquiry, EnquiryActivity, Organization, Product, Quotation, User};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\{DB, URL};
use Illuminate\Validation\{Rule, ValidationException};

class SalesWorkspace
{
    public function query(User $user): Builder
    {
        abort_unless($user->is_active && $user->organization_id && $user->organization()->where('is_active', true)->exists()
            && $user->canAccessModule('enquiries', 'read'), 403);
        return Enquiry::where('organization_id', $user->organization_id)
            ->when($user->scopeType('enquiries') !== 'all', fn ($q) => $q->where('owner_id', $user->id));
    }

    public function enquiry(int $id, string $action = 'read'): Enquiry
    {
        abort_unless(auth()->user()->canAccessModule('enquiries', $action), 403);
        return $this->query(auth()->user())->findOrFail($id);
    }

    public function quote(int $id, string $action = 'read'): Quotation
    {
        abort_unless(auth()->user()->canAccessModule('quotations', $action), 403);
        $quote = Quotation::where('organization_id', auth()->user()->organization_id)->findOrFail($id);
        $enquiry = $this->enquiry($quote->enquiry_id, $action === 'read' ? 'read' : 'update');
        abort_if(auth()->user()->scopeType('quotations') !== 'all' && (int) $enquiry->owner_id !== (int) auth()->id(), 404);
        return $quote;
    }

    public function owners(): \Illuminate\Support\Collection
    {
        return User::where('organization_id', auth()->user()->organization_id)->where('is_active', true)
            ->with('assignedRole')->orderBy('name')->get()
            ->filter(fn ($u) => $u->canAccessModule('enquiries', 'read') && $u->canAccessModule('enquiries', 'update'));
    }

    public function timezone(): string
    {
        return config('app.timezone', 'Asia/Kolkata');
    }

    /** Only contact fields already available to enquiry editors; never expose private customer documents. */
    public function customers(): \Illuminate\Support\Collection
    {
        return Customer::where('organization_id', auth()->user()->organization_id)
            ->orderBy('name')->get(['id', 'name', 'phone', 'email']);
    }

    /** Called inside the organisation/enquiry transaction; draft validation rolls this back on failure. */
    public function prepareDraft(Request $request, Enquiry $enquiry, ?Quotation $quote = null): void
    {
        $request->validate(['next' => 'nullable|in:save,review', 'pricing_kind' => 'nullable|in:rental,sale', 'kind_confirmed' => 'nullable|boolean']);
        $previousKind = $quote?->kind ?? $enquiry->intent;
        if (($previousKind && $previousKind !== $request->input('kind') && !$request->boolean('kind_confirmed'))
            || ($request->filled('pricing_kind') && $request->input('pricing_kind') !== $request->input('kind'))) {
            throw ValidationException::withMessages(['kind' => 'The order type changed. Review the prices, then choose product prices or keep your entered prices.']);
        }
        if ($request->exists('customer_id')) {
            $data = $request->validate([
                'customer_id' => ['nullable', 'integer', Rule::exists('customers', 'id')->where('organization_id', $enquiry->organization_id)],
                'enquiry_lock_version' => 'required|integer|min:1',
            ]);
            if ((int) $enquiry->lock_version !== (int) $data['enquiry_lock_version']) {
                throw ValidationException::withMessages(['workspace' => 'The enquiry changed in another window. Refresh and review the customer before saving.']);
            }
            $customerId = empty($data['customer_id']) ? null : (int) $data['customer_id'];
            if ((int) $enquiry->customer_id !== (int) $customerId) {
                $enquiry->update(['customer_id' => $customerId, 'lock_version' => $enquiry->lock_version + 1]);
                $enquiry->unsetRelation('customer');
                $this->event($enquiry, 'enquiry.customer', 'Quotation recipient selected. Finalised quotation revisions remain unchanged.');
            }
        }
        if ($request->input('next') === 'review' && !$enquiry->customer_id) {
            throw ValidationException::withMessages(['customer_id' => 'Select a customer or add one here to review and share. You can still save a draft.']);
        }
    }

    /** Organisation first, then enquiry, then quotation: the same boundary as financial order writes. */
    public function atomic(int $org, callable $callback): mixed
    {
        return DB::transaction(function () use ($org, $callback) {
            $organization = Organization::whereKey($org)->lockForUpdate()->firstOrFail();
            abort_unless($organization->is_active, 403, 'This organisation is inactive.');
            return $callback();
        });
    }

    public function locked(Enquiry $enquiry, bool $checkAccess = true): Enquiry
    {
        $locked = Enquiry::where('organization_id', $enquiry->organization_id)->lockForUpdate()->findOrFail($enquiry->id);
        if ($checkAccess) $this->enquiry($locked->id, 'update');
        return $locked;
    }

    public function version(object $record, Request $request): void
    {
        $request->validate(['lock_version' => 'required|integer|min:1']);
        if ((int) $record->lock_version !== $request->integer('lock_version')) {
            throw ValidationException::withMessages(['workspace' => 'This record changed in another window. Refresh before saving.']);
        }
    }

    public function event(Enquiry $enquiry, string $type, string $body, bool $public = false): void
    {
        EnquiryActivity::create(['enquiry_id' => $enquiry->id, 'user_id' => $public ? null : auth()->id(),
            'type' => $type, 'body' => $body, 'created_at' => now()]);
        $enquiry->touch();
    }

    public function snapshot(Request $request, Enquiry $enquiry): array
    {
        $data = $request->validate([
            'kind' => 'required|in:rental,sale', 'valid_until' => 'required|date_format:Y-m-d|after_or_equal:today',
            'start_date' => 'required_if:kind,rental|nullable|date_format:Y-m-d',
            'end_date' => 'required_if:kind,rental|nullable|date_format:Y-m-d|after_or_equal:start_date',
            'tax_type' => 'required|in:cgst_sgst,igst', 'terms' => 'nullable|string|max:12000',
            'transport' => 'required|numeric|min:0|max:99999999.99|decimal:0,2',
            'deposit' => 'required|numeric|min:0|max:99999999.99|decimal:0,2',
            'lines' => 'required|array|min:1|max:30',
            'lines.*.product_id' => ['required', 'integer', Rule::exists('products', 'id')->where('organization_id', $enquiry->organization_id)],
            'lines.*.quantity' => 'required|integer|min:1|max:10000',
            'lines.*.rate' => 'required|numeric|min:0|max:9999999.99|decimal:0,2',
            'lines.*.tax_rate' => 'required|numeric|min:0|max:100|decimal:0,2',
            'lines.*.tax_mode' => 'required|in:inclusive,exclusive',
        ]);
        $org = $enquiry->organization;
        $customer = $enquiry->customer_id ? Customer::where('organization_id', $org->id)->findOrFail($enquiry->customer_id) : null;
        $products = Product::where('organization_id', $org->id)->whereIn('id', array_column($data['lines'], 'product_id'))->get()->keyBy('id');
        $lines = [];
        foreach (array_values($data['lines']) as $i => $line) {
            $product = $products->get((int) $line['product_id']);
            if (!$product || !($data['kind'] === 'rental' ? $product->canRent() : $product->canSell())) {
                throw ValidationException::withMessages(['lines.'.$i.'.product_id' => 'Choose a product available for this order type.']);
            }
            $allowedRates = collect([0, 5, 12, 18, 28, $product->defaultGstRate()])
                ->map(fn ($rate) => number_format($rate, 2, '.', ''))->unique()->all();
            if (!in_array(number_format((float) $line['tax_rate'], 2, '.', ''), $allowedRates, true)) {
                throw ValidationException::withMessages(['lines.'.$i.'.tax_rate' => 'Choose a standard GST slab or this product\'s saved tax rate.']);
            }
            $base = round((int) $line['quantity'] * (float) $line['rate'], 2);
            $taxable = $line['tax_mode'] === 'inclusive' ? round($base / (1 + (float) $line['tax_rate'] / 100), 2) : $base;
            $tax = $line['tax_mode'] === 'inclusive' ? round($base - $taxable, 2) : round($base * (float) $line['tax_rate'] / 100, 2);
            $lines[] = ['product_id' => $product->id, 'name' => $product->name, 'quantity' => (int) $line['quantity'],
                'rate' => round((float) $line['rate'], 2), 'tax_rate' => (float) $line['tax_rate'], 'tax_mode' => $line['tax_mode'],
                'taxable' => $taxable, 'tax' => $tax, 'total' => $line['tax_mode'] === 'inclusive' ? $base : round($base + $tax, 2)];
        }
        $deposit = $data['kind'] === 'rental' ? round((float) $data['deposit'], 2) : 0;
        $total = round(array_sum(array_column($lines, 'total')) + (float) $data['transport'], 2);
        if ($total > 99999999.99) throw ValidationException::withMessages(['lines' => 'The quotation total exceeds the supported order amount.']);
        return ['lines' => $lines, 'product_ids' => array_values(array_unique(array_column($lines, 'product_id'))), 'customer_id' => $customer?->id,
            'contact' => ['name' => $enquiry->name, 'phone' => $enquiry->phone, 'email' => $enquiry->email],
            'recipient' => ['name' => $customer?->name ?? $enquiry->name, 'email' => $customer?->email ?? $enquiry->email,
                'phone' => $customer?->phone ?? $enquiry->phone, 'address' => $customer?->billing_address ?: ($customer?->address ?? ''), 'tax_number' => $customer?->gst_number],
            'issuer' => ['name' => $org->name, 'address' => $org->address, 'email' => $org->email, 'phone' => $org->phone, 'tax_number' => $org->gst_number,
                'logo' => app(\App\Support\InvoicePdfAssetResolver::class)->logoDataUri($org->logo)],
            'region' => $this->region($org), 'currency' => $org->currency_code ?: 'INR', 'timezone' => config('app.timezone', 'Asia/Kolkata'),
            'tax_label' => ($org->country_code ?: 'IN') === 'IN' ? 'GST' : 'Tax', 'tax_type' => $data['tax_type'],
            'start_date' => $data['kind'] === 'rental' ? $data['start_date'] : null, 'end_date' => $data['kind'] === 'rental' ? $data['end_date'] : null,
            'terms' => $data['terms'] ?? '', 'transport' => round((float) $data['transport'], 2), 'deposit' => $deposit,
            'subtotal' => round(array_sum(array_column($lines, 'taxable')), 2), 'tax' => round(array_sum(array_column($lines, 'tax')), 2),
            'total' => $total, 'payable_with_deposit' => round($total + $deposit, 2)];
    }

    public function region(Organization $org): array
    {
        return ['country_code' => (string) $org->country_code, 'state_code' => (string) $org->state_code,
            'state' => (string) $org->state, 'currency' => $org->currency_code ?: 'INR'];
    }

    public function assertRegion(Quotation $quote): void
    {
        if (($quote->snapshot['region'] ?? null) !== $this->region($quote->organization()->firstOrFail())) {
            throw ValidationException::withMessages(['quotation_id' => 'Company region or currency changed after this quotation was prepared. Create a revision, save its updated details and obtain acceptance again.']);
        }
    }

    public function current(Quotation $quote): void
    {
        abort_if(Quotation::where('enquiry_id', $quote->enquiry_id)->where('revision', '>', $quote->revision)->exists(), 409, 'A newer quotation revision is available.');
    }

    public function shareUrl(Quotation $quote): string
    {
        return URL::temporarySignedRoute('quotation-share.show', $quote->expiresAt(), ['quotation' => $quote->id]);
    }

    public function decide(Quotation $quote, string $decision, string $name, string $note, bool $public = false): void
    {
        $this->current($quote);
        if ($quote->status !== 'sent' || $quote->expired()) {
            throw ValidationException::withMessages(['workspace' => 'This quotation is no longer awaiting a response. Refresh to see its current status.']);
        }
        $enquiry = $quote->enquiry;
        abort_if($enquiry->closed(), 409, 'This enquiry is closed.');
        $quote->update(['status' => $decision, 'accepted_at' => $decision === 'accepted' ? now() : null, 'lock_version' => $quote->lock_version + 1]);
        $enquiry->update(['stage' => $decision === 'accepted' ? 'accepted' : 'qualified', 'lock_version' => $enquiry->lock_version + 1]);
        $this->event($enquiry, 'quotation.'.$decision, $quote->number().' '.$decision.' by '.$name.'. '.($public ? 'Customer link response. ' : 'Recorded by staff. ').$note, $public);
    }
}
