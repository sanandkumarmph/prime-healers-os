<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;

/** Presentation only: never infer an action time from a business date. */
final class ActionTimestamp
{
    public static function ist(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->setTimezone('Asia/Kolkata');
        }

        if (!is_string($value) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::(\d{2})(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:\d{2})?$/D', $value, $parts)) {
            return null;
        }

        if (!checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            || (int) $parts[4] > 23 || (int) $parts[5] > 59 || (int) ($parts[6] ?? 0) > 59) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, 'Asia/Kolkata')->setTimezone('Asia/Kolkata');
        } catch (\Throwable) {
            return null;
        }
    }

    public static function label(mixed $value): string
    {
        return self::ist($value)?->format('d M Y, h:i:s A \I\S\T') ?? 'Time not recorded';
    }
}
