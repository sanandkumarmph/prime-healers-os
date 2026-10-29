<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Enquiry extends Model
{
    protected $guarded = ['id'];
    public const STAGES = ['new' => 'New', 'qualified' => 'Qualified', 'quoted' => 'Quotation ready', 'accepted' => 'Accepted', 'won' => 'Order created', 'lost' => 'Lost'];
    public function owner() { return $this->belongsTo(User::class, 'owner_id'); }
    public function customer() { return $this->belongsTo(Customer::class); }
    public function organization() { return $this->belongsTo(Organization::class); }
    public function quotations() { return $this->hasMany(Quotation::class); }
    public function followUps() { return $this->hasMany(EnquiryFollowUp::class); }
    public function activities() { return $this->hasMany(EnquiryActivity::class); }
    public function closed(): bool { return in_array($this->stage, ['won', 'lost'], true); }
}
