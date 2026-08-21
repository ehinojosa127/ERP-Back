<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Billing\BillingIndexRequest;
use App\Http\Requests\Billing\UpdateBillingPdfTemplateRequest;
use App\Services\Billing\BillingDocumentPdfService;
use App\Services\Billing\BillingQueryService;
use App\Services\Billing\OrderBillingService;
use App\Support\Auth\PermissionGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BillingController extends ApiController
{
    public function __construct(
        private readonly BillingQueryService $billingQueryService,
        private readonly BillingDocumentPdfService $billingDocumentPdfService,
        private readonly OrderBillingService $orderBillingService,
        private readonly PermissionGate $permissionGate,
    ) {}

    public function index(BillingIndexRequest $request): JsonResponse
    {
        $this->permissionGate->assert($request, 'billing.view');

        return $this->success(
            $this->billingQueryService->list($request->toListQuery(), $request->filters()),
        );
    }

    public function show(Request $request, string $document): JsonResponse
    {
        $this->permissionGate->assert($request, 'billing.detail');

        return $this->success($this->billingQueryService->show($document));
    }

    public function retry(Request $request, string $document): JsonResponse
    {
        $this->permissionGate->assert($request, 'billing.retry');

        return $this->success(
            $this->billingQueryService->retry($document, $request->user()),
            'Reintento enviado al servicio de facturación.',
        );
    }

    public function consult(Request $request, string $document): JsonResponse
    {
        $this->permissionGate->assert($request, 'billing.consult');

        return $this->success(
            $this->billingQueryService->consult($document, $request->user()),
            'Consulta enviada a SUNAT.',
        );
    }

    public function cancel(Request $request, string $document): JsonResponse
    {
        $this->permissionGate->assert($request, 'billing.cancel');
        $reason = trim((string) $request->input('reason', ''));
        if (strlen($reason) < 3) {
            return $this->error('Indica un motivo de baja de al menos 3 caracteres.', 422);
        }

        return $this->success(
            $this->billingQueryService->cancel($document, $reason, $request->user()),
            'Solicitud de baja registrada.',
        );
    }

    public function download(Request $request, string $document, string $kind): Response
    {
        $permission = match ($kind) {
            'pdf' => 'billing.download_pdf',
            'xml' => 'billing.download_xml',
            'cdr' => 'billing.download_cdr',
            default => null,
        };

        if ($permission === null) {
            return $this->error('Tipo de archivo no soportado.', 422);
        }

        $this->permissionGate->assert($request, $permission);
        $file = $this->billingQueryService->download($document, $kind, $request->user());

        return response($file['content'], 200, [
            'Content-Type' => $file['content_type'],
            'Content-Disposition' => 'attachment; filename="'.$file['file_name'].'"',
        ]);
    }

    public function regeneratePdf(Request $request, string $document): JsonResponse
    {
        $this->permissionGate->assert($request, 'billing.regenerate_pdf');

        $templateType = $request->input('templateType', $request->input('template'));

        return $this->success(
            $this->billingQueryService->regeneratePdf(
                $document,
                $request->user(),
                is_string($templateType) ? $templateType : null,
            ),
            'PDF regenerado correctamente.',
        );
    }

    public function pdfTemplates(Request $request): JsonResponse
    {
        $this->permissionGate->assertAny($request, 'billing.manage_templates', 'billing.regenerate_pdf');

        return $this->success($this->billingDocumentPdfService->catalog());
    }

    public function updatePdfTemplate(UpdateBillingPdfTemplateRequest $request): JsonResponse
    {
        $this->permissionGate->assert($request, 'billing.manage_templates');

        return $this->success(
            $this->billingDocumentPdfService->updateSelectedTemplate($request->validated('template')),
            'Plantilla actualizada para los próximos comprobantes.',
        );
    }

    public function capabilities(Request $request): JsonResponse
    {
        $this->permissionGate->assertAny($request, 'billing.issue', 'orders.create', 'billing.view');

        return $this->success($this->orderBillingService->issuerCapabilities());
    }
}
