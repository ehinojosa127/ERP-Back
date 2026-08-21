<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Support\Auth\AccessToken;
use App\Support\Auth\SessionKey;
use App\Support\Auth\UserSession;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Redis;

/**
 * Sesión de usuario en Redis bajo la llave "session-{userId}-{jti}".
 *
 * Cada access token emitido tiene su propia entrada, que guarda los datos
 * básicos del usuario, el propio access token y los ids de permiso que
 * consulta el middleware de autorización. El TTL es el mismo que el del
 * access token. El índice por usuario se mantiene al menos hasta el TTL
 * de refresh para poder invalidar sesiones al rotar o cerrar sesión.
 */
class UserSessionService
{
    private const SECONDS_PER_MINUTE = 60;

    public function store(User $user, AccessToken $accessToken): UserSession
    {
        $session = UserSession::fromUser($user, $accessToken);
        $ttl = $this->ttlSeconds();
        $userId = $session->userId;

        $connection = $this->connection();

        $connection->setex(
            SessionKey::for($userId, $session->jti),
            $ttl,
            json_encode($session->toArray(), JSON_THROW_ON_ERROR),
        );

        // El índice permite invalidar todas las sesiones del usuario cuando
        // cambian su rol o los permisos de ese rol.
        $indexKey = SessionKey::indexFor($userId);
        $connection->sadd($indexKey, $session->jti);

        // El índice debe vivir al menos tanto como la ventana de refresh + access,
        // para no perder JTI activos al renovar el token.
        $indexTtl = $this->indexTtlSeconds();
        $currentIndexTtl = (int) $connection->ttl($indexKey);
        $connection->expire($indexKey, max($indexTtl, $currentIndexTtl, $ttl));

        return $session;
    }

    public function get(int $userId, string $jti): ?UserSession
    {
        $payload = $this->connection()->get(SessionKey::for($userId, $jti));

        if (! is_string($payload) || $payload === '') {
            return null;
        }

        return UserSession::fromArray(
            json_decode($payload, true, 512, JSON_THROW_ON_ERROR),
        );
    }

    public function forget(int $userId, string $jti): void
    {
        $connection = $this->connection();

        $connection->del(SessionKey::for($userId, $jti));
        $connection->srem(SessionKey::indexFor($userId), $jti);
    }

    /** Invalida todas las sesiones abiertas del usuario en cualquier dispositivo. */
    public function forgetAllForUser(int $userId): void
    {
        $connection = $this->connection();
        $indexKey = SessionKey::indexFor($userId);

        foreach ((array) $connection->smembers($indexKey) as $jti) {
            $connection->del(SessionKey::for($userId, (string) $jti));
        }

        $connection->del($indexKey);
    }

    /**
     * Devuelve la sesión cacheada y, si expiró o Redis fue reiniciado, la
     * reconstruye desde la base de datos para no invalidar un token vigente.
     */
    public function resolve(User $user, AccessToken $accessToken): UserSession
    {
        return $this->get((int) $user->id, $accessToken->jti)
            ?? $this->store($user, $accessToken);
    }

    private function connection(): Connection
    {
        return Redis::connection(config('auth_tokens.redis_connection'));
    }

    private function ttlSeconds(): int
    {
        return (int) config('auth_tokens.access_ttl_minutes') * self::SECONDS_PER_MINUTE;
    }

    private function indexTtlSeconds(): int
    {
        $access = (int) config('auth_tokens.access_ttl_minutes');
        $refresh = (int) config('auth_tokens.refresh_ttl_minutes');

        return ($access + $refresh) * self::SECONDS_PER_MINUTE;
    }
}
