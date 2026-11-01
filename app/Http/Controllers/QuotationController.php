<?php

namespace App\Http\Controllers;

use App\Models\{Enquiry, Organization, Product, Quotation};
use App\Services\Sales\SalesWorkspace;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class QuotationController extends Controller
{
    public function __construct(private SalesWorkspace $workspace) {}

    private function form(Enquiry $enquiry, ?Quotation $quote = null)
    {
        $products = Product::where('organization_id', $enquiry->organization_id)->orderBy('name')->get();
        $rates = collect([0, 5, 12, 18, 28])->merge($products->map(fn ($product) => $product->defaultGstRate()))->unique()->sort()->values()->all();
        $customers = $this->workspace->customers();
        return view('sales-workspace.quote-form', compact('enquiry', 'quote', 'products', 'rates', 'customers'));
    }

    public function create(int $enquiry)
    {
        $e = $this->workspace->enquiry($enquiry, 'update');
        abort_unless(auth()->user()->canAccessModule('quotations', 'create'), 403);
        abort_if(auth()->user()->scopeType('quotations') !== 'all' && (int) $e->owner_id !== (int) auth()->id(), 404);
        if ($quote = $e->quotations()->latest('revision')->first()) return redirect()->route('quotations.show', $quote);
        abort_if($e->closed(), 409, 'Reopen this enquiry first.');
        return $this->form($e);
    }

    public function store(Request $request, int $enquiry)
    {
        $e = $this->workspace->enquiry($enquiry, 'update');
        abort_unless($request->user()->canAccessModule('quotations', 'create'), 403);
        abort_if($request->user()->scopeType('quotations') !== 'all' && (int) $e->owner_id !== (int) $request->user()->id, 404);
        $quote = $this->workspace->atomic($e->organization_id, function () use ($e, $request) {
            $e = $this->workspace->locked($e); abort_if($e->closed(), 409, 'Reopen this enquiry first.');
            if ($existing = $e->quotations()->latest('revision')->first()) return $existing;
            $this->workspace->prepareDraft($request, $e);
            $snapshot = $this->workspace->snapshot($request, $e);
            $quote = $e->quotations()->create(['organization_id' => $e->organization_id, 'revision' => 1,
                'kind' => $request->input('kind'), 'valid_until' => $request->input('valid_until'), 'snapshot' => $snapshot]);
            $this->workspace->event($e, 'quotation.draft', $quote->number().' drafted.');
            return $quote;
        });
        return redirect()->route('quotations.show', $quote)->with('success', 'Draft saved.')
            ->with('review_ready', $request->input('next') === 'review');
    }

    public function edit(int $quotation)
    {
        $quote = $this->workspace->quote($quotation, 'update');
        abort_unless($quote->status === 'draft', 409, 'Create a revision to change a sent quotation.');
        return $this->form($quote->enquiry, $quote);
    }

    public function update(Request $request, int $quotation)
    {
        $quote = $this->workspace->quote($quotation, 'update');
        $this->workspace->atomic($quote->organization_id, function () use ($quote, $request) {
            $e = $this->workspace->locked($quote->enquiry); $quote = $quote->fresh(); $this->workspace->version($quote, $request);
            $this->workspace->current($quote); abort_unless($quote->status === 'draft' && !$e->closed(), 409);
            $this->workspace->prepareDraft($request, $e, $quote);
            $quote->update(['kind' => $request->input('kind'), 'valid_until' => $request->input('valid_until'),
                'snapshot' => $this->workspace->snapshot($request, $e), 'lock_version' => $quote->lock_version + 1]);
            $this->workspace->event($e, 'quotation.updated', $quote->number().' draft updated.');
        });
        return redirect()->route('quotations.show', $quote)->with('success', 'Draft saved.')
            ->with('review_ready', $request->input('next') === 'review');
    }

    public function show(int $quotation)
    {
        $quote = $this->workspace->quote($quotation); $enquiry = $quote->enquiry;
        if ($quote->status === 'draft' && !$enquiry->closed() && auth()->user()->canAccessModule('quotations', 'update') && auth()->user()->canAccessModule('enquiries', 'update')) {
            return $this->form($enquiry, $quote);
        }
        $shareUrl = in_array($quote->status, ['sent', 'accepted'], true) && !$quote->expired() ? $this->workspace->shareUrl($quote) : null;
        return view('sales-workspace.quote-show', compact('quote', 'enquiry', 'shareUrl'));
    }

    public function action(Request $request, int $quotation)
    {
        $quote = $this->workspace->quote($quotation, 'update');
        $data = $request->validate(['action' => 'required|in:send,mark_shared,revise,withdraw,accepted,declined',
            'shared_channel' => 'required_if:action,mark_shared|nullable|in:whatsapp,email,other',
            'contact_name' => 'required_if:action,accepted,declined|nullable|string|max:150',
            'note' => 'required_if:action,accepted,declined,withdraw|nullable|string|max:3000']);
        $target = $this->workspace->atomic($quote->organization_id, function () use ($quote, $request, $data) {
            $e = $this->workspace->locked($quote->enquiry); $quote = $quote->fresh();
            $this->workspace->version($quote, $request); $this->workspace->current($quote);
            abort_if($e->closed(), 409, 'This enquiry is closed.');
            if ($data['action'] === 'revise') {
                abort_unless(auth()->user()->canAccessModule('quotations', 'create'), 403);
        abort_if(auth()->user()->scopeType('quotations') !== 'all' && (int) $e->owner_id !== (int) auth()->id(), 404);
                abort_if(in_array($quote->status, ['converted', 'superseded', 'draft'], true), 409);
                $next = $e->quotations()->create(['organization_id' => $quote->organization_id, 'revision' => $quote->revision + 1,
                    'kind' => $quote->kind, 'valid_until' => now(config('app.timezone'))->addDays(7)->toDateString(), 'snapshot' => $quote->snapshot]);
                $quote->update(['status' => 'superseded', 'lock_version' => $quote->lock_version + 1]);
                $e->update(['stage' => 'qualified', 'lock_version' => $e->lock_version + 1]);
                $this->workspace->event($e, 'quotation.revised', $next->number().' created from '.$quote->number().'. The earlier link and acceptance are no longer valid.');
                return $next;
            }
            if ($data['action'] === 'send') {
                abort_unless($quote->status === 'draft', 409);
                if (!$quote->snapshot['customer_id'] || (int) $quote->snapshot['customer_id'] !== (int) $e->customer_id) {
                    throw ValidationException::withMessages(['workspace' => 'Select the quotation recipient here, then review and save the draft before sharing.']);
                }
                $this->workspace->assertRegion($quote);
                if ($quote->expired()) throw ValidationException::withMessages(['workspace' => 'Update the quotation validity before sharing.']);
                $quote->update(['status' => 'sent', 'sent_at' => now(), 'lock_version' => $quote->lock_version + 1]);
                $e->update(['stage' => 'quoted', 'lock_version' => $e->lock_version + 1]);
                $this->workspace->event($e, 'quotation.finalised', $quote->number().' ready to share. Customer link enabled; no message sent automatically.');
            } elseif ($data['action'] === 'mark_shared') {
                abort_unless($quote->status === 'sent' && !$quote->expired(), 409);
                if (!$quote->shared_at) {
                    $quote->update(['shared_at' => now(), 'shared_channel' => $data['shared_channel'], 'lock_version' => $quote->lock_version + 1]);
                    $this->workspace->event($e, 'quotation.shared', $quote->number().' marked as sent via '.$data['shared_channel'].' by staff.');
                }
            } elseif ($data['action'] === 'withdraw') {
                abort_unless(in_array($quote->status, ['sent', 'accepted'], true), 409);
                $quote->update(['status' => 'withdrawn', 'lock_version' => $quote->lock_version + 1]);
                $e->update(['stage' => 'qualified', 'lock_version' => $e->lock_version + 1]);
                $this->workspace->event($e, 'quotation.withdrawn', $quote->number().' withdrawn. '.$data['note']);
            } else {
                $this->workspace->decide($quote, $data['action'], $data['contact_name'], $data['note']);
            }
            return $quote;
        });
        return redirect()->route($data['action'] === 'revise' ? 'quotations.edit' : 'quotations.show', $target)
            ->with('success', $data['action'] === 'send' ? 'Ready to share. Copy the customer link or download the PDF.' : 'Quotation updated.');
    }

    public function pdf(int $quotation)
    {
        return $this->download($this->workspace->quote($quotation));
    }

    private function download(Quotation $quote)
    {
        $pdf = Pdf::loadView('sales-workspace.quote-pdf', compact('quote'))->setPaper('a4')
            ->setOption(['defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => false, 'isPhpEnabled' => false]);
        return response($pdf->output(), 200, ['Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$quote->number().'.pdf"', 'Cache-Control' => 'private, no-store',
            'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow']);
    }

    private function publicQuote(int $id): Quotation
    {
        $q = Quotation::findOrFail($id);
        abort_unless(Organization::whereKey($q->organization_id)->where('is_active', true)->exists(), 404);
        if (!in_array($q->status, ['sent', 'accepted', 'declined', 'converted'], true) || $q->expired()) {
            abort(response()->view('sales-workspace.quote-unavailable', [], 410)->withHeaders(['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']));
        }
        $this->workspace->current($q);
        return $q;
    }

    public function share(int $quotation)
    {
        $quote = $this->publicQuote($quotation);
        if (!$quote->viewed_at) Quotation::whereKey($quote->id)->whereNull('viewed_at')->update(['viewed_at' => now()]);
        return response()->view('sales-workspace.quote-public', compact('quote'))
            ->withHeaders(['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow']);
    }

    public function publicPdf(int $quotation)
    {
        return $this->download($this->publicQuote($quotation));
    }

    public function respond(Request $request, int $quotation)
    {
        $quote = $this->publicQuote($quotation);
        $data = $request->validate(['decision' => 'required|in:accepted,declined', 'contact_name' => 'required|string|max:150',
            'note' => 'nullable|string|max:3000', 'acknowledge' => 'accepted']);
        $this->workspace->atomic($quote->organization_id, function () use ($quote, $data) {
            $this->workspace->locked($quote->enquiry, false);
            $this->workspace->decide($quote->fresh(), $data['decision'], $data['contact_name'], $data['note'] ?? '', true);
        });
        return redirect()->to($request->fullUrl())->with('success', 'Your response has been recorded. Thank you.');
    }
}
