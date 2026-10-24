<?php

namespace App\Support;

use App\Models\Vendor;
use Illuminate\Validation\Rule;

class VendorRules
{
    public const GST = '/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/';

    public const PAN = '/^[A-Z]{5}[0-9]{4}[A-Z]$/';

    public const IFSC = '/^[A-Z]{4}0[A-Z0-9]{6}$/';

    public const PHONE = '/^[0-9]{10,15}$/';

    public const PINCODE = '/^[1-9][0-9]{5}$/';

    public const ACCOUNT = '/^[0-9]{9,18}$/';

    /**
     * User-entered profile fields that must be complete on create.
     *
     * @return list<string>
     */
    public static function requiredProfileFields(): array
    {
        return [
            'name',
            'party_type',
            'contact_person',
            'phone',
            'email',
            'address',
            'city',
            'state',
            'pincode',
            'gst_number',
            'pan_number',
            'bank_name',
            'account_number',
            'ifsc',
        ];
    }

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(bool $creating): array
    {
        $required = $creating ? 'required' : 'sometimes';

        return [
            'name' => ['required', 'string', 'max:255'],
            'party_type' => [$creating ? 'required' : 'sometimes', Rule::in([Vendor::VENDOR, Vendor::CONTRACTOR, Vendor::BOTH])],
            'contact_person' => [$required, 'string', 'max:255'],
            'phone' => [$required, 'string', 'max:30', 'regex:'.self::PHONE],
            'email' => [$required, 'email', 'max:255'],
            'address' => [$required, 'string', 'max:2000'],
            'city' => [$required, 'string', 'max:100'],
            'state' => [$required, 'string', 'max:100'],
            'pincode' => [$required, 'string', 'max:12', 'regex:'.self::PINCODE],
            'gst_number' => [$required, 'string', 'max:20', 'regex:'.self::GST],
            'pan_number' => [$required, 'string', 'max:20', 'regex:'.self::PAN],
            'bank_name' => [$required, 'string', 'max:255'],
            'account_number' => [$required, 'string', 'max:40', 'regex:'.self::ACCOUNT],
            'ifsc' => [$required, 'string', 'max:20', 'regex:'.self::IFSC],
            'opening_advance' => ['nullable', 'numeric', 'gte:0'],
            'opening_outstanding' => ['nullable', 'numeric', 'gte:0'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'status' => ['sometimes', Rule::in([Vendor::ACTIVE, Vendor::INACTIVE])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function messages(): array
    {
        return [
            'name.required' => 'Enter the vendor name.',
            'party_type.required' => 'Select a classification.',
            'contact_person.required' => 'Enter a contact person.',
            'phone.required' => 'Enter a phone number.',
            'phone.regex' => 'Enter a phone number with 10 to 15 digits.',
            'email.required' => 'Enter an email address.',
            'email.email' => 'Enter a valid email address.',
            'address.required' => 'Enter the address.',
            'city.required' => 'Enter the city.',
            'state.required' => 'Enter the state.',
            'pincode.required' => 'Enter the pincode.',
            'pincode.regex' => 'Enter a 6-digit pincode.',
            'gst_number.required' => 'Enter the GST number.',
            'gst_number.regex' => 'Enter a valid 15-character GSTIN.',
            'pan_number.required' => 'Enter the PAN.',
            'pan_number.regex' => 'Enter a valid PAN (for example ABCDE1234F).',
            'bank_name.required' => 'Enter the bank name.',
            'account_number.required' => 'Enter the bank account number.',
            'account_number.regex' => 'Enter a bank account number with 9 to 18 digits.',
            'ifsc.required' => 'Enter the IFSC code.',
            'ifsc.regex' => 'Enter a valid IFSC code.',
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public static function normalize(array $input): array
    {
        foreach (['name', 'party_type', 'contact_person', 'phone', 'email', 'address', 'city', 'state', 'pincode', 'gst_number', 'pan_number', 'bank_name', 'account_number', 'ifsc', 'notes'] as $field) {
            if (! array_key_exists($field, $input) || ! is_string($input[$field])) {
                continue;
            }

            $value = trim(\App\Support\SafeInput::plainText($input[$field]));
            if (in_array($field, ['gst_number', 'pan_number', 'ifsc'], true)) {
                $value = strtoupper($value);
            }
            if ($field === 'phone') {
                $value = preg_replace('/\D+/', '', $value) ?? $value;
            }
            $input[$field] = $value;
        }

        foreach (['opening_advance', 'opening_outstanding'] as $field) {
            if (! array_key_exists($field, $input) || $input[$field] === null || $input[$field] === '') {
                $input[$field] = 0;
            }
        }

        return $input;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    public static function importErrors(array $data, bool $creating): array
    {
        $errors = [];

        if ($creating) {
            foreach (self::requiredProfileFields() as $field) {
                if (! self::filled($data[$field] ?? null)) {
                    $errors[] = str_replace('_', ' ', ucfirst($field)).' is required.';
                }
            }
        }

        if (self::filled($data['email'] ?? null) && ! filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Email is invalid.';
        }
        if (self::filled($data['phone'] ?? null) && ! preg_match(self::PHONE, (string) $data['phone'])) {
            $errors[] = 'Phone must be 10 to 15 digits.';
        }
        if (self::filled($data['pincode'] ?? null) && ! preg_match(self::PINCODE, (string) $data['pincode'])) {
            $errors[] = 'Pincode must be 6 digits.';
        }
        if (self::filled($data['gst_number'] ?? null) && ! preg_match(self::GST, strtoupper((string) $data['gst_number']))) {
            $errors[] = 'GST number is invalid.';
        }
        if (self::filled($data['pan_number'] ?? null) && ! preg_match(self::PAN, strtoupper((string) $data['pan_number']))) {
            $errors[] = 'PAN is invalid.';
        }
        if (self::filled($data['ifsc'] ?? null) && ! preg_match(self::IFSC, strtoupper((string) $data['ifsc']))) {
            $errors[] = 'IFSC is invalid.';
        }
        if (self::filled($data['account_number'] ?? null) && ! preg_match(self::ACCOUNT, (string) $data['account_number'])) {
            $errors[] = 'Account number must be 9 to 18 digits.';
        }
        if (($data['opening_advance'] ?? 0) < 0 || ($data['opening_outstanding'] ?? 0) < 0) {
            $errors[] = 'Opening amounts cannot be negative.';
        }

        return $errors;
    }

    public static function profileIsComplete(Vendor $vendor): bool
    {
        foreach (self::requiredProfileFields() as $field) {
            if (! self::filled($vendor->{$field})) {
                return false;
            }
        }

        return true;
    }

    private static function filled(mixed $value): bool
    {
        return is_string($value) ? trim($value) !== '' : $value !== null && $value !== '';
    }
}
