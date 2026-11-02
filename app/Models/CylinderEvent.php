<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CylinderEvent extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['details' => 'array'];
    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Cylinder movement history is append-only.'));
        static::deleting(fn () => throw new \LogicException('Cylinder movement history is append-only.'));
    }
    public function user() { return $this->belongsTo(User::class); }
    public function cylinder() { return $this->belongsTo(Cylinder::class); }
    public function job() { return $this->belongsTo(CylinderJob::class, 'cylinder_job_id'); }
}
