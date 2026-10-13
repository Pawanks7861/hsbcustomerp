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
            'name' => fake()->company(),
            'phone' => fake()->numerify('98########'),
            'email' => fake()->safeEmail(),
            'address' => fake()->streetAddress(),
            'gst_number' => '24AAAAA0000A1Z5',
            'opening_advance' => 0,
            'opening_outstanding' => 0,
            'status' => Vendor::ACTIVE,
        ];
    }
}
