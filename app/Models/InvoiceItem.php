<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class InvoiceItem extends Model
{
    protected $fillable = [
        'invoice_id',
        'product_id',
        'source_type',
        'source_id',
        'description',
        'hsn_sac_code',
        'quantity',
        'unit',
        'days',
        'rate',
        'discount_amount',
        'taxable_amount',
        'tax_percentage',
        'tax_type',
        'cgst_rate',
        'sgst_rate',
        'igst_rate',
        'cgst_amount',
        'sgst_amount',
        'igst_amount',
        'line_total',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'days' => 'decimal:2',
        'rate' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'taxable_amount' => 'decimal:2',
        'tax_percentage' => 'decimal:2',
        'cgst_rate' => 'decimal:2',
        'sgst_rate' => 'decimal:2',
        'igst_rate' => 'decimal:2',
        'cgst_amount' => 'decimal:2',
        'sgst_amount' => 'decimal:2',
        'igst_amount' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    public function invoice()
    {
        return $this->belongsTo(Invoice::class);
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function rental()
    {
        return $this->belongsTo(Rental::class, 'source_id');
    }

    public function getDisplayDescriptionAttribute(): string
    {
        $description = trim((string) ($this->description ?? ''));

        if (
            $description !== ''
            && preg_match('/^Rental renewal charge for rental #\d+$/i', $description)
            && !empty($this->product?->name)
        ) {
            return $this->product->name;
        }

        if ($description !== '') {
            return $description;
        }

        return (string) ($this->product?->name ?? 'Item');
    }

    public function syncLegacyRenewalDescription(): bool
    {
        $description = trim((string) ($this->description ?? ''));

        if (
            $description !== ''
            && preg_match('/^Rental renewal charge for rental #\d+$/i', $description)
            && !empty($this->product?->name)
        ) {
            $this->forceFill([
                'description' => Str::limit($this->product->name, 255, ''),
            ])->save();

            return true;
        }

        return false;
    }
}
