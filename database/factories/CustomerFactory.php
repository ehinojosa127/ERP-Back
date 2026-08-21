<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Customer> */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'name' => fake()->firstName(),
            'lastname' => fake()->lastName(),
            'dni' => (string) fake()->unique()->numerify('########'),
            'phone_number' => (string) fake()->numerify('9########'),
            'city' => fake()->randomElement([
                'Lima', 'Arequipa', 'Cusco', 'Trujillo', 'Piura', 'Chiclayo', 'Iquitos',
            ]),
            'agency_destination' => fake()->randomElement([
                'Shalom Surco', 'Olva Centro', 'Marvisur', 'Cruz del Sur', null,
            ]),
        ];
    }
}
