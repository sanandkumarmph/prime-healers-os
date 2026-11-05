<?php

namespace App\Http\Controllers;

use App\Models\{Rental, RentalAgreement};
use App\Services\Finance\OrganizationFinancialTransaction;
use App\Services\Sales\RentalAgreements;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class RentalAgreementController extends Controller
{
    public function __construct(private RentalAgreements $agreements) {}

    private function rental(RentalAgreement $agreement): Rental
    {
        abort_unless(RentalAgreements::enabled() && $agreement->rental_id && !$agreement->quotation_id, 404);

        $rental = Rental::where('organization_id', $agreement->organization_id)->findOrFail($agreement->rental_id);
        abort_unless($rental->organization()->where('is_active', true)->exists(), 404);
        abort_unless($rental->status === 'active' && $this->agreements->current($rental)?->id === $agreement->id
            && $this->agreements->matches($rental, $agreement), 410, 'This agreement has been replaced or the rental is closed.');

        return $rental;
    }

    public function show(RentalAgreement $agreement)
    {
        $rental = $this->rental($agreement);
        return response()->view('sales-workspace.rental-agreement-public', compact('agreement', 'rental'))
            ->withHeaders(['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer', 'X-Robots-Tag' => 'noindex, nofollow']);
    }

    public function sign(Request $request, RentalAgreement $agreement)
    {
        $this->rental($agreement);
        $proof = $this->agreements->validate($request);
        app(OrganizationFinancialTransaction::class)->run((int) $agreement->organization_id, function () use ($agreement, $request, $proof) {
            $agreement = $agreement->fresh() ?? abort(404);
            $this->rental($agreement);
            if (!$agreement->signed_at) {
                $this->agreements->verifyTerms($request, $agreement->terms);
                $this->agreements->sign($agreement, $proof);
            }
        });

        return redirect()->to($request->fullUrl())->with('success', 'Your rental terms are signed. Thank you.');
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

        return redirect()->to(route('rentals.show', $rental).'#rental-agreement')->with('success', 'The signing link is ready to share.');
    }
}
