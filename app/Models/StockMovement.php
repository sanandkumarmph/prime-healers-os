<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use LogicException;

class StockMovement extends Model
{
    public const TYPE_PURCHASE = 'purchase';
    public const TYPE_OPENING = 'opening';
    public const TYPE_IMPORT = 'import';
    public const TYPE_ADD_STOCK = 'add_stock';
    public const TYPE_SALE = 'sale';
    public const TYPE_DELIVERY = 'delivery';
    public const TYPE_RENTAL_OUT = 'rental_out';
    public const TYPE_PICKUP_RETURN = 'pickup_return';
    public const TYPE_RETURN_VERIFICATION = 'return_verification';
    public const TYPE_REPAIR = 'repair';
    public const TYPE_SCRAP = 'scrap';
    public const TYPE_WAREHOUSE_TRANSFER = 'warehouse_transfer';
    public const TYPE_MANUAL_ADJUSTMENT = 'manual_adjustment';
    public const TYPE_CORRECTION_ADD = 'correction_add';
    public const TYPE_CORRECTION_REMOVE = 'correction_remove';
    public const TYPE_CORRECTION_TRANSFER = 'correction_transfer';

    public const MOVEMENT_TYPES = [
        self::TYPE_PURCHASE,
        self::TYPE_OPENING,
        self::TYPE_IMPORT,
        self::TYPE_ADD_STOCK,
        self::TYPE_SALE,
        self::TYPE_DELIVERY,
        self::TYPE_RENTAL_OUT,
        self::TYPE_PICKUP_RETURN,
        self::TYPE_RETURN_VERIFICATION,
        self::TYPE_REPAIR,
        self::TYPE_SCRAP,
        self::TYPE_WAREHOUSE_TRANSFER,
        self::TYPE_MANUAL_ADJUSTMENT,
        self::TYPE_CORRECTION_ADD,
        self::TYPE_CORRECTION_REMOVE,
        self::TYPE_CORRECTION_TRANSFER,
    ];

    protected $fillable = [
        'organization_id',
        'product_id',
        'asset_id',
        'movement_type',
        'quantity',
        'from_status',
        'to_status',
        'from_warehouse_id',
        'to_warehouse_id',
        'rental_id',
        'sale_id',
        'delivery_id',
        'invoice_id',
        'payment_id',
        'performed_by_user_id',
        'movement_at',
        'notes',
        'checksum',
    ];

    protected $casts = [
        'movement_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Stock movements are immutable and cannot be edited.');
        });

        static::deleting(function (): void {
            throw new LogicException('Stock movements are immutable and cannot be deleted.');
        });
    }

    public function scopeForOrganization(Builder $query, int $organizationId): Builder
    {
        return $query->where('organization_id', $organizationId);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function fromWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'from_warehouse_id');
    }

    public function toWarehouse()
    {
        return $this->belongsTo(Warehouse::class, 'to_warehouse_id');
    }

    public function rental()
    {
        return $this->belongsTo(Rental::class);
    }

    public function sale()
    {
        return $this->belongsTo(Sale::class);
    }

    public function delivery()
    {
        return $this->belongsTo(Delivery::class);
    }

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function payment()
    {
        return $this->belongsTo(Payment::class);
    }

    public function performedBy()
    {
        return $this->belongsTo(User::class, 'performed_by_user_id');
    }

    public static function checksumFor(array $attributes): string
    {
        $movementAt = $attributes['movement_at'] ?? null;

        if ($movementAt instanceof \DateTimeInterface) {
            $movementAt = Carbon::instance($movementAt)->format('Y-m-d H:i:s.u');
        } elseif ($movementAt !== null) {
            $movementAt = (string) $movementAt;
        }

        $payload = [
            'organization_id' => (int) ($attributes['organization_id'] ?? 0),
            'product_id' => $attributes['product_id'] ?? null,
            'asset_id' => $attributes['asset_id'] ?? null,
            'movement_type' => (string) ($attributes['movement_type'] ?? ''),
            'quantity' => (int) ($attributes['quantity'] ?? 0),
            'from_status' => $attributes['from_status'] ?? null,
            'to_status' => $attributes['to_status'] ?? null,
            'from_warehouse_id' => $attributes['from_warehouse_id'] ?? null,
            'to_warehouse_id' => $attributes['to_warehouse_id'] ?? null,
            'rental_id' => $attributes['rental_id'] ?? null,
            'sale_id' => $attributes['sale_id'] ?? null,
            'delivery_id' => $attributes['delivery_id'] ?? null,
            'invoice_id' => $attributes['invoice_id'] ?? null,
            'payment_id' => $attributes['payment_id'] ?? null,
            'performed_by_user_id' => $attributes['performed_by_user_id'] ?? null,
            'movement_at' => $movementAt,
            'notes' => $attributes['notes'] ?? null,
        ];

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
