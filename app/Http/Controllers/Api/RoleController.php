<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Roles\StoreRoleRequest;
use App\Http\Requests\Roles\SyncRolePermissionsRequest;
use App\Http\Requests\Roles\UpdateRoleRequest;
use App\Http\Requests\Shared\PaginatedIndexRequest;
use App\Models\Role;
use App\Services\Roles\RoleService;
use App\Support\Auth\PermissionGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RoleController extends ApiController
{
    public function __construct(
        private readonly RoleService $roleService,
        private readonly PermissionGate $permissionGate,
    ) {}

    public function index(PaginatedIndexRequest $request): JsonResponse
    {
        return $this->success($this->roleService->list($request->toListQuery()));
    }

    /** Catálogo completo (id + nombre) para los selectores del frontend. */
    public function catalog(Request $request): JsonResponse
    {
        $this->permissionGate->assertAny($request, 'roles.view', 'users.view', 'users.create', 'users.update');

        return $this->success($this->roleService->catalog());
    }

    public function show(Role $role): JsonResponse
    {
        return $this->success($this->roleService->find($role));
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        $role = $this->roleService->create($request->validated());

        return $this->success($role, 'Rol creado correctamente.', 201);
    }

    public function update(UpdateRoleRequest $request, Role $role): JsonResponse
    {
        $updated = $this->roleService->update($role, $request->validated());

        return $this->success($updated, 'Rol actualizado correctamente.');
    }

    public function destroy(Role $role): JsonResponse
    {
        $this->roleService->delete($role);

        return $this->success(null, 'Rol eliminado correctamente.');
    }

    public function syncPermissions(SyncRolePermissionsRequest $request, Role $role): JsonResponse
    {
        $updated = $this->roleService->syncPermissions(
            $role,
            $request->validated('permission_ids'),
        );

        return $this->success($updated, 'Permisos actualizados correctamente.');
    }
}
