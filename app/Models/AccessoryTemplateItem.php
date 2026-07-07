<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AccessoryTemplateItem extends Model
{
    protected $fillable = [
        'accessory_template_id',
        'name',
        'is_required',
        'sort_order',
    ];

    protected $casts = [
        'is_required' => 'boolean',
    ];
}
