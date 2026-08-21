<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Support\Auth\UserSession;
use Illuminate\Support\Facades\Hash;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use PHPOpenSourceSaver\JWTAuth\Token;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Throwable;

class AuthService
{
    public function __construct(
        private readonly JwtTokenService $jwtTokenService,
        private readonly RefreshTokenService $refreshTokenService,
        private readonly UserSessionService $userSessionService,
    ) {}

    public function register(array $data): array
    {
        $user = User::query()->create([
            'username' => $data['username'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role_id' => $data['role_id'],
        ]);

        return $this->issueTokenPair($user->load('role.permissions'));
    }

    public function login(string $email, string $password): array
    {
        $user = User::query()
            ->where('email', $email)
            ->with('role.permissions')
            ->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            throw new UnauthorizedHttpException('Bearer', 'Credenciales inválidas.');
        }

        return $this->issueTokenPair($user);
    }

    public function refresh(string $refreshToken): array
    {
        $stored = $this->refreshTokenService->resolveValidToken($refreshToken);
        $user = $stored->user;
        $previousJti = $stored->access_jti;

        $accessToken = $this->jwtTokenService->createAccessToken($user);
        $session = $this->userSessionService->store($user, $accessToken);
        $newRefresh = $this->refreshTokenService->rotate(
            $user,
            $refreshToken,
            $accessToken->jti,
        );

        // Al rotar se elimina la sesión Redis del access anterior (mismo dispositivo).
        if (is_string($previousJti) && $previousJti !== '') {
            $this->invalidatePreviousAccess((int) $user->id, $previousJti);
        }

        return $this->formatTokenResponse(
            $accessToken->token,
            $newRefresh,
            $session,
        );
    }

    /**
     * Con refresh token cierra solo la sesión actual; sin él cierra todas las
     * sesiones del usuario en cualquier dispositivo.
     */
    public function logout(User $user, ?string $refreshToken = null): void
    {
        $currentAccessToken = $this->jwtTokenService->currentAccessToken();

        if ($refreshToken) {
            $this->refreshTokenService->revoke($refreshToken);

            if ($currentAccessToken) {
                $this->userSessionService->forget((int) $user->id, $currentAccessToken->jti);
            }
        } else {
            $this->refreshTokenService->revokeAllForUser($user);
            $this->userSessionService->forgetAllForUser((int) $user->id);
        }

        auth('api')->logout();
    }

    public function me(User $user): array
    {
        $accessToken = $this->jwtTokenService->currentAccessToken();

        if (! $accessToken) {
            throw new UnauthorizedHttpException('Bearer', 'No autenticado.');
        }

        $profile = $this->userSessionService->resolve($user, $accessToken)->toProfile();
        $profile['avatar_url'] = $user->avatar_url;

        return $profile;
    }

    private function issueTokenPair(User $user): array
    {
        $accessToken = $this->jwtTokenService->createAccessToken($user);
        $session = $this->userSessionService->store($user, $accessToken);
        $refreshToken = $this->refreshTokenService->create($user, $accessToken->jti);

        return $this->formatTokenResponse(
            $accessToken->token,
            $refreshToken,
            $session,
        );
    }

    private function formatTokenResponse(
        string $accessToken,
        string $refreshToken,
        UserSession $session,
    ): array {
        $user = User::query()->find($session->userId);

        $profile = $session->toProfile();
        $profile['avatar_url'] = $user?->avatar_url;

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'token_type' => 'Bearer',
            'expires_in' => $this->jwtTokenService->getAccessTokenTtlSeconds(),
            'user' => $profile,
        ];
    }

    private function invalidatePreviousAccess(int $userId, string $jti): void
    {
        $previous = $this->userSessionService->get($userId, $jti);

        if ($previous !== null && $previous->accessToken !== '') {
            try {
                JWTAuth::manager()->invalidate(new Token($previous->accessToken), false);
            } catch (Throwable) {
                // El token pudo haber expirado o ya estar en blacklist.
            }
        }

        $this->userSessionService->forget($userId, $jti);
    }
}
