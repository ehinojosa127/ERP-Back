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
        $ids = [];
        foreach (PermissionCatalog::all() as $name) {
            $ids[] = Permission::query()->firstOrCreate(['name' => $name])->id;
        }
        Cache::forget('permission_name_id_map');

        Role::query()->where('name', 'Admin')->first()?->permissions()->syncWithoutDetaching($ids);

        $userRole = Role::query()->where('name', 'User')->first();
        if ($userRole !== null) {
            $userRole->permissions()->syncWithoutDetaching(
                Permission::query()->whereIn('name', PermissionCatalog::forUserRole())->pluck('id')->all(),
            );
        }
    }

    public function down(): void
    {
        Permission::query()->whereIn('name', ['billing.cancel', 'billing.consult'])->delete();
    }
};
