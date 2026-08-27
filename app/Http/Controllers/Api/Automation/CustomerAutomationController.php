<?php

namespace App\Http\Controllers\Api\Automation;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Automation\StoreAutomationCustomerRequest;
use App\Http\Resources\Automation\AutomationBillingDocumentResource;
use App\Http\Resources\Automation\AutomationCustomerResource;
use App\Http\Resources\Automation\AutomationOrderResource;
use App\Http\Resources\Automation\AutomationShipmentResource;
use App\Services\Automation\AutomationCustomerService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

class CustomerAutomationController extends ApiController
{
    public function __construct(
        private readonly AutomationCustomerService $customers,
    ) {}

    public function byPhone(string $phone): JsonResponse
    {
        $customer = $this->customers->findByPhone(urldecode($phone));

        return $this->success(new AutomationCustomerResource($customer));
    }

    public function summary(string $phone): JsonResponse
    {
        $summary = $this->customers->summary(urldecode($phone));

        return $this->success([
            'customer' => new AutomationCustomerResource($summary['customer']),
            'orders' => AutomationOrderResource::collection($summary['orders']),
            'pendingBalance' => $summary['pendingBalance'],
        ]);
    }

    public function orders(string $phone): JsonResponse
    {
        $orders = $this->customers->orders(urldecode($phone));

        return $this->success(AutomationOrderResource::collection($orders));
    }

    public function order(string $phone, string $orderNumber): JsonResponse
    {
        $order = $this->customers->orderByNumber(urldecode($phone), urldecode($orderNumber));

        return $this->success(new AutomationOrderResource($order));
    }

    public function balance(string $phone): JsonResponse
    {
        $balance = $this->customers->balance(urldecode($phone));

        return $this->success([
            'totalPending' => $balance['totalPending'],
            'orders' => collect($balance['orders'])->map(fn ($order) => [
                'orderNumber' => $order->order_number,
                'total' => (float) $order->total_amount,
                'paid' => (float) $order->paid_amount,
                'balance' => (float) $order->remaining_amount,
            ])->values()->all(),
        ]);
    }

    public function shipments(string $phone): JsonResponse
    {
        $shipments = $this->customers->shipments(urldecode($phone));

        return $this->success(AutomationShipmentResource::collection($shipments));
    }

    public function shipment(string $phone, string $orderNumber): JsonResponse
    {
        $shipment = $this->customers->shipmentForOrder(urldecode($phone), urldecode($orderNumber));

        return $this->success(new AutomationShipmentResource($shipment));
    }

    public function billingDocuments(string $phone, string $orderNumber): JsonResponse
    {
        $docs = $this->customers->billingDocuments(urldecode($phone), urldecode($orderNumber));

        return $this->success(AutomationBillingDocumentResource::collection($docs));
    }

    public function billingPdf(string $phone, string $documentId): Response
    {
        $file = $this->customers->downloadBillingPdf(urldecode($phone), urldecode($documentId));

        return response($file['content'], 200, [
            'Content-Type' => $file['content_type'],
            'Content-Disposition' => 'attachment; filename="'.$file['file_name'].'"',
        ]);
    }

    public function store(StoreAutomationCustomerRequest $request): JsonResponse
    {
        $customer = $this->customers->createCustomer($request->validated());

        return $this->success(
            new AutomationCustomerResource($customer),
            'Cliente creado correctamente.',
            201,
        );
    }
}
