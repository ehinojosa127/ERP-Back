<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Billing\IssueOrderDocumentRequest;
use App\Models\Order;
use App\Services\Billing\OrderBillingService;
use App\Support\Auth\PermissionGate;
use App\Support\Billing\PaymentCondition;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderBillingController extends ApiController
{
    public function __construct(
        private readonly OrderBillingService $orderBillingService,
        private readonly PermissionGate $permissionGate,
    ) {}

    public function show(Request $request, Order $order): JsonResponse
    {
        $this->permissionGate->assert($request, 'billing.detail');

        return $this->success($this->orderBillingService->forOrder($order));
    }

    public function capabilities(Request $request, Order $order): JsonResponse
    {
        $this->permissionGate->assertAny($request, 'billing.issue', 'orders.view');

        return $this->success($this->orderBillingService->capabilities($order));
    }

    public function issue(IssueOrderDocumentRequest $request, Order $order): JsonResponse
    {
        $this->permissionGate->assert($request, 'billing.issue');
        $validated = $request->validated();

        $result = $this->orderBillingService->issueFromOrder(
            $order,
            $validated['document_kind'],
            $request->user(),
            $validated['series'] ?? null,
            isset($validated['payment_id']) ? (int) $validated['payment_id'] : null,
            $validated['payment_condition'] ?? PaymentCondition::CASH,
        );

        $alreadyIssued = filled($result['warning'] ?? null);

        return $this->success(
            $result,
            $alreadyIssued ? 'El pedido ya tiene un comprobante asociado.' : 'Solicitud de comprobante registrada.',
            $alreadyIssued ? 200 : 201,
        );
    }

    public function retry(Request $request, Order $order): JsonResponse
    {
        $this->permissionGate->assert($request, 'billing.retry');

        return $this->success(
            $this->orderBillingService->retry($order, $request->user()),
            'Reintento enviado al servicio de facturación.',
        );
    }

    public function consult(Request $request, Order $order): JsonResponse
    {
        $this->permissionGate->assert($request, 'billing.consult');

        return $this->success(
            $this->orderBillingService->consult($order, $request->user()),
            'Consulta enviada a SUNAT.',
        );
    }

    public function cancel(Request $request, Order $order): JsonResponse
    {
        $this->permissionGate->assert($request, 'billing.cancel');
        $reason = trim((string) $request->input('reason', ''));
        if (strlen($reason) < 3) {
            return $this->error('Indica un motivo de baja de al menos 3 caracteres.', 422);
        }

        return $this->success(
            $this->orderBillingService->cancel($order, $reason, $request->user()),
            'Solicitud de baja registrada.',
        );
    }
}
