<?php

namespace App\Services\Categories;

use App\Models\Category;
use App\Models\User;
use App\Support\Query\ListQuery;
use App\Support\Query\SearchablePaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class CategoryService
{
    private const SEARCHABLE_COLUMNS = ['name'];

    public function list(ListQuery $query): LengthAwarePaginator
    {
        $builder = Category::query()->orderBy('name');

        return SearchablePaginator::paginate($builder, $query, self::SEARCHABLE_COLUMNS);
    }

    public function find(Category $category): Category
    {
        return $category;
    }

    public function create(array $data, User $author): Category
    {
        return Category::query()->create([
            'name' => $data['name'],
            'created_by' => $author->id,
            'updated_by' => $author->id,
        ]);
    }

    public function update(Category $category, array $data, User $author): Category
    {
        $category->update([
            'name' => $data['name'],
            'updated_by' => $author->id,
        ]);

        return $category;
    }

    public function delete(Category $category): void
    {
        if ($category->products()->exists()) {
            throw new ConflictHttpException(
                'No se puede eliminar esta categoría porque tiene productos asociados.',
            );
        }

        $category->delete();
    }
}
