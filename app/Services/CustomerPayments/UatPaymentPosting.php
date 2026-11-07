<?php

namespace App\Services\CustomerPayments;

use Illuminate\Support\Facades\DB;

class UatPaymentPosting
{
    public function enabled(): bool
    {
        if (!config('customer_payments.uat_test_posting', true) || config('app.env') !== 'uat'
            || rtrim((string) config('app.url'), '/') !== 'https://uat-admin.primehealers.com') {
            return false;
        }
        try {
            $connection = DB::connection();
            if ($connection->getDriverName() !== 'mysql') return false;
            $identity = $connection->selectOne('SELECT DATABASE() AS db, @@socket AS socket, @@server_id AS sid');
            return $identity->db === 'uata_primehealersos'
                && $identity->socket === '/run/phos-uat-database/mysql.sock' && (int) $identity->sid === 194716;
        } catch (\Throwable) {
            return false;
        }
    }
}
