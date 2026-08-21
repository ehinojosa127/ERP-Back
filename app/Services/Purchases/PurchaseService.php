<?php

namespace App\Services\Purchases;

use App\Models\Movement;
use App\Models\Purchase;
use App\Models\PurchaseDetail;
use App\Models\PurchasePayment;
use App\Models\User;
use App\Support\Inventory\MovementReferenceType;
use App\Support\Inventory\MovementType;
use App\Support\Inventory\PaymentReceiptStorage;
use App\Support\Inventory\PaymentStatus;
use App\Support\Inventory\PurchaseStatus;
use App\Support\Query\ListQuery;
use App\Support\Query\SearchablePaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseService
{
    private const SEARCHABLE_COLUMNS = [
        'purchase_number',
        'observations',
        'status',
        'supplier.name',
        'supplier.lastname',
        'supplier.company_name',
        'supplier.ruc',
    ];

    private const PURCHASE_NUMBER_PREFIX = 'OC-';

    private const PURCHASE_NUMBER_PAD = 5;

    public function list(
        ListQuery $query,
        ?int $supplierId = null,
        ?string $status = null,
        ?string $paymentStatus = null,
        ?string $purchaseDate = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?float $minTotal = null,
        ?float $maxTotal = null,
    ): LengthAwarePaginator {
        $builder = Purchase::query()
            ->with(['supplier'])
            ->withSum('payments as paid_amount', 'amount')
            ->orderByDesc('purchase_date')
            ->orderByDesc('id');

        if ($supplierId !== null) {
            $builder->where('supplier_id', $supplierId);
        }

        if ($status !== null) {
            $builder->where('status', $status);
        }

        if ($purchaseDate !== null) {
            $builder->whereDate('purchase_date', $purchaseDate);
        }

        if ($dateFrom !== null) {
            $builder->whereDate('purchase_date', '>=', $dateFrom);
        }

        if ($dateTo !== null) {
            $builder->whereDate('purchase_date', '<=', $dateTo);
        }

        if ($minTotal !== null) {
            $builder->where('total_amount', '>=', $minTotal);
        }

        if ($maxTotal !== null) {
            $builder->where('total_amount', '<=', $maxTotal);
        }

        if ($paymentStatus !== null) {
            $this->applyPaymentStatusFilter($builder, $paymentStatus);
        }

        return SearchablePaginator::paginate($builder, $query, self::SEARCHABLE_COLUMNS);
    }

    public function find(Purchase $purchase): Purchase
    {
        $purchase->load([
            'supplier',
            'details.product.category',
            'payments',
            'document',
        ]);
        $purchase->loadSum('payments as paid_amount', 'amount');

        return $purchase;
    }

    public function create(array $data, User $author): Purchase
    {
        return DB::transaction(function () use ($data, $author) {
            $details = $this->normalizeDetails($data['details'] ?? []);
            $totalAmount = $this->calculateTotal($details);

            $purchase = Purchase::query()->create([
                'purchase_number' => $this->nextPurchaseNumber(),
                'total_amount' => $totalAmount,
                'observations' => $data['observations'] ?? null,
                'status' => PurchaseStatus::CREATED,
                'purchase_date' => $data['purchase_date'],
                'supplier_id' => $data['supplier_id'],
                'created_by' => $author->id,
                'updated_by' => $author->id,
            ]);

            foreach ($details as $detail) {
                $purchase->details()->create([
                    'product_id' => $detail['product_id'],
                    'quantity' => $detail['quantity'],
                    'unit_cost' => $detail['unit_cost'],
                    'created_by' => $author->id,
                    'updated_by' => $author->id,
                ]);
            }

            if ($this->hasDocumentPayload($data)) {
                $this->storeDocument($purchase, $data, $author);
            }

            return $this->find($purchase->fresh());
        });
    }

    public function updateStatus(Purchase $purchase, array $data, User $author): Purchase
    {
        $nextStatus = $data['status'];

        if (! PurchaseStatus::canTransition($purchase->status, $nextStatus)) {
            throw ValidationException::withMessages([
                'status' => ['La transición de estado no es válida.'],
            ]);
        }

        if ($nextStatus === PurchaseStatus::IN_WAREHOUSE) {
            return $this->receiveInWarehouse(
                $purchase,
                $data['movement_date'] ?? now()->toDateString(),
                $author,
            );
        }

        $purchase->update([
            'status' => $nextStatus,
            'updated_by' => $author->id,
        ]);

        return $this->find($purchase->fresh());
    }

    public function listPayments(Purchase $purchase, ListQuery $query): LengthAwarePaginator
    {
        $builder = $purchase->payments()->getQuery()->orderByDesc('created_at');

        return SearchablePaginator::paginate($builder, $query, []);
    }

    public function createPayment(Purchase $purchase, array $data, User $author): PurchasePayment
    {
        $amount = (float) $data['amount'];
        $paid = (float) $purchase->payments()->sum('amount');
        $remaining = (float) $purchase->total_amount - $paid;

        if ($amount <= 0) {
            throw ValidationException::withMessages([
                'amount' => ['El monto del pago debe ser mayor a cero.'],
            ]);
        }

        if ($amount - $remaining > 0.00001) {
            throw ValidationException::withMessages([
                'amount' => ['El pago no puede superar el saldo pendiente de la compra.'],
            ]);
        }

        return PurchasePayment::query()->create([
            'amount' => $amount,
            'payment_method' => (int) $data['payment_method'],
            'payment_date' => $data['payment_date'],
            'operation_number' => filled($data['operation_number'] ?? null)
                ? trim((string) $data['operation_number'])
                : null,
            ...(PaymentReceiptStorage::store($data['receipt_file'] ?? null) ?? []),
            'purchase_id' => $purchase->id,
            'created_by' => $author->id,
            'updated_by' => $author->id,
        ]);
    }

    /**
     * @return array{path: string, name: string, mime: string}
     */
    public function paymentReceiptDownload(
        Purchase $purchase,
        PurchasePayment $payment,
    ): array {
        if ($payment->purchase_id !== $purchase->id) {
            throw ValidationException::withMessages([
                'payment' => ['El pago no pertenece a esta compra.'],
            ]);
        }

        if (! filled($payment->receipt_file_path)) {
            throw ValidationException::withMessages([
                'payment' => ['Este pago no tiene comprobante adjunto.'],
            ]);
        }

        return [
            'path' => $payment->receipt_file_path,
            'name' => $payment->receipt_file_name ?? 'comprobante-pago',
            'mime' => $payment->receipt_file_mime ?? 'application/octet-stream',
        ];
    }

    /**
     * @return array{path: string, name: string, mime: string}
     */
    public function documentDownload(Purchase $purchase): array
    {
        $document = $purchase->document;

        if ($document === null || ! filled($document->file_path)) {
            throw ValidationException::withMessages([
                'document' => ['Esta compra no tiene comprobante adjunto.'],
            ]);
        }

        return [
            'path' => $document->file_path,
            'name' => $document->file_name ?? 'comprobante-compra',
            'mime' => $document->file_mime ?? 'application/octet-stream',
        ];
    }

    private function receiveInWarehouse(Purchase $purchase, string $movementDate, User $author): Purchase
    {
        return DB::transaction(function () use ($purchase, $movementDate, $author) {
            $locked = Purchase::query()->whereKey($purchase->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === PurchaseStatus::IN_WAREHOUSE) {
                throw ValidationException::withMessages([
                    'status' => ['La compra ya está registrada en almacén.'],
                ]);
            }

            if (! PurchaseStatus::canTransition($locked->status, PurchaseStatus::IN_WAREHOUSE)) {
                throw ValidationException::withMessages([
                    'status' => ['La transición de estado no es válida.'],
                ]);
            }

            $alreadyCreated = Movement::query()
                ->where('reference_type', MovementReferenceType::PURCHASE)
                ->where('reference_id', $locked->id)
                ->exists();

            if ($alreadyCreated) {
                throw ValidationException::withMessages([
                    'status' => ['Ya existen movimientos de inventario para esta compra.'],
                ]);
            }

            $details = PurchaseDetail::query()
                ->where('purchase_id', $locked->id)
                ->get();

            if ($details->isEmpty()) {
                throw ValidationException::withMessages([
                    'details' => ['La compra no tiene productos para ingresar al almacén.'],
                ]);
            }

            $locked->update([
                'status' => PurchaseStatus::IN_WAREHOUSE,
                'updated_by' => $author->id,
            ]);

            foreach ($details as $detail) {
                Movement::query()->create([
                    'product_id' => $detail->product_id,
                    'type' => MovementType::IN,
                    'quantity' => $detail->quantity,
                    'unit_cost' => $detail->unit_cost,
                    'movement_date' => $movementDate,
                    'reference_type' => MovementReferenceType::PURCHASE,
                    'reference_id' => $locked->id,
                    'created_by' => $author->id,
                    'updated_by' => $author->id,
                ]);
            }

            return $this->find($locked->fresh());
        });
    }

    /** PurchaseNumber correlativo único generado solo en backend. */
    private function nextPurchaseNumber(): string
    {
        $latest = Purchase::query()
            ->where('purchase_number', 'like', self::PURCHASE_NUMBER_PREFIX.'%')
            ->lockForUpdate()
            ->orderByDesc('id')
            ->value('purchase_number');

        $sequence = 1;

        if (
            is_string($latest)
            && preg_match('/^'.preg_quote(self::PURCHASE_NUMBER_PREFIX, '/').'(\d+)$/', $latest, $matches)
        ) {
            $sequence = ((int) $matches[1]) + 1;
        }

        do {
            $number = self::PURCHASE_NUMBER_PREFIX.str_pad(
                (string) $sequence,
                self::PURCHASE_NUMBER_PAD,
                '0',
                STR_PAD_LEFT,
            );
            $exists = Purchase::query()->where('purchase_number', $number)->exists();
            $sequence++;
        } while ($exists);

        return $number;
    }

    /**
     * @param  array<int, array{product_id: int, quantity: int, unit_cost: float|int|string}>  $details
     * @return array<int, array{product_id: int, quantity: int, unit_cost: float}>
     */
    private function normalizeDetails(array $details): array
    {
        if ($details === []) {
            throw ValidationException::withMessages([
                'details' => ['La compra debe incluir al menos un producto.'],
            ]);
        }

        $normalized = [];
        $seen = [];

        foreach ($details as $detail) {
            $productId = (int) $detail['product_id'];

            if (isset($seen[$productId])) {
                throw ValidationException::withMessages([
                    'details' => ['No se puede agregar el mismo producto más de una vez.'],
                ]);
            }

            $quantity = (int) $detail['quantity'];
            $unitCost = (float) $detail['unit_cost'];

            if ($quantity <= 0) {
                throw ValidationException::withMessages([
                    'details' => ['La cantidad debe ser mayor a cero.'],
                ]);
            }

            if ($unitCost < 0) {
                throw ValidationException::withMessages([
                    'details' => ['El costo unitario no puede ser negativo.'],
                ]);
            }

            $seen[$productId] = true;
            $normalized[] = [
                'product_id' => $productId,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
            ];
        }

        return $normalized;
    }

    /** @param  array<int, array{quantity: int, unit_cost: float}>  $details */
    private function calculateTotal(array $details): float
    {
        $total = 0.0;

        foreach ($details as $detail) {
            $total += $detail['quantity'] * $detail['unit_cost'];
        }

        return round($total, 2);
    }

    private function applyPaymentStatusFilter(Builder $builder, string $paymentStatus): void
    {
        $paidSub = PurchasePayment::query()
            ->selectRaw('COALESCE(SUM(amount), 0)')
            ->whereColumn('purchase_id', 'purchases.id');

        match ($paymentStatus) {
            PaymentStatus::UNPAID => $builder->whereRaw('('.$paidSub->toSql().') <= 0', $paidSub->getBindings()),
            PaymentStatus::PAID => $builder->whereRaw(
                '('.$paidSub->toSql().') >= purchases.total_amount',
                $paidSub->getBindings(),
            ),
            PaymentStatus::PARTIALLY_PAID => $builder
                ->whereRaw('('.$paidSub->toSql().') > 0', $paidSub->getBindings())
                ->whereRaw(
                    '('.$paidSub->toSql().') < purchases.total_amount',
                    $paidSub->getBindings(),
                ),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function saveDocument(Purchase $purchase, array $data, User $author): Purchase
    {
        $this->storeDocument($purchase, $data, $author);

        return $this->find($purchase->fresh());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function hasDocumentPayload(array $data): bool
    {
        return filled($data['document_type'] ?? null)
            || filled($data['document_series'] ?? null)
            || filled($data['document_number'] ?? null)
            || filled($data['document_issue_date'] ?? null)
            || array_key_exists('document_file', $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function storeDocument(Purchase $purchase, array $data, User $author): void
    {
        $attributes = [
            'document_type' => $data['document_type'] ?? null,
            'series' => $data['document_series'] ?? null,
            'number' => $data['document_number'] ?? null,
            'issue_date' => $data['document_issue_date'] ?? null,
            'amount' => $data['document_amount'] ?? null,
            'observations' => $data['document_observations'] ?? null,
            'updated_by' => $author->id,
        ];

        $file = $data['document_file'] ?? null;
        if ($file instanceof \Illuminate\Http\UploadedFile) {
            $path = $file->store('purchase-documents', 'local');
            $attributes['file_path'] = $path;
            $attributes['file_name'] = $file->getClientOriginalName();
            $attributes['file_mime'] = $file->getClientMimeType();
        }

        $existing = $purchase->document;
        if ($existing === null) {
            $purchase->document()->create([
                ...$attributes,
                'created_by' => $author->id,
            ]);

            return;
        }

        if (isset($attributes['file_path']) && filled($existing->file_path)) {
            \Illuminate\Support\Facades\Storage::disk('local')->delete($existing->file_path);
        }

        $existing->update($attributes);
    }
}
