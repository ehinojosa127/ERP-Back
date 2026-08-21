<?php

namespace App\Http\Middleware;

use App\Models\Permission;
use App\Services\Auth\JwtTokenService;
use App\Services\Auth\UserSessionService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    private const PERMISSION_NAME_CACHE_TTL_SECONDS = 300;

    public function __construct(
        private readonly JwtTokenService $jwtTokenService,
        private readonly UserSessionService $userSessionService,
    ) {}

    /**
     * Exige que el usuario autenticado tenga todos los permisos indicados.
     * Los permisos se leen de la sesión en Redis (jti del access token).
     *
     * Acepta ids numéricos o nombres (`users.view`, `orders.create`).
     * Uso: ->middleware('permission:users.view') o ->middleware('permission:1,2')
     */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();
        $accessToken = $this->jwtTokenService->currentAccessToken();

        if (! $user || ! $accessToken) {
            return response()->json(['message' => 'No autenticado.'], 401);
        }

        $permissionIds = $this->resolvePermissionIds($permissions);

        if ($permissionIds === []) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        $session = $this->userSessionService->resolve($user, $accessToken);

        if (! $session->hasAllPermissions($permissionIds)) {
            return response()->json(['message' => 'No autorizado.'], 403);
        }

        return $next($request);
    }

    /**
     * @param  array<int, string>  $permissions
     * @return array<int, int>
     */
    private function resolvePermissionIds(array $permissions): array
    {
        $ids = [];
        $names = [];

        foreach ($permissions as $permission) {
            if (ctype_digit($permission)) {
                $ids[] = (int) $permission;
            } else {
                $names[] = $permission;
            }
        }

        if ($names !== []) {
            $map = Cache::remember(
                'permission_name_id_map',
                self::PERMISSION_NAME_CACHE_TTL_SECONDS,
                fn () => Permission::query()->pluck('id', 'name')->map(
                    fn ($id) => (int) $id,
                )->all(),
            );

            foreach ($names as $name) {
                if (! isset($map[$name])) {
                    return [];
                }

                $ids[] = (int) $map[$name];
            }
        }

        return array_values(array_unique($ids));
    }
}
