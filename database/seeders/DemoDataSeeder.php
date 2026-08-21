<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Datos de ejemplo para ver la paginación y la búsqueda con volumen realista.
 * No forma parte de DatabaseSeeder: se ejecuta a propósito con
 * `php artisan db:seed --class=DemoDataSeeder`.
 */
class DemoDataSeeder extends Seeder
{
    private const CUSTOMERS = 45;

    private const PERSON_SUPPLIERS = 18;

    private const COMPANY_SUPPLIERS = 17;

    public function run(): void
    {
        $author = User::query()->orderBy('id')->first();
        $audit = [
            'created_by' => $author?->id,
            'updated_by' => $author?->id,
        ];

        Customer::factory()->count(self::CUSTOMERS)->create($audit);
        Supplier::factory()->count(self::PERSON_SUPPLIERS)->create($audit);
        Supplier::factory()->company()->count(self::COMPANY_SUPPLIERS)->create($audit);
    }
}
