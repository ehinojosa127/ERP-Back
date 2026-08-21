<?php

namespace App\Http\Controllers\Api;

use App\Services\Roles\RoleService;
use Illuminate\Http\JsonResponse;

class PermissionController extends ApiController
{
    public function __construct(
        private readonly RoleService $roleService,
    ) {}

    public function index(): JsonResponse
    {
        return $this->success($this->roleService->permissions());
    }
}
