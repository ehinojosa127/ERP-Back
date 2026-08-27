<?php

namespace App\Http\Controllers\Api\Automation;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Automation\AutomationProductIndexRequest;
use App\Http\Resources\Automation\AutomationProductResource;
use App\Models\Product;
use App\Services\Automation\AutomationProductService;
use Illuminate\Http\JsonResponse;

class ProductAutomationController extends ApiController
{
    public function __construct(
        private readonly AutomationProductService $products,
    ) {}

    public function index(AutomationProductIndexRequest $request): JsonResponse
    {
        $paginator = $this->products->list(
            $request->toListQuery(),
            $request->categoryId(),
            $request->minPrice(),
            $request->maxPrice(),
        );

        $paginator->through(
            fn (Product $product) => (new AutomationProductResource($product))->resolve(),
        );

        return $this->success($paginator);
    }

    public function available(AutomationProductIndexRequest $request): JsonResponse
    {
        $paginator = $this->products->list(
            $request->toListQuery(),
            $request->categoryId(),
            $request->minPrice(),
            $request->maxPrice(),
            availableOnly: true,
        );

        $paginator->through(
            fn (Product $product) => (new AutomationProductResource($product))->resolve(),
        );

        return $this->success($paginator);
    }

    public function stock(Product $product): JsonResponse
    {
        return $this->success($this->products->stock((int) $product->id));
    }
}
