<?php

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\RefreshToken;
use App\Models\Role;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPOpenSourceSaver\JWTAuth\Facades\JWTAuth;
use Tests\TestCase;

/**
 * Valida access 20m / refresh 40m de inactividad con rotación.
 * Usa Carbon::setTestNow para no esperar tiempos reales.
 */
class RefreshTokenInactivityTest extends TestCase
{
    use RefreshDatabase;

    private const ACCESS_TTL_MINUTES = 20;

    private const REFRESH_TTL_MINUTES = 40;

    private const INACTIVITY_OVER_REFRESH_MINUTES = 41;

    private const TWO_HOURS_MINUTES = 120;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'jwt.ttl' => self::ACCESS_TTL_MINUTES,
            'auth_tokens.access_ttl_minutes' => self::ACCESS_TTL_MINUTES,
            'auth_tokens.refresh_ttl_minutes' => self::REFRESH_TTL_MINUTES,
        ]);

        JWTAuth::factory()->setTTL(self::ACCESS_TTL_MINUTES);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_refresh_succeeds_within_inactivity_window_and_rotates(): void
    {
        $user = $this->createUser();
        $login = $this->login($user);
        $refreshToken = $login['refresh_token'];

        Carbon::setTestNow(now()->addMinutes(self::ACCESS_TTL_MINUTES + 1));

        $response = $this->postJson('/api/auth/refresh', [
            'refresh_token' => $refreshToken,
        ]);

        $response->assertOk();
        $response->assertJsonPath(
            'data.expires_in',
            self::ACCESS_TTL_MINUTES * 60,
        );

        $newRefresh = $response->json('data.refresh_token');
        $this->assertNotSame($refreshToken, $newRefresh);
        $this->assertDatabaseMissing('refresh_tokens', [
            'token_hash' => hash('sha256', $refreshToken),
        ]);
        $this->assertDatabaseHas('refresh_tokens', [
            'token_hash' => hash('sha256', $newRefresh),
        ]);

        $stored = RefreshToken::query()
            ->where('token_hash', hash('sha256', $newRefresh))
            ->firstOrFail();

        $this->assertEqualsWithDelta(
            now()->addMinutes(self::REFRESH_TTL_MINUTES)->getTimestamp(),
            $stored->expires_at->getTimestamp(),
            2,
        );
    }

    public function test_active_user_can_refresh_indefinitely_via_rotation(): void
    {
        $user = $this->createUser();
        $login = $this->login($user);
        $refreshToken = $login['refresh_token'];

        for ($cycle = 1; $cycle <= 3; $cycle++) {
            Carbon::setTestNow(now()->addMinutes(self::ACCESS_TTL_MINUTES + 1));

            $response = $this->postJson('/api/auth/refresh', [
                'refresh_token' => $refreshToken,
            ]);

            $response->assertOk();
            $this->assertNotEmpty($response->json('data.access_token'));

            $refreshToken = $response->json('data.refresh_token');
            $this->assertNotEmpty($refreshToken);

            $stored = RefreshToken::query()
                ->where('token_hash', hash('sha256', $refreshToken))
                ->firstOrFail();

            // Cada rotación reinicia la ventana de inactividad.
            $this->assertEqualsWithDelta(
                now()->addMinutes(self::REFRESH_TTL_MINUTES)->getTimestamp(),
                $stored->expires_at->getTimestamp(),
                2,
            );
        }
    }

    public function test_refresh_fails_after_inactivity_over_forty_minutes(): void
    {
        $user = $this->createUser();
        $login = $this->login($user);
        $refreshToken = $login['refresh_token'];

        Carbon::setTestNow(now()->addMinutes(self::INACTIVITY_OVER_REFRESH_MINUTES));

        $this->postJson('/api/auth/refresh', [
            'refresh_token' => $refreshToken,
        ])->assertUnauthorized();
    }

    public function test_refresh_fails_after_two_hours_of_inactivity(): void
    {
        $user = $this->createUser();
        $login = $this->login($user);
        $refreshToken = $login['refresh_token'];

        Carbon::setTestNow(now()->addMinutes(self::TWO_HOURS_MINUTES));

        $this->postJson('/api/auth/refresh', [
            'refresh_token' => $refreshToken,
        ])->assertUnauthorized();
    }

    public function test_old_refresh_token_is_rejected_after_rotation(): void
    {
        $user = $this->createUser();
        $login = $this->login($user);
        $oldRefresh = $login['refresh_token'];

        $rotated = $this->postJson('/api/auth/refresh', [
            'refresh_token' => $oldRefresh,
        ])->assertOk()->json('data.refresh_token');

        $this->postJson('/api/auth/refresh', [
            'refresh_token' => $oldRefresh,
        ])->assertUnauthorized();

        $this->postJson('/api/auth/refresh', [
            'refresh_token' => $rotated,
        ])->assertOk();
    }

    /** @return array{access_token: string, refresh_token: string} */
    private function login(User $user): array
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        return [
            'access_token' => (string) $response->json('data.access_token'),
            'refresh_token' => (string) $response->json('data.refresh_token'),
        ];
    }

    private function createUser(): User
    {
        $role = Role::query()->create([
            'name' => 'Tester',
            'description' => 'Role for refresh tests',
        ]);

        $permission = Permission::query()->create(['name' => 'users.view']);
        $role->permissions()->attach($permission->id);

        return User::query()->create([
            'username' => 'refresh-tester',
            'email' => 'refresh-tester@example.com',
            'password' => Hash::make('password'),
            'role_id' => $role->id,
        ]);
    }
}
