<?php

namespace App\Services\Automation;

use App\Contracts\Billing\BillingGateway;
use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderBillingReference;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Customers\CustomerService;
use App\Support\Customers\PhoneNormalizer;
use App\Support\Orders\OrderStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AutomationCustomerService
{
    public function __construct(
        private readonly CustomerService $customerService,
        private readonly BillingGateway $billingGateway,
    ) {}

    public function findByPhone(string $phone): Customer
    {
        $customer = $this->resolveCustomerByPhone($phone);

        if ($customer === null) {
            throw new NotFoundHttpException('Cliente no encontrado.');
        }

        return $customer;
    }

    /**
     * @return array{customer: Customer, orders: Collection<int, Order>, pendingBalance: float}
     */
    public function summary(string $phone): array
    {
        $customer = $this->findByPhone($phone);
        $orders = $this->ordersQuery($customer)->get();

        return [
            'customer' => $customer,
            'orders' => $orders,
            'pendingBalance' => round((float) $orders->sum(
                fn (Order $order) => (float) $order->remaining_amount,
            ), 2),
        ];
    }

    /**
     * @return Collection<int, Order>
     */
    public function orders(string $phone): Collection
    {
        $customer = $this->findByPhone($phone);

        return $this->ordersQuery($customer)->get();
    }

    public function orderByNumber(string $phone, string $orderNumber): Order
    {
        $customer = $this->findByPhone($phone);

        $order = $this->ordersQuery($customer)
            ->where('order_number', $orderNumber)
            ->first();

        if ($order === null) {
            throw new NotFoundHttpException('Pedido no encontrado.');
        }

        return $order;
    }

    /**
     * @return array{totalPending: float, orders: Collection<int, Order>}
     */
    public function balance(string $phone): array
    {
        $orders = $this->orders($phone)->filter(
            fn (Order $order) => (float) $order->remaining_amount > 0.00001
                && $order->status !== OrderStatus::CANCELLED,
        )->values();

        return [
            'totalPending' => round((float) $orders->sum(
                fn (Order $order) => (float) $order->remaining_amount,
            ), 2),
            'orders' => $orders,
        ];
    }

    /**
     * @return Collection<int, Shipment>
     */
    public function shipments(string $phone): Collection
    {
        $customer = $this->findByPhone($phone);

        return Shipment::query()
            ->whereHas('order', fn (Builder $q) => $q->where('customer_id', $customer->id))
            ->with(['order.payments', 'order.details'])
            ->orderByDesc('id')
            ->get();
    }

    public function shipmentForOrder(string $phone, string $orderNumber): Shipment
    {
        $order = $this->orderByNumber($phone, $orderNumber);
        $shipment = $order->shipment;

        if ($shipment === null) {
            throw new NotFoundHttpException('El pedido no tiene envío.');
        }

        $shipment->setRelation('order', $order);

        return $shipment;
    }

    /**
     * @return Collection<int, OrderBillingReference>
     */
    public function billingDocuments(string $phone, string $orderNumber): Collection
    {
        $order = $this->orderByNumber($phone, $orderNumber);
        $order->loadMissing(['billingReferences.payment', 'billingReferences.salesNote']);

        return $order->billingReferences;
    }

    /**
     * @return array{content: string, content_type: string, file_name: string}
     */
    public function downloadBillingPdf(string $phone, string $documentId): array
    {
        $customer = $this->findByPhone($phone);

        $reference = OrderBillingReference::query()
            ->where('billing_document_id', $documentId)
            ->with(['order.customer', 'payment'])
            ->first();

        if ($reference === null || $reference->order === null) {
            throw new NotFoundHttpException('Comprobante no encontrado.');
        }

        if ((int) $reference->order->customer_id !== (int) $customer->id) {
            throw new NotFoundHttpException('Comprobante no encontrado.');
        }

        if (! $this->phonesMatch($customer->phone_number, $phone)) {
            throw new NotFoundHttpException('Comprobante no encontrado.');
        }

        return $this->billingGateway->download($documentId, 'pdf');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function createCustomer(array $data): Customer
    {
        $rawPhone = (string) ($data['phone_number'] ?? $data['phone'] ?? '');
        $canonical = PhoneNormalizer::canonical($rawPhone);

        if ($canonical === '') {
            throw new HttpException(422, 'El teléfono es obligatorio.');
        }

        if ($this->resolveCustomerByPhone($canonical) !== null) {
            throw new ConflictHttpException('Ya existe un cliente con ese teléfono.');
        }

        $author = User::query()->orderBy('id')->first();
        if ($author === null) {
            throw new HttpException(503, 'No hay un usuario del sistema para registrar el cliente.');
        }

        $payload = [
            'name' => $data['name'],
            'lastname' => $data['lastname'],
            'dni' => $data['dni'] ?? null,
            'ruc' => $data['ruc'] ?? null,
            'legal_name' => $data['legal_name'] ?? null,
            'address' => $data['address'] ?? null,
            'phone_number' => $canonical,
            'city' => $data['city'],
            'agency_destination' => $data['agency_destination'] ?? null,
        ];

        $created = $this->customerService->create($payload, $author);

        return Customer::query()->findOrFail((int) $created['id']);
    }

    public function resolveCustomerByPhone(string $phone): ?Customer
    {
        $variants = PhoneNormalizer::searchVariants($phone);
        if ($variants === []) {
            return null;
        }

        $candidates = Customer::query()
            ->where(function (Builder $query) use ($variants) {
                foreach ($variants as $variant) {
                    $query->orWhere('phone_number', $variant)
                        ->orWhere('phone_number', '+'.$variant);
                }
            })
            ->get();

        foreach ($candidates as $candidate) {
            if ($this->phonesMatch($candidate->phone_number, $phone)) {
                return $candidate;
            }
        }

        return null;
    }

    private function phonesMatch(?string $stored, string $incoming): bool
    {
        $storedVariants = PhoneNormalizer::searchVariants($stored);
        $incomingVariants = PhoneNormalizer::searchVariants($incoming);

        if ($storedVariants === [] || $incomingVariants === []) {
            return false;
        }

        return count(array_intersect($storedVariants, $incomingVariants)) > 0;
    }

    private function ordersQuery(Customer $customer): Builder
    {
        return Order::query()
            ->where('customer_id', $customer->id)
            ->select('orders.*')
            ->selectSub(Order::totalAmountSubquery(), 'total_amount')
            ->withSum('payments as paid_amount', 'amount')
            ->with(['shipment', 'payments', 'details'])
            ->orderByDesc('order_date')
            ->orderByDesc('id');
    }
}
