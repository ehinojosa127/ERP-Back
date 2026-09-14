<?php

namespace App\Services\Automation;

use App\Models\Shipment;

/**
 * URLs firmadas temporales para que WAHA/n8n descarguen el comprobante
 * sin API key (WhatsApp solo acepta URL pública al fetch).
 */
final class ShipmentReceiptSignedUrl
{
    private const DEFAULT_TTL_SECONDS = 172800; // 48h

    public function make(Shipment $shipment, ?int $ttlSeconds = null): ?array
    {
        $path = $shipment->getAttributes()['receipt_file_path'] ?? null;
        if (! filled($path)) {
            return null;
        }

        $expires = now()->getTimestamp() + max(60, $ttlSeconds ?? self::DEFAULT_TTL_SECONDS);
        $shipmentId = (int) $shipment->id;
        $signature = $this->sign($shipmentId, $expires);

        $base = rtrim((string) (
            config('services.automation.public_base_url')
            ?: config('app.url')
        ), '/');

        $query = http_build_query([
            'expires' => $expires,
            'signature' => $signature,
        ]);

        $mime = (string) ($shipment->receipt_file_mime ?: 'application/octet-stream');
        $fileName = (string) ($shipment->receipt_file_name ?: 'comprobante-envio');

        return [
            'url' => $base.'/api/automation/shipments/'.$shipmentId.'/receipt?'.$query,
            'mime' => $mime,
            'fileName' => $fileName,
            'isImage' => str_starts_with(strtolower($mime), 'image/'),
            'expiresAt' => now()->setTimestamp($expires)->toIso8601String(),
        ];
    }

    public function isValid(int $shipmentId, int $expires, string $signature): bool
    {
        if ($expires < now()->getTimestamp()) {
            return false;
        }

        $expected = $this->sign($shipmentId, $expires);

        return hash_equals($expected, $signature);
    }

    private function sign(int $shipmentId, int $expires): string
    {
        return hash_hmac(
            'sha256',
            $shipmentId.'|'.$expires,
            (string) config('app.key'),
        );
    }
}
