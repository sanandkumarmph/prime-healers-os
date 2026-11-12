<?php

namespace App\Services\Deliveries;

use App\Models\{Delivery, Invoice, Rental, Sale, Staff, Vendor, VendorOrderDetail};
use Illuminate\Validation\ValidationException;

class VendorCollectionAttribution
{
    public function forOrder(Rental|Sale $order): ?array
    {
        $organization = (int) $order->organization_id;
        $orderType = $order instanceof Sale ? 'sale' : 'rental';
        $detail = VendorOrderDetail::forOrganization($organization)
            ->where('order_type', $orderType)->where($orderType.'_id', $order->id)->first();
        $assignment = $detail ?? $order;
        $vendor = $assignment->delivery_responsibility === 'vendor_delivery' && $assignment->vendor_id
            ? Vendor::forOrganization($organization)->find($assignment->vendor_id)
            : null;

        return $vendor ? ['key' => 'vendor:'.$vendor->id, 'name' => $vendor->name] : null;
    }

    public function vendor(Delivery $task): ?array
    {
        if ($task->type !== 'delivery' || (bool) $task->sale_id === (bool) $task->rental_id) {
            return null;
        }

        $vendorAssignment = $task->assignment_type === 'vendor';
        if ($vendorAssignment || (!$task->assigned_user_id && $task->assignment_type !== 'third_party')) {
            $order = $task->sale_id ? $task->sale : $task->rental;
            if ($order && (int) $order->organization_id === (int) $task->organization_id
                && ($vendor = $this->forOrder($order))) {
                return $vendor;
            }
        }

        if ($vendorAssignment && $task->assigned_staff_id) {
            $staff = Staff::forOrganization($task->organization_id)->find($task->assigned_staff_id);
            if ($staff) {
                return ['key' => 'staff:'.$staff->id, 'name' => $staff->name];
            }
        }

        return null;
    }

    public function attributes(Delivery $task, ?string $recipient, bool $hasAmount): array
    {
        $vendor = $hasAmount ? $this->vendor($task) : null;
        if ($hasAmount && $vendor && !$recipient) {
            throw ValidationException::withMessages(['collection_recipient' => 'Choose whether the vendor or Prime Healers received the payment.']);
        }

        return $this->recipientAttributes($vendor, $recipient, $hasAmount);
    }

    public function forOrderPayment(Rental|Sale|null $order, ?string $recipient): array
    {
        $vendor = $order ? $this->forOrder($order) : null;
        if ($vendor && !$recipient) {
            throw ValidationException::withMessages(['collection_recipient' => 'Choose whether the vendor or Prime Healers received the payment.']);
        }
        if ($recipient === null) {
            return [];
        }

        return $this->recipientAttributes($vendor, $recipient, true);
    }

    public function invoiceOrder(Invoice $invoice): Rental|Sale|null
    {
        return $invoice->sale_id
            ? Sale::where('organization_id', $invoice->organization_id)->find($invoice->sale_id)
            : Rental::forOrganization($invoice->organization_id)->find($invoice->linkedRentalId());
    }

    private function recipientAttributes(?array $vendor, ?string $recipient, bool $hasAmount): array
    {
        if ($recipient === 'vendor' && !$vendor) {
            throw ValidationException::withMessages(['collection_recipient' => 'Vendor collection requires a positive amount and an identified delivery vendor.']);
        }

        return [
            'collection_recipient' => $hasAmount ? ($recipient ?: 'company') : null,
            'collection_vendor_key' => $recipient === 'vendor' ? $vendor['key'] : null,
            'collection_vendor_name' => $recipient === 'vendor' ? $vendor['name'] : null,
        ];
    }
}
