<?php

namespace App\Models;

use App\Support\CustomerProfileSupport;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class Customer extends Model
{
    protected static ?bool $hasWhatsappNumberColumn = null;
    protected static ?bool $hasStatusColumn = null;
    protected static ?bool $hasCustomerCodeColumn = null;
    protected static ?bool $hasSalutationColumn = null;
    protected static ?bool $hasContactNameColumn = null;
    protected static ?bool $hasMapLocationTextColumn = null;
    protected static ?bool $hasMapLocationUrlColumn = null;
    protected static ?bool $hasIdProofFilePathColumn = null;
    protected static ?bool $hasIdProofOriginalNameColumn = null;
    protected static ?array $tableColumns = null;

    protected $fillable = [
        'name',
        'customer_type',
        'salutation',
        'first_name',
        'last_name',
        'company_name',
        'contact_name',
        'phone',
        'whatsapp_number',
        'email',
        'gst_registered',
        'gst_treatment',
        'place_of_supply',
        'gst_number',
        'legal_name',
        'address',
        'billing_address',
        'city',
        'state',
        'pincode',
        'map_location_text',
        'map_location_url',
        'patient_name',
        'id_proof_type',
        'id_proof_number',
        'id_proof_file_path',
        'id_proof_original_name',
        'notes',
        'organization_id',
    ];

    protected $casts = [
        'gst_registered' => 'boolean',
    ];

    public static function hasWhatsappNumberColumn(): bool
    {
        return static::$hasWhatsappNumberColumn ??= static::hasCachedColumn('whatsapp_number');
    }

    public static function hasStatusColumn(): bool
    {
        return static::$hasStatusColumn ??= static::hasCachedColumn('status');
    }

    public static function hasCustomerCodeColumn(): bool
    {
        return static::$hasCustomerCodeColumn ??= static::hasCachedColumn('customer_code');
    }

    public static function hasSalutationColumn(): bool
    {
        return static::$hasSalutationColumn ??= static::hasCachedColumn('salutation');
    }

    public static function hasContactNameColumn(): bool
    {
        return static::$hasContactNameColumn ??= static::hasCachedColumn('contact_name');
    }

    public static function hasMapLocationTextColumn(): bool
    {
        return static::$hasMapLocationTextColumn ??= static::hasCachedColumn('map_location_text');
    }

    public static function hasMapLocationUrlColumn(): bool
    {
        return static::$hasMapLocationUrlColumn ??= static::hasCachedColumn('map_location_url');
    }

    public static function hasIdProofFilePathColumn(): bool
    {
        return static::$hasIdProofFilePathColumn ??= static::hasCachedColumn('id_proof_file_path');
    }

    public static function hasIdProofOriginalNameColumn(): bool
    {
        return static::$hasIdProofOriginalNameColumn ??= static::hasCachedColumn('id_proof_original_name');
    }

    public static function relationSelectColumns(array $extra = []): array
    {
        $columns = ['id', 'name', 'phone'];

        $optionalColumns = [
            'whatsapp_number' => self::hasWhatsappNumberColumn(),
            'email' => self::hasCachedColumn('email'),
            'address' => self::hasCachedColumn('address'),
            'city' => self::hasCachedColumn('city'),
            'state' => self::hasCachedColumn('state'),
            'pincode' => self::hasCachedColumn('pincode'),
            'map_location_text' => self::hasMapLocationTextColumn(),
            'map_location_url' => self::hasMapLocationUrlColumn(),
        ];

        foreach ($optionalColumns as $column => $available) {
            if ($available) {
                $columns[] = $column;
            }
        }

        foreach ($extra as $column) {
            if ($column !== '' && !in_array($column, $columns, true) && self::hasCachedColumn($column)) {
                $columns[] = $column;
            }
        }

        return $columns;
    }

    protected static function hasCachedColumn(string $column): bool
    {
        return in_array($column, static::tableColumns(), true);
    }

    protected static function tableColumns(): array
    {
        return static::$tableColumns ??= Schema::getColumnListing('customers');
    }

    public static function indianStates(): array
    {
        return CustomerProfileSupport::indianStates();
    }

    public function normalizedCustomerType(): string
    {
        return CustomerProfileSupport::normalizeCustomerType($this->customer_type);
    }

    public function isBusiness(): bool
    {
        return $this->normalizedCustomerType() === 'Business';
    }

    public function isIndividual(): bool
    {
        return $this->normalizedCustomerType() === 'Individual';
    }

    public function displayName(): string
    {
        if (!empty($this->name)) {
            return $this->name;
        }

        return CustomerProfileSupport::resolveDisplayName($this->toArray()) ?? 'Customer';
    }

    public function contactPersonName(): ?string
    {
        if ($this->isBusiness()) {
            return $this->contact_name ?: null;
        }

        return trim(collect([$this->first_name, $this->last_name])->filter()->implode(' ')) ?: null;
    }

    public function openMapUrl(): ?string
    {
        if (self::hasMapLocationUrlColumn() && !empty($this->map_location_url)) {
            return $this->map_location_url;
        }

        return null;
    }

    public function preferredWhatsAppNumber(): ?string
    {
        if (self::hasWhatsappNumberColumn() && !empty($this->whatsapp_number)) {
            return $this->whatsapp_number;
        }

        return $this->phone ?: null;
    }

    public function hasPrivateIdProof(): bool
    {
        return self::hasIdProofFilePathColumn()
            && filled($this->id_proof_file_path)
            && Storage::disk('local')->exists($this->id_proof_file_path);
    }

    public function rentals(): HasMany
    {
        return $this->hasMany(Rental::class);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function sales(): HasMany
    {
        return $this->hasMany(Sale::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function ledgerEntries(): Collection
    {
        $invoiceEntries = $this->invoices->map(function (Invoice $invoice) {
            return [
                'type' => 'invoice',
                'date' => $invoice->invoice_date ?? $invoice->created_at,
                'reference' => $invoice->invoice_number,
                'debit' => (float) $invoice->total_amount,
                'credit' => 0.0,
                'balance_effect' => (float) $invoice->total_amount,
                'model' => $invoice,
            ];
        });

        $paymentEntries = $this->payments->map(function (Payment $payment) {
            return [
                'type' => 'payment',
                'date' => $payment->payment_date ?? $payment->created_at,
                'reference' => $payment->invoice?->invoice_number ?: ('PAY-' . $payment->id),
                'debit' => 0.0,
                'credit' => (float) $payment->amount,
                'balance_effect' => -(float) $payment->amount,
                'model' => $payment,
            ];
        });

        $runningBalance = 0.0;

        return $invoiceEntries
            ->merge($paymentEntries)
            ->sortBy(fn ($entry) => optional($entry['date'])->timestamp ?? 0)
            ->values()
            ->map(function (array $entry) use (&$runningBalance) {
                $runningBalance += $entry['balance_effect'];
                $entry['running_balance'] = $runningBalance;

                return $entry;
            });
    }
}
