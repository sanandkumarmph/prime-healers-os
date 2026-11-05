<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RentalAgreement extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['proof'];

    protected $casts = ['signed_at' => 'datetime', 'proof' => 'encrypted:array'];
}
