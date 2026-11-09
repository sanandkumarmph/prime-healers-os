<?php

namespace App\Support;

use App\Models\Product;

/** Editable suggestions for new transactions; never rewrites a saved price or quotation. */
final class CommercialDefaults
{
    public const DEPOSIT = 2000;
    public const SMALL_TRANSPORT = 800;
    public const HEAVY_TRANSPORT = 2500;

    public static function depositFor(string $kind): int
    {
        return $kind === 'rental' ? self::DEPOSIT : 0;
    }

    public static function isHeavy(Product $product): bool
    {
        if ($product->transport_class === 'heavy') return true;
        if ($product->transport_class === 'small') return false;

        $name = mb_strtolower(trim((string) $product->name));
        $category = mb_strtolower(trim((string) $product->category));
        if ($product->relationLoaded('categoryMaster')) {
            $category = mb_strtolower(trim((string) ($product->categoryMaster?->name ?: $category)));
        }
        $bedNamePattern = '\b(?:hospital|patient|medical|icu)[\s_-]+beds?\b|\b(?:electric|manual|motorised|motorized|fowler|semi[\s-]?fowler)\b.*\bbeds?\b|\bbeds?\b.*\b(?:electric|manual|motorised|motorized|fowler)\b';
        // A bed supplied with accessories remains heavy; an accessory for a bed does not.
        if (preg_match('/\b(mattress(?:es)?|sheets?|rails?|bedpan|bedside|overbed|covers?|tables?|pillows?)\b/u', $name, $accessory, PREG_OFFSET_CAPTURE)) {
            $prefix = substr($name, 0, $accessory[0][1]);
            return (bool) preg_match('/(?:'.$bedNamePattern.').*\b(?:with|including|includes|incl)\b/u', $prefix);
        }
        if (preg_match('/(?:'.$bedNamePattern.')/u', $name)) return true;
        return in_array($category, ['bed', 'beds', 'hospital bed', 'hospital beds', 'patient bed', 'patient beds', 'medical bed', 'medical beds'], true);
    }

    public static function gstRate(Product $product): float
    {
        $split = round((float) $product->cgst_rate + (float) $product->sgst_rate, 2);
        return match ($product->gst_tax_type) {
            Product::GST_TAX_TYPE_CGST_SGST => $split,
            Product::GST_TAX_TYPE_IGST => round((float) $product->igst_rate, 2),
            Product::GST_TAX_TYPE_BOTH => $split > 0 ? $split : round((float) $product->igst_rate, 2),
            default => 0.0,
        };
    }

    public static function productData(Product $product): array
    {
        return [
            'rental' => (float) ($product->rental_price ?: $product->rental_price_30_days ?: 0),
            'daily' => (float) $product->price_per_day,
            'days15' => (float) $product->rental_price_15_days,
            'days30' => (float) $product->rental_price_30_days,
            'months3' => (float) $product->rental_price_3_months,
            'sale' => (float) $product->sale_price,
            'heavy' => self::isHeavy($product),
            'tax_rate' => self::gstRate($product),
            'tax_mode' => 'inclusive',
        ];
    }

    /** Lines contain a Product or its verified catalogue metadata, plus quantity. */
    public static function transportFor(iterable $lines): int
    {
        $units = 0;
        foreach ($lines as $line) {
            $product = $line['product'] ?? null;
            if (!$product instanceof Product && empty($line['product_id']) && !array_key_exists('heavy', $line)) continue;
            $quantity = max(0, (int) ($line['quantity'] ?? 0));
            if ($quantity === 0) continue;
            if ($product instanceof Product ? self::isHeavy($product) : (bool) ($line['heavy'] ?? false)) return self::HEAVY_TRANSPORT;
            $units += $quantity;
            if ($units > 3) return self::HEAVY_TRANSPORT;
        }
        return self::SMALL_TRANSPORT;
    }
}
