<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Rental;
use App\Models\User;
use App\Policies\Concerns\HandlesModuleAuthorization;

class PaymentPolicy
{
    use HandlesModuleAuthorization;

    public function viewAny(User $user): bool
    {
        return $this->allowsModule($user, 'payments', 'read');
    }

    public function view(User $user, Payment $payment): bool
    {
        return $this->sameOrganization($user, $payment)
            && $this->allowsModule($user, 'payments', 'read');
    }

    public function create(User $user): bool
    {
        return $this->allowsModule($user, 'payments', 'create');
    }

    public function createForRental(User $user, Rental $rental): bool
    {
        return $this->sameOrganization($user, $rental)
            && $this->allowsModule($user, 'payments', 'create');
    }

    public function createForInvoice(User $user, Invoice $invoice): bool
    {
        return $this->sameOrganization($user, $invoice)
            && $this->allowsModule($user, 'payments', 'create');
    }

    public function delete(User $user, Payment $payment): bool
    {
        return $this->sameOrganization($user, $payment)
            && $this->allowsModule($user, 'payments', 'delete');
    }

    public function export(User $user): bool
    {
        return $user->hasPermission('payments.export');
    }
}
