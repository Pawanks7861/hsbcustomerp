<?php

namespace Database\Factories;

use App\Models\Vendor;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vendor>
 */
class VendorFactory extends Factory
{
    protected $model = Vendor::class;

    public function definition(): array
    {
        return [
            'vendor_code' => 'VEN-'.sprintf('%03d', fake()->unique()->numberBetween(1, 999999)),
            'party_type' => Vendor::VENDOR,
            'name' => fake()->company(),
            'contact_person' => fake()->name(),
            'phone' => fake()->numerify('98########'),
            'email' => fake()->unique()->safeEmail(),
            'address' => fake()->streetAddress(),
            'city' => 'Ahmedabad',
            'state' => 'Gujarat',
            'pincode' => '380001',
            'gst_number' => '24AAAAA0000A1Z5',
            'pan_number' => 'AAAAA0000A',
            'bank_name' => 'HDFC Bank',
            'account_number' => '123456789012',
            'ifsc' => 'HDFC0001234',
            'opening_advance' => 0,
            'opening_outstanding' => 0,
            'status' => Vendor::ACTIVE,
        ];
    }
}
