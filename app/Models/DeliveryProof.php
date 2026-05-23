<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class DeliveryProof extends Model
{
    public const STAGE_DELIVERY = 'delivery';
    public const STAGE_PICKUP = 'pickup';

    public const MOMENT_START = 'start';
    public const MOMENT_COMPLETE = 'complete';

    public const TYPE_DELIVERED_DEVICE = 'delivered_device';
    public const TYPE_PREMISES = 'premises';
    public const TYPE_PICKED_UP_DEVICE = 'picked_up_device';
    public const TYPE_DAMAGE = 'damage';
    public const TYPE_SIGNATURE = 'signature';
    public const TYPE_ACKNOWLEDGEMENT_REASON = 'acknowledgement_reason';
    public const TYPE_COLLECTION = 'collection';
    public const TYPE_LOCATION = 'location';

    protected $fillable = [
        'organization_id',
        'delivery_id',
        'rental_id',
        'workflow_stage',
        'capture_moment',
        'proof_type',
        'file_path',
        'original_name',
        'mime_type',
        'size_bytes',
        'latitude',
        'longitude',
        'accuracy',
        'captured_at',
        'acknowledgement_text',
        'notes',
        'meta',
        'created_by_user_id',
    ];

    protected $casts = [
        'latitude' => 'float',
        'longitude' => 'float',
        'accuracy' => 'float',
        'captured_at' => 'datetime',
        'meta' => 'array',
    ];

    public static function acknowledgementFor(string $stage, bool $requiresReturnAcknowledgement = true): string
    {
        if ($stage === self::STAGE_PICKUP) {
            return 'I confirm that the product(s) have been picked up. Any visible damages or missing accessories noted at pickup have been reviewed and acknowledged.';
        }

        return $requiresReturnAcknowledgement
            ? 'I confirm that the product(s) have been received in good condition and working order. I agree to return the product(s) in the same condition, subject to normal use.'
            : 'I confirm that the product(s) have been received in good condition and working order.';
    }

    public static function labelForType(string $type): string
    {
        return match ($type) {
            self::TYPE_DELIVERED_DEVICE => 'Delivered Device Photo',
            self::TYPE_PREMISES => 'Premises Photo',
            self::TYPE_PICKED_UP_DEVICE => 'Picked-up Device Photo',
            self::TYPE_DAMAGE => 'Damage Photo',
            self::TYPE_SIGNATURE => 'Customer Signature',
            self::TYPE_ACKNOWLEDGEMENT_REASON => 'Acknowledgement Reason',
            self::TYPE_COLLECTION => 'Collection Proof',
            self::TYPE_LOCATION => 'Location Capture',
            default => ucwords(str_replace('_', ' ', $type)),
        };
    }

    public static function historyLabelFor(self $proof): string
    {
        $meta = $proof->meta ?? [];
        $isExtra = (bool) ($meta['is_extra'] ?? false);

        return match ($proof->proof_type) {
            self::TYPE_LOCATION => 'Location Proof',
            self::TYPE_PREMISES => $proof->workflow_stage === self::STAGE_PICKUP ? 'Accessories Photo' : 'Delivery Photo',
            self::TYPE_DELIVERED_DEVICE => $isExtra ? 'Extra Photo' : 'Product Photo',
            self::TYPE_PICKED_UP_DEVICE => $isExtra ? 'Extra Photo' : 'Product Photo',
            self::TYPE_DAMAGE => 'Condition Photo',
            self::TYPE_SIGNATURE => 'Customer Signature',
            self::TYPE_ACKNOWLEDGEMENT_REASON => 'Customer Consent Reason',
            self::TYPE_COLLECTION => 'Payment Proof',
            default => self::labelForType($proof->proof_type),
        };
    }

    public function delivery()
    {
        return $this->belongsTo(Delivery::class);
    }

    public function rental()
    {
        return $this->belongsTo(Rental::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function hasFile(): bool
    {
        return filled($this->file_path);
    }

    public function existsOnDisk(): bool
    {
        return $this->hasFile() && Storage::disk(config('proof.storage_disk', 'local'))->exists($this->file_path);
    }
}
