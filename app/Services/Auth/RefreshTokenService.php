<?php

namespace App\Services\Auth;

use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

class RefreshTokenService
{
    public function create(User $user, ?string $accessJti = null): string
    {
        $plainToken = Str::random(64);

        RefreshToken::query()->create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plainToken),
            'access_jti' => $accessJti,
            // Ventana de inactividad: se reinicia en cada rotate() exitoso.
            'expires_at' => now()->addMinutes(
                (int) config('auth_tokens.refresh_ttl_minutes'),
            ),
        ]);

        return $plainToken;
    }

    public function rotate(User $user, string $plainToken, ?string $accessJti = null): string
    {
        $this->revoke($plainToken);

        return $this->create($user, $accessJti);
    }

    public function revoke(string $plainToken): void
    {
        RefreshToken::query()
            ->where('token_hash', hash('sha256', $plainToken))
            ->delete();
    }

    public function revokeAllForUser(User $user): void
    {
        RefreshToken::query()
            ->where('user_id', $user->id)
            ->delete();
    }

    public function resolveValidToken(string $plainToken): RefreshToken
    {
        $refreshToken = RefreshToken::query()
            ->where('token_hash', hash('sha256', $plainToken))
            ->with('user.role.permissions')
            ->first();

        if (! $refreshToken || $refreshToken->isExpired()) {
            throw new UnauthorizedHttpException('Bearer', 'Refresh token inválido o expirado.');
        }

        return $refreshToken;
    }
}
