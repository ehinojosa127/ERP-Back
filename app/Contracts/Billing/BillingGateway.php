<?php

namespace App\Contracts\Billing;

interface BillingGateway
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function issue(string $path, array $payload, string $idempotencyKey, ?string $correlationId = null): array;

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function listDocuments(array $query): array;

    /** @return array<string, mixed> */
    public function getDocument(string $id): array;

    /** @return array<string, mixed> */
    public function getStatus(string $id): array;

    /** @return array<string, mixed> */
    public function retry(string $id): array;

    /** @return array<string, mixed> */
    public function consult(string $id): array;

    /** @return array<string, mixed> */
    public function cancel(string $id, string $reason): array;

    /** @return array<int, array<string, mixed>> */
    public function listSeries(): array;

    /** @return array<string, mixed> */
    public function capabilities(): array;

    /**
     * @return array{content: string, content_type: string, file_name: string}
     */
    public function download(string $id, string $kind, ?string $template = null): array;

    /**
     * @return array{content: string, content_type: string, file_name: string}
     */
    public function renderPdf(array $payload): array;

    /**
     * @return array<string, mixed>
     */
    public function regeneratePdf(string $id, ?string $templateType = null): array;
}
