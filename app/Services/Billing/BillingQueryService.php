<?php

namespace App\Services\Billing;

use App\Contracts\Billing\BillingGateway;
use App\Exceptions\Billing\BillingUnavailableException;
use App\Models\BillingEvent;
use App\Models\SalesNote;
use App\Models\User;
use App\Support\Billing\DocumentKind;
use App\Support\Query\ListQuery;
use App\Support\Query\SearchablePaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;

final class BillingQueryService
{
    private const SALES_NOTE_SEARCH_COLUMNS = [
        'full_number',
        'customer_name',
        'customer_document',
        'order.order_number',
    ];

    public function __construct(
        private readonly BillingGateway $billingGateway,
        private readonly BillingDocumentPdfService $pdfPreference,
    ) {}

    /**
     * @return array<string, mixed>|LengthAwarePaginator
     */
    public function list(ListQuery $query, array $filters): array|LengthAwarePaginator
    {
        $type = $this->normalizedType($filters);

        if ($type === DocumentKind::SALES_NOTE) {
            return $this->listSalesNotes($query, $filters);
        }

        if ($type !== null) {
            return $this->listElectronic($query, $filters, $type);
        }

        return $this->listAll($query, $filters);
    }

    /**
     * @return array<string, mixed>
     */
    public function show(string $id): array
    {
        if (str_starts_with($id, 'sn-')) {
            $note = SalesNote::query()->with(['order.details.product', 'customer'])->findOrFail((int) substr($id, 3));

            return $this->mapSalesNote($note);
        }

        return $this->mapElectronicDetail($this->billingGateway->getDocument($id));
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    public function formatElectronicDocument(array $document): array
    {
        return $this->mapElectronicDetail($document);
    }

    public function formatSalesNote(SalesNote $note): array
    {
        return $this->mapSalesNote($note);
    }

    /**
     * @return array{content: string, content_type: string, file_name: string}
     */
    public function download(string $id, string $kind, User $author): array
    {
        if (str_starts_with($id, 'sn-')) {
            if ($kind !== 'pdf') {
                throw new \App\Exceptions\Billing\BillingValidationException(
                    'Las notas de venta solo generan PDF. No tienen XML ni CDR.',
                );
            }

            $note = SalesNote::query()->with(['order.details.product', 'customer'])->findOrFail((int) substr($id, 3));
            $file = $this->renderSalesNotePdf($note);
            BillingEvent::query()->create([
                'event' => 'download.pdf',
                'user_id' => $author->id,
                'payload' => [
                    'sales_note_id' => $note->id,
                    'file_name' => $file['file_name'],
                ],
            ]);

            return $file;
        }

        $file = $this->billingGateway->download($id, $kind);
        BillingEvent::query()->create([
            'event' => 'download.'.$kind,
            'user_id' => $author->id,
            'billing_document_id' => $id,
            'payload' => ['file_name' => $file['file_name']],
        ]);

        return $file;
    }

    public function regeneratePdf(string $id, User $author, ?string $templateType = null): array
    {
        if (str_starts_with($id, 'sn-')) {
            $note = SalesNote::query()->with(['order.details.product', 'customer'])->findOrFail((int) substr($id, 3));
            $this->renderSalesNotePdf($note, $templateType);
            BillingEvent::query()->create([
                'event' => 'regenerate.pdf',
                'user_id' => $author->id,
                'payload' => [
                    'sales_note_id' => $note->id,
                    'template_type' => $templateType ?? $this->pdfPreference->selectedTemplateCode(),
                ],
            ]);

            return [
                'id' => 'sn-'.$note->id,
                'templateType' => $templateType ?? $this->pdfPreference->selectedTemplateCode(),
                'pdfAvailable' => true,
            ];
        }
        $template = $templateType ?? $this->pdfPreference->selectedTemplateCode();
        $result = $this->billingGateway->regeneratePdf($id, $template);
        BillingEvent::query()->create([
            'event' => 'regenerate.pdf',
            'user_id' => $author->id,
            'billing_document_id' => $id,
            'payload' => [
                'template_type' => $result['templateType'] ?? $result['template_type'] ?? $template,
            ],
        ]);

        return $result;
    }

    public function retry(string $id, User $author): array
    {
        $status = $this->billingGateway->getStatus($id);
        if (! ($status['canRetry'] ?? false)) {
            throw new \App\Exceptions\Billing\BillingValidationException(
                'Este documento no se puede reintentar. El rechazo tributario requiere corregir los datos o emitir uno nuevo.',
            );
        }

        $document = $this->billingGateway->retry($id);
        BillingEvent::query()->create([
            'event' => 'retry',
            'user_id' => $author->id,
            'billing_document_id' => $id,
            'payload' => ['sunatStatus' => $document['sunatStatus'] ?? null],
        ]);

        return $this->mapElectronicDetail($document);
    }

    public function consult(string $id, User $author): array
    {
        $status = $this->billingGateway->getStatus($id);
        if (! ($status['canConsult'] ?? false)) {
            throw new \App\Exceptions\Billing\BillingValidationException(
                'Este documento no se puede consultar en SUNAT.',
            );
        }

        $document = $this->billingGateway->consult($id);
        BillingEvent::query()->create([
            'event' => 'consult',
            'user_id' => $author->id,
            'billing_document_id' => $id,
            'payload' => [
                'sunatStatus' => $document['sunatStatus'] ?? null,
                'status' => $document['status'] ?? null,
            ],
        ]);

        return $this->mapElectronicDetail($document);
    }

    public function cancel(string $id, string $reason, User $author): array
    {
        $status = $this->billingGateway->getStatus($id);
        if (! ($status['canCancel'] ?? false)) {
            throw new \App\Exceptions\Billing\BillingValidationException(
                'Este documento no se puede dar de baja en su estado actual.',
            );
        }

        $document = $this->billingGateway->cancel($id, $reason);
        BillingEvent::query()->create([
            'event' => 'cancel',
            'user_id' => $author->id,
            'billing_document_id' => $id,
            'payload' => [
                'reason' => $reason,
                'status' => $document['status'] ?? null,
            ],
        ]);

        return $this->mapElectronicDetail($document);
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboardSummary(): array
    {
        try {
            $page = $this->billingGateway->listDocuments(['skip' => 0, 'take' => 200]);
            $items = $page['items'] ?? [];
            $counts = [
                'issued' => count($items),
                'accepted' => 0,
                'rejected' => 0,
                'pending' => 0,
            ];
            foreach ($items as $item) {
                $sunat = $item['sunatStatus'] ?? '';
                if ($sunat === 'accepted' || $sunat === 'acceptedWithObservations') {
                    $counts['accepted']++;
                } elseif ($sunat === 'rejected') {
                    $counts['rejected']++;
                } else {
                    $counts['pending']++;
                }
            }

            return $counts;
        } catch (BillingUnavailableException) {
            return [
                'issued' => null,
                'accepted' => null,
                'rejected' => null,
                'pending' => null,
                'unavailable' => true,
            ];
        }
    }

    /**
     * @return array<string, mixed>|LengthAwarePaginator
     */
    private function listAll(ListQuery $query, array $filters): array|LengthAwarePaginator
    {
        try {
            $electronic = $this->collectElectronic($filters, $query->search);
        } catch (BillingUnavailableException) {
            return $this->listSalesNotes($query, $filters);
        }

        $notes = $this->includeSalesNotes($filters)
            ? $this->collectSalesNotes($query, $filters)
            : [];

        $merged = array_values(array_merge($electronic, $notes));
        usort($merged, function (array $left, array $right): int {
            $dates = strcmp((string) ($right['issue_date'] ?? ''), (string) ($left['issue_date'] ?? ''));
            if ($dates !== 0) {
                return $dates;
            }

            return strcmp((string) ($right['id'] ?? ''), (string) ($left['id'] ?? ''));
        });

        $page = max(1, $query->page);
        $perPage = max(1, $query->perPage);
        $total = count($merged);
        $slice = array_slice($merged, ($page - 1) * $perPage, $perPage);

        return new Paginator($slice, $total, $perPage, $page);
    }

    /**
     * @return array<string, mixed>
     */
    private function listElectronic(ListQuery $query, array $filters, string $type): array
    {
        $take = $query->perPage;
        $skip = ($query->page - 1) * $take;
        $result = $this->billingGateway->listDocuments($this->electronicQuery(
            $filters,
            $query->search,
            $type,
            $skip,
            $take,
        ));

        $items = $result['items'] ?? [];
        $total = (int) ($result['total'] ?? count($items));

        return [
            'data' => array_map(fn (array $item) => $this->mapElectronicListItem($item), $items),
            'current_page' => $query->page,
            'per_page' => $take,
            'total' => $total,
            'last_page' => (int) max(1, ceil($total / max($take, 1))),
            'origin' => 'billing_service',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function collectElectronic(array $filters, ?string $search): array
    {
        $items = [];
        $skip = 0;
        $take = 200;

        do {
            $result = $this->billingGateway->listDocuments(
                $this->electronicQuery($filters, $search, null, $skip, $take),
            );
            $pageItems = $result['items'] ?? [];
            foreach ($pageItems as $item) {
                $items[] = $this->mapElectronicListItem($item);
            }

            $total = (int) ($result['total'] ?? count($items));
            $skip += $take;
        } while ($pageItems !== [] && $skip < $total);

        return $items;
    }

    /**
     * @return array<string, mixed>
     */
    private function electronicQuery(
        array $filters,
        ?string $search,
        ?string $type,
        int $skip,
        int $take,
    ): array {
        return array_filter([
            'documentType' => $type,
            'series' => $filters['series'] ?? null,
            'status' => $filters['document_status'] ?? null,
            'sunatStatus' => $filters['sunat_status'] ?? null,
            'search' => $search,
            'dateFrom' => $filters['date_from'] ?? null,
            'dateTo' => $filters['date_to'] ?? null,
            'minAmount' => $filters['min_total'] ?? null,
            'maxAmount' => $filters['max_total'] ?? null,
            'externalReference' => $filters['external_reference'] ?? null,
            'skip' => $skip,
            'take' => $take,
        ], static fn ($value) => $value !== null && $value !== '');
    }

    private function includeSalesNotes(array $filters): bool
    {
        $sunatStatus = $filters['sunat_status'] ?? null;
        if (filled($sunatStatus)) {
            return false;
        }

        $documentStatus = $filters['document_status'] ?? null;

        return ! filled($documentStatus) || $documentStatus === 'issued';
    }

    private function normalizedType(array $filters): ?string
    {
        $type = $filters['document_type'] ?? null;
        if (! is_string($type) || trim($type) === '' || $type === 'all') {
            return null;
        }

        return $type;
    }

    private function listSalesNotes(ListQuery $query, array $filters): LengthAwarePaginator
    {
        $builder = $this->salesNotesBuilder($filters);
        $paginator = SearchablePaginator::paginate($builder, $query, self::SALES_NOTE_SEARCH_COLUMNS);
        $paginator->setCollection(
            $paginator->getCollection()->map(fn (SalesNote $note) => $this->mapSalesNote($note)),
        );

        return $paginator;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function collectSalesNotes(ListQuery $query, array $filters): array
    {
        $builder = $this->salesNotesBuilder($filters);
        if ($query->hasSearch()) {
            SearchablePaginator::applySearch(
                $builder,
                (string) $query->search,
                self::SALES_NOTE_SEARCH_COLUMNS,
            );
        }

        return $builder
            ->get()
            ->map(fn (SalesNote $note) => $this->mapSalesNote($note))
            ->all();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<SalesNote>
     */
    private function salesNotesBuilder(array $filters)
    {
        $builder = SalesNote::query()->with('order')->orderByDesc('issue_date')->orderByDesc('id');
        if (! empty($filters['date_from'])) {
            $builder->whereDate('issue_date', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $builder->whereDate('issue_date', '<=', $filters['date_to']);
        }
        if (! empty($filters['series'])) {
            $builder->where('series', $filters['series']);
        }
        if (! empty($filters['min_total'])) {
            $builder->where('total', '>=', $filters['min_total']);
        }
        if (! empty($filters['max_total'])) {
            $builder->where('total', '<=', $filters['max_total']);
        }

        return $builder;
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function mapElectronicListItem(array $item): array
    {
        return [
            'id' => $item['id'],
            'origin' => 'sunat',
            'internal' => false,
            'document_type' => $item['documentType'] ?? null,
            'series' => $item['series'] ?? null,
            'number' => $item['number'] ?? null,
            'full_number' => $item['fullNumber'] ?? null,
            'order_number' => $item['externalReference'] ?? null,
            'recipient_name' => $item['recipientName'] ?? null,
            'recipient_document' => $item['recipientIdentityNumber'] ?? null,
            'issue_date' => $item['issueDate'] ?? null,
            'total' => $item['payableAmount'] ?? null,
            'document_status' => $item['status'] ?? null,
            'sunat_status' => $item['sunatStatus'] ?? null,
            'external_reference' => $item['externalReference'] ?? null,
            'can_retry' => $item['canRetry'] ?? false,
            'can_cancel' => $item['canCancel'] ?? false,
            'can_consult' => $item['canConsult'] ?? false,
        ];
    }

    /**
     * @param  array<string, mixed>  $document
     * @return array<string, mixed>
     */
    private function mapElectronicDetail(array $document): array
    {
        return [
            'id' => $document['id'],
            'origin' => 'sunat',
            'internal' => false,
            'document_type' => $document['documentType'] ?? null,
            'series' => $document['series'] ?? null,
            'number' => $document['number'] ?? null,
            'full_number' => $document['fullNumber'] ?? null,
            'issue_date' => $document['issueDate'] ?? null,
            'issuer_name' => $document['issuerLegalName'] ?? null,
            'issuer_ruc' => $document['issuerRuc'] ?? null,
            'recipient_name' => $document['recipientName'] ?? null,
            'recipient_identity_type' => $document['recipientIdentityType'] ?? null,
            'recipient_identity_number' => $document['recipientIdentityNumber'] ?? null,
            'recipient_address' => $document['recipientAddress'] ?? null,
            'items' => $document['items'] ?? [],
            'taxable_amount' => $document['taxableAmount'] ?? null,
            'igv_amount' => $document['igvAmount'] ?? null,
            'total' => $document['payableAmount'] ?? null,
            'document_status' => $document['status'] ?? null,
            'sunat_status' => $document['sunatStatus'] ?? null,
            'submission' => $document['lastSubmission'] ?? null,
            'attempt_count' => $document['attemptCount'] ?? 0,
            'sunat_code' => $document['sunatResponseCode'] ?? null,
            'sunat_description' => $document['sunatDescription'] ?? null,
            'can_retry' => $document['canRetry'] ?? false,
            'can_cancel' => $document['canCancel'] ?? false,
            'can_consult' => $document['canConsult'] ?? false,
            'external_system' => $document['externalSystem'] ?? null,
            'external_entity' => $document['externalEntity'] ?? null,
            'external_id' => $document['externalId'] ?? null,
            'external_reference' => $document['externalReference'] ?? null,
            'files' => $document['files'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapSalesNote(SalesNote $note): array
    {
        $items = $this->salesNoteItems($note);

        return [
            'id' => 'sn-'.$note->id,
            'origin' => 'internal',
            'internal' => true,
            'document_type' => DocumentKind::SALES_NOTE,
            'series' => $note->series,
            'number' => $note->number,
            'full_number' => $note->full_number,
            'order_id' => $note->order_id,
            'order_number' => $note->order?->order_number,
            'recipient_name' => $note->customer_name,
            'recipient_document' => $note->customer_document,
            'issue_date' => optional($note->issue_date)?->toDateString(),
            'items' => $items,
            'taxable_amount' => $note->subtotal,
            'igv_amount' => 0,
            'total' => (float) $note->total,
            'document_status' => 'accepted',
            'sunat_status' => null,
            'external_reference' => $note->order?->order_number,
            'can_retry' => false,
            'can_cancel' => false,
            'can_consult' => false,
            'internal_label' => 'Nota de venta',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function salesNoteItems(SalesNote $note): array
    {
        $rows = [];
        $line = 1;
        foreach ($note->items_snapshot ?? [] as $item) {
            $description = trim((string) ($item['description'] ?? $item['product_name'] ?? ''));
            $quantity = (float) ($item['quantity'] ?? 0);
            $unitPrice = (float) ($item['unit_price'] ?? $item['unitPrice'] ?? $item['unit_value'] ?? 0);
            $total = (float) ($item['total'] ?? $item['subtotal'] ?? round($quantity * $unitPrice, 2));
            if ($description === '' && $quantity <= 0 && $total <= 0) {
                continue;
            }

            $rows[] = [
                'line_number' => $line++,
                'code' => isset($item['product_id']) ? (string) $item['product_id'] : null,
                'description' => $description !== '' ? $description : 'Ítem',
                'quantity' => $quantity,
                'unit_value' => $unitPrice,
                'igv_amount' => 0,
                'total' => $total,
            ];
        }

        if ($rows !== []) {
            return $rows;
        }

        $note->loadMissing(['order.details.product']);
        foreach ($note->order?->details ?? [] as $detail) {
            $quantity = (int) $detail->quantity;
            $unitPrice = round((float) $detail->unit_price, 2);
            $rows[] = [
                'line_number' => $line++,
                'code' => $detail->product_id ? (string) $detail->product_id : null,
                'description' => trim((string) $detail->display_name) !== ''
                    ? trim((string) $detail->display_name)
                    : 'Ítem',
                'quantity' => $quantity,
                'unit_value' => $unitPrice,
                'igv_amount' => 0,
                'total' => round($quantity * $unitPrice, 2),
            ];
        }

        return $rows;
    }

    /**
     * @return array{content: string, content_type: string, file_name: string}
     */
    private function renderSalesNotePdf(SalesNote $note, ?string $templateType = null): array
    {
        $items = $this->salesNoteItems($note);
        $issueDate = optional($note->issue_date)?->format('d/m/Y') ?? now()->format('d/m/Y');
        $identityNumber = (string) ($note->customer_document ?? $note->customer?->dni ?? $note->customer?->ruc ?? '-');
        $identityType = strlen(preg_replace('/\D+/', '', $identityNumber) ?? '') === 11 ? '6' : '1';

        return $this->billingGateway->renderPdf([
            'pdfTemplate' => $templateType ?? $this->pdfPreference->selectedTemplateCode(),
            'showQr' => false,
            'showTaxBreakdown' => false,
            'typeLabel' => 'NOTA DE VENTA',
            'series' => $note->series,
            'number' => (int) $note->number,
            'fullNumber' => $note->full_number,
            'issueDate' => $issueDate,
            'externalReference' => $note->order?->order_number,
            'recipientName' => $note->customer_name,
            'recipientIdentityType' => $identityType,
            'recipientIdentityNumber' => $identityNumber !== '' ? $identityNumber : '-',
            'recipientAddress' => $note->customer?->address,
            'items' => array_map(fn (array $item) => [
                'description' => $item['description'],
                'quantity' => $item['quantity'],
                'unitPrice' => $item['unit_value'],
                'total' => $item['total'],
            ], $items),
            'payableAmount' => (float) $note->total,
            'observation' => $note->observations,
            'footerText' => 'Documento interno. No constituye comprobante de pago electrónico ni tiene validez tributaria ante SUNAT.',
        ]);
    }
}
