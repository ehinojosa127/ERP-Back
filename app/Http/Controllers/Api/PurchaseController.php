<?php

namespace App\Http\Controllers\Api;

use App\Http\Requests\Purchases\PurchaseIndexRequest;
use App\Http\Requests\Purchases\StorePurchasePaymentRequest;
use App\Http\Requests\Purchases\StorePurchaseRequest;
use App\Http\Requests\Purchases\UpdatePurchaseStatusRequest;
use App\Http\Requests\Shared\PaginatedIndexRequest;
use App\Models\Purchase;
use App\Services\Purchases\PurchaseService;
use Illuminate\Http\JsonResponse;

class PurchaseController extends ApiController
{
    public function __construct(
        private readonly PurchaseService $purchaseService,
    ) {}

    public function index(PurchaseIndexRequest $request): JsonResponse
    {
        return $this->success(
            $this->purchaseService->list(
                $request->toListQuery(),
                $request->supplierId(),
                $request->status(),
                $request->paymentStatus(),
                $request->purchaseDate(),
                $request->dateFrom(),
                $request->dateTo(),
                $request->minTotal(),
                $request->maxTotal(),
            ),
        );
    }

    public function show(Purchase $purchase): JsonResponse
    {
        return $this->success($this->purchaseService->find($purchase));
    }

    public function store(StorePurchaseRequest $request): JsonResponse
    {
        $data = $request->validated();
        if ($request->hasFile('document_file')) {
            $data['document_file'] = $request->file('document_file');
        }

        $purchase = $this->purchaseService->create(
            $data,
            $request->user(),
        );

        return $this->success($purchase, 'Compra creada correctamente.', 201);
    }

    public function updateStatus(
        UpdatePurchaseStatusRequest $request,
        Purchase $purchase,
    ): JsonResponse {
        $updated = $this->purchaseService->updateStatus(
            $purchase,
            $request->validated(),
            $request->user(),
        );

        return $this->success($updated, 'Estado de compra actualizado correctamente.');
    }

    public function payments(PaginatedIndexRequest $request, Purchase $purchase): JsonResponse
    {
        return $this->success(
            $this->purchaseService->listPayments($purchase, $request->toListQuery()),
        );
    }

    public function storePayment(
        StorePurchasePaymentRequest $request,
        Purchase $purchase,
    ): JsonResponse {
        $data = $request->validated();
        if ($request->hasFile('receipt_file')) {
            $data['receipt_file'] = $request->file('receipt_file');
        }

        $payment = $this->purchaseService->createPayment(
            $purchase,
            $data,
            $request->user(),
        );

        return $this->success($payment, 'Pago registrado correctamente.', 201);
    }

    public function downloadPaymentReceipt(
        \Illuminate\Http\Request $request,
        Purchase $purchase,
        \App\Models\PurchasePayment $payment,
    ): \Symfony\Component\HttpFoundation\StreamedResponse {
        $file = $this->purchaseService->paymentReceiptDownload($purchase, $payment);

        return \Illuminate\Support\Facades\Storage::disk('local')->download(
            $file['path'],
            $file['name'],
            ['Content-Type' => $file['mime']],
        );
    }

    public function storeDocument(
        \App\Http\Requests\Purchases\StorePurchaseDocumentRequest $request,
        Purchase $purchase,
    ): JsonResponse {
        $data = $request->validated();
        if ($request->hasFile('document_file')) {
            $data['document_file'] = $request->file('document_file');
        }

        $updated = $this->purchaseService->saveDocument(
            $purchase,
            $data,
            $request->user(),
        );

        return $this->success($updated, 'Comprobante de compra registrado.');
    }

    public function downloadDocument(Purchase $purchase): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $file = $this->purchaseService->documentDownload($purchase);

        return \Illuminate\Support\Facades\Storage::disk('local')->download(
            $file['path'],
            $file['name'],
            ['Content-Type' => $file['mime']],
        );
    }
}
