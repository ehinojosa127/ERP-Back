<?php

use App\Models\Permission;
use App\Models\Role;
use App\Support\Auth\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;

return new class extends Migration
{
    public function up(): void
    {
        foreach (PermissionCatalog::renames() as $from => $to) {
            Permission::query()
                ->where('name', $from)
                ->update(['name' => $to]);
        }

        $ids = [];

        foreach (PermissionCatalog::all() as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $ids[] = $permission->id;
        }

        Cache::forget('permission_name_id_map');

        $admin = Role::query()->where('name', 'Admin')->first();

        if ($admin === null) {
            $admin = Role::query()->create([
                'name' => 'Admin',
                'description' => 'Administrador del sistema',
            ]);
        }

        $admin->permissions()->sync($ids);

        $userRole = Role::query()->where('name', 'User')->first();

        if ($userRole === null) {
            $userRole = Role::query()->create([
                'name' => 'User',
                'description' => 'Usuario operativo del ERP',
            ]);
        }

        $userPermissionIds = Permission::query()
            ->whereIn('name', PermissionCatalog::forUserRole())
            ->pluck('id')
            ->all();

        $userRole->permissions()->sync($userPermissionIds);
    }

    public function down(): void
    {
        foreach (PermissionCatalog::renames() as $from => $to) {
            Permission::query()
                ->where('name', $to)
                ->update(['name' => $from]);
        }

        Cache::forget('permission_name_id_map');
    }
};
