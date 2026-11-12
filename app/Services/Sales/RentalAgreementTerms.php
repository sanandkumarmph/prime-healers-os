<?php

namespace App\Services\Sales;

final class RentalAgreementTerms
{
    public const POLICY_URL = 'https://www.primehealers.com/rental-terms-and-conditions';

    public const TEXT = <<<'TERMS'
Rental period and payment
The standard minimum rental period is 30 days. Hospital beds always require at least 30 days.
Pay in advance online to an official Prime Healers account.
There is no rental refund for a voluntary early return.

Renewals
In Bangalore, a 15-day renewal costs 60% of the monthly rent. A 7-day renewal costs 40%.
In other cities, the minimum renewal period is 30 days.
Tell us at least one day before the rental ends if you want to return the equipment. If we do not receive notice, the rental renews automatically.
Late payments incur a fee of ₹100 per day.

Delivery and pickup
Delivery takes 7 to 8 working hours after order confirmation.
We collect the equipment within 72 hours of your pickup request.
Our working hours are 9 AM to 9 PM IST.

Equipment and damage
Please return the rented equipment at the end of your rental. You are responsible for damage to the equipment.

Security deposit
We refund the security deposit within 48 hours of pickup. Refunds are made by UPI, QR payment or bank transfer. Cash refunds are unavailable at pickup.

Documents and contact details
Provide your full delivery address, proof of address, identity proof for KYC, and a primary and alternate phone number. Accepted ID includes Aadhaar, a driving licence or voter ID. A prescription is recommended where applicable.

Technical and oxygen support
We offer phone and video support, with on-site assistance on request.
For oxygen equipment, follow your clinician’s instructions and the manufacturer’s operating guidance. Ask your care team about backup oxygen and how to monitor oxygen saturation with a pulse oximeter.

Contact Prime Healers
Call 7975708181 or 8700125059, or email info@primehealers.com. For delivery or pickup queries, contact Prime Healers support directly.

Jurisdiction and full policy
Disputes are subject to Bangalore jurisdiction.
https://www.primehealers.com/rental-terms-and-conditions
TERMS;

    public static function sections(string $terms): array
    {
        $terms = str_replace(["\r\n", "\r"], "\n", trim($terms));
        return array_map(static function (string $section): array {
            $lines = explode("\n", $section);
            return ['heading' => array_shift($lines), 'lines' => $lines];
        }, preg_split('/\n{2,}/', $terms, -1, PREG_SPLIT_NO_EMPTY));
    }
}
