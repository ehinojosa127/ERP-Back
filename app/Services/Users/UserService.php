<?php

namespace App\Services\Users;

use App\Models\User;
use App\Services\Auth\UserSessionService;
use App\Support\Query\ListQuery;
use App\Support\Query\SearchablePaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class UserService
{
    private const SEARCHABLE_COLUMNS = ['username', 'email', 'role.name'];

    public function __construct(
        private readonly UserSessionService $userSessionService,
    ) {}

    public function list(ListQuery $query): LengthAwarePaginator
    {
        return SearchablePaginator::paginate(
            User::query()->with('role:id,name')->orderBy('username'),
            $query,
            self::SEARCHABLE_COLUMNS,
        );
    }

    public function find(User $user): User
    {
        return $user->load('role:id,name');
    }

    public function create(array $data): User
    {
        $user = User::query()->create([
            'username' => $data['username'],
            'email' => $data['email'],
            'password' => $data['password'],
            'role_id' => $data['role_id'],
        ]);

        return $user->load('role:id,name');
    }

    public function update(User $user, array $data): User
    {
        $user->fill([
            'username' => $data['username'],
            'email' => $data['email'],
            'role_id' => $data['role_id'],
        ]);

        // Sin contraseña en la petición se conserva la actual.
        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }

        $user->save();

        // El rol pudo cambiar: las sesiones cacheadas quedarían con permisos viejos.
        $this->userSessionService->forgetAllForUser((int) $user->id);

        return $user->load('role:id,name');
    }

    public function delete(User $user, User $currentUser): void
    {
        if ($user->id === $currentUser->id) {
            throw new ConflictHttpException('No puedes eliminar tu propio usuario.');
        }

        $this->userSessionService->forgetAllForUser((int) $user->id);

        $user->delete();
    }
}
