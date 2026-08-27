<?php

namespace App\Services\Billing;

use App\Contracts\Billing\BillingGateway;
use App\Exceptions\Billing\BillingUnavailableException;
use App\Exceptions\Billing\BillingValidationException;
use App\Models\BillingEvent;
use App\Models\Order;
use App\Models\OrderBillingReference;
use App\Models\OrderPayment;
use App\Models\User;
use App\Support\Billing\BillingEmissionStatus;
use App\Support\Billing\DocumentKind;
use App\Support\Billing\PaymentCondition;
use App\Support\Inventory\PaymentMethod;
use App\Support\Orders\OrderStatus;

final class OrderBillingService
{
    public function __construct(
        private readonly BillingGateway $billingGateway,
        private readonly BillingDocumentMapper $mapper,
        private readonly SalesNoteService $salesNoteService,
        private readonly BillingQueryService $billingQueryService,
        private readonly BillingDocumentPdfService $pdfPreference,
        private readonly PaymentConceptSuggester $conceptSuggester,
    ) {}

    /**
     * Emisión tributaria desde un pago (regla principal: Order → N Payments → 0..N CPE).
     *
     * @return array<string, mixed>
     */
    public function issueFromPayment(
        Order $order,
        OrderPayment $payment,
        string $kind,
        User $author,
        ?string $series = null,
        string $paymentCondition = PaymentCondition::CASH,
    ): array {
        if ($payment->order_id !== $order->id) {
            throw new BillingValidationException('El pago no pertenece a este pedido.');
        }

        $order = $order->fresh(['customer', 'details', 'shipment']) ?? $order;
        $payment = $payment->fresh(['billingReference.salesNote']) ?? $payment;

        if ($order->status === OrderStatus::CANCELLED) {
            throw new BillingValidationException('No se puede emitir un comprobante para un pedido cancelado.');
        }

        if ($payment->billingReference !== null) {
            return $this->presentOrderBilling(
                $order->fresh(['billingReferences.salesNote', 'billingReferences.payment', 'shipment', 'customer', 'details', 'payments.billingReference']),
                warning: 'Este pago ya tiene un comprobante asociado.',
                focusPaymentId: $payment->id,
            );
        }

        if (! in_array($kind, DocumentKind::issuableFromOrder(), true)) {
            throw new BillingValidationException('El tipo de documento no se puede emitir desde un pago.');
        }

        $payment->update(['billing_emission_status' => BillingEmissionStatus::PENDING]);

        if (DocumentKind::isInternal($kind)) {
            $note = $this->salesNoteService->issueFromPayment($order, $payment, $author);
            $payment->update(['billing_emission_status' => BillingEmissionStatus::ISSUED]);
            $this->recordEvent('issue.sales_note', $order, $author, null, [
                'full_number' => $note->full_number,
                'order_payment_id' => $payment->id,
            ]);

            return $this->presentOrderBilling(
                $order->fresh(['billingReferences.salesNote', 'billingReferences.payment', 'shipment', 'customer', 'details', 'payments.billingReference']),
                focusPaymentId: $payment->id,
            );
        }

        $payload = $this->mapper->fromPayment($order, $payment, $kind, $series, $paymentCondition);
        $payload['pdfTemplate'] = $this->pdfPreference->selectedTemplateCode();

        if (! $this->regimeAllows($kind)) {
            $payment->update(['billing_emission_status' => BillingEmissionStatus::FAILED]);
            throw new BillingValidationException(
                'El régimen RUS solo permite emitir boletas electrónicas. No se envió la solicitud a SUNAT.',
            );
        }
        $payload['series'] = $this->resolveSeries($kind, $series);
        unset($payload['snapshot']);

        $idempotencyKey = sprintf('erp:payment:%d:%s:v1', $payment->id, $kind);

        $reference = OrderBillingReference::query()->create([
            'order_id' => $order->id,
            'order_payment_id' => $payment->id,
            'document_kind' => $kind,
            'origin' => 'billing_service',
            'idempotency_key' => $idempotencyKey,
        ]);

        try {
            $document = $this->billingGateway->issue(
                DocumentKind::billingPath($kind),
                $payload,
                $idempotencyKey,
            );
        } catch (BillingUnavailableException $exception) {
            $recovered = $this->recoverIssuedDocument($payment, $reference, $payload['series'] ?? null);
            if ($recovered !== null) {
                $payment->update(['billing_emission_status' => BillingEmissionStatus::ISSUED]);
                $this->recordEvent('issue.recovered', $order, $author, $recovered['id'] ?? null, [
                    'message' => $exception->getMessage(),
                    'order_payment_id' => $payment->id,
                ]);

                return $this->presentOrderBilling(
                    $order->fresh(['billingReferences.salesNote', 'billingReferences.payment', 'shipment', 'customer', 'details', 'payments.billingReference']),
                    document: $recovered,
                    warning: 'El comprobante quedó registrado en facturación. Revisa el estado o usa Reintentar.',
                    focusPaymentId: $payment->id,
                );
            }

            $reference->delete();
            $payment->update(['billing_emission_status' => BillingEmissionStatus::FAILED]);
            $this->recordEvent('issue.unavailable', $order, $author, null, [
                'message' => $exception->getMessage(),
                'order_payment_id' => $payment->id,
            ]);
            throw $exception;
        } catch (\Throwable $exception) {
            $recovered = $this->recoverIssuedDocument($payment, $reference, $payload['series'] ?? null);
            if ($recovered !== null) {
                $payment->update(['billing_emission_status' => BillingEmissionStatus::ISSUED]);
                $this->recordEvent('issue.recovered', $order, $author, $recovered['id'] ?? null, [
                    'message' => $exception->getMessage(),
                    'status' => $recovered['status'] ?? null,
                    'order_payment_id' => $payment->id,
                ]);

                return $this->presentOrderBilling(
                    $order->fresh(['billingReferences.salesNote', 'billingReferences.payment', 'shipment', 'customer', 'details', 'payments.billingReference']),
                    document: $recovered,
                    warning: 'SUNAT recibió el comprobante, pero el PDF/XML se completará con Reintentar si aún no aparecen.',
                    focusPaymentId: $payment->id,
                );
            }

            $reference->delete();
            $payment->update(['billing_emission_status' => BillingEmissionStatus::FAILED]);
            throw $exception;
        }

        $reference->update([
            'billing_document_id' => $document['id'] ?? null,
            'series' => $document['series'] ?? $payload['series'],
            'number' => $document['number'] ?? null,
            'full_number' => $document['fullNumber'] ?? null,
        ]);
        $payment->update(['billing_emission_status' => BillingEmissionStatus::ISSUED]);

        $this->recordEvent('issue.document', $order, $author, $document['id'] ?? null, [
            'sunatStatus' => $document['sunatStatus'] ?? null,
            'status' => $document['status'] ?? null,
            'fullNumber' => $document['fullNumber'] ?? null,
            'order_payment_id' => $payment->id,
        ]);

        return $this->presentOrderBilling(
            $order->fresh(['billingReferences.salesNote', 'billingReferences.payment', 'shipment', 'customer', 'details', 'payments.billingReference']),
            document: $document,
            focusPaymentId: $payment->id,
        );
    }

    /**
     * Reintento de emisión cuando el pago quedó con billing_emission_status=failed.
     *
     * @return array<string, mixed>
     */
    public function retryEmissionFromPayment(
        Order $order,
        OrderPayment $payment,
        string $kind,
        User $author,
        ?string $series = null,
        string $paymentCondition = PaymentCondition::CASH,
    ): array {
        if ($payment->billingReference !== null) {
            return $this->issueFromPayment($order, $payment, $kind, $author, $series, $paymentCondition);
        }

        return $this->issueFromPayment($order, $payment, $kind, $author, $series, $paymentCondition);
    }

    /**
     * Flujo legado (pedido completo). Preferir issueFromPayment.
     * Si se pasa payment_id, delega al flujo por pago.
     *
     * @return array<string, mixed>
     */
    public function issueFromOrder(
        Order $order,
        string $kind,
        User $author,
        ?string $series = null,
        ?int $paymentId = null,
        string $paymentCondition = PaymentCondition::CASH,
    ): array {
        if ($paymentId !== null) {
            $payment = OrderPayment::query()->whereKey($paymentId)->where('order_id', $order->id)->first();
            if ($payment === null) {
                throw new BillingValidationException('El pago indicado no existe en este pedido.');
            }

            return $this->issueFromPayment($order, $payment, $kind, $author, $series, $paymentCondition);
        }

        $order = $order->fresh(['customer', 'details.product', 'billingReferences.salesNote', 'shipment', 'payments']) ?? $order;

        if ($order->status === OrderStatus::CANCELLED) {
            throw new BillingValidationException('No se puede emitir un comprobante para un pedido cancelado.');
        }

        if ($order->billingReferences->isNotEmpty()) {
            return $this->presentOrderBilling($order, warning: 'El pedido ya tiene al menos un comprobante asociado. Emite desde un pago sin comprobante.');
        }

        // Compatibilidad UI antigua: crear pago por el saldo restante y emitir sobre él.
        $remaining = (float) $order->remaining_amount;
        if ($remaining <= 0.00001) {
            throw new BillingValidationException('No hay saldo pendiente. Registra un pago y emite el comprobante desde ese pago.');
        }

        $concept = $this->conceptSuggester->suggest($order, $remaining, $remaining)
            ?? sprintf('Pago pedido %s', $order->order_number);

        $payment = OrderPayment::query()->create([
            'amount' => $remaining,
            'concept' => $concept,
            'payment_method' => PaymentMethod::CASH,
            'payment_date' => now()->toDateString(),
            'order_id' => $order->id,
            'created_by' => $author->id,
            'updated_by' => $author->id,
        ]);

        return $this->issueFromPayment($order, $payment, $kind, $author, $series, $paymentCondition);
    }

    /**
     * @return array<string, mixed>
     */
    public function forOrder(Order $order): array
    {
        $order->loadMissing([
            'billingReferences.salesNote.customer',
            'billingReferences.salesNote.order.details.product',
            'billingReferences.payment',
            'payments.billingReference.salesNote',
            'customer',
            'details',
            'shipment',
        ]);

        $documents = [];
        foreach ($order->billingReferences as $reference) {
            $live = null;
            if ($reference->billing_document_id) {
                try {
                    $live = $this->billingGateway->getDocument($reference->billing_document_id);
                } catch (BillingUnavailableException) {
                    $live = ['unavailable' => true];
                }
            }
            $documents[] = $this->presentReference($reference, $live);
        }

        $latest = $order->billingReference;
        $latestLive = null;
        if ($latest?->billing_document_id) {
            try {
                $latestLive = $this->billingGateway->getDocument($latest->billing_document_id);
            } catch (BillingUnavailableException) {
                $latestLive = ['unavailable' => true];
            }
        }

        return $this->presentOrderBilling($order, document: $latestLive, documents: $documents);
    }

    /**
     * @return array<string, mixed>
     */
    public function retry(Order $order, User $author, ?string $billingDocumentId = null): array
    {
        $reference = $this->resolveElectronicReference($order, $billingDocumentId);
        if ($reference === null || $reference->billing_document_id === null) {
            throw new BillingValidationException('El pedido no tiene un comprobante electrónico para reintentar.');
        }

        $status = $this->billingGateway->getStatus($reference->billing_document_id);
        if (! ($status['canRetry'] ?? false)) {
            throw new BillingValidationException('Este documento no se puede reintentar. El rechazo tributario requiere corregir los datos o emitir uno nuevo.');
        }

        $document = $this->billingGateway->retry($reference->billing_document_id);
        $this->recordEvent('retry', $order, $author, $reference->billing_document_id, [
            'sunatStatus' => $document['sunatStatus'] ?? null,
        ]);

        return $this->forOrder($order->fresh());
    }

    /**
     * @return array<string, mixed>
     */
    public function consult(Order $order, User $author, ?string $billingDocumentId = null): array
    {
        $reference = $this->resolveElectronicReference($order, $billingDocumentId);
        if ($reference === null || $reference->billing_document_id === null) {
            throw new BillingValidationException('El pedido no tiene un comprobante electrónico para consultar.');
        }

        $status = $this->billingGateway->getStatus($reference->billing_document_id);
        if (! ($status['canConsult'] ?? false)) {
            throw new BillingValidationException('Este documento no se puede consultar en SUNAT.');
        }

        $document = $this->billingGateway->consult($reference->billing_document_id);
        $this->recordEvent('consult', $order, $author, $reference->billing_document_id, [
            'sunatStatus' => $document['sunatStatus'] ?? null,
            'status' => $document['status'] ?? null,
        ]);

        return $this->forOrder($order->fresh());
    }

    /**
     * @return array<string, mixed>
     */
    public function cancel(Order $order, string $reason, User $author, ?string $billingDocumentId = null): array
    {
        $reference = $this->resolveElectronicReference($order, $billingDocumentId);
        if ($reference === null || $reference->billing_document_id === null) {
            throw new BillingValidationException('El pedido no tiene un comprobante electrónico para dar de baja.');
        }

        $status = $this->billingGateway->getStatus($reference->billing_document_id);
        if (! ($status['canCancel'] ?? false)) {
            throw new BillingValidationException('Este documento no se puede dar de baja en su estado actual.');
        }

        $document = $this->billingGateway->cancel($reference->billing_document_id, $reason);
        $this->recordEvent('cancel', $order, $author, $reference->billing_document_id, [
            'reason' => $reason,
            'status' => $document['status'] ?? null,
        ]);

        return $this->forOrder($order->fresh());
    }

    /**
     * @return array<string, mixed>
     */
    public function capabilities(Order $order): array
    {
        $order->loadMissing('customer');
        $customer = $order->customer;
        $options = [
            [
                'kind' => DocumentKind::SALES_NOTE,
                'label' => 'Nota de venta',
                'internal' => true,
                'enabled' => true,
                'reason' => null,
            ],
        ];

        $hasDni = $customer !== null && strlen((string) preg_replace('/\D+/', '', (string) $customer->dni)) === 8;
        $hasRuc = $customer !== null && strlen((string) preg_replace('/\D+/', '', (string) $customer->ruc)) === 11;
        $profile = $this->issuerCapabilities();
        $canIssueReceipt = (bool) ($profile['canIssueReceipt'] ?? true);
        $canIssueInvoice = (bool) ($profile['canIssueInvoice'] ?? true);

        $options[] = [
            'kind' => DocumentKind::RECEIPT,
            'label' => 'Boleta electrónica',
            'internal' => false,
            'enabled' => $hasDni && $canIssueReceipt,
            'reason' => ! $canIssueReceipt
                ? 'El régimen tributario del emisor no permite boletas.'
                : ($hasDni ? null : 'El cliente necesita un DNI de 8 dígitos.'),
        ];
        $options[] = [
            'kind' => DocumentKind::INVOICE,
            'label' => 'Factura electrónica',
            'internal' => false,
            'enabled' => $hasRuc && $canIssueInvoice,
            'reason' => ! $canIssueInvoice
                ? 'El régimen RUS no permite facturas. Solo boletas o nota de venta.'
                : ($hasRuc ? null : 'El cliente necesita un RUC de 11 dígitos.'),
        ];

        $series = [];
        try {
            $series = $this->billingGateway->listSeries();
        } catch (BillingUnavailableException) {
            $series = [];
        }

        return [
            'options' => $options,
            'series' => $series,
            'billingAvailable' => $series !== [] || $this->billingIsConfigured(),
            'taxRegime' => $profile['taxRegime'] ?? null,
            'taxpayerType' => $profile['taxpayerType'] ?? null,
            'payment_conditions' => [
                ['value' => PaymentCondition::CASH, 'label' => 'Contado'],
                ['value' => PaymentCondition::CREDIT, 'label' => 'Crédito'],
            ],
        ];
    }

    private function resolveSeries(string $kind, ?string $series): string
    {
        if ($series !== null && $series !== '') {
            return strtoupper($series);
        }

        $code = DocumentKind::sunatTypeCode($kind);
        try {
            $available = $this->billingGateway->listSeries();
        } catch (BillingUnavailableException) {
            $available = [];
        }

        foreach ($available as $item) {
            if (($item['documentType'] ?? null) === $code && ($item['isActive'] ?? true)) {
                return (string) $item['series'];
            }
        }

        return DocumentKind::defaultSeries($kind);
    }

    /**
     * @param  array<string, mixed>|null  $document
     * @param  array<int, array<string, mixed>>|null  $documents
     * @return array<string, mixed>
     */
    private function presentOrderBilling(
        Order $order,
        ?array $document = null,
        ?string $warning = null,
        ?array $documents = null,
        ?int $focusPaymentId = null,
    ): array {
        $order->loadMissing([
            'billingReferences.salesNote',
            'billingReferences.payment',
            'payments.billingReference',
            'shipment',
        ]);

        $reference = $focusPaymentId !== null
            ? $order->billingReferences->firstWhere('order_payment_id', $focusPaymentId)
            : $order->billingReference;

        $documents ??= $order->billingReferences->map(
            fn (OrderBillingReference $ref) => $this->presentReference($ref, null),
        )->values()->all();

        $payload = [
            'order_id' => $order->id,
            'order_status' => $order->status,
            'shipment_status' => $order->shipment?->status,
            'reference' => $reference,
            'documents' => $documents,
            'document' => $this->normalizeElectronicDocument($document),
            'sales_note' => $reference?->salesNote
                ? $this->billingQueryService->formatSalesNote($reference->salesNote)
                : null,
            'warning' => $warning,
        ];

        if (is_array($document) && ($document['sunatStatus'] ?? null) === 'rejected') {
            $payload['sunat_rejected'] = true;
            $payload['sunat_message'] = $document['sunatDescription'] ?? 'Rechazado por SUNAT';
            $payload['sunat_code'] = $document['sunatResponseCode'] ?? null;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>|null  $document
     * @return array<string, mixed>
     */
    private function presentReference(OrderBillingReference $reference, ?array $document): array
    {
        return [
            'id' => $reference->id,
            'order_payment_id' => $reference->order_payment_id,
            'document_kind' => $reference->document_kind,
            'origin' => $reference->origin,
            'billing_document_id' => $reference->billing_document_id,
            'series' => $reference->series,
            'number' => $reference->number,
            'full_number' => $reference->full_number,
            'sales_note' => $reference->salesNote
                ? $this->billingQueryService->formatSalesNote($reference->salesNote)
                : null,
            'document' => $this->normalizeElectronicDocument($document),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $document
     * @return array<string, mixed>|null
     */
    private function normalizeElectronicDocument(?array $document): ?array
    {
        if ($document === null || ($document['unavailable'] ?? false)) {
            return $document;
        }

        return $this->billingQueryService->formatElectronicDocument($document);
    }

    private function billingIsConfigured(): bool
    {
        return filled(config('services.billing.url'));
    }

    private function regimeAllows(string $kind): bool
    {
        $profile = $this->issuerCapabilities();
        if ($profile === []) {
            return true;
        }

        return match ($kind) {
            DocumentKind::INVOICE => (bool) ($profile['canIssueInvoice'] ?? true),
            DocumentKind::RECEIPT => (bool) ($profile['canIssueReceipt'] ?? true),
            default => true,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function issuerCapabilities(): array
    {
        try {
            return $this->billingGateway->capabilities();
        } catch (BillingUnavailableException) {
            return [];
        }
    }

    private function resolveElectronicReference(Order $order, ?string $billingDocumentId): ?OrderBillingReference
    {
        $order->loadMissing('billingReferences');
        if ($billingDocumentId !== null && $billingDocumentId !== '') {
            return $order->billingReferences
                ->first(fn (OrderBillingReference $ref) => $ref->billing_document_id === $billingDocumentId
                    && $ref->origin === 'billing_service');
        }

        return $order->billingReferences
            ->first(fn (OrderBillingReference $ref) => $ref->origin === 'billing_service' && filled($ref->billing_document_id));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function recoverIssuedDocument(OrderPayment $payment, OrderBillingReference $reference, ?string $series): ?array
    {
        try {
            $result = $this->billingGateway->listDocuments([
                'externalSystem' => (string) config('services.billing.external_system'),
                'externalId' => (string) $payment->id,
                'skip' => 0,
                'take' => 5,
            ]);
        } catch (BillingUnavailableException) {
            return null;
        }

        $items = $result['items'] ?? [];
        if (! is_array($items) || $items === []) {
            return null;
        }

        $item = $items[0];
        $id = $item['id'] ?? null;
        if (! is_string($id) || $id === '') {
            return null;
        }

        try {
            $document = $this->billingGateway->getDocument($id);
        } catch (\Throwable) {
            $document = $item;
        }

        $reference->update([
            'billing_document_id' => $document['id'] ?? $id,
            'series' => $document['series'] ?? $series,
            'number' => $document['number'] ?? null,
            'full_number' => $document['fullNumber'] ?? null,
        ]);

        return $document;
    }

    private function recordEvent(string $event, Order $order, User $author, ?string $billingDocumentId, array $payload): void
    {
        BillingEvent::query()->create([
            'event' => $event,
            'order_id' => $order->id,
            'user_id' => $author->id,
            'billing_document_id' => $billingDocumentId,
            'payload' => $payload,
        ]);
    }
}
