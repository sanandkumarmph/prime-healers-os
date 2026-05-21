<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DashboardWidget extends Model
{
    protected $fillable = [
        'widget_key',
        'name',
        'description',
        'category',
        'sensitivity',
        'default_enabled',
        'sort_order',
        'status',
    ];

    protected $casts = [
        'default_enabled' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function roleAssignments()
    {
        return $this->hasMany(RoleDashboardWidget::class);
    }
}
