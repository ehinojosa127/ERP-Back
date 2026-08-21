<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Categories\StoreCategoryRequest;
use App\Http\Requests\Categories\UpdateCategoryRequest;
use App\Http\Requests\Shared\PaginatedIndexRequest;
use App\Models\Category;
use App\Services\Categories\CategoryService;
use Illuminate\Http\JsonResponse;

class CategoryController extends ApiController
{
    public function __construct(
        private readonly CategoryService $categoryService,
    ) {}

    public function index(PaginatedIndexRequest $request): JsonResponse
    {
        return $this->success($this->categoryService->list($request->toListQuery()));
    }

    public function show(Category $category): JsonResponse
    {
        return $this->success($this->categoryService->find($category));
    }

    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $category = $this->categoryService->create(
            $request->validated(),
            $request->user(),
        );

        return $this->success($category, 'Categoría creada correctamente.', 201);
    }

    public function update(UpdateCategoryRequest $request, Category $category): JsonResponse
    {
        $updated = $this->categoryService->update(
            $category,
            $request->validated(),
            $request->user(),
        );

        return $this->success($updated, 'Categoría actualizada correctamente.');
    }

    public function destroy(Category $category): JsonResponse
    {
        $this->categoryService->delete($category);

        return $this->success(null, 'Categoría eliminada correctamente.');
    }
}
