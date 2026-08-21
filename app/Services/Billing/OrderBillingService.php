<?php

namespace App\Services\Billing;

use App\Contracts\Billing\BillingGateway;
use App\Exceptions\Billing\BillingUnavailableException;
use App\Exceptions\Billing\BillingValidationException;
use App\Models\BillingEvent;
use App\Models\Order;
use App\Models\OrderBillingReference;
use App\Models\User;
use App\Support\Billing\DocumentKind;
use App\Support\Orders\OrderStatus;

final class OrderBillingService
{
    public function __construct(
        private readonly BillingGateway $billingGateway,
        private readonly BillingDocumentMapper $mapper,
        private readonly SalesNoteService $salesNoteService,
        private readonly BillingQueryService $billingQueryService,
        private readonly BillingDocumentPdfService $pdfPreference,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function issueFromOrder(Order $order, string $kind, User $author, ?string $series = null): array
    {
        $order = $order->fresh(['customer', 'details', 'billingReference.salesNote', 'shipment']) ?? $order;

        if ($order->status === OrderStatus::CANCELLED) {
            throw new BillingValidationException('No se puede emitir un comprobante para un pedido cancelado.');
        }

        if ($order->billingReference !== null) {
            return $this->presentOrderBilling($order, warning: 'El pedido ya tiene un comprobante asociado.');
        }

        if (! in_array($kind, DocumentKind::issuableFromOrder(), true)) {
            throw new BillingValidationException('El tipo de documento no se puede emitir desde un pedido.');
        }

        if (DocumentKind::isInternal($kind)) {
            $note = $this->salesNoteService->issueFromOrder($order, $author);
            $this->recordEvent('issue.sales_note', $order, $author, null, [
                'full_number' => $note->full_number,
            ]);

            return $this->presentOrderBilling($order->fresh(['billingReference.salesNote', 'shipment', 'customer', 'details']));
        }

        $payload = $this->mapper->fromOrder($order, $kind, $series);
        $payload['pdfTemplate'] = $this->pdfPreference->selectedTemplateCode();

        if (! $this->regimeAllows($kind)) {
            throw new BillingValidationException(
                'El régimen RUS solo permite emitir boletas electrónicas. No se envió la solicitud a SUNAT.',
            );
        }
        $payload['series'] = $this->resolveSeries($kind, $series);
        unset($payload['snapshot']);

        $idempotencyKey = sprintf('erp:order:%d:%s:v1', $order->id, $kind);

        $reference = OrderBillingReference::query()->create([
            'order_id' => $order->id,
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
            $recovered = $this->recoverIssuedDocument($order, $reference, $payload['series'] ?? null);
            if ($recovered !== null) {
                $this->recordEvent('issue.recovered', $order, $author, $recovered['id'] ?? null, [
                    'message' => $exception->getMessage(),
                ]);

                return $this->presentOrderBilling(
                    $order->fresh(['billingReference.salesNote', 'shipment', 'customer', 'details']),
                    document: $recovered,
                    warning: 'El comprobante quedó registrado en facturación. Revisa el estado o usa Reintentar.',
                );
            }

            $reference->delete();
            $this->recordEvent('issue.unavailable', $order, $author, null, ['message' => $exception->getMessage()]);
            throw $exception;
        } catch (\Throwable $exception) {
            $recovered = $this->recoverIssuedDocument($order, $reference, $payload['series'] ?? null);
            if ($recovered !== null) {
                $this->recordEvent('issue.recovered', $order, $author, $recovered['id'] ?? null, [
                    'message' => $exception->getMessage(),
                    'status' => $recovered['status'] ?? null,
                ]);

                return $this->presentOrderBilling(
                    $order->fresh(['billingReference.salesNote', 'shipment', 'customer', 'details']),
                    document: $recovered,
                    warning: 'SUNAT recibió la boleta, pero el PDF/XML se completará con Reintentar si aún no aparecen.',
                );
            }

            $reference->delete();
            throw $exception;
        }

        $reference->update([
            'billing_document_id' => $document['id'] ?? null,
            'series' => $document['series'] ?? $payload['series'],
            'number' => $document['number'] ?? null,
            'full_number' => $document['fullNumber'] ?? null,
        ]);

        $this->recordEvent('issue.document', $order, $author, $document['id'] ?? null, [
            'sunatStatus' => $document['sunatStatus'] ?? null,
            'status' => $document['status'] ?? null,
            'fullNumber' => $document['fullNumber'] ?? null,
        ]);

        return $this->presentOrderBilling(
            $order->fresh(['billingReference.salesNote', 'shipment', 'customer', 'details']),
            document: $document,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function forOrder(Order $order): array
    {
        $order->loadMissing(['billingReference.salesNote.customer', 'billingReference.salesNote.order.details.product', 'customer', 'details', 'shipment']);
        $live = null;
        if ($order->billingReference?->billing_document_id) {
            try {
                $live = $this->billingGateway->getDocument($order->billingReference->billing_document_id);
            } catch (BillingUnavailableException) {
                $live = ['unavailable' => true];
            }
        }

        return $this->presentOrderBilling($order, document: $live);
    }

    /**
     * @return array<string, mixed>
     */
    public function retry(Order $order, User $author): array
    {
        $reference = $order->billingReference;
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

        return $this->presentOrderBilling(
            $order->fresh(['billingReference.salesNote', 'shipment', 'customer', 'details']),
            document: $document,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function consult(Order $order, User $author): array
    {
        $reference = $order->billingReference;
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

        return $this->presentOrderBilling(
            $order->fresh(['billingReference.salesNote', 'shipment', 'customer', 'details']),
            document: $document,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function cancel(Order $order, string $reason, User $author): array
    {
        $reference = $order->billingReference;
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

        return $this->presentOrderBilling(
            $order->fresh(['billingReference.salesNote', 'shipment', 'customer', 'details']),
            document: $document,
        );
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

        // BillingService asigna correlativo y crea la serie al emitir si no existe.
        return DocumentKind::defaultSeries($kind);
    }

    /**
     * @param  array<string, mixed>|null  $document
     * @return array<string, mixed>
     */
    private function presentOrderBilling(Order $order, ?array $document = null, ?string $warning = null): array
    {
        $reference = $order->billingReference;
        $payload = [
            'order_id' => $order->id,
            'order_status' => $order->status,
            'shipment_status' => $order->shipment?->status,
            'reference' => $reference,
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

    /**
     * @return array<string, mixed>|null
     */
    private function recoverIssuedDocument(Order $order, OrderBillingReference $reference, ?string $series): ?array
    {
        try {
            $result = $this->billingGateway->listDocuments([
                'externalSystem' => (string) config('services.billing.external_system'),
                'externalId' => (string) $order->id,
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
