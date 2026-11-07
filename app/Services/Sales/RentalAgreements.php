<?php

namespace App\Services\Sales;

use App\Models\{Quotation, Rental, RentalAgreement};
use App\Services\Deliveries\SignatureCapture;
use App\Support\PhoneNumber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;

class RentalAgreements
{
    public static function enabled(): bool
    {
        return config('app.rental_agreements') === true
            || parse_url((string) config('app.url'), PHP_URL_HOST) === 'uat-admin.primehealers.com';
    }

    public function quotation(Quotation $quote): ?RentalAgreement
    {
        if (!self::enabled() || $quote->kind !== 'rental') {
            return null;
        }

        return RentalAgreement::where('organization_id', $quote->organization_id)
            ->where('quotation_id', $quote->id)->first();
    }

    public function current(Rental $rental): ?RentalAgreement
    {
        if (!self::enabled()) {
            return null;
        }

        return RentalAgreement::where('organization_id', $rental->organization_id)
            ->where('rental_id', $rental->id)->latest('id')->first();
    }

    public function issue(Rental $rental): RentalAgreement
    {
        return RentalAgreement::create([
            'organization_id' => $rental->organization_id,
            'rental_id' => $rental->id,
            'rental_fingerprint' => $this->fingerprint($rental),
            'terms' => RentalAgreementTerms::TEXT,
        ]);
    }

    public function signedFor(Rental $rental, RentalAgreement $agreement): bool
    {
        return $agreement->signed_at !== null && $this->matches($rental, $agreement);
    }

    public function matches(Rental $rental, RentalAgreement $agreement): bool
    {
        return $agreement->rental_fingerprint === $this->fingerprint($rental);
    }

    public function dispatch(?Rental $rental): void
    {
        if ($rental && ($agreement = $this->current($rental)) && !$this->signedFor($rental, $agreement)) {
            throw ValidationException::withMessages(['rental_agreement' => 'The customer must sign the current rental terms before dispatch. Open the rental to share the signing link.']);
        }
    }

    public function canIssueForQuote(Quotation $quote): bool
    {
        return self::enabled() && $quote->kind === 'rental' && in_array($quote->status, ['sent', 'accepted'], true)
            && !$quote->expired() && !$quote->enquiry->closed();
    }

    public function issueForQuote(Quotation $quote): RentalAgreement
    {
        return RentalAgreement::firstOrCreate([
            'organization_id' => $quote->organization_id,
            'quotation_id' => $quote->id,
        ], ['terms' => RentalAgreementTerms::TEXT]);
    }

    public function terms(RentalAgreement $agreement): string
    {
        return $agreement->signed_at ? $agreement->terms : RentalAgreementTerms::TEXT;
    }

    public function attach(Quotation $quote, Rental $rental): void
    {
        $this->quotation($quote)?->update([
            'rental_id' => $rental->id,
            'rental_fingerprint' => $this->fingerprint($rental),
        ]);
    }

    public function sign(RentalAgreement $agreement, array $proof, ?Quotation $quote = null): void
    {
        if ($agreement->signed_at) {
            return;
        }
        if ($quote) {
            $proof += ['quotation' => $quote->snapshot, 'quotation_number' => $quote->number()];
        }
        $agreement->fill(['terms' => $this->terms($agreement), 'signed_at' => now(), 'proof' => $proof])->save();
    }

    public function validate(Request $request): array
    {
        $data = $request->validate([
            'contact_name' => 'required|string|max:150',
            'rental_agreement_acknowledged' => 'accepted',
            'rental_agreement_version' => 'required|string|size:64',
            'rental_agreement_id_reference' => 'nullable|digits:4',
            'signature_data' => 'required|string|max:350000',
        ], [
            'rental_agreement_acknowledged.accepted' => 'Confirm that you agree to the rental terms.',
            'signature_data.required' => 'Please sign in the signature pad.',
        ]);

        return [
            'name' => $data['contact_name'],
            'id_reference' => $data['rental_agreement_id_reference'] ?? null,
            'signature' => base64_encode(SignatureCapture::clean($data['signature_data'], 256 * 1024)),
        ];
    }

    public function verifyTerms(Request $request, string $terms): void
    {
        if ($request->input('rental_agreement_version') !== hash('sha256', $terms)) {
            throw ValidationException::withMessages(['rental_agreement_version' => 'The rental terms changed. Review the current version and sign again.']);
        }
    }

    public function link(RentalAgreement $agreement): string
    {
        return URL::temporarySignedRoute('rental-agreement.show', now()->addDays(7), ['agreement' => $agreement->id]);
    }

    public function pdfLink(RentalAgreement $agreement): string
    {
        return URL::temporarySignedRoute('rental-agreement.pdf', now()->addDays(7), ['agreement' => $agreement->id]);
    }

    public function whatsapp(RentalAgreement $agreement, ?string $phone, string $reference): string
    {
        $recipient = preg_replace('/\D/', '', PhoneNumber::normalize($phone) ?? '');
        return 'https://wa.me/' . $recipient . '?text=' . rawurlencode($this->message($agreement, $reference));
    }

    public function message(RentalAgreement $agreement, string $reference): string
    {
        $message = '*Prime Healers | Rental agreement*' . "\n" . $reference . "\n\n";
        $message .= $agreement->signed_at
            ? 'Thank you for signing. Download your signed copy: ' . $this->pdfLink($agreement)
            : 'Please review and sign before dispatch: ' . $this->link($agreement);
        $message .= "\n\n*Rental terms*\n" . $this->terms($agreement);

        return $message;
    }

    public function signatureOnWhite(string $signature): string
    {
        $bytes = base64_decode($signature, true);
        $ink = $bytes !== false ? @imagecreatefromstring($bytes) : false;
        if (!$ink) {
            return $signature;
        }

        $width = imagesx($ink);
        $height = imagesy($ink);
        // Older signature pads could save their transparent background as opaque black.
        // Identify the pad by its dimensions and corners. Its strokes are dark blue.
        if ($width === 760 && $height === 280 && imageistruecolor($ink)
            && imagecolorat($ink, 0, 0) === 0 && imagecolorat($ink, $width - 1, 0) === 0
            && imagecolorat($ink, 0, $height - 1) === 0 && imagecolorat($ink, $width - 1, $height - 1) === 0) {
            imagecolortransparent($ink, 0);
        }

        $white = imagecreatetruecolor($width, $height);
        imagefill($white, 0, 0, 0xffffff);
        imagecopy($white, $ink, 0, 0, 0, 0, $width, $height);
        ob_start();
        try {
            imagepng($white);
            return base64_encode(ob_get_contents());
        } finally {
            ob_end_clean();
            imagedestroy($white);
            imagedestroy($ink);
        }
    }

    private function fingerprint(Rental $rental): string
    {
        $fields = [
            'customer_id', 'customer_type', 'business_partner_id', 'partner_client_id', 'customer_name', 'phone',
            'product_id', 'quantity', 'start_date', 'end_date', 'rental_amount', 'deposit_amount', 'transport_amount', 'other_amount',
        ];
        $order = $rental->only($fields);
        $order['start_date'] = $rental->start_date?->toDateString();
        $order['end_date'] = $rental->end_date?->toDateString();
        foreach (['rental_amount', 'deposit_amount', 'transport_amount', 'other_amount'] as $amount) {
            $order[$amount] = number_format((float) $order[$amount], 2, '.', '');
        }

        $rentalFields = ['product_id', 'quantity', 'start_date', 'end_date', 'unit_rental_amount', 'gst_rate', 'gst_mode', 'tax_type'];
        $saleFields = ['product_id', 'quantity', 'unit_price', 'gst_rate', 'gst_mode', 'tax_type'];
        $order['items'] = $rental->rentalItems()->orderBy('id')->get($rentalFields)->toArray();
        $order['purchases'] = $rental->saleItems()->orderBy('id')->get($saleFields)->toArray();

        return hash('sha256', json_encode($order, JSON_THROW_ON_ERROR));
    }
}
