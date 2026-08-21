<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /** @var array<int, string> */
    private const PERMISSIONS = [
        'orders.read',
        'orders.create',
        'orders.update',
        'orders.delete',
        'orders.payments',
        'orders.ship',
        'orders.shipment.update',
        'orders.close',
    ];

    public function up(): void
    {
        $ids = [];

        foreach (self::PERMISSIONS as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $ids[] = $permission->id;
        }

        $admin = Role::query()->where('name', 'Admin')->first();

        if ($admin !== null) {
            $admin->permissions()->syncWithoutDetaching($ids);
        }
    }

    public function down(): void
    {
        Permission::query()->whereIn('name', self::PERMISSIONS)->delete();
    }
};
