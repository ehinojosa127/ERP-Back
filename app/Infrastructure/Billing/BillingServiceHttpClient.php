<?php

namespace App\Infrastructure\Billing;

use App\Contracts\Billing\BillingGateway;
use App\Exceptions\Billing\BillingConflictException;
use App\Exceptions\Billing\BillingNotFoundException;
use App\Exceptions\Billing\BillingUnavailableException;
use App\Exceptions\Billing\BillingValidationException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class BillingServiceHttpClient implements BillingGateway
{
    public function issue(string $path, array $payload, string $idempotencyKey, ?string $correlationId = null): array
    {
        return $this->decode(
            $this->execute(fn () => $this->http($correlationId, $idempotencyKey)->post($path, $payload)),
            [200, 201],
        );
    }

    public function listDocuments(array $query): array
    {
        return $this->decode(
            $this->execute(fn () => $this->http()->get('/api/v1/documents', $query)),
            [200],
        );
    }

    public function getDocument(string $id): array
    {
        return $this->decode(
            $this->execute(fn () => $this->http()->get('/api/v1/documents/'.$id)),
            [200],
        );
    }

    public function getStatus(string $id): array
    {
        return $this->decode(
            $this->execute(fn () => $this->http()->get('/api/v1/documents/'.$id.'/status')),
            [200],
        );
    }

    public function retry(string $id): array
    {
        return $this->decode(
            $this->execute(fn () => $this->http()->post('/api/v1/documents/'.$id.'/retry')),
            [200],
        );
    }

    public function consult(string $id): array
    {
        return $this->decode(
            $this->execute(fn () => $this->http()->post('/api/v1/documents/'.$id.'/consult')),
            [200],
        );
    }

    public function cancel(string $id, string $reason): array
    {
        return $this->decode(
            $this->execute(fn () => $this->http()->post('/api/v1/documents/'.$id.'/cancel', [
                'reason' => $reason,
            ])),
            [200],
        );
    }

    public function listSeries(): array
    {
        $payload = $this->decode(
            $this->execute(fn () => $this->http()->get('/api/v1/series')),
            [200],
        );

        return array_is_list($payload) ? $payload : ($payload['items'] ?? []);
    }

    public function capabilities(): array
    {
        return $this->decode(
            $this->execute(fn () => $this->http()->get('/api/v1/capabilities')),
            [200],
        );
    }

    public function download(string $id, string $kind, ?string $template = null): array
    {
        $query = [];
        if ($kind === 'pdf' && $template !== null && $template !== '') {
            $query['template'] = $template;
        }

        $response = $this->execute(fn () => $this->http()->get('/api/v1/documents/'.$id.'/'.$kind, $query));
        if (! $response->successful()) {
            $this->throwFrom($response);
        }

        return $this->fileFromResponse($response, $kind);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{content: string, content_type: string, file_name: string}
     */
    public function renderPdf(array $payload): array
    {
        $response = $this->execute(
            fn () => $this->http()->accept('application/pdf')->post('/api/v1/pdf/render', $payload),
        );
        if (! $response->successful()) {
            $this->throwFrom($response);
        }

        return $this->fileFromResponse($response, 'pdf');
    }

    public function regeneratePdf(string $id, ?string $templateType = null): array
    {
        $payload = [];
        if ($templateType !== null && $templateType !== '') {
            $payload['templateType'] = strtolower($templateType);
        }

        return $this->decode(
            $this->execute(fn () => $this->http()->post('/api/v1/documents/'.$id.'/pdf/regenerate', $payload)),
            [200],
        );
    }

    private function http(?string $correlationId = null, ?string $idempotencyKey = null): PendingRequest
    {
        $headers = [
            'Accept' => 'application/json',
            'X-Correlation-ID' => $correlationId ?: (string) Str::uuid(),
        ];

        $apiKey = (string) config('services.billing.api_key');
        if ($apiKey !== '') {
            $headers['X-Api-Key'] = $apiKey;
        }

        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        return Http::baseUrl(rtrim((string) config('services.billing.url'), '/'))
            ->timeout((int) config('services.billing.timeout', 30))
            ->connectTimeout(5)
            ->withHeaders($headers)
            ->acceptJson();
    }

    /**
     * @return array{content: string, content_type: string, file_name: string}
     */
    private function fileFromResponse(Response $response, string $kind): array
    {
        $disposition = (string) $response->header('Content-Disposition');
        $fileName = 'document.'.$kind;
        if (preg_match('/filename="?([^"]+)"?/', $disposition, $matches) === 1) {
            $fileName = $matches[1];
        }

        return [
            'content' => $response->body(),
            'content_type' => $response->header('Content-Type') ?: 'application/octet-stream',
            'file_name' => $fileName,
        ];
    }

    /**
     * @param  callable(): Response  $callback
     */
    private function execute(callable $callback): Response
    {
        try {
            return $callback();
        } catch (ConnectionException) {
            throw new BillingUnavailableException();
        }
    }

    /**
     * @param  array<int, int>  $ok
     * @return array<string, mixed>
     */
    private function decode(Response $response, array $ok): array
    {
        if (! in_array($response->status(), $ok, true)) {
            $this->throwFrom($response);
        }

        $decoded = $response->json();

        return is_array($decoded) ? $decoded : [];
    }

    private function throwFrom(Response $response): never
    {
        $status = $response->status();
        $payload = $response->json();
        $title = is_array($payload)
            ? (string) ($payload['detail'] ?? $payload['title'] ?? $payload['message'] ?? 'Error de facturación.')
            : 'Error de facturación.';

        if (in_array($status, [400, 422], true)) {
            $errors = is_array($payload) ? ($payload['errors'] ?? $payload['extensions']['errors'] ?? []) : [];
            if (! is_array($errors)) {
                $errors = [];
            }
            $first = '';
            foreach ($errors as $error) {
                if (is_string($error) && $error !== '') {
                    $first = $error;
                    break;
                }
                if (is_array($error) && isset($error[0]) && is_string($error[0])) {
                    $first = $error[0];
                    break;
                }
            }

            throw new BillingValidationException(
                $first !== '' ? $first : $this->spanishMessage($title),
                $errors,
            );
        }

        if ($status === 409) {
            throw new BillingConflictException($this->spanishMessage($title));
        }

        if ($status === 404) {
            $detail = strtolower($title);
            if (str_contains($detail, 'issuer')) {
                throw new BillingNotFoundException($this->spanishMessage($title));
            }

            if (str_contains($detail, 'file') || str_contains($detail, 'pdf') || str_contains($detail, 'xml') || str_contains($detail, 'cdr')) {
                throw new BillingNotFoundException(
                    'El archivo del comprobante aún no está disponible. Si el documento quedó en borrador, usa Reintentar.',
                );
            }

            throw new BillingNotFoundException($this->spanishMessage($title));
        }

        if ($status >= 500 || $status === 0) {
            throw new BillingUnavailableException();
        }

        throw new BillingValidationException($this->spanishMessage($title));
    }

    private function spanishMessage(string $message): string
    {
        return match ($message) {
            'The request is invalid.' => 'La solicitud de facturación no es válida.',
            'Issuer has not been configured.' => 'El emisor no está configurado en el servicio de facturación.',
            'SUNAT is currently unavailable.' => 'SUNAT no está disponible temporalmente.',
            'A temporary communication error occurred.' => 'Ocurrió un error temporal de comunicación con SUNAT.',
            'SUNAT returned HTTP 404.' => 'SUNAT no devolvió información para este comprobante.',
            default => $message,
        };
    }
}
