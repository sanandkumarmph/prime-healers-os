<?php

namespace App\Http\Controllers;

use App\Models\{Organization, Quotation, Rental, RentalAgreement};
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

    public function issueForQuotation(int $quotation)
    {
        abort_unless(RentalAgreements::enabled(), 404);
        $quote = $this->workspace->quote($quotation, 'update');
        $this->workspace->atomic($quote->organization_id, function () use ($quote) {
            $this->workspace->locked($quote->enquiry);
            $quote = $quote->fresh() ?? abort(404);
            $this->workspace->current($quote);
            abort_unless($this->agreements->canIssueForQuote($quote), 409);
            $this->agreements->issueForQuote($quote);
        });

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
}
