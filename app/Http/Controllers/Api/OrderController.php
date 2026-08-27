<?php

namespace App\Http\Controllers\Api;

use App\Exceptions\Billing\BillingException;
use App\Http\Requests\Orders\OrderIndexRequest;
use App\Http\Requests\Orders\StoreOrderPaymentRequest;
use App\Http\Requests\Orders\StoreOrderRequest;
use App\Http\Requests\Orders\UpdateOrderRequest;
use App\Http\Requests\Orders\UpdateOrderStatusRequest;
use App\Http\Requests\Orders\UpdateShipmentStatusRequest;
use App\Http\Requests\Shared\PaginatedIndexRequest;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Services\Billing\OrderBillingService;
use App\Services\Orders\OrderService;
use App\Support\Auth\PermissionGate;
use App\Events\OrderReadyForPickup;
use App\Support\Billing\PaymentCondition;
use App\Support\Orders\OrderStatus;
use App\Support\Orders\ShipmentStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderController extends ApiController
{
    public function __construct(
        private readonly OrderService $orderService,
        private readonly OrderBillingService $orderBillingService,
        private readonly PermissionGate $permissionGate,
    ) {}

    public function index(OrderIndexRequest $request): JsonResponse
    {
        $this->permissionGate->assert($request, 'orders.view');

        return $this->success(
            $this->orderService->list(
                $request->toListQuery(),
                $request->customerId(),
                $request->status(),
                $request->paymentStatus(),
                $request->dateFrom(),
                $request->dateTo(),
                $request->minTotal(),
                $request->maxTotal(),
            ),
        );
    }

    public function show(Request $request, Order $order): JsonResponse
    {
        $this->permissionGate->assert($request, 'orders.view');

        return $this->success($this->orderService->find($order));
    }

    public function store(StoreOrderRequest $request): JsonResponse
    {
        $this->permissionGate->assert($request, 'orders.create');

        $order = $this->orderService->create(
            $request->validated(),
            $request->user(),
        );

        return $this->success(
            $this->orderService->find($order),
            'Pedido creado correctamente.',
            201,
        );
    }

    public function update(UpdateOrderRequest $request, Order $order): JsonResponse
    {
        $this->permissionGate->assert($request, 'orders.update');

        $updated = $this->orderService->update(
            $order,
            $request->validated(),
            $request->user(),
        );

        return $this->success($updated, 'Pedido actualizado correctamente.');
    }

    public function destroy(Request $request, Order $order): JsonResponse
    {
        $this->permissionGate->assert($request, 'orders.delete');

        $this->orderService->delete($order);

        return $this->success(null, 'Pedido eliminado correctamente.');
    }

    public function updateStatus(
        UpdateOrderStatusRequest $request,
        Order $order,
    ): JsonResponse {
        $status = $request->validated('status');

        match ($status) {
            OrderStatus::PREPARING => $this->permissionGate->assert($request, 'orders.update'),
            OrderStatus::SHIPPED => $this->permissionGate->assert($request, 'orders.ship'),
            OrderStatus::CLOSED => $this->permissionGate->assert($request, 'orders.close'),
            OrderStatus::CANCELLED => $this->permissionGate->assert($request, 'orders.delete'),
            default => $this->permissionGate->assert($request, 'orders.update'),
        };

        $updated = $this->orderService->updateStatus(
            $order,
            $request->validated(),
            $request->user(),
        );

        return $this->success($updated, 'Estado del pedido actualizado correctamente.');
    }

    public function payments(PaginatedIndexRequest $request, Order $order): JsonResponse
    {
        $this->permissionGate->assert($request, 'orders.view');

        return $this->success(
            $this->orderService->listPayments($order, $request->toListQuery()),
        );
    }

    public function storePayment(
        StoreOrderPaymentRequest $request,
        Order $order,
    ): JsonResponse {
        $this->permissionGate->assert($request, 'orders.payments');

        $data = $request->validated();
        if ($request->hasFile('receipt_file')) {
            $data['receipt_file'] = $request->file('receipt_file');
        }

        $remainingBefore = (float) $order->remaining_amount;

        $payment = $this->orderService->createPayment(
            $order,
            $data,
            $request->user(),
        );

        $billing = null;
        $billingError = null;
        $emit = (bool) ($data['emit_document'] ?? false);

        if ($emit) {
            try {
                $this->permissionGate->assert($request, 'billing.issue');
                $billing = $this->orderBillingService->issueFromPayment(
                    $order,
                    $payment,
                    $data['document_kind'],
                    $request->user(),
                    $data['series'] ?? null,
                    $data['payment_condition'] ?? PaymentCondition::CASH,
                );
                $payment = $payment->fresh(['billingReference']) ?? $payment;
            } catch (BillingException $exception) {
                $billingError = $exception->getMessage();
            }
        }

        $orderFresh = $this->orderService->find($order);
        if (
            $remainingBefore > 0.00001
            && (float) $orderFresh->remaining_amount <= 0.00001
            && $orderFresh->shipment?->status === ShipmentStatus::AT_DESTINATION
        ) {
            event(new OrderReadyForPickup($orderFresh));
        }

        return $this->success([
            'payment' => $payment,
            'order' => $orderFresh,
            'billing' => $billing,
            'billing_error' => $billingError,
            'suggested_concept' => $payment->concept,
        ], 'Pago registrado correctamente.', 201);
    }

    public function downloadPaymentReceipt(
        Request $request,
        Order $order,
        OrderPayment $payment,
    ): \Symfony\Component\HttpFoundation\StreamedResponse {
        $this->permissionGate->assert($request, 'orders.view');

        $file = $this->orderService->paymentReceiptDownload($order, $payment);

        return \Illuminate\Support\Facades\Storage::disk('local')->download(
            $file['path'],
            $file['name'],
            ['Content-Type' => $file['mime']],
        );
    }

    public function destroyPayment(
        Request $request,
        Order $order,
        OrderPayment $payment,
    ): JsonResponse {
        $this->permissionGate->assert($request, 'orders.payments');

        $this->orderService->deletePayment($order, $payment);

        return $this->success(null, 'Pago eliminado correctamente.');
    }

    public function shipment(Request $request, Order $order): JsonResponse
    {
        $this->permissionGate->assert($request, 'orders.view');

        $shipment = $this->orderService->getShipment($order);

        if ($shipment === null) {
            return $this->error('El pedido no tiene un envío asociado.', 404);
        }

        return $this->success($shipment);
    }

    public function updateShipmentStatus(
        UpdateShipmentStatusRequest $request,
        Order $order,
    ): JsonResponse {
        $this->permissionGate->assert($request, 'orders.shipment.update');

        $updated = $this->orderService->updateShipmentStatus(
            $order,
            $request->validated('status'),
            $request->user(),
        );

        return $this->success($updated, 'Estado del envío actualizado correctamente.');
    }
}
