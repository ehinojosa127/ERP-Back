<?php

namespace App\Services\Billing;

use App\Exceptions\Billing\BillingValidationException;
use App\Models\Order;
use App\Models\OrderBillingReference;
use App\Models\SalesNote;
use App\Models\User;
use App\Support\Billing\DocumentKind;
use Illuminate\Support\Facades\DB;

final class SalesNoteService
{
    public const SERIES = 'NV01';

    public function __construct(
        private readonly BillingDocumentMapper $mapper,
    ) {}

    public function issueFromOrder(Order $order, User $author): SalesNote
    {
        $order->loadMissing(['customer', 'details.product']);
        if ($order->customer === null) {
            throw new BillingValidationException('El pedido no tiene cliente asociado.');
        }

        return DB::transaction(function () use ($order, $author) {
            $number = $this->nextNumber();
            $items = [];
            foreach ($order->details as $detail) {
                $unitPrice = round((float) $detail->unit_price, 2);
                $quantity = (int) $detail->quantity;
                $items[] = [
                    'product_id' => $detail->product_id,
                    'description' => $this->mapper->itemDescription($detail),
                    'product_name' => $this->mapper->itemDescription($detail),
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'unit_value' => $unitPrice,
                    'total' => round($quantity * $unitPrice, 2),
                    'subtotal' => round($quantity * $unitPrice, 2),
                ];
            }

            if ($items === []) {
                throw new BillingValidationException('El pedido no tiene ítems para facturar.');
            }

            $total = round(array_sum(array_column($items, 'total')), 2);

            $note = SalesNote::query()->create([
                'series' => self::SERIES,
                'number' => $number,
                'full_number' => sprintf('%s-%05d', self::SERIES, $number),
                'issue_date' => optional($order->order_date)?->toDateString() ?? now()->toDateString(),
                'order_id' => $order->id,
                'customer_id' => $order->customer_id,
                'customer_name' => trim($order->customer->name.' '.$order->customer->lastname),
                'customer_document' => $order->customer->dni ?: $order->customer->ruc,
                'subtotal' => $total,
                'total' => $total,
                'status' => 'ISSUED',
                'observations' => $order->observations,
                'items_snapshot' => $items,
                'created_by' => $author->id,
            ]);

            OrderBillingReference::query()->create([
                'order_id' => $order->id,
                'document_kind' => DocumentKind::SALES_NOTE,
                'origin' => 'internal',
                'sales_note_id' => $note->id,
                'series' => $note->series,
                'number' => $note->number,
                'full_number' => $note->full_number,
                'idempotency_key' => sprintf('erp:order:%d:sales_note:v1', $order->id),
            ]);

            return $note;
        });
    }

    private function nextNumber(): int
    {
        $last = SalesNote::query()->where('series', self::SERIES)->lockForUpdate()->max('number');

        return ((int) $last) + 1;
    }
}
