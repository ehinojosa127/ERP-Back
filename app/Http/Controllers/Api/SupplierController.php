<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Suppliers\StoreSupplierRequest;
use App\Http\Requests\Suppliers\SupplierIndexRequest;
use App\Http\Requests\Suppliers\UpdateSupplierRequest;
use App\Models\Supplier;
use App\Services\Suppliers\SupplierService;
use Illuminate\Http\JsonResponse;

class SupplierController extends ApiController
{
    public function __construct(
        private readonly SupplierService $supplierService,
    ) {}

    public function index(SupplierIndexRequest $request): JsonResponse
    {
        return $this->success(
            $this->supplierService->list($request->toListQuery(), $request->kind()),
        );
    }

    public function show(Supplier $supplier): JsonResponse
    {
        return $this->success($this->supplierService->find($supplier));
    }

    public function store(StoreSupplierRequest $request): JsonResponse
    {
        $supplier = $this->supplierService->create(
            $request->validated(),
            $request->user(),
        );

        return $this->success($supplier, 'Proveedor creado correctamente.', 201);
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier): JsonResponse
    {
        $updated = $this->supplierService->update(
            $supplier,
            $request->validated(),
            $request->user(),
        );

        return $this->success($updated, 'Proveedor actualizado correctamente.');
    }

    public function destroy(Supplier $supplier): JsonResponse
    {
        $this->supplierService->delete($supplier);

        return $this->success(null, 'Proveedor eliminado correctamente.');
    }
}
