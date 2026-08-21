<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Products\ProductIndexRequest;
use App\Http\Requests\Products\StoreProductRequest;
use App\Http\Requests\Products\UpdateProductRequest;
use App\Models\Product;
use App\Models\ProductDetail;
use App\Services\Products\ProductService;
use Illuminate\Http\JsonResponse;

class ProductController extends ApiController
{
    public function __construct(
        private readonly ProductService $productService,
    ) {}

    public function index(ProductIndexRequest $request): JsonResponse
    {
        return $this->success(
            $this->productService->list(
                $request->toListQuery(),
                $request->categoryId(),
                $request->minPrice(),
                $request->maxPrice(),
            ),
        );
    }

    public function show(Product $product): JsonResponse
    {
        return $this->success($this->productService->find($product));
    }

    public function store(StoreProductRequest $request): JsonResponse
    {
        $product = $this->productService->create(
            $request->validated(),
            $request->user(),
        );

        return $this->success($product, 'Producto creado correctamente.', 201);
    }

    public function update(UpdateProductRequest $request, Product $product): JsonResponse
    {
        $updated = $this->productService->update(
            $product,
            $request->validated(),
            $request->user(),
        );

        return $this->success($updated, 'Producto actualizado correctamente.');
    }

    public function destroy(Product $product): JsonResponse
    {
        $this->productService->delete($product);

        return $this->success(null, 'Producto eliminado correctamente.');
    }

    public function destroyDetail(Product $product, ProductDetail $productDetail): JsonResponse
    {
        $this->productService->deleteDetail($product, $productDetail);

        return $this->success(null, 'Detalle de producto eliminado correctamente.');
    }
}
