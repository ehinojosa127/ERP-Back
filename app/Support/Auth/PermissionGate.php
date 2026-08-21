<?php

namespace App\Support\Auth;

use App\Models\Permission;
use App\Services\Auth\JwtTokenService;
use App\Services\Auth\UserSessionService;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Autorización por nombre de permiso (resuelve el id en BD).
 * Evita hardcodear ids frágiles en las rutas.
 */
final class PermissionGate
{
    public function __construct(
        private readonly JwtTokenService $jwtTokenService,
        private readonly UserSessionService $userSessionService,
    ) {}

    public function assert(Request $request, string ...$permissionNames): void
    {
        $user = $request->user();
        $accessToken = $this->jwtTokenService->currentAccessToken();

        if (! $user || ! $accessToken) {
            throw new HttpException(401, 'No autenticado.');
        }

        $ids = Permission::query()
            ->whereIn('name', $permissionNames)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if (count($ids) !== count($permissionNames)) {
            throw new HttpException(403, 'No autorizado.');
        }

        $session = $this->userSessionService->resolve($user, $accessToken);

        if (! $session->hasAllPermissions($ids)) {
            throw new HttpException(403, 'No autorizado.');
        }
    }

    /** Exige al menos uno de los permisos indicados. */
    public function assertAny(Request $request, string ...$permissionNames): void
    {
        $user = $request->user();
        $accessToken = $this->jwtTokenService->currentAccessToken();

        if (! $user || ! $accessToken) {
            throw new HttpException(401, 'No autenticado.');
        }

        $ids = Permission::query()
            ->whereIn('name', $permissionNames)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        if ($ids === []) {
            throw new HttpException(403, 'No autorizado.');
        }

        $session = $this->userSessionService->resolve($user, $accessToken);

        foreach ($ids as $id) {
            if (in_array($id, $session->permissionIds, true)) {
                return;
            }
        }

        throw new HttpException(403, 'No autorizado.');
    }
}
