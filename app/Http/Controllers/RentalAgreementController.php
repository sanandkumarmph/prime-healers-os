<?php

namespace App\Http\Controllers;

use App\Models\{ActivityLog, Organization, Quotation, Rental, RentalAgreement};
use App\Services\Finance\OrganizationFinancialTransaction;
use App\Services\Sales\{RentalAgreements, SalesWorkspace};
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class RentalAgreementController extends Controller
{
    private const PRIVATE_HEADERS = [
        'Cache-Control' => 'private, no-store',
        'Referrer-Policy' => 'no-referrer',
        'X-Robots-Tag' => 'noindex, nofollow',
    ];

    public function __construct(private RentalAgreements $agreements, private SalesWorkspace $workspace) {}

    private function ensureAvailable(RentalAgreement $agreement): void
    {
        abort_unless(RentalAgreements::enabled()
            && Organization::whereKey($agreement->organization_id)->where('is_active', true)->exists(), 404);
    }

    private function quotation(RentalAgreement $agreement): ?Quotation
    {
        return $agreement->quotation_id
            ? Quotation::where('organization_id', $agreement->organization_id)->findOrFail($agreement->quotation_id)
            : null;
    }

    private function requireCurrent(RentalAgreement $agreement, ?Quotation $quote): void
    {
        if ($agreement->rental_id) {
            $rental = Rental::where('organization_id', $agreement->organization_id)->findOrFail($agreement->rental_id);
            abort_unless($rental->status === 'active' && $this->agreements->current($rental)?->id === $agreement->id
                && $this->agreements->matches($rental, $agreement), 410, 'This agreement has been replaced or the rental is closed.');
            return;
        }
        if ($quote) {
            abort_unless($this->agreements->canIssueForQuote($quote), 410, 'This quotation is no longer open.');
            $this->workspace->current($quote);
            return;
        }

        $rental = Rental::where('organization_id', $agreement->organization_id)->findOrFail($agreement->rental_id);
        abort_unless($rental->status === 'active' && $this->agreements->current($rental)?->id === $agreement->id
            && $this->agreements->matches($rental, $agreement), 410, 'This agreement has been replaced or the rental is closed.');
    }

    public function show(RentalAgreement $agreement)
    {
        $this->ensureAvailable($agreement);
        $quote = $this->quotation($agreement);
        if (!$agreement->signed_at) {
            $this->requireCurrent($agreement, $quote);
        }
        $reference = $quote ? 'Quotation '.$quote->number() : 'Rental #'.$agreement->rental_id;
        $rentalTerms = $this->agreements->terms($agreement);
        $pdfUrl = $agreement->signed_at ? $this->agreements->pdfLink($agreement) : null;

        return response()->view('sales-workspace.rental-agreement-public', compact('reference', 'rentalTerms', 'pdfUrl'))
            ->withHeaders(self::PRIVATE_HEADERS);
    }

    public function sign(Request $request, RentalAgreement $agreement)
    {
        $this->ensureAvailable($agreement);
        if (!$agreement->signed_at) {
            $this->requireCurrent($agreement, $this->quotation($agreement));
            $proof = $this->agreements->validate($request);
            app(OrganizationFinancialTransaction::class)->run((int) $agreement->organization_id, function () use ($agreement, $request, $proof) {
                $agreement = $agreement->fresh() ?? abort(404);
                $this->ensureAvailable($agreement);
                if ($agreement->signed_at) {
                    return;
                }
                $quote = $this->quotation($agreement);
                if ($quote) {
                    $enquiry = $this->workspace->locked($quote->enquiry, false);
                    $quote = $quote->fresh() ?? abort(404);
                }
                $this->requireCurrent($agreement, $quote);
                $this->agreements->verifyTerms($request, $this->agreements->terms($agreement));
                $this->agreements->sign($agreement, $proof, $quote);
                if ($quote) {
                    $this->workspace->event($enquiry, 'quotation.rental_agreement_signed', $quote->number().' rental agreement signed.', true);
                }
            });
        }

        return redirect()->to($request->fullUrl())->withHeaders(self::PRIVATE_HEADERS);
    }

    public function pdf(RentalAgreement $agreement)
    {
        $this->ensureAvailable($agreement);
        abort_unless($agreement->signed_at, 404);

        $proof = $agreement->proof;
        $proof['signature'] = $this->agreements->signatureOnWhite($proof['signature']);
        $reference = isset($proof['quotation_number']) ? 'Quotation '.$proof['quotation_number'] : 'Rental #'.$agreement->rental_id;
        $rentalTerms = preg_replace('/[\p{Extended_Pictographic}\x{FE0F}\x{200D}]+/u', '', $agreement->terms);
        $pdf = Pdf::loadView('sales-workspace.rental-agreement-pdf', compact('agreement', 'reference', 'rentalTerms', 'proof'))
            ->setPaper('a4')
            ->setOption(['defaultFont' => 'DejaVu Sans', 'isRemoteEnabled' => false, 'isPhpEnabled' => false]);
        $pdf->render();
        $document = $pdf->getDomPDF();
        $canvas = $document->getCanvas();
        $font = $document->getFontMetrics()->getFont('DejaVu Sans', 'normal');
        $canvas->page_text(46, 807, 'Prime Healers | Agreement #'.$agreement->id, $font, 8, [0.27, 0.36, 0.42]);
        $canvas->page_text(497, 807, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 8, [0.27, 0.36, 0.42]);

        return response($pdf->output(), 200, self::PRIVATE_HEADERS + [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="Prime-Healers-Rental-Agreement-'.$agreement->id.'.pdf"',
        ]);
    }

    public function issueForQuotation(Request $request, int $quotation)
    {
        abort_unless(RentalAgreements::enabled(), 404);
        $quote = $this->workspace->quote($quotation, 'update');
        $agreement = $this->workspace->atomic($quote->organization_id, function () use ($quote) {
            $this->workspace->locked($quote->enquiry);
            $quote = $quote->fresh() ?? abort(404);
            $this->workspace->current($quote);
            abort_unless($this->agreements->canIssueForQuote($quote), 409);
            return $this->agreements->issueForQuote($quote);
        });
        if ($request->expectsJson()) {
            return response()->json(['whatsapp_url' => $this->agreements->whatsapp($agreement, $quote->snapshot['recipient']['phone'] ?? null, 'Quotation '.$quote->number())], 200, self::PRIVATE_HEADERS);
        }
        if ($request->input('destination') === 'whatsapp') {
            return redirect()->away($this->agreements->whatsapp($agreement, $quote->snapshot['recipient']['phone'] ?? null, 'Quotation '.$quote->number()));
        }

        return redirect()->to(route('enquiries.show', $quote->enquiry_id).'#rental-agreement')
            ->with('success', 'The rental agreement is ready to share on WhatsApp.');
    }

    public function reissue(Rental $rental)
    {
        abort_unless(RentalAgreements::enabled(), 404);
        Gate::authorize('update', $rental);

        app(OrganizationFinancialTransaction::class)->run((int) $rental->organization_id, function () use ($rental) {
            $rental = $rental->fresh() ?? abort(404);
            $agreement = $this->agreements->current($rental);
            abort_unless($rental->status === 'active' && $agreement, 409);
            if (!$this->agreements->matches($rental, $agreement)) {
                $this->agreements->issue($rental);
            }
        });

        return redirect()->to(route('rentals.show', $rental).'#rental-agreement')->with('success', 'The rental agreement is ready to share on WhatsApp.');
    }

    private function exceptionInput(Request $request): array
    {
        return $request->validate(['exception_reason' => 'required|string|min:10|max:1500',
            'alternative_acceptance_confirmed' => 'accepted', 'exception_context' => 'required|string|size:64'],
            ['alternative_acceptance_confirmed.accepted' => 'Confirm that the terms were explained and the customer agreed by an alternative method.']);
    }

    public function approveQuoteException(Request $request, int $quotation)
    {
        abort_unless(RentalAgreements::enabled() && $this->agreements->canApproveException($request->user()), 403);
        $quote = $this->workspace->quote($quotation, 'update');
        $data = $this->exceptionInput($request);
        $this->workspace->atomic($quote->organization_id, function () use ($quote, $request, $data) {
            $enquiry = $this->workspace->locked($quote->enquiry);
            $quote = $quote->fresh() ?? abort(404);
            $this->workspace->current($quote);
            abort_unless($this->agreements->canIssueForQuote($quote), 409, 'Only the current open rental quotation can receive a signature exception.');
            $agreement = $this->agreements->issueForQuote($quote);
            if ($agreement->signed_at || $this->agreements->exceptionForQuote($quote, $agreement)) return;
            $this->workspace->version($quote, $request);
            if (!hash_equals($this->agreements->exceptionContext($agreement, $quote), $data['exception_context'])) {
                throw \Illuminate\Validation\ValidationException::withMessages(['exception_context' => 'The quotation or rental terms changed. Review them before approving an exception.']);
            }
            $this->agreements->approveException($agreement, $request->user(), $data['exception_reason'], $quote);
            $quote->increment('lock_version');
            $this->workspace->event($enquiry, 'quotation.signature_exception_approved', $quote->number().' digital signature exception approved by '.$request->user()->name.'. Reason saved in the rental agreement audit.');
        });
        return redirect()->to(route('enquiries.show', $quote->enquiry_id).'#rental-agreement')->with('success', 'Signature exception approved. Quotation acceptance is still required before creating the order.');
    }

    public function approveRentalException(Request $request, Rental $rental)
    {
        abort_unless(RentalAgreements::enabled() && $this->agreements->canApproveRentalException($request->user(), $rental), 403);
        $data = $this->exceptionInput($request);
        app(OrganizationFinancialTransaction::class)->run((int) $rental->organization_id, function () use ($rental, $request, $data) {
            $rental = $rental->fresh() ?? abort(404);
            abort_unless($this->agreements->canApproveRentalException($request->user(), $rental), 403);
            $agreement = $this->agreements->current($rental);
            abort_unless($rental->status === 'active' && $agreement && $this->agreements->matches($rental, $agreement), 409, 'Use the current rental agreement for this active order.');
            if ($this->agreements->readyFor($rental, $agreement)) return;
            if (!hash_equals($this->agreements->exceptionContext($agreement, null, $rental), $data['exception_context'])) {
                throw \Illuminate\Validation\ValidationException::withMessages(['exception_context' => 'The rental or its terms changed. Review them before approving an exception.']);
            }
            $this->agreements->approveException($agreement, $request->user(), $data['exception_reason'], null, $rental);
            ActivityLog::create(['organization_id'=>$rental->organization_id,'user_id'=>$request->user()->id,
                'action'=>'rental.signature_exception_approved','subject_type'=>Rental::class,'subject_id'=>$rental->id,
                'rental_id'=>$rental->id,'customer_id'=>$rental->customer_id,
                'description'=>'Digital signature exception approved by '.$request->user()->name.'. Reason saved in the rental agreement audit.',
                'properties'=>['agreement_id'=>$agreement->id,'approval_type'=>'digital_signature_exception']]);
        });
        return redirect()->to(route('rentals.show', $rental).'#rental-agreement')->with('success', 'Signature exception approved. The approval reason is saved with this rental.');
    }
}
