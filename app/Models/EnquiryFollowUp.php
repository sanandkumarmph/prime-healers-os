<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EnquiryFollowUp extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['due_at' => \App\Casts\UtcDateTime::class, 'completed_at' => 'datetime'];
    public function enquiry() { return $this->belongsTo(Enquiry::class); }
}
