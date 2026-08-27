<?php

namespace App\Services\Orders;

use App\Models\Movement;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\OrderPayment;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Billing\PaymentConceptSuggester;
use App\Support\Inventory\MovementReferenceType;
use App\Support\Inventory\MovementType;
use App\Support\Inventory\PaymentReceiptStorage;
use App\Support\Inventory\PaymentStatus;
use App\Support\Orders\FulfillmentType;
use App\Support\Orders\OrderStatus;
use App\Support\Orders\ShipmentStatus;
use App\Support\Query\ListQuery;
use App\Support\Query\SearchablePaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderService
{
    private const SEARCHABLE_COLUMNS = [
        'order_number',
        'observations',
        'status',
        'customer.name',
        'customer.lastname',
        'customer.dni',
        'customer.phone_number',
    ];

    private const ORDER_NUMBER_PREFIX = 'PED-';

    private const ORDER_NUMBER_PAD = 5;

    /** Evita recursión entre closeOrder y updateShipmentStatus al sincronizar entrega/cierre. */
    private bool $syncingDeliveryClosure = false;

    public function list(
        ListQuery $query,
        ?int $customerId = null,
        ?string $status = null,
        ?string $paymentStatus = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?float $minTotal = null,
        ?float $maxTotal = null,
    ): LengthAwarePaginator {
        $builder = Order::query()
            ->select('orders.*')
            ->selectSub(Order::totalAmountSubquery(), 'total_amount')
            ->with(['customer'])
            ->withSum('payments as paid_amount', 'amount')
            ->orderByDesc('order_date')
            ->orderByDesc('id');

        if ($customerId !== null) {
            $builder->where('customer_id', $customerId);
        }

        if ($status !== null) {
            $builder->where('status', $status);
        }

        if ($dateFrom !== null) {
            $builder->whereDate('order_date', '>=', $dateFrom);
        }

        if ($dateTo !== null) {
            $builder->whereDate('order_date', '<=', $dateTo);
        }

        if ($minTotal !== null) {
            $this->applyTotalFilter($builder, '>=', $minTotal);
        }

        if ($maxTotal !== null) {
            $this->applyTotalFilter($builder, '<=', $maxTotal);
        }

        if ($paymentStatus !== null) {
            $this->applyPaymentStatusFilter($builder, $paymentStatus);
        }

        return SearchablePaginator::paginate($builder, $query, self::SEARCHABLE_COLUMNS);
    }

    public function find(Order $order): Order
    {
        $found = Order::query()
            ->whereKey($order->id)
            ->select('orders.*')
            ->selectSub(Order::totalAmountSubquery(), 'total_amount')
            ->with([
                'customer',
                'details.product.category',
                'details.product.details.attribute',
                'details.supplier',
                'payments.createdBy',
                'payments.billingReference',
                'shipment',
                'billingReference.salesNote',
                'billingReferences.salesNote',
                'billingReferences.payment',
            ])
            ->firstOrFail();

        $found->loadSum('payments as paid_amount', 'amount');

        return $found;
    }

    public function create(array $data, User $author): Order
    {
        return DB::transaction(function () use ($data, $author) {
            $details = $this->normalizeDetails($data['details'] ?? []);

            $order = Order::query()->create([
                'order_number' => $this->nextOrderNumber(),
                'observations' => $data['observations'] ?? null,
                'status' => OrderStatus::REGISTERED,
                'order_date' => $data['order_date'],
                'customer_id' => $data['customer_id'],
                'created_by' => $author->id,
                'updated_by' => $author->id,
            ]);

            $this->syncDetails($order, $details, $author);

            return $this->find($order->fresh());
        });
    }

    public function update(Order $order, array $data, User $author): Order
    {
        return DB::transaction(function () use ($order, $data, $author) {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === OrderStatus::CANCELLED) {
                throw ValidationException::withMessages([
                    'order' => ['No se puede editar un pedido cancelado.'],
                ]);
            }

            if (! OrderStatus::isEditable($locked->status)) {
                throw ValidationException::withMessages([
                    'order' => ['El pedido no se puede editar en su estado actual.'],
                ]);
            }

            $locked->update([
                'customer_id' => $data['customer_id'] ?? $locked->customer_id,
                'observations' => array_key_exists('observations', $data)
                    ? $data['observations']
                    : $locked->observations,
                'order_date' => $data['order_date'] ?? $locked->order_date,
                'updated_by' => $author->id,
            ]);

            if (array_key_exists('details', $data)) {
                $details = $this->normalizeDetails($data['details'] ?? []);
                $locked->details()->delete();
                $this->syncDetails($locked, $details, $author);
            }

            return $this->find($locked->fresh());
        });
    }

    public function delete(Order $order): void
    {
        $order->loadMissing('billingReferences');

        if ($order->billingReferences->isNotEmpty()) {
            throw ValidationException::withMessages([
                'order' => ['No se puede eliminar un pedido con comprobante. Cancélalo para conservarlo en el historial.'],
            ]);
        }

        if ($order->status !== OrderStatus::REGISTERED) {
            throw ValidationException::withMessages([
                'order' => ['Solo se pueden eliminar pedidos en estado registrado sin comprobante.'],
            ]);
        }

        if ($order->payments()->exists()) {
            throw ValidationException::withMessages([
                'order' => ['No se puede eliminar un pedido que tiene pagos registrados. Cancélalo en su lugar.'],
            ]);
        }

        $order->delete();
    }

    public function updateStatus(Order $order, array $data, User $author): Order
    {
        $nextStatus = $data['status'];

        if (! OrderStatus::canTransition($order->status, $nextStatus)) {
            throw ValidationException::withMessages([
                'status' => ['La transición de estado no es válida.'],
            ]);
        }

        return match ($nextStatus) {
            OrderStatus::PREPARING => $this->transitionToPreparing($order, $author),
            OrderStatus::SHIPPED => $this->shipOrder($order, $data['shipment'] ?? null, $author),
            OrderStatus::CLOSED => $this->closeOrder($order, $author),
            OrderStatus::CANCELLED => $this->cancelOrder($order, $author),
            default => throw ValidationException::withMessages([
                'status' => ['La transición de estado no es válida.'],
            ]),
        };
    }

    public function listPayments(Order $order, ListQuery $query): LengthAwarePaginator
    {
        $builder = $order->payments()->with('billingReference')->getQuery()->orderByDesc('created_at');

        return SearchablePaginator::paginate($builder, $query, []);
    }

    public function createPayment(Order $order, array $data, User $author): OrderPayment
    {
        return DB::transaction(function () use ($order, $data, $author) {
            $locked = Order::query()
                ->whereKey($order->id)
                ->with(['details.product'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === OrderStatus::CLOSED || $locked->status === OrderStatus::CANCELLED) {
                throw ValidationException::withMessages([
                    'amount' => ['No se pueden registrar pagos en un pedido cerrado o cancelado.'],
                ]);
            }

            $amount = (float) $data['amount'];
            $total = (float) $locked->total_amount;
            $paid = (float) $locked->payments()->sum('amount');
            $remaining = max(0, round($total - $paid, 2));

            if ($amount <= 0) {
                throw ValidationException::withMessages([
                    'amount' => ['El monto del pago debe ser mayor a cero.'],
                ]);
            }

            if ($amount - $remaining > 0.00001) {
                throw ValidationException::withMessages([
                    'amount' => [
                        'El pago no puede superar el saldo pendiente de S/ '.$this->formatMoney($remaining).'.',
                    ],
                ]);
            }

            $concept = filled($data['concept'] ?? null)
                ? trim((string) $data['concept'])
                : app(PaymentConceptSuggester::class)->suggest($locked, $amount, $remaining);

            $payment = OrderPayment::query()->create([
                'amount' => $amount,
                'concept' => $concept,
                'payment_method' => (int) $data['payment_method'],
                'payment_date' => $data['payment_date'],
                'operation_number' => filled($data['operation_number'] ?? null)
                    ? trim((string) $data['operation_number'])
                    : null,
                ...(PaymentReceiptStorage::store($data['receipt_file'] ?? null) ?? []),
                'order_id' => $locked->id,
                'created_by' => $author->id,
                'updated_by' => $author->id,
            ]);

            return $payment->fresh(['billingReference']) ?? $payment;
        });
    }

    public function deletePayment(Order $order, OrderPayment $payment): void
    {
        if ($payment->order_id !== $order->id) {
            throw ValidationException::withMessages([
                'payment' => ['El pago no pertenece a este pedido.'],
            ]);
        }

        if ($order->status === OrderStatus::CLOSED || $order->status === OrderStatus::CANCELLED) {
            throw ValidationException::withMessages([
                'payment' => ['No se pueden eliminar pagos de un pedido cerrado o cancelado.'],
            ]);
        }

        $payment->loadMissing('billingReference');
        if ($payment->billingReference !== null) {
            throw ValidationException::withMessages([
                'payment' => ['No se puede eliminar un pago que ya tiene comprobante asociado.'],
            ]);
        }

        PaymentReceiptStorage::delete($payment->receipt_file_path);

        $payment->delete();
    }

    /**
     * @return array{path: string, name: string, mime: string}
     */
    public function paymentReceiptDownload(Order $order, OrderPayment $payment): array
    {
        if ($payment->order_id !== $order->id) {
            throw ValidationException::withMessages([
                'payment' => ['El pago no pertenece a este pedido.'],
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

    public function getShipment(Order $order): ?Shipment
    {
        return $order->shipment()->first();
    }

    public function updateShipmentStatus(Order $order, string $status, User $author): Order
    {
        return DB::transaction(function () use ($order, $status, $author) {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $shipment = Shipment::query()
                ->where('order_id', $locked->id)
                ->lockForUpdate()
                ->first();

            if ($shipment === null) {
                throw ValidationException::withMessages([
                    'shipment' => ['El pedido no tiene un envío asociado.'],
                ]);
            }

            if ($locked->status === OrderStatus::CANCELLED) {
                throw ValidationException::withMessages([
                    'status' => ['No se puede actualizar el envío de un pedido cancelado.'],
                ]);
            }

            if (! ShipmentStatus::canTransition($shipment->status, $status)) {
                throw ValidationException::withMessages([
                    'status' => ['La transición de estado del envío no es válida.'],
                ]);
            }

            $previousStatus = $shipment->status;

            if ($status === ShipmentStatus::DELIVERED) {
                // El envío SÍ puede llegar a DELIVERED con saldo pendiente.
                // El pedido solo se cierra automáticamente si está totalmente pagado.
                $remaining = $this->remainingAmount($locked);
                $canCloseOrder = $remaining <= 0.00001
                    && OrderStatus::canTransition($locked->status, OrderStatus::CLOSED);

                $this->syncingDeliveryClosure = true;

                try {
                    $shipment->update([
                        'status' => ShipmentStatus::DELIVERED,
                        'updated_by' => $author->id,
                    ]);

                    if ($canCloseOrder) {
                        $locked->update([
                            'status' => OrderStatus::CLOSED,
                            'updated_by' => $author->id,
                        ]);
                    }
                } finally {
                    $this->syncingDeliveryClosure = false;
                }

                return $this->find($locked->fresh());
            }

            $shipment->update([
                'status' => $status,
                'updated_by' => $author->id,
            ]);

            if (
                $previousStatus !== ShipmentStatus::AT_DESTINATION
                && $status === ShipmentStatus::AT_DESTINATION
            ) {
                event(new \App\Events\ShipmentArrivedAtDestination($shipment->fresh() ?? $shipment));
            }

            return $this->find($locked->fresh());
        });
    }

    private function transitionToPreparing(Order $order, User $author): Order
    {
        $order->update([
            'status' => OrderStatus::PREPARING,
            'updated_by' => $author->id,
        ]);

        return $this->find($order->fresh());
    }

    /**
     * @param  array<string, mixed>|null  $shipmentData
     */
    private function shipOrder(Order $order, ?array $shipmentData, User $author): Order
    {
        if ($shipmentData === null) {
            throw ValidationException::withMessages([
                'shipment' => ['Debe proporcionar los datos del envío para marcar el pedido como enviado.'],
            ]);
        }

        return DB::transaction(function () use ($order, $shipmentData, $author) {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! OrderStatus::canTransition($locked->status, OrderStatus::SHIPPED)) {
                throw ValidationException::withMessages([
                    'status' => ['La transición de estado no es válida.'],
                ]);
            }

            if ($locked->shipment()->exists()) {
                throw ValidationException::withMessages([
                    'shipment' => ['El envío ya existe para este pedido.'],
                ]);
            }

            $alreadyCreated = Movement::query()
                ->where('reference_type', MovementReferenceType::ORDER)
                ->where('reference_id', $locked->id)
                ->exists();

            if ($alreadyCreated) {
                throw ValidationException::withMessages([
                    'status' => ['Ya existen movimientos de inventario para este pedido.'],
                ]);
            }

            $details = OrderDetail::query()
                ->where('order_id', $locked->id)
                ->get();

            $this->assertSufficientStock($details);

            $shipmentDate = $shipmentData['shipment_date'];

            Shipment::query()->create([
                'agency' => $shipmentData['agency'],
                'shipment_date' => $shipmentDate,
                'delivery_date' => $shipmentData['delivery_date'] ?? null,
                'shipping_key' => $shipmentData['shipping_key'],
                'destination' => $shipmentData['destination'],
                'status' => ShipmentStatus::SHIPPED,
                'agency_destination' => $shipmentData['agency_destination'],
                'order_id' => $locked->id,
                'created_by' => $author->id,
                'updated_by' => $author->id,
            ]);

            foreach ($details as $detail) {
                if ($detail->fulfillment_type !== FulfillmentType::STOCK) {
                    continue;
                }

                Movement::query()->create([
                    'product_id' => $detail->product_id,
                    'type' => MovementType::OUT,
                    'quantity' => $detail->quantity,
                    'unit_cost' => $detail->unit_price,
                    'movement_date' => $shipmentDate,
                    'reference_type' => MovementReferenceType::ORDER,
                    'reference_id' => $locked->id,
                    'created_by' => $author->id,
                    'updated_by' => $author->id,
                ]);
            }

            $locked->update([
                'status' => OrderStatus::SHIPPED,
                'updated_by' => $author->id,
            ]);

            return $this->find($locked->fresh());
        });
    }

    private function cancelOrder(Order $order, User $author): Order
    {
        return DB::transaction(function () use ($order, $author) {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if (! OrderStatus::canTransition($locked->status, OrderStatus::CANCELLED)) {
                throw ValidationException::withMessages([
                    'status' => ['Este pedido no se puede cancelar.'],
                ]);
            }

            $this->returnStockForCancelledOrder($locked, $author);

            $locked->update([
                'status' => OrderStatus::CANCELLED,
                'updated_by' => $author->id,
            ]);

            return $this->find($locked->fresh());
        });
    }

    private function returnStockForCancelledOrder(Order $order, User $author): void
    {
        $outgoing = Movement::query()
            ->where('reference_type', MovementReferenceType::ORDER)
            ->where('reference_id', $order->id)
            ->where('type', MovementType::OUT)
            ->get()
            ->groupBy('product_id')
            ->map(fn ($rows) => (int) $rows->sum('quantity'));

        $incoming = Movement::query()
            ->where('reference_type', MovementReferenceType::ORDER)
            ->where('reference_id', $order->id)
            ->where('type', MovementType::IN)
            ->get()
            ->groupBy('product_id')
            ->map(fn ($rows) => (int) $rows->sum('quantity'));

        $returnDate = optional($order->order_date)?->toDateString() ?? now()->toDateString();

        foreach ($outgoing as $productId => $outQuantity) {
            $toReturn = $outQuantity - (int) ($incoming[$productId] ?? 0);
            if ($toReturn <= 0 || $productId === null) {
                continue;
            }

            Movement::query()->create([
                'product_id' => (int) $productId,
                'type' => MovementType::IN,
                'quantity' => $toReturn,
                'unit_cost' => 0,
                'movement_date' => $returnDate,
                'reference_type' => MovementReferenceType::ORDER,
                'reference_id' => $order->id,
                'created_by' => $author->id,
                'updated_by' => $author->id,
            ]);
        }
    }

    private function closeOrder(Order $order, User $author): Order
    {
        return DB::transaction(function () use ($order, $author) {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();

            if ($this->syncingDeliveryClosure || $locked->status === OrderStatus::CLOSED) {
                return $this->find($locked->fresh());
            }

            if (! OrderStatus::canTransition($locked->status, OrderStatus::CLOSED)) {
                throw ValidationException::withMessages([
                    'status' => ['La transición de estado no es válida.'],
                ]);
            }

            $remaining = $this->remainingAmount($locked);

            if ($remaining > 0.00001) {
                throw ValidationException::withMessages([
                    'status' => [
                        'El pedido no puede cerrarse porque mantiene un saldo pendiente de S/ '.$this->formatMoney($remaining).'.',
                    ],
                ]);
            }

            $shipment = Shipment::query()
                ->where('order_id', $locked->id)
                ->lockForUpdate()
                ->first();

            if ($shipment === null) {
                throw ValidationException::withMessages([
                    'shipment' => ['No se puede cerrar el pedido sin un envío. Debe enviarse primero.'],
                ]);
            }

            $this->syncingDeliveryClosure = true;

            try {
                if ($shipment->status !== ShipmentStatus::DELIVERED) {
                    $shipment->update([
                        'status' => ShipmentStatus::DELIVERED,
                        'updated_by' => $author->id,
                    ]);
                }

                $locked->update([
                    'status' => OrderStatus::CLOSED,
                    'updated_by' => $author->id,
                ]);
            } finally {
                $this->syncingDeliveryClosure = false;
            }

            return $this->find($locked->fresh());
        });
    }

    /** Número correlativo único generado solo en backend (PED-00001). */
    private function nextOrderNumber(): string
    {
        $latest = Order::query()
            ->where('order_number', 'like', self::ORDER_NUMBER_PREFIX.'%')
            ->lockForUpdate()
            ->orderByDesc('id')
            ->value('order_number');

        $sequence = 1;

        if (
            is_string($latest)
            && preg_match('/^'.preg_quote(self::ORDER_NUMBER_PREFIX, '/').'(\d+)$/', $latest, $matches)
        ) {
            $sequence = ((int) $matches[1]) + 1;
        }

        do {
            $number = self::ORDER_NUMBER_PREFIX.str_pad(
                (string) $sequence,
                self::ORDER_NUMBER_PAD,
                '0',
                STR_PAD_LEFT,
            );
            $exists = Order::query()->where('order_number', $number)->exists();
            $sequence++;
        } while ($exists);

        return $number;
    }

    /**
     * @param  array<int, array<string, mixed>>  $details
     * @return array<int, array{
     *     product_id: int|null,
     *     product_name: string,
     *     quantity: int,
     *     unit_price: float,
     *     observations: string|null,
     *     fulfillment_type: string,
     *     supplier_id: int|null
     * }>
     */
    private function normalizeDetails(array $details): array
    {
        if ($details === []) {
            throw ValidationException::withMessages([
                'details' => ['El pedido debe incluir al menos un detalle.'],
            ]);
        }

        $normalized = [];

        foreach ($details as $detail) {
            $fulfillmentType = (string) ($detail['fulfillment_type'] ?? '');

            if (! in_array($fulfillmentType, FulfillmentType::values(), true)) {
                throw ValidationException::withMessages([
                    'details' => ['El tipo de cumplimiento del detalle no es válido.'],
                ]);
            }

            $quantity = (int) ($detail['quantity'] ?? 0);
            $unitPrice = (float) ($detail['unit_price'] ?? -1);

            if ($quantity <= 0) {
                throw ValidationException::withMessages([
                    'details' => ['La cantidad debe ser mayor a cero.'],
                ]);
            }

            if ($unitPrice < 0) {
                throw ValidationException::withMessages([
                    'details' => ['El precio unitario no puede ser negativo.'],
                ]);
            }

            $productId = isset($detail['product_id']) && $detail['product_id'] !== null && $detail['product_id'] !== ''
                ? (int) $detail['product_id']
                : null;
            $supplierId = isset($detail['supplier_id']) && $detail['supplier_id'] !== null && $detail['supplier_id'] !== ''
                ? (int) $detail['supplier_id']
                : null;
            $productName = isset($detail['product_name']) ? trim((string) $detail['product_name']) : '';

            if ($fulfillmentType === FulfillmentType::STOCK) {
                if ($productId === null) {
                    throw ValidationException::withMessages([
                        'details' => ['Debe seleccionar un producto de inventario para detalles con cumplimiento STOCK.'],
                    ]);
                }

                if ($supplierId !== null) {
                    throw ValidationException::withMessages([
                        'details' => ['El proveedor debe ser nulo para detalles con cumplimiento STOCK.'],
                    ]);
                }

                $product = Product::query()->find($productId);

                if ($product === null) {
                    throw ValidationException::withMessages([
                        'details' => ['El producto seleccionado no existe.'],
                    ]);
                }

                $productName = $product->name;
                $supplierId = null;
            }

            if ($fulfillmentType === FulfillmentType::SUPPLIER) {
                if ($supplierId === null) {
                    throw ValidationException::withMessages([
                        'details' => ['Debe seleccionar un proveedor para productos enviados directamente por proveedor.'],
                    ]);
                }

                if ($productId !== null) {
                    $product = Product::query()->find($productId);

                    if ($product === null) {
                        throw ValidationException::withMessages([
                            'details' => ['El producto seleccionado no existe.'],
                        ]);
                    }

                    $productName = $product->name;
                } elseif ($productName === '') {
                    throw ValidationException::withMessages([
                        'details' => ['Debe indicar el nombre del producto.'],
                    ]);
                }
            }

            $normalized[] = [
                'product_id' => $productId,
                'product_name' => $productName,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'observations' => $detail['observations'] ?? null,
                'fulfillment_type' => $fulfillmentType,
                'supplier_id' => $supplierId,
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<int, array{
     *     product_id: int|null,
     *     product_name: string,
     *     quantity: int,
     *     unit_price: float,
     *     observations: string|null,
     *     fulfillment_type: string,
     *     supplier_id: int|null
     * }>  $details
     */
    private function syncDetails(Order $order, array $details, User $author): void
    {
        foreach ($details as $detail) {
            $order->details()->create([
                'product_id' => $detail['product_id'],
                'product_name' => $detail['product_name'],
                'quantity' => $detail['quantity'],
                'unit_price' => $detail['unit_price'],
                'observations' => $detail['observations'],
                'fulfillment_type' => $detail['fulfillment_type'],
                'supplier_id' => $detail['supplier_id'],
                'created_by' => $author->id,
                'updated_by' => $author->id,
            ]);
        }
    }

    /** @param  \Illuminate\Support\Collection<int, OrderDetail>  $details */
    private function assertSufficientStock($details): void
    {
        $requiredByProduct = [];

        foreach ($details as $detail) {
            if ($detail->fulfillment_type !== FulfillmentType::STOCK || $detail->product_id === null) {
                continue;
            }

            $productId = (int) $detail->product_id;

            if (! isset($requiredByProduct[$productId])) {
                $requiredByProduct[$productId] = [
                    'quantity' => 0,
                    'name' => $detail->product_name,
                ];
            }

            $requiredByProduct[$productId]['quantity'] += (int) $detail->quantity;
        }

        if ($requiredByProduct === []) {
            return;
        }

        $products = Product::query()
            ->whereIn('id', array_keys($requiredByProduct))
            ->select('products.*')
            ->selectSub(Product::stockSubquery(), 'stock')
            ->get()
            ->keyBy('id');

        $insufficient = [];

        foreach ($requiredByProduct as $productId => $required) {
            $product = $products->get($productId);
            $stock = $product !== null ? (int) $product->stock : 0;

            if ($stock < $required['quantity']) {
                $insufficient[] = $product?->name ?? $required['name'];
            }
        }

        if ($insufficient !== []) {
            throw ValidationException::withMessages([
                'status' => [
                    'El pedido no puede enviarse porque tiene productos sin stock suficiente: '.implode(', ', $insufficient).'.',
                ],
            ]);
        }
    }

    private function remainingAmount(Order $order): float
    {
        $total = (float) $order->total_amount;
        $paid = (float) $order->payments()->sum('amount');

        return max(0, round($total - $paid, 2));
    }

    private function formatMoney(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    private function applyTotalFilter(Builder $builder, string $operator, float $value): void
    {
        $totalSub = Order::totalAmountSubquery();

        // Inline the numeric literal: SQLite PDO float bindings compare incorrectly
        // against numeric subqueries when passed as bound parameters.
        $builder->whereRaw(
            '('.$totalSub->toSql().') '.$operator.' '.(float) $value,
            $totalSub->getBindings(),
        );
    }

    private function applyPaymentStatusFilter(Builder $builder, string $paymentStatus): void
    {
        $paidSub = OrderPayment::query()
            ->selectRaw('COALESCE(SUM(amount), 0)')
            ->whereColumn('order_id', 'orders.id');

        $totalSub = Order::totalAmountSubquery();

        match ($paymentStatus) {
            PaymentStatus::UNPAID => $builder->whereRaw(
                '('.$paidSub->toSql().') <= 0',
                $paidSub->getBindings(),
            ),
            PaymentStatus::PAID => $builder->whereRaw(
                '('.$paidSub->toSql().') >= ('.$totalSub->toSql().')',
                array_merge($paidSub->getBindings(), $totalSub->getBindings()),
            ),
            PaymentStatus::PARTIALLY_PAID => $builder
                ->whereRaw('('.$paidSub->toSql().') > 0', $paidSub->getBindings())
                ->whereRaw(
                    '('.$paidSub->toSql().') < ('.$totalSub->toSql().')',
                    array_merge($paidSub->getBindings(), $totalSub->getBindings()),
                ),
            default => null,
        };
    }
}
