<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Profile\UpdateAvatarRequest;
use App\Http\Requests\Profile\UpdatePasswordRequest;
use App\Services\Profile\ProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends ApiController
{
    public function __construct(
        private readonly ProfileService $profileService,
    ) {}

    public function updatePassword(UpdatePasswordRequest $request): JsonResponse
    {
        $this->profileService->changePassword(
            $request->user(),
            $request->validated(),
        );

        return $this->success(null, 'Contraseña actualizada correctamente.');
    }

    public function updateAvatar(UpdateAvatarRequest $request): JsonResponse
    {
        $user = $this->profileService->updateAvatar(
            $request->user(),
            $request->file('avatar'),
        );

        return $this->success([
            'avatar_url' => $user->avatar_url,
        ], 'Foto de perfil actualizada correctamente.');
    }

    public function deleteAvatar(Request $request): JsonResponse
    {
        $user = $this->profileService->deleteAvatar($request->user());

        return $this->success([
            'avatar_url' => $user->avatar_url,
        ], 'Foto de perfil eliminada correctamente.');
    }
}
