<?php

namespace App\Services\Roles;

use App\Models\Permission;
use App\Models\Role;
use App\Services\Auth\UserSessionService;
use App\Support\Auth\PermissionCatalog;
use App\Support\Query\ListQuery;
use App\Support\Query\SearchablePaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Cache;

class RoleService
{
    private const SEARCHABLE_COLUMNS = ['name', 'description'];

    public function __construct(
        private readonly UserSessionService $userSessionService,
    ) {}

    public function list(ListQuery $query): LengthAwarePaginator
    {
        return SearchablePaginator::paginate(
            Role::query()->with('permissions')->orderBy('name'),
            $query,
            self::SEARCHABLE_COLUMNS,
        );
    }

    /**
     * Listado completo sin paginar para poblar selectores. Los roles son un
     * catálogo corto: paginarlo rompería los formularios que lo consumen.
     */
    public function catalog(): Collection
    {
        return Role::query()
            ->select(['id', 'name'])
            ->orderBy('name')
            ->get();
    }

    public function find(Role $role): Role
    {
        return $role->load('permissions');
    }

    public function create(array $data): Role
    {
        $role = Role::query()->create([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
        ]);

        return $role->load('permissions');
    }

    public function update(Role $role, array $data): Role
    {
        $role->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
        ]);

        return $role->load('permissions');
    }

    public function delete(Role $role): void
    {
        if ($role->users()->exists()) {
            throw new ConflictHttpException(
                'No se puede eliminar un rol que tiene usuarios asignados.',
            );
        }

        $role->permissions()->detach();

        $role->delete();
    }

    public function syncPermissions(Role $role, array $permissionIds): Role
    {
        $role->permissions()->sync($permissionIds);

        Cache::forget('permission_name_id_map');
        $this->invalidateSessionsOfRole($role);

        return $role->load('permissions');
    }

    public function permissions(): SupportCollection
    {
        return Permission::query()
            ->orderBy('name')
            ->get()
            ->map(static function (Permission $permission) {
                return [
                    'id' => $permission->id,
                    'name' => $permission->name,
                    'label' => PermissionCatalog::actionLabel($permission->name),
                    'group' => PermissionCatalog::groupKey($permission->name),
                    'group_label' => PermissionCatalog::groupLabel($permission->name),
                    'created_at' => $permission->created_at,
                    'updated_at' => $permission->updated_at,
                ];
            });
    }

    /**
     * Los permisos viven cacheados en Redis por usuario: al cambiarlos hay que
     * invalidar la sesión de todos los usuarios que tengan ese rol.
     */
    private function invalidateSessionsOfRole(Role $role): void
    {
        $role->users()
            ->pluck('id')
            ->each(fn ($userId) => $this->userSessionService->forgetAllForUser((int) $userId));
    }
}
