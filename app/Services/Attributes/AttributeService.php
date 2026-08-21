<?php

namespace App\Services\Attributes;

use App\Models\Attribute;
use App\Models\User;
use App\Support\Query\ListQuery;
use App\Support\Query\SearchablePaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class AttributeService
{
    private const SEARCHABLE_COLUMNS = ['name'];

    public function list(ListQuery $query): LengthAwarePaginator
    {
        $builder = Attribute::query()->orderBy('name');

        return SearchablePaginator::paginate($builder, $query, self::SEARCHABLE_COLUMNS);
    }

    public function find(Attribute $attribute): Attribute
    {
        return $attribute;
    }

    public function create(array $data, User $author): Attribute
    {
        return Attribute::query()->create([
            'name' => $data['name'],
            'created_by' => $author->id,
            'updated_by' => $author->id,
        ]);
    }

    public function update(Attribute $attribute, array $data, User $author): Attribute
    {
        $attribute->update([
            'name' => $data['name'],
            'updated_by' => $author->id,
        ]);

        return $attribute;
    }

    public function delete(Attribute $attribute): void
    {
        if ($attribute->productDetails()->exists()) {
            throw new ConflictHttpException(
                'No se puede eliminar este atributo porque está en uso por productos.',
            );
        }

        $attribute->delete();
    }
}
