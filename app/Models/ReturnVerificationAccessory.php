<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ReturnVerificationAccessory extends Model
{
    public const STATUS_RETURNED = 'returned';
    public const STATUS_MISSING = 'missing';
    public const STATUS_DAMAGED = 'damaged';
    public const STATUS_NOT_APPLICABLE = 'not_applicable';

    public const STATUSES = [
        self::STATUS_RETURNED,
        self::STATUS_MISSING,
        self::STATUS_DAMAGED,
        self::STATUS_NOT_APPLICABLE,
    ];

    protected $fillable = [
        'return_verification_id',
        'accessory_name',
        'status',
        'remarks',
    ];

    public function returnVerification()
    {
        return $this->belongsTo(ReturnVerification::class);
    }
}
