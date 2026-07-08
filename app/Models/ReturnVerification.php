<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReturnVerification extends Model
{
    protected $fillable = [
        'asset_id',
        'rental_id',
        'verified_by',
        'condition_before',
        'condition_after',
        'outcome',
        'remarks',
        'verified_at',
    ];

    protected $casts = [
        'verified_at' => 'datetime',
    ];

    public function accessories()
    {
        return $this->hasMany(ReturnVerificationAccessory::class);
    }

    public function photos()
    {
        return $this->hasMany(ReturnVerificationPhoto::class);
    }
}
