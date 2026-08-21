<?php

namespace App\Support\Auth;

use App\Models\User;

/**
 * Estructura de la sesión que se guarda en Redis. Centralizarla en un objeto
 * evita que cada servicio arme el arreglo a mano y que las claves se escriban
 * como strings sueltos por todo el código.
 */
final class UserSession
{
    /** @param  array<int, int>  $permissionIds */
    public function __construct(
        public readonly int $userId,
        public readonly string $username,
        public readonly string $email,
        public readonly int $roleId,
        public readonly ?string $roleName,
        public readonly array $permissionIds,
        public readonly string $permissionsMask,
        public readonly string $accessToken,
        public readonly string $jti,
    ) {}

    public static function fromUser(User $user, AccessToken $accessToken): self
    {
        $user->loadMissing('role.permissions');

        return new self(
            userId: (int) $user->id,
            username: (string) $user->username,
            email: (string) $user->email,
            roleId: (int) $user->role_id,
            roleName: $user->role?->name,
            permissionIds: $user->permissionIds(),
            permissionsMask: $accessToken->permissionsMask,
            accessToken: $accessToken->token,
            jti: $accessToken->jti,
        );
    }

    /** @param  array<string, mixed>  $payload */
    public static function fromArray(array $payload): self
    {
        return new self(
            userId: (int) ($payload['user_id'] ?? 0),
            username: (string) ($payload['username'] ?? ''),
            email: (string) ($payload['email'] ?? ''),
            roleId: (int) ($payload['role_id'] ?? 0),
            roleName: $payload['role_name'] ?? null,
            permissionIds: array_map('intval', $payload['permission_ids'] ?? []),
            permissionsMask: (string) ($payload['permissions_mask'] ?? ''),
            accessToken: (string) ($payload['access_token'] ?? ''),
            jti: (string) ($payload['jti'] ?? ''),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'user_id' => $this->userId,
            'username' => $this->username,
            'email' => $this->email,
            'role_id' => $this->roleId,
            'role_name' => $this->roleName,
            'permission_ids' => $this->permissionIds,
            'permissions_mask' => $this->permissionsMask,
            'access_token' => $this->accessToken,
            'jti' => $this->jti,
        ];
    }

    /** Perfil público del usuario; es lo que responde la API al frontend. */
    public function toProfile(): array
    {
        return [
            'id' => $this->userId,
            'username' => $this->username,
            'email' => $this->email,
            'role_id' => $this->roleId,
            'role_name' => $this->roleName,
            'permissions' => $this->permissionsMask,
        ];
    }

    /** @param  array<int, int|string>  $permissionIds */
    public function hasAllPermissions(array $permissionIds): bool
    {
        foreach ($permissionIds as $permissionId) {
            if (! in_array((int) $permissionId, $this->permissionIds, true)) {
                return false;
            }
        }

        return true;
    }
}
