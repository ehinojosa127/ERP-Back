<?php

namespace App\Http\Controllers\Api\Automation;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Automation\StoreAutomationOrderRequest;
use App\Http\Resources\Automation\AutomationOrderResource;
use App\Models\User;
use App\Services\Orders\OrderService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

class OrderAutomationController extends ApiController
{
    public function __construct(
        private readonly OrderService $orderService,
    ) {}

    public function store(StoreAutomationOrderRequest $request): JsonResponse
    {
        $author = User::query()->orderBy('id')->first();
        if ($author === null) {
            throw new HttpException(503, 'No hay un usuario del sistema para registrar el pedido.');
        }

        $order = $this->orderService->create($request->validated(), $author);

        return $this->success(
            new AutomationOrderResource($order),
            'Pedido creado correctamente.',
            201,
        );
    }
}
