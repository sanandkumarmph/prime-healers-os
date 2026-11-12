<?php

namespace App\Services\Sales;

use App\Models\{Organization, Quotation, User};
use Carbon\CarbonImmutable;

class QuotationSharing
{
    public function sender(User $user, Organization $organization): array
    {
        return ['user_id' => $user->id, 'name' => $user->name,
            'company' => $organization->name, 'phone' => $organization->phone, 'email' => $organization->email];
    }

    public function message(Quotation $quote, string $url): string
    {
        $s = $quote->snapshot;
        $clean = fn ($value) => trim(preg_replace('/[\r\n\x00-\x1F*_~`]+/u', ' ', (string) $value) ?? '');
        $money = fn ($value) => ($s['currency'] === 'INR' ? '₹' : $s['currency'].' ').number_format((float) $value, 2);
        $taxLabel = $clean($s['tax_label'] ?? 'Tax');
        $allInclusive = collect($s['lines'])->every(fn ($line) => $line['tax_mode'] === 'inclusive');
        $rows = ['*Prime Healers*', '*'.($quote->kind === 'rental' ? 'Rental' : 'Sales').' quotation · '.$quote->number().'*', '',
            'Hi '.$clean(\App\Models\Enquiry::contactName($s['recipient']['name'], $s['recipient']['salutation'] ?? null)).','];
        if ($quote->kind === 'rental') {
            $from = CarbonImmutable::parse($s['start_date']);
            $to = CarbonImmutable::parse($s['end_date']);
            $rows[] = '';
            $rows[] = '*Rental — '.((int) $from->diffInDays($to) + 1).' days*';
            $rows[] = $from->format('d M Y').' – '.$to->format('d M Y');
        }
        foreach (['rental', 'sale'] as $type) {
            $lines = array_values(array_filter($s['lines'], fn ($line) => ($line['line_type'] ?? $quote->kind) === $type));
            if (!$lines) continue;
            if ($type === 'sale') {
                $rows[] = '';
                $rows[] = $quote->kind === 'rental' ? '*One-time purchases*' : '*Products*';
            }
            foreach ($lines as $line) {
                $rows[] = '• '.$clean($line['name']);
                if ((int) $line['quantity'] === 1 && $line['tax_mode'] === 'inclusive') {
                    $detail = '  Qty 1 · *'.$money($line['total']).'*';
                } else {
                    $detail = '  '.$line['quantity'].' × '.$money($line['rate']);
                    if ($line['tax_mode'] === 'exclusive') $detail .= ' + '.$taxLabel;
                    $detail .= ' = *'.$money($line['total']).'*';
                }
                if (!$allInclusive && $line['tax_mode'] === 'inclusive') $detail .= ' ('.$taxLabel.' included)';
                $rows[] = $detail;
            }
        }
        $rows[] = '';
        $rows[] = '*Amount summary*';
        $rows[] = ($quote->kind === 'rental' ? 'Rental & items: ' : 'Products: ').$money(array_sum(array_column($s['lines'], 'total')));
        $rows[] = $allInclusive ? '_Product prices include '.$taxLabel.'._' : '_Product line totals include applicable '.$taxLabel.'._';
        $rows[] = 'Delivery / transport: '.$money($s['transport']);
        $rows[] = '*Quotation total: '.$money($s['total']).'*';
        if ($quote->kind === 'rental') {
            $rows[] = '';
            $rows[] = 'Refundable security deposit: '.$money($s['deposit']);
            $rows[] = '*Total including deposit: '.$money($s['payable_with_deposit']).'*';
        }
        $rows[] = '';
        $rows[] = 'Valid until: '.CarbonImmutable::parse($quote->valid_until)->format('d M Y');
        $rows[] = 'Availability is confirmed when the order is created.';
        $rows[] = '';
        $rows[] = '*View quotation & PDF*';
        $rows[] = $url;
        if (!empty($s['sender']['name'])) {
            $rows[] = '';
            $rows[] = 'Regards,';
            $rows[] = $clean($s['sender']['name']);
            if (!empty($s['sender']['phone'])) $rows[] = $clean($s['sender']['phone']);
        }
        $company = $clean($s['sender']['company'] ?? $s['issuer']['name']);
        if ($company !== '' && strcasecmp($company, 'Prime Healers') !== 0) $rows[] = $company;
        return implode("\n", $rows);
    }
}
