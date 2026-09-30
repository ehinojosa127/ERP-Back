<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use App\Services\Customers\CustomerService;
use App\Support\Customers\PhoneNormalizer;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CustomerPhoneUniqueTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_rejects_duplicate_canonical_phone(): void
    {
        $admin = $this->createAdmin();

        app(CustomerService::class)->create([
            'name' => 'Ana',
            'lastname' => 'Pérez',
            'dni' => '11111111',
            'phone_number' => '925720365',
            'city' => 'Lima',
        ], $admin);

        $this->expectException(UniqueConstraintViolationException::class);

        app(CustomerService::class)->create([
            'name' => 'Luis',
            'lastname' => 'Torres',
            'dni' => '22222222',
            'phone_number' => '51925720365',
            'city' => 'Arequipa',
        ], $admin);
    }

    public function test_automation_rejects_duplicate_phone_with_validation(): void
    {
        Customer::query()->create([
            'name' => 'Ana',
            'lastname' => 'Pérez',
            'dni' => '33333333',
            'phone_number' => PhoneNormalizer::canonical('911111111'),
            'city' => 'Lima',
        ]);

        $this->withHeader('X-API-Key', 'test-automation-key')
            ->postJson('/api/automation/customers', [
                'name' => 'Luis',
                'lastname' => 'Torres',
                'dni' => '44444444',
                'phone' => '51911111111',
                'city' => 'Cusco',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone_number']);
    }

    private function createAdmin(): User
    {
        $role = Role::query()->firstOrCreate(
            ['name' => 'phone-admin'],
            ['description' => 'Admin'],
        );

        return User::query()->firstOrCreate(
            ['email' => 'phone-admin@example.com'],
            [
                'username' => 'phone-admin',
                'password' => Hash::make('password'),
                'role_id' => $role->id,
            ],
        );
    }
}
