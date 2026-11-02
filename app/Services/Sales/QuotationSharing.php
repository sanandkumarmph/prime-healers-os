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
        $clean = fn ($value) => trim(preg_replace('/[\r\n\x00-\x1F]+/u', ' ', (string) $value) ?? '');
        $money = fn ($value) => $s['currency'].' '.number_format((float) $value, 2);
        $rows = [$clean($s['issuer']['name']).' | Quotation '.$quote->number(), 'For: '.$clean($s['recipient']['name'])];
        if ($quote->kind === 'rental') {
            $from = CarbonImmutable::parse($s['start_date']);
            $to = CarbonImmutable::parse($s['end_date']);
            $rows[] = 'Rental period: '.$from->format('d M Y').' to '.$to->format('d M Y').' ('.((int) $from->diffInDays($to) + 1).' days)';
        }
        $rows[] = '';
        foreach ($s['lines'] as $line) {
            $type = ($line['line_type'] ?? $quote->kind) === 'rental' ? 'Rental, quoted period' : 'One-time purchase';
            $rows[] = $clean($line['name']).' | '.$type.' | Qty '.$line['quantity'].' @ '.$money($line['rate']).' | Line total '.$money($line['total']);
            $rows[] = 'Tax '.$line['tax_rate'].'% '.($line['tax_mode'] === 'inclusive' ? 'included' : 'extra, included in line total');
        }
        $rows[] = '';
        $rows[] = 'Transport: '.$money($s['transport']);
        $rows[] = 'Total including applicable tax: '.$money($s['total']);
        if ($quote->kind === 'rental') {
            $rows[] = 'Refundable deposit: '.$money($s['deposit']);
            $rows[] = 'Total with deposit: '.$money($s['payable_with_deposit']);
        }
        $rows[] = 'Valid until: '.CarbonImmutable::parse($quote->valid_until)->format('d M Y');
        $rows[] = 'View quotation and PDF: '.$url;
        $rows[] = 'Availability is confirmed when the order is created.';
        if (!empty($s['sender']['name'])) {
            $rows[] = '';
            $rows[] = 'Regards, '.$clean($s['sender']['name']);
            $rows[] = $clean($s['sender']['company'] ?? $s['issuer']['name']);
            if (!empty($s['sender']['phone'])) $rows[] = 'Contact: '.$clean($s['sender']['phone']);
        }
        return implode("\n", $rows);
    }
}
