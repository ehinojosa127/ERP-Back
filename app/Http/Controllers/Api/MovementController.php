<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Movements\MovementIndexRequest;
use App\Models\Movement;
use App\Services\Movements\MovementService;
use Illuminate\Http\JsonResponse;

class MovementController extends ApiController
{
    public function __construct(
        private readonly MovementService $movementService,
    ) {}

    public function index(MovementIndexRequest $request): JsonResponse
    {
        return $this->success(
            $this->movementService->list(
                $request->toListQuery(),
                $request->productId(),
                $request->type(),
                $request->referenceType(),
                $request->referenceId(),
                $request->dateFrom(),
                $request->dateTo(),
            ),
        );
    }

    public function show(Movement $movement): JsonResponse
    {
        return $this->success($this->movementService->find($movement));
    }
}
