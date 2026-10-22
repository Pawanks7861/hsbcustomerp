<?php

namespace Database\Factories;

use App\Models\Client;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Client>
 */
class ClientFactory extends Factory
{
    protected $model = Client::class;

    public function definition(): array
    {
        return [
            'client_code' => 'CLI-'.sprintf('%03d', fake()->unique()->numberBetween(1, 999999)),
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
            'payment_terms' => 'Net 30',
            'status' => Client::ACTIVE,
        ];
    }
}
