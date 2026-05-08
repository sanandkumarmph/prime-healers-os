<?php

namespace App\Policies;

use App\Models\Invoice;
use App\Models\User;
use App\Policies\Concerns\HandlesModuleAuthorization;

class InvoicePolicy
{
    use HandlesModuleAuthorization;

    public function viewAny(User $user): bool
    {
        return $this->allowsModule($user, 'invoices', 'read');
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $this->sameOrganization($user, $invoice)
            && $this->allowsModule($user, 'invoices', 'read');
    }

    public function create(User $user): bool
    {
        return $this->allowsModule($user, 'invoices', 'create');
    }

    public function update(User $user, Invoice $invoice): bool
    {
        return $this->sameOrganization($user, $invoice)
            && $this->allowsModule($user, 'invoices', 'update');
    }

    public function delete(User $user, Invoice $invoice): bool
    {
        return $this->sameOrganization($user, $invoice)
            && $this->allowsModule($user, 'invoices', 'delete');
    }

    public function export(User $user): bool
    {
        return $user->hasPermission('invoices.export');
    }

    public function printAny(User $user): bool
    {
        return $user->hasPermission('invoices.print');
    }

    public function print(User $user, Invoice $invoice): bool
    {
        return $this->sameOrganization($user, $invoice)
            && $user->hasPermission('invoices.print');
    }
}
