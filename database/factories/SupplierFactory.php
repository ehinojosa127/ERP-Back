<?php

namespace Database\Factories;

use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Supplier> */
class SupplierFactory extends Factory
{
    protected $model = Supplier::class;

    public function definition(): array
    {
        return [
            'name' => fake()->firstName(),
            'lastname' => fake()->lastName(),
            'company_name' => null,
            'ruc' => null,
            'dni' => (string) fake()->unique()->numerify('########'),
            'phone_number' => (string) fake()->numerify('9########'),
            'city' => fake()->randomElement(['Lima', 'Arequipa', 'Cusco', 'Trujillo', 'Piura']),
        ];
    }

    /** Proveedor empresa: razón social y RUC en lugar de nombre y DNI. */
    public function company(): static
    {
        return $this->state(fn () => [
            'name' => null,
            'lastname' => null,
            'dni' => null,
            'company_name' => fake()->company().' SAC',
            'ruc' => (string) fake()->unique()->numerify('20#########'),
        ]);
    }
}
