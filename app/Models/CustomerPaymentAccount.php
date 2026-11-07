<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerPaymentAccount extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['key_id', 'key_secret', 'webhook_secret', 'key_fingerprint', 'webhook_token'];

    protected $casts = ['key_id' => 'encrypted', 'key_secret' => 'encrypted', 'webhook_secret' => 'encrypted', 'is_active' => 'boolean'];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }
}
