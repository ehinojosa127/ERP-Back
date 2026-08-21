<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Customers\StoreCustomerRequest;
use App\Http\Requests\Customers\UpdateCustomerRequest;
use App\Http\Requests\Shared\PaginatedIndexRequest;
use App\Models\Customer;
use App\Services\Customers\CustomerService;
use Illuminate\Http\JsonResponse;

class CustomerController extends ApiController
{
    public function __construct(
        private readonly CustomerService $customerService,
    ) {}

    public function index(PaginatedIndexRequest $request): JsonResponse
    {
        return $this->success($this->customerService->list($request->toListQuery()));
    }

    public function show(Customer $customer): JsonResponse
    {
        return $this->success($this->customerService->find($customer));
    }

    public function store(StoreCustomerRequest $request): JsonResponse
    {
        $customer = $this->customerService->create(
            $request->validated(),
            $request->user(),
        );

        return $this->success($customer, 'Cliente creado correctamente.', 201);
    }

    public function update(UpdateCustomerRequest $request, Customer $customer): JsonResponse
    {
        $updated = $this->customerService->update(
            $customer,
            $request->validated(),
            $request->user(),
        );

        return $this->success($updated, 'Cliente actualizado correctamente.');
    }

    public function destroy(Customer $customer): JsonResponse
    {
        $this->customerService->delete($customer);

        return $this->success(null, 'Cliente eliminado correctamente.');
    }
}
