<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Support\Auth\AccessToken;
use App\Support\Auth\PermissionBitmask;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use PHPOpenSourceSaver\JWTAuth\Payload;
use PHPOpenSourceSaver\JWTAuth\Token;

class JwtTokenService
{
    private const SECONDS_PER_MINUTE = 60;

    /**
     * Construye los claims personalizados del access token.
     * Edita este método para cambiar qué datos viajan dentro del JWT.
     *
     * `permissions` viaja como bitmask hexadecimal en lugar de como arreglo de
     * ids: los ids son consecutivos, así que la representación compacta ahorra
     * bastante espacio en cada cabecera Authorization.
     */
    public function buildAccessTokenClaims(User $user): array
    {
        $user->loadMissing('role.permissions');

        return [
            'user' => [
                'id' => (int) $user->id,
                'username' => $user->username,
                'email' => $user->email,
                'role_id' => (int) $user->role_id,
                'role_name' => $user->role?->name,
            ],
            'permissions' => PermissionBitmask::encode($user->permissionIds()),
        ];
    }

    public function createAccessToken(User $user): AccessToken
    {
        $claims = $this->buildAccessTokenClaims($user);
        $token = JWTAuth::customClaims($claims)->fromUser($user);

        return new AccessToken(
            token: $token,
            jti: (string) $this->payloadOf($token)->get('jti'),
            permissionsMask: (string) $claims['permissions'],
            expiresInSeconds: $this->getAccessTokenTtlSeconds(),
        );
    }

    public function getAccessTokenTtlSeconds(): int
    {
        return (int) config('auth_tokens.access_ttl_minutes') * self::SECONDS_PER_MINUTE;
    }

    /**
     * Reconstruye el access token con el que viene firmada la petición actual.
     * Es lo que permite a la autorización localizar la sesión por su jti.
     */
    public function currentAccessToken(): ?AccessToken
    {
        $guard = auth('api');
        $token = $guard->getToken();

        if (! $token) {
            return null;
        }

        $payload = $guard->payload();

        return new AccessToken(
            token: $token->get(),
            jti: (string) $payload->get('jti'),
            permissionsMask: (string) ($payload->get('permissions') ?? PermissionBitmask::encode([])),
            expiresInSeconds: max(0, (int) $payload->get('exp') - time()),
        );
    }

    /**
     * Decodifica el token con el manager en lugar de `JWTAuth::setToken()` para
     * no dejar fijado ese token en la instancia compartida de la petición.
     */
    private function payloadOf(string $token): Payload
    {
        return JWTAuth::manager()->decode(new Token($token));
    }
}
