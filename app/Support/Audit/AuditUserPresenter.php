<?php

namespace App\Support\Audit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Expone nombres de usuario de auditoría sin envolver el FK en un objeto.
 */
final class AuditUserPresenter
{
    private const AUDIT_RELATIONS = [
        'createdBy:id,username',
        'updatedBy:id,username',
    ];

    public static function present(Model $model): array
    {
        $model->loadMissing(self::AUDIT_RELATIONS);

        $payload = $model->attributesToArray();

        $payload['created_by_username'] = $model->createdBy?->username;
        $payload['updated_by_username'] = $model->updatedBy?->username;

        return $payload;
    }

    /**
     * @param  Collection<int, Model>  $models
     * @return Collection<int, array<string, mixed>>
     */
    public static function presentMany(Collection $models): Collection
    {
        $models->loadMissing(self::AUDIT_RELATIONS);

        return $models->map(fn (Model $model) => self::present($model));
    }
}
