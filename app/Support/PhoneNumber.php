<?php

namespace App\Support;

class PhoneNumber
{
    public const INDIA_CODE = '+91';
    public const DEFAULT_CODE = self::INDIA_CODE;

    private const LOCAL_LENGTH_RULES = [
        '+91' => [10],
        '+1' => [10],
        '+44' => [10],
        '+61' => [9],
        '+65' => [8],
        '+971' => [9],
        '+966' => [9],
        '+974' => [8],
        '+968' => [8],
        '+965' => [8],
        '+973' => [8],
        '+60' => [9, 10],
    ];

    public static function countryCodeOptions(): array
    {
        return [
            '+91' => 'India (+91)',
            '+1' => 'USA / Canada (+1)',
            '+7' => 'Russia / Kazakhstan (+7)',
            '+20' => 'Egypt (+20)',
            '+27' => 'South Africa (+27)',
            '+31' => 'Netherlands (+31)',
            '+32' => 'Belgium (+32)',
            '+33' => 'France (+33)',
            '+34' => 'Spain (+34)',
            '+39' => 'Italy (+39)',
            '+41' => 'Switzerland (+41)',
            '+44' => 'UK (+44)',
            '+49' => 'Germany (+49)',
            '+52' => 'Mexico (+52)',
            '+54' => 'Argentina (+54)',
            '+55' => 'Brazil (+55)',
            '+61' => 'Australia (+61)',
            '+62' => 'Indonesia (+62)',
            '+63' => 'Philippines (+63)',
            '+64' => 'New Zealand (+64)',
            '+65' => 'Singapore (+65)',
            '+66' => 'Thailand (+66)',
            '+81' => 'Japan (+81)',
            '+82' => 'South Korea (+82)',
            '+84' => 'Vietnam (+84)',
            '+86' => 'China (+86)',
            '+90' => 'Turkey (+90)',
            '+92' => 'Pakistan (+92)',
            '+94' => 'Sri Lanka (+94)',
            '+95' => 'Myanmar (+95)',
            '+98' => 'Iran (+98)',
            '+212' => 'Morocco (+212)',
            '+213' => 'Algeria (+213)',
            '+234' => 'Nigeria (+234)',
            '+254' => 'Kenya (+254)',
            '+255' => 'Tanzania (+255)',
            '+256' => 'Uganda (+256)',
            '+260' => 'Zambia (+260)',
            '+263' => 'Zimbabwe (+263)',
            '+971' => 'UAE (+971)',
            '+966' => 'Saudi Arabia (+966)',
            '+974' => 'Qatar (+974)',
            '+968' => 'Oman (+968)',
            '+965' => 'Kuwait (+965)',
            '+973' => 'Bahrain (+973)',
            '+60' => 'Malaysia (+60)',
            '+880' => 'Bangladesh (+880)',
            '+960' => 'Maldives (+960)',
            '+977' => 'Nepal (+977)',
            '+992' => 'Tajikistan (+992)',
        ];
    }

    public static function validationRules(bool $required = false): array
    {
        return [
            $required ? 'required' : 'nullable',
            'string',
            'max:20',
            function (string $attribute, mixed $value, \Closure $fail): void {
                $value = trim((string) $value);

                if ($value === '') {
                    return;
                }

                $countryCode = request()->input($attribute . '_country_code', static::DEFAULT_CODE);

                if (!static::isValid($value, $countryCode)) {
                    $fail('Enter a valid phone number with the selected country code.');
                }
            },
        ];
    }

    public static function isValid(?string $value, ?string $defaultCode = null): bool
    {
        $normalized = static::normalize($value, $defaultCode);

        if ($normalized === null) {
            return false;
        }

        $parts = static::split($normalized);
        $localDigits = preg_replace('/\D+/', '', $parts['local']);

        if ($localDigits === '') {
            return false;
        }

        $allowedLengths = static::LOCAL_LENGTH_RULES[$parts['code']] ?? null;

        if ($allowedLengths !== null) {
            return in_array(strlen($localDigits), $allowedLengths, true);
        }

        return strlen($localDigits) >= 6 && strlen($localDigits) <= 15;
    }

    public static function normalize(?string $value, ?string $defaultCode = null): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        $providedDefaultCode = $defaultCode !== null;
        $defaultCode = static::sanitizeCountryCode($defaultCode ?: static::DEFAULT_CODE);
        $digits = preg_replace('/\D+/', '', $value);

        if ($digits === '') {
            return null;
        }

        $inputHasPlus = str_starts_with($value, '+');
        $matchedCode = static::matchingCodeFromDigits($digits);

        if ($inputHasPlus && $matchedCode !== null) {
            $local = substr($digits, strlen(ltrim($matchedCode, '+')));

            return $matchedCode . $local;
        }

        if (
            !$providedDefaultCode
            && strlen($digits) >= 8
            && strlen($digits) <= 15
            && $matchedCode !== null
            && strlen($digits) > 10
        ) {
            $local = substr($digits, strlen(ltrim($matchedCode, '+')));

            if ($local !== '') {
                return $matchedCode . $local;
            }
        }

        if (strlen($digits) > 10 && str_starts_with($digits, '0')) {
            $digits = ltrim($digits, '0');
        }

        return $defaultCode . ltrim($digits, '0');
    }

    public static function local(?string $value): string
    {
        return static::split($value)['local'];
    }

    public static function countryCode(?string $value): string
    {
        return static::split($value)['code'];
    }

    public static function normalizeFields(array $validated, array $fields): array
    {
        foreach ($fields as $field) {
            if (!array_key_exists($field, $validated)) {
                continue;
            }

            $countryCodeField = $field . '_country_code';
            $validated[$field] = static::normalize(
                $validated[$field],
                $validated[$countryCodeField] ?? static::DEFAULT_CODE
            );
        }

        return $validated;
    }

    public static function split(?string $value): array
    {
        $normalized = static::normalize($value);

        if ($normalized === null) {
            return [
                'code' => static::DEFAULT_CODE,
                'local' => '',
            ];
        }

        $digits = preg_replace('/\D+/', '', $normalized);
        $code = static::matchingCodeFromDigits($digits) ?? static::DEFAULT_CODE;
        $local = substr($digits, strlen(ltrim($code, '+')));

        return [
            'code' => $code,
            'local' => $local,
        ];
    }

    private static function sanitizeCountryCode(string $code): string
    {
        $digits = preg_replace('/\D+/', '', $code);

        return '+' . ($digits ?: ltrim(static::DEFAULT_CODE, '+'));
    }

    private static function matchingCodeFromDigits(string $digits): ?string
    {
        foreach (array_keys(static::countryCodeOptions()) as $code) {
            $numericCode = ltrim($code, '+');

            if (str_starts_with($digits, $numericCode)) {
                return $code;
            }
        }

        return null;
    }
}
