<?php

namespace App\Services;

use App\Models\User;

class WorkflowGuides
{
    public function forUser(User $user): array
    {
        abort_unless($user->is_active && $user->organization()->where('is_active', true)->exists(), 403);
        $catalog = [
            ['customers', 'create', 'Add a customer', 'customers.create', [
                'Choose a direct customer or the existing business partner flow.',
                'Enter the billing contact, phone country code and service address. Save only the information needed for the order.',
                'Reopen the customer and check the saved details before creating an order.',
            ]],
            ['enquiries', 'create', 'Capture an enquiry', 'enquiries.create', [
                'Record the contact, requirement and responsible person. A customer account can be linked later.',
                'Save the enquiry, or choose Save & create quotation when you are ready to price.',
                'Use follow-ups to record the next action and its outcome. Internal notes are never part of the customer quotation.',
            ]],
            ['quotations', 'create', 'Prepare and share a quotation', 'enquiries.index', [
                'Open an enquiry, select its billed customer and create the rental or sale quotation.',
                'Review product quantities, rates for the full rental period, tax, transport and deposit. Checking availability does not reserve stock.',
                'Review the saved document and create its private link. Copy or open a message composer, then check and send it yourself.',
                'Record the customer response. Revise an offer when its commercial details change; the previous link and acceptance become invalid.',
                'Create the order from the accepted quotation. Complete stock and delivery details in the existing PHOS form.',
            ]],
            ['rentals', 'read', 'Check rental availability', 'availability.index', [
                'Choose the requested dates, product quantities and warehouse.',
                'Read ready stock separately from expected returns and units committed to other dates.',
                'Expected returns are conditional. Returned equipment must pass the existing inspection before it can be allocated.',
                'Confirm stock again when saving the order. This screen does not create a reservation.',
            ]],
            ['rentals', 'create', 'Create a rental', 'rentals.create', [
                'Select the billed customer and the existing service contact or partner client.',
                'Choose the products, period, rates, tax and deposit using PHOS booking dates. Delivery completion does not change this billing policy.',
                'Choose in-house or vendor fulfilment. Allocate eligible stock and arrange delivery using the existing assignment controls.',
                'Save and review the invoice. Record receipts through the payment actions; a delivery collection report alone is not proof of a posted receipt.',
            ]],
            ['rentals', 'update', 'Renew a rental', 'renewal-center.index', [
                'Open the rental in Renewal Center and check its current period and outstanding invoices.',
                'Review the new dates and added charges before saving the renewal.',
                'Check the renewal invoice and retain the original payment history. Schedule a pickup when equipment is no longer needed.',
            ]],
            ['deliveries', 'update', 'Complete delivery or pickup', 'deliveries.index', [
                'Open your assigned task and check its customer, address, equipment and quantities.',
                'Start the task, capture the required photos and location, then obtain a fresh customer signature or record why signing was not possible.',
                'Review the quantities and evidence before confirming. Reopen saved history after an uncertain response instead of submitting a different record.',
                'Pickup is followed by warehouse return verification. Collected equipment is not automatically ready for another rental.',
            ]],
            ['assets', 'update', 'Verify returned equipment', 'assets.pending-verification', [
                'Match the returned unit to its serial number or barcode.',
                'Check condition, accessories and required photos. Record missing items and damage.',
                'Choose the appropriate stock, repair or retirement outcome. Only approved returned stock becomes available.',
            ]],
            ['sales', 'create', 'Create a sale', 'sales.create', [
                'Choose the customer or existing business partner and actual-client flow.',
                'Check product quantities, negotiated rates, tax and shipping charges. Choose eligible sale stock or vendor fulfilment.',
                'Save once and review the sale invoice, stock movement and delivery assignment.',
                'Record the receipt and check the remaining balance. Preserve the order and payment history when correcting mistakes.',
            ]],
            ['payments', 'create', 'Record and correct a receipt', 'invoices.index', [
                'Open the correct customer invoice and confirm the amount still due.',
                'Record the payment date, method and amount. Check the saved receipt and invoice balance.',
                'For a wrong receipt, use the authorised correction action with a reason. A reversal preserves the original and does not send a refund.',
            ]],
        ];

        return collect($catalog)->filter(fn ($guide) => $user->canAccessModule($guide[0], $guide[1])
                && ($guide[0] !== 'payments' || $user->canAccessModule('invoices', 'read'))
                && ($guide[0] !== 'quotations' || ($user->canAccessModule('enquiries', 'read') && $user->canAccessModule('enquiries', 'update'))))
            ->map(fn ($guide) => ['id' => $guide[0].'-'.$guide[1].'-'.str($guide[2])->slug(), 'title' => $guide[2],
                'route' => $guide[3], 'steps' => $guide[4]])->values()->all();
    }
}
