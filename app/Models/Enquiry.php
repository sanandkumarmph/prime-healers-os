<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Enquiry extends Model
{
    protected $guarded = ['id'];
    public const STAGES = ['new' => 'New', 'qualified' => 'Qualified', 'quoted' => 'Quotation ready', 'accepted' => 'Accepted', 'won' => 'Order created', 'lost' => 'Lost'];
    public const INCENTIVE_SOURCES = [
        'incoming_phone' => 'Incoming phone call',
        'indiamart' => 'IndiaMART',
        'justdial' => 'Justdial',
        'facebook' => 'Facebook leads / chats / queries',
    ];
    public const SOURCES = self::INCENTIVE_SOURCES + [
        'website' => 'Direct website order',
        'b2b_referral' => 'B2B referral',
        'whatsapp' => 'WhatsApp',
        'walk_in' => 'Walk-in',
        'referral' => 'Other referral',
        'outbound_phone' => 'Outgoing phone call',
        'other_social' => 'Other social media',
        'other' => 'Other',
    ];

    public static function incentiveSource(?string $source): ?string
    {
        // Only unambiguous historical labels qualify. "Phone" and "Social media" did not identify the channel precisely.
        return match (strtolower(trim((string) $source))) {
            'incoming_phone', 'incoming phone call', 'incoming phone calls', 'incoming call', 'inbound phone call' => 'incoming_phone',
            'indiamart', 'india mart' => 'indiamart',
            'justdial', 'just dial' => 'justdial',
            'facebook', 'facebook lead', 'facebook leads', 'facebook chat', 'facebook chats', 'facebook query', 'facebook queries',
            'facebook leads / chats / queries' => 'facebook',
            default => null,
        };
    }

    public function getSourceLabelAttribute(): string
    {
        return self::SOURCES[$this->source] ?? ($this->source ?: 'Not specified');
    }
    public function owner() { return $this->belongsTo(User::class, 'owner_id'); }
    public function customer() { return $this->belongsTo(Customer::class); }
    public function organization() { return $this->belongsTo(Organization::class); }
    public function quotations() { return $this->hasMany(Quotation::class); }
    public function followUps() { return $this->hasMany(EnquiryFollowUp::class); }
    public function activities() { return $this->hasMany(EnquiryActivity::class); }
    public function closed(): bool { return in_array($this->stage, ['won', 'lost'], true); }
}
