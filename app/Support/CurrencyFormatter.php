<?php

namespace App\Support;

class CurrencyFormatter
{
    public static function symbol(): string
    {
        return "\u{20B9}";
    }

    public static function format(float|int|string|null $amount, int $decimals = 2): string
    {
        return static::symbol() . number_format((float) $amount, $decimals);
    }
}
