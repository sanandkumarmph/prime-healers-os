<?php

namespace App\Services\CustomerPayments;

use Illuminate\Validation\ValidationException;

class GatewayFailure extends ValidationException
{
    public bool $definitelyRejected = false;
}
