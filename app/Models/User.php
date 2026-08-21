<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

class User extends Authenticatable implements JWTSubject
{
    use HasFactory;

    protected $fillable = [
        'username',
        'email',
        'password',
        'role_id',
        'avatar_path',
    ];

    protected $hidden = [
        'password',
        'avatar_path',
    ];

    protected $appends = [
        'avatar_url',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
        ];
    }

    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims(): array
    {
        return [];
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function refreshTokens(): HasMany
    {
        return $this->hasMany(RefreshToken::class);
    }

    public function permissionIds(): array
    {
        $this->loadMissing('role.permissions');

        if (! $this->role) {
            return [];
        }

        return $this->role->permissions
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function getAvatarUrlAttribute(): ?string
    {
        if (! $this->avatar_path) {
            return null;
        }

        // Ruta relativa al origen del frontend: evita depender de APP_URL
        // (p. ej. http://localhost sin el puerto de `artisan serve`).
        // En desarrollo Vite hace proxy de /storage al backend.
        return '/storage/'.ltrim($this->avatar_path, '/');
    }
}
