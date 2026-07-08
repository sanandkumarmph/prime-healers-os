<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReturnVerificationPhoto extends Model
{
    protected $fillable = [
        'return_verification_id',
        'photo_type',
        'path',
    ];

    public function returnVerification()
    {
        return $this->belongsTo(ReturnVerification::class);
    }
}
