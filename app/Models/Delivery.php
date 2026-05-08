<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Delivery extends Model
{
    public const TYPES = ['delivery', 'pickup'];

    public const STATUSES = ['pending', 'in_progress', 'completed', 'cancelled'];

    public const ASSIGNMENT_TYPES = ['delivery_team', 'vendor', 'third_party'];

    protected $fillable = [
        'rental_id',
        'sale_id',
        'type',
        'assigned_to',
        'scheduled_at',
        'status',
        'notes',
        'completed_at',
        'organization_id',
        'assignment_type',
        'assigned_user_id',
        'assigned_staff_id',
        'third_party_name',
        'third_party_contact',
        'third_party_phone',
    ];

    protected $casts = [
        'scheduled_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function rental()
    {
        return $this->belongsTo(Rental::class);
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function assignedStaff()
    {
        return $this->belongsTo(Staff::class, 'assigned_staff_id');
    }
}
