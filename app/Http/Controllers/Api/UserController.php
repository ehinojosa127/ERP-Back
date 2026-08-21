<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Shared\PaginatedIndexRequest;
use App\Http\Requests\Users\StoreUserRequest;
use App\Http\Requests\Users\UpdateUserRequest;
use App\Models\User;
use App\Services\Users\UserService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends ApiController
{
    public function __construct(
        private readonly UserService $userService,
    ) {}

    public function index(PaginatedIndexRequest $request): JsonResponse
    {
        return $this->success($this->userService->list($request->toListQuery()));
    }

    public function show(User $user): JsonResponse
    {
        return $this->success($this->userService->find($user));
    }

    public function store(StoreUserRequest $request): JsonResponse
    {
        $user = $this->userService->create($request->validated());

        return $this->success($user, 'Usuario creado correctamente.', 201);
    }

    public function update(UpdateUserRequest $request, User $user): JsonResponse
    {
        $updated = $this->userService->update($user, $request->validated());

        return $this->success($updated, 'Usuario actualizado correctamente.');
    }

    public function destroy(Request $request, User $user): JsonResponse
    {
        $this->userService->delete($user, $request->user());

        return $this->success(null, 'Usuario eliminado correctamente.');
    }
}
