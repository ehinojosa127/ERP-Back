<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Attributes\StoreAttributeRequest;
use App\Http\Requests\Attributes\UpdateAttributeRequest;
use App\Http\Requests\Shared\PaginatedIndexRequest;
use App\Models\Attribute;
use App\Services\Attributes\AttributeService;
use Illuminate\Http\JsonResponse;

class AttributeController extends ApiController
{
    public function __construct(
        private readonly AttributeService $attributeService,
    ) {}

    public function index(PaginatedIndexRequest $request): JsonResponse
    {
        return $this->success($this->attributeService->list($request->toListQuery()));
    }

    public function show(Attribute $attribute): JsonResponse
    {
        return $this->success($this->attributeService->find($attribute));
    }

    public function store(StoreAttributeRequest $request): JsonResponse
    {
        $attribute = $this->attributeService->create(
            $request->validated(),
            $request->user(),
        );

        return $this->success($attribute, 'Atributo creado correctamente.', 201);
    }

    public function update(UpdateAttributeRequest $request, Attribute $attribute): JsonResponse
    {
        $updated = $this->attributeService->update(
            $attribute,
            $request->validated(),
            $request->user(),
        );

        return $this->success($updated, 'Atributo actualizado correctamente.');
    }

    public function destroy(Attribute $attribute): JsonResponse
    {
        $this->attributeService->delete($attribute);

        return $this->success(null, 'Atributo eliminado correctamente.');
    }
}
