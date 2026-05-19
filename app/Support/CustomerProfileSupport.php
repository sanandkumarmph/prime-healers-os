<?php

namespace App\Support;

use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;

class CustomerProfileSupport
{
    public static function indianStates(): array
    {
        return [
            'Andaman and Nicobar Islands',
            'Andhra Pradesh',
            'Arunachal Pradesh',
            'Assam',
            'Bihar',
            'Chandigarh',
            'Chhattisgarh',
            'Dadra and Nagar Haveli and Daman and Diu',
            'Delhi',
            'Goa',
            'Gujarat',
            'Haryana',
            'Himachal Pradesh',
            'Jammu and Kashmir',
            'Jharkhand',
            'Karnataka',
            'Kerala',
            'Ladakh',
            'Lakshadweep',
            'Madhya Pradesh',
            'Maharashtra',
            'Manipur',
            'Meghalaya',
            'Mizoram',
            'Nagaland',
            'Odisha',
            'Puducherry',
            'Punjab',
            'Rajasthan',
            'Sikkim',
            'Tamil Nadu',
            'Telangana',
            'Tripura',
            'Uttar Pradesh',
            'Uttarakhand',
            'West Bengal',
        ];
    }

    public static function validationRules(
        bool $hasWhatsappNumberColumn = true,
        bool $allowFileUpload = true,
        ?int $organizationId = null,
        ?int $ignoreCustomerId = null
    ): array
    {
        $phoneRules = [
            ...PhoneNumber::validationRules(),
        ];
        $whatsAppRules = [
            ...PhoneNumber::validationRules(),
        ];
        $emailRules = ['nullable', 'email', 'max:255'];

        if ($organizationId !== null) {
            $emailRules[] = Rule::unique('customers', 'email')
                ->where(fn ($query) => $query->where('organization_id', $organizationId))
                ->ignore($ignoreCustomerId);

            $phoneRules[] = function (string $attribute, mixed $value, \Closure $fail) use ($organizationId, $ignoreCustomerId): void {
                $normalized = PhoneNumber::normalize(
                    (string) $value,
                    request()->input($attribute . '_country_code')
                );

                if ($normalized === null) {
                    return;
                }

                $exists = DB::table('customers')
                    ->where('organization_id', $organizationId)
                    ->where('phone', $normalized)
                    ->when($ignoreCustomerId, fn ($query) => $query->where('id', '!=', $ignoreCustomerId))
                    ->exists();

                if ($exists) {
                    $fail('This mobile number is already in use.');
                }
            };

            $whatsAppRules[] = function (string $attribute, mixed $value, \Closure $fail) use ($organizationId, $ignoreCustomerId): void {
                $normalized = PhoneNumber::normalize(
                    (string) $value,
                    request()->input($attribute . '_country_code')
                );

                if ($normalized === null) {
                    return;
                }

                $exists = DB::table('customers')
                    ->where('organization_id', $organizationId)
                    ->where('whatsapp_number', $normalized)
                    ->when($ignoreCustomerId, fn ($query) => $query->where('id', '!=', $ignoreCustomerId))
                    ->exists();

                if ($exists) {
                    $fail('This WhatsApp number is already in use.');
                }
            };
        }

        $rules = [
            'customer_type' => 'required|string|in:Business,Individual,business,individual',
            'salutation' => 'nullable|string|max:50',
            'first_name' => 'nullable|string|max:255',
            'last_name' => 'nullable|string|max:255',
            'company_name' => 'nullable|string|max:255',
            'contact_name' => 'nullable|string|max:255',
            'name' => 'nullable|string|max:255',
            'phone' => $phoneRules,
            'email' => $emailRules,
            'gst_registered' => 'nullable|boolean',
            'gst_treatment' => 'nullable|string|max:255',
            'place_of_supply' => 'nullable|string|max:255',
            'gst_number' => 'nullable|string|max:255|required_if:gst_registered,1',
            'legal_name' => 'nullable|string|max:255|required_if:gst_registered,1',
            'address' => 'nullable|string',
            'billing_address' => 'nullable|string|required_if:gst_registered,1',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'pincode' => 'nullable|string|max:20',
            'patient_name' => 'nullable|string|max:255',
            'id_proof_type' => 'nullable|string|max:100',
            'id_proof_number' => 'nullable|string|max:100',
            'id_proof_file' => $allowFileUpload ? 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120' : 'nullable',
            'map_location_text' => 'nullable|string|max:255',
            'map_location_url' => 'nullable|url|max:2048',
            'notes' => 'nullable|string',
        ];

        if ($hasWhatsappNumberColumn) {
            $rules['whatsapp_number'] = $whatsAppRules;
        }

        $rules['phone_country_code'] = 'nullable|string|max:8';

        if ($hasWhatsappNumberColumn) {
            $rules['whatsapp_number_country_code'] = 'nullable|string|max:8';
        }

        return $rules;
    }

    public static function normalizeCustomerType(?string $customerType): string
    {
        return strtolower(trim((string) $customerType)) === 'business'
            ? 'Business'
            : 'Individual';
    }

    public static function validateIdentity(array $validated, bool $quickMode = false): array
    {
        $customerType = static::normalizeCustomerType($validated['customer_type'] ?? null);
        $validated['customer_type'] = $customerType;

        if ($customerType === 'Business') {
            if (trim((string) ($validated['company_name'] ?? '')) === '') {
                throw ValidationException::withMessages([
                    'company_name' => ['Company name is required for business customers.'],
                ]);
            }

            if (trim((string) ($validated['contact_name'] ?? '')) === '' && !$quickMode) {
                throw ValidationException::withMessages([
                    'contact_name' => ['Contact name is required for business customers.'],
                ]);
            }
        }

        if ($customerType === 'Individual' && trim((string) ($validated['first_name'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'first_name' => ['First name is required for individual customers.'],
            ]);
        }

        if ($quickMode && trim((string) ($validated['phone'] ?? '')) === '') {
            throw ValidationException::withMessages([
                'phone' => ['Phone is required for quick customer creation.'],
            ]);
        }

        $displayName = static::resolveDisplayName($validated);

        if ($displayName === null) {
            throw ValidationException::withMessages([
                'name' => ['Please provide enough customer details to build a display name.'],
            ]);
        }

        $validated['name'] = $displayName;

        return $validated;
    }

    public static function resolveDisplayName(array $validated): ?string
    {
        $customerType = static::normalizeCustomerType($validated['customer_type'] ?? null);

        if ($customerType === 'Business') {
            $companyName = trim((string) ($validated['company_name'] ?? ''));

            return $companyName !== '' ? $companyName : null;
        }

        $nameParts = array_filter([
            trim((string) ($validated['salutation'] ?? '')) ?: null,
            trim((string) ($validated['first_name'] ?? '')) ?: null,
            trim((string) ($validated['last_name'] ?? '')) ?: null,
        ]);

        $displayName = trim(implode(' ', $nameParts));

        if ($displayName !== '') {
            return $displayName;
        }

        $fallbackName = trim((string) ($validated['name'] ?? ''));

        return $fallbackName !== '' ? $fallbackName : null;
    }

    public static function normalizeStateAndSupply(?string $state, ?string $placeOfSupply): array
    {
        $customerState = trim((string) $state) ?: null;
        $customerPlaceOfSupply = trim((string) $placeOfSupply) ?: null;

        if (!empty($customerState) && empty($customerPlaceOfSupply)) {
            $customerPlaceOfSupply = $customerState;
        }

        if (!empty($customerPlaceOfSupply) && empty($customerState)) {
            $customerState = $customerPlaceOfSupply;
        }

        return [$customerState, $customerPlaceOfSupply];
    }

    public static function payload(array $validated, array $extra = []): array
    {
        [$customerState, $customerPlaceOfSupply] = static::normalizeStateAndSupply(
            $validated['state'] ?? null,
            $validated['place_of_supply'] ?? null
        );

        $gstRegistered = (bool) ($validated['gst_registered'] ?? false);

        return array_merge([
            'name' => $validated['name'],
            'customer_type' => static::normalizeCustomerType($validated['customer_type'] ?? null),
            'salutation' => $validated['salutation'] ?? null,
            'first_name' => $validated['first_name'] ?? null,
            'last_name' => $validated['last_name'] ?? null,
            'company_name' => $validated['company_name'] ?? null,
            'contact_name' => $validated['contact_name'] ?? null,
            'phone' => PhoneNumber::normalize($validated['phone'] ?? null),
            'whatsapp_number' => PhoneNumber::normalize($validated['whatsapp_number'] ?? null),
            'email' => $validated['email'] ?? null,
            'gst_registered' => $gstRegistered,
            'gst_treatment' => $validated['gst_treatment'] ?? null,
            'place_of_supply' => $customerPlaceOfSupply,
            'gst_number' => $gstRegistered ? ($validated['gst_number'] ?? null) : null,
            'legal_name' => $gstRegistered ? ($validated['legal_name'] ?? null) : null,
            'address' => $validated['address'] ?? null,
            'billing_address' => $gstRegistered
                ? ($validated['billing_address'] ?? null)
                : null,
            'city' => $validated['city'] ?? null,
            'state' => $customerState,
            'pincode' => $validated['pincode'] ?? null,
            'patient_name' => $validated['patient_name'] ?? null,
            'id_proof_type' => $validated['id_proof_type'] ?? null,
            'id_proof_number' => $validated['id_proof_number'] ?? null,
            'id_proof_file_path' => $validated['id_proof_file_path'] ?? null,
            'id_proof_original_name' => $validated['id_proof_original_name'] ?? null,
            'map_location_text' => $validated['map_location_text'] ?? null,
            'map_location_url' => $validated['map_location_url'] ?? null,
            'notes' => $validated['notes'] ?? null,
        ], $extra);
    }
}
