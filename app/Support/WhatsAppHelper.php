<?php

namespace App\Support;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Rental;
use App\Models\Sale;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class WhatsAppHelper
{
    protected static function formatDateValue(mixed $value, string $format = 'd M Y'): string
    {
        if (blank($value)) {
            return '';
        }

        if ($value instanceof CarbonInterface) {
            return $value->format($format);
        }

        try {
            return Carbon::parse($value)->format($format);
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    public static function resolveCustomerNumber(?Customer $customer, string $defaultCountryCode = '91'): ?string
    {
        if (!$customer) {
            return null;
        }

        $number = null;

        if (method_exists($customer, 'preferredWhatsAppNumber')) {
            $number = $customer->preferredWhatsAppNumber();
        }

        if (!$number) {
            $number = $customer->whatsapp_number ?? $customer->phone ?? null;
        }

        return static::normalizeNumber($number, $defaultCountryCode);
    }

    public static function normalizeNumber(?string $number, string $defaultCountryCode = '91'): ?string
    {
        $normalized = preg_replace('/\D+/', '', (string) $number);

        if (!$normalized) {
            return null;
        }

        if (str_starts_with($normalized, '00')) {
            $normalized = substr($normalized, 2);
        }

        if (strlen($normalized) === 10) {
            return $defaultCountryCode . $normalized;
        }

        if (strlen($normalized) === 12 && str_starts_with($normalized, $defaultCountryCode)) {
            return $normalized;
        }

        return $normalized;
    }

    public static function chatUrl(?string $normalizedNumber, ?string $message = null): ?string
    {
        if (!$normalizedNumber) {
            return null;
        }

        $baseUrl = 'https://wa.me/' . $normalizedNumber;

        if (!$message) {
            return $baseUrl;
        }

        return $baseUrl . '?text=' . rawurlencode($message);
    }

    public static function rentalRenewalReminder(Rental $rental): string
    {
        $endDate = static::formatDateValue($rental->end_date);

        return trim("Hello {$rental->customer_name}, your rental for {$rental->product->name} is ending on {$endDate}. Please let us know if you would like to renew it.");
    }

    public static function rentalDeliveryConfirmation(Rental $rental): string
    {
        $startDate = static::formatDateValue($rental->start_date);

        return trim("Hello {$rental->customer_name}, your rental order for {$rental->product->name} is scheduled for delivery on {$startDate}. Please keep the delivery location and contact ready.");
    }

    public static function rentalPickupReminder(Rental $rental): string
    {
        $endDate = static::formatDateValue($rental->end_date);

        return trim("Hello {$rental->customer_name}, this is a reminder that pickup for {$rental->product->name} is due on {$endDate}. Please let us know a suitable pickup time.");
    }

    public static function rentalRenewedConfirmation(
        Rental $rental,
        mixed $previousEndDate,
        mixed $renewedEndDate,
        float $addedAmount = 0
    ): string {
        $oldDate = static::formatDateValue($previousEndDate);
        $newDate = static::formatDateValue($renewedEndDate);
        $amountText = $addedAmount > 0 ? ' Additional renewal amount: ' . CurrencyFormatter::format($addedAmount) . '.' : '';

        return trim("Hello {$rental->customer_name}, your rental for {$rental->product->name} has been renewed from {$oldDate} to {$newDate}.{$amountText} Thank you.");
    }

    public static function invoiceMessage(Invoice $invoice): string
    {
        $invoiceDate = static::formatDateValue($invoice->invoice_date);

        return trim("Hello {$invoice->bill_to_name}, your invoice {$invoice->invoice_number} dated {$invoiceDate} is ready. Total amount: " . CurrencyFormatter::format((float) $invoice->total_amount) . ". Please review and let us know if you need any help.");
    }

    public static function paymentReminderForInvoice(Invoice $invoice): string
    {
        return trim("Hello {$invoice->bill_to_name}, this is a payment reminder for invoice {$invoice->invoice_number}. Outstanding amount: " . CurrencyFormatter::format((float) $invoice->balance_amount) . ". Thank you.");
    }

    public static function saleFollowUp(Sale $sale): string
    {
        $customerName = $sale->customer->name ?? 'Customer';
        $productName = $sale->product->name ?? 'your requested item';
        $saleDate = static::formatDateValue($sale->sale_date);

        return trim("Hello {$customerName}, following up on your sale for {$productName} dated {$saleDate}. Please let us know if you need any assistance or repeat order.");
    }
}
