<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OrderIncentiveAttribution extends Model
{
    protected $guarded = ['id'];

    public static function record(int $organization, string $kind, int $order, string $source, ?int $staff, int $actor): void
    {
        static::upsert([[
            'organization_id' => $organization,
            'order_type' => $kind,
            'order_id' => $order,
            'source' => $source,
            'sales_user_id' => $staff,
            'recorded_by_user_id' => $actor,
        ]], ['organization_id', 'order_type', 'order_id'], ['source', 'sales_user_id', 'recorded_by_user_id']);
    }
}
