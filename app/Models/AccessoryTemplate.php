<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccessoryTemplate extends Model
{
    protected $fillable = [
        'name',
        'category',
        'equipment_type',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function items()
    {
        return $this->hasMany(AccessoryTemplateItem::class)->orderBy('sort_order')->orderBy('name');
    }
}
