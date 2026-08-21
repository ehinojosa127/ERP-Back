<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\Auth\PermissionCatalog;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $adminRole = Role::query()->firstOrCreate(
            ['name' => 'Admin'],
            ['description' => 'Administrador del sistema'],
        );

        $userRole = Role::query()->firstOrCreate(
            ['name' => 'User'],
            ['description' => 'Usuario operativo del ERP'],
        );

        $permissionIds = [];

        foreach (PermissionCatalog::all() as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $permissionIds[] = $permission->id;
        }

        $adminRole->permissions()->sync($permissionIds);

        $userPermissionIds = Permission::query()
            ->whereIn('name', PermissionCatalog::forUserRole())
            ->pluck('id')
            ->all();

        $userRole->permissions()->sync($userPermissionIds);

        User::query()->firstOrCreate(
            ['email' => 'admin@example.com'],
            [
                'username' => 'admin',
                'password' => 'password',
                'role_id' => $adminRole->id,
            ],
        );

        User::query()->firstOrCreate(
            ['email' => 'user@example.com'],
            [
                'username' => 'user',
                'password' => 'password',
                'role_id' => $userRole->id,
            ],
        );
    }
}
