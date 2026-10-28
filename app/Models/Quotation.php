<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Quotation extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['snapshot' => 'array', 'valid_until' => 'date', 'sent_at' => 'datetime', 'shared_at' => 'datetime', 'viewed_at' => 'datetime', 'accepted_at' => 'datetime'];
    public function enquiry() { return $this->belongsTo(Enquiry::class); }
    public function organization() { return $this->belongsTo(Organization::class); }
    public function number(): string { return 'Q-'.str_pad((string) $this->enquiry_id, 5, '0', STR_PAD_LEFT).'-R'.$this->revision; }
    public function expiresAt(): \Illuminate\Support\Carbon { return \Illuminate\Support\Carbon::parse($this->valid_until->toDateString(), $this->snapshot['timezone'] ?? 'UTC')->endOfDay()->utc(); }
    public function expired(): bool { return $this->expiresAt()->isPast(); }
    public function statusLabel(): string { return match ($this->displayStatus()) { 'sent' => $this->shared_at ? 'Awaiting response' : 'Ready to share', 'converted' => 'Order created', default => ucfirst($this->displayStatus()) }; }
    public function displayStatus(): string { return $this->expired() && in_array($this->status, ['sent', 'accepted'], true) ? 'expired' : $this->status; }
}
