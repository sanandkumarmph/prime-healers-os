<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RoleDashboardWidget extends Model
{
    protected $fillable = [
        'organization_id',
        'role_id',
        'dashboard_widget_id',
        'is_enabled',
        'sort_order',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function widget()
    {
        return $this->belongsTo(DashboardWidget::class, 'dashboard_widget_id');
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }
}
