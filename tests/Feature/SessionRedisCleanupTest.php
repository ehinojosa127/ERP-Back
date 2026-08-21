<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\RefreshToken;
use App\Models\Role;
use App\Models\User;
use App\Support\Auth\SessionKey;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class SessionRedisCleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_refresh_removes_previous_redis_session_key(): void
    {
        $user = $this->createUser();

        $login = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()->json('data');

        $firstAccess = (string) $login['access_token'];
        $firstRefresh = (string) $login['refresh_token'];
        $firstJti = (string) RefreshToken::query()
            ->where('token_hash', hash('sha256', $firstRefresh))
            ->value('access_jti');

        $this->assertNotEmpty($firstJti);
        $this->assertNotNull(
            Redis::connection()->get(SessionKey::for((int) $user->id, $firstJti)),
        );

        $refresh = $this->postJson('/api/auth/refresh', [
            'refresh_token' => $firstRefresh,
        ])->assertOk()->json('data');

        $this->assertNull(
            Redis::connection()->get(SessionKey::for((int) $user->id, $firstJti)),
        );

        $secondJti = (string) RefreshToken::query()
            ->where('token_hash', hash('sha256', (string) $refresh['refresh_token']))
            ->value('access_jti');

        $this->assertNotEmpty($secondJti);
        $this->assertNotSame($firstJti, $secondJti);
        $this->assertNotNull(
            Redis::connection()->get(SessionKey::for((int) $user->id, $secondJti)),
        );
        $this->assertNotSame($firstAccess, $refresh['access_token']);
    }

    public function test_logout_removes_current_redis_session_key(): void
    {
        $user = $this->createUser();

        $login = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk()->json('data');

        $jti = (string) RefreshToken::query()
            ->where('token_hash', hash('sha256', (string) $login['refresh_token']))
            ->value('access_jti');

        $this->withHeader('Authorization', 'Bearer '.$login['access_token'])
            ->postJson('/api/auth/logout', [
                'refresh_token' => $login['refresh_token'],
            ])
            ->assertOk();

        $this->assertNull(
            Redis::connection()->get(SessionKey::for((int) $user->id, $jti)),
        );
        $this->assertFalse(
            (bool) Redis::connection()->sismember(
                SessionKey::indexFor((int) $user->id),
                $jti,
            ),
        );
    }

    private function createUser(): User
    {
        $role = Role::query()->create([
            'name' => 'SessionTester',
            'description' => 'Redis session tests',
        ]);

        $permission = Permission::query()->create(['name' => 'dashboard.view']);
        $role->permissions()->attach($permission->id);

        return User::query()->create([
            'username' => 'session-tester',
            'email' => 'session-tester@example.com',
            'password' => Hash::make('password'),
            'role_id' => $role->id,
        ]);
    }
}
