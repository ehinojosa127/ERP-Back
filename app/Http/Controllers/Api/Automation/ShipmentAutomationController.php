<?php

namespace App\Http\Controllers\Api\Automation;

use App\Http\Controllers\Api\ApiController;
use App\Models\Shipment;
use App\Services\Automation\ShipmentReceiptSignedUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ShipmentAutomationController extends ApiController
{
    public function __construct(
        private readonly ShipmentReceiptSignedUrl $signedUrls,
    ) {}

    public function receipt(Request $request, Shipment $shipment): StreamedResponse
    {
        $expires = (int) $request->query('expires', 0);
        $signature = (string) $request->query('signature', '');

        if (! $this->signedUrls->isValid((int) $shipment->id, $expires, $signature)) {
            throw new AccessDeniedHttpException('Enlace de comprobante inválido o expirado.');
        }

        $path = $shipment->getAttributes()['receipt_file_path'] ?? null;
        if (! filled($path) || ! Storage::disk('local')->exists($path)) {
            throw new NotFoundHttpException('Comprobante de envío no encontrado.');
        }

        $name = $shipment->receipt_file_name ?? 'comprobante-envio';
        $mime = $shipment->receipt_file_mime ?? 'application/octet-stream';

        return Storage::disk('local')->download($path, $name, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
