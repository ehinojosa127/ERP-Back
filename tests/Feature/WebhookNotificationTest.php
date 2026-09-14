<?php

namespace Tests\Feature;

use App\Events\OrderReadyForPickup;
use App\Events\ShipmentArrivedAtDestination;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Movement;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\OrderPayment;
use App\Models\OutboundWebhookDelivery;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\User;
use App\Services\Orders\OrderService;
use App\Support\Inventory\MovementReferenceType;
use App\Support\Inventory\MovementType;
use App\Support\Inventory\PaymentMethod;
use App\Support\Orders\FulfillmentType;
use App\Support\Orders\OrderStatus;
use App\Support\Orders\ShipmentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebhookNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_at_destination_fires_one_webhook_delivery(): void
    {
        Http::fake([
            'http://n8n.test/*' => Http::response(['ok' => true], 200),
        ]);

        $shipment = $this->createShipmentAt(ShipmentStatus::IN_TRANSIT, paid: 100);
        $admin = User::query()->orderBy('id')->firstOrFail();

        app(OrderService::class)->updateShipmentStatus(
            $shipment->order,
            ShipmentStatus::AT_DESTINATION,
            $admin,
        );

        $this->assertDatabaseCount('outbound_webhook_deliveries', 1);
        $this->assertDatabaseHas('outbound_webhook_deliveries', [
            'event' => 'SHIPMENT_AT_DESTINATION',
            'status' => 'delivered',
            'idempotency_key' => 'shipment_at_destination:'.$shipment->id,
        ]);

        Http::assertSent(fn ($request) => $request->url() === 'http://n8n.test/webhook'
            && $request->hasHeader('X-Webhook-Secret', 'test-webhook-secret')
            && str_starts_with((string) $request->header('X-Webhook-Signature')[0], 'sha256='));
    }

    public function test_same_status_again_does_not_duplicate_webhook(): void
    {
        Http::fake([
            'http://n8n.test/*' => Http::response(['ok' => true], 200),
        ]);

        $shipment = $this->createShipmentAt(ShipmentStatus::AT_DESTINATION, paid: 100);

        event(new ShipmentArrivedAtDestination($shipment->fresh() ?? $shipment));
        event(new ShipmentArrivedAtDestination($shipment->fresh() ?? $shipment));

        $this->assertSame(1, OutboundWebhookDelivery::query()->count());
        Http::assertSentCount(1);
    }

    public function test_balance_positive_omits_shipping_key_in_payload(): void
    {
        Http::fake([
            'http://n8n.test/*' => Http::response(['ok' => true], 200),
        ]);

        $shipment = $this->createShipmentAt(ShipmentStatus::IN_TRANSIT, paid: 40);
        $admin = User::query()->orderBy('id')->firstOrFail();

        app(OrderService::class)->updateShipmentStatus(
            $shipment->order,
            ShipmentStatus::AT_DESTINATION,
            $admin,
        );

        $delivery = OutboundWebhookDelivery::query()->first();
        $this->assertNotNull($delivery);
        $payload = $delivery->payload ?? [];

        $this->assertSame('SHIPMENT_AT_DESTINATION', $payload['event'] ?? null);
        $this->assertSame(60.0, (float) ($payload['order']['balance'] ?? -1));
        $this->assertArrayHasKey('shippingKey', $payload['shipment'] ?? []);
        $this->assertNull($payload['shipment']['shippingKey']);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'n8n.test'));
    }

    public function test_order_ready_for_pickup_when_paid_at_destination(): void
    {
        Http::fake([
            'http://n8n.test/*' => Http::response(['ok' => true], 200),
        ]);

        $shipment = $this->createShipmentAt(ShipmentStatus::AT_DESTINATION, paid: 40, unitPrice: 100);
        $order = $shipment->order;
        $admin = User::query()->orderBy('id')->firstOrFail();

        app(OrderService::class)->createPayment($order, [
            'amount' => 60,
            'payment_method' => PaymentMethod::YAPE,
            'payment_date' => now()->toDateString(),
        ], $admin);

        $this->assertDatabaseHas('outbound_webhook_deliveries', [
            'event' => 'PAYMENT_CONFIRMED',
            'status' => 'delivered',
        ]);
        $this->assertDatabaseHas('outbound_webhook_deliveries', [
            'event' => 'ORDER_READY_FOR_PICKUP',
            'status' => 'delivered',
            'idempotency_key' => 'order_ready_for_pickup:'.$order->id.':'.$shipment->id,
        ]);

        Http::assertSent(function ($request) {
            $payload = json_decode($request->body(), true) ?: [];

            return ($payload['event'] ?? null) === 'ORDER_READY_FOR_PICKUP'
                && ($payload['shipment']['shippingKey'] ?? null) === '1234';
        });
    }

    public function test_direct_order_ready_event_includes_shipping_key(): void
    {
        Http::fake([
            'http://n8n.test/*' => Http::response(['ok' => true], 200),
        ]);

        $shipment = $this->createShipmentAt(ShipmentStatus::AT_DESTINATION, paid: 100);
        $order = $shipment->order->fresh(['customer', 'shipment', 'payments', 'details']);

        event(new OrderReadyForPickup($order));

        Http::assertSent(function ($request) {
            $payload = json_decode($request->body(), true) ?: [];

            return ($payload['event'] ?? null) === 'ORDER_READY_FOR_PICKUP'
                && (float) ($payload['order']['balance'] ?? -1) === 0.0
                && ($payload['shipment']['shippingKey'] ?? null) === '1234';
        });
    }

    public function test_order_shipped_fires_webhook(): void
    {
        Http::fake([
            'http://n8n.test/*' => Http::response(['ok' => true], 200),
        ]);

        $admin = $this->createAdminUser();
        $order = $this->createPreparingOrder(unitPrice: 100);

        app(OrderService::class)->updateStatus($order, [
            'status' => OrderStatus::SHIPPED,
            'shipment' => [
                'agency' => 'Shalom',
                'shipment_date' => now()->toDateString(),
                'delivery_date' => now()->addDays(2)->toDateString(),
                'shipping_key' => '9876',
                'destination' => 'Cusco',
                'agency_destination' => 'Shalom Cusco',
            ],
        ], $admin);

        $this->assertDatabaseHas('outbound_webhook_deliveries', [
            'event' => 'ORDER_SHIPPED',
            'status' => 'delivered',
            'idempotency_key' => 'order_shipped:'.$order->id,
        ]);

        Http::assertSent(function ($request) {
            $payload = json_decode($request->body(), true) ?: [];

            return ($payload['event'] ?? null) === 'ORDER_SHIPPED'
                && ($payload['shipment']['status'] ?? null) === ShipmentStatus::SHIPPED
                && ($payload['shipment']['destination'] ?? null) === 'Cusco'
                && array_key_exists('shippingKey', $payload['shipment'] ?? []);
        });
    }

    public function test_payment_confirmed_fires_webhook(): void
    {
        Http::fake([
            'http://n8n.test/*' => Http::response(['ok' => true], 200),
        ]);

        $admin = $this->createAdminUser();
        $order = $this->createPreparingOrder(unitPrice: 100, paid: 0);

        $payment = app(OrderService::class)->createPayment($order, [
            'amount' => 40,
            'concept' => 'Adelanto',
            'payment_method' => PaymentMethod::YAPE,
            'payment_date' => now()->toDateString(),
            'operation_number' => 'OP-99',
        ], $admin);

        $this->assertDatabaseHas('outbound_webhook_deliveries', [
            'event' => 'PAYMENT_CONFIRMED',
            'status' => 'delivered',
            'idempotency_key' => 'payment_confirmed:'.$payment->id,
        ]);

        Http::assertSent(function ($request) {
            $payload = json_decode($request->body(), true) ?: [];

            return ($payload['event'] ?? null) === 'PAYMENT_CONFIRMED'
                && (float) ($payload['payment']['amount'] ?? 0) === 40.0
                && ($payload['payment']['paymentMethodLabel'] ?? null) === 'Yape'
                && ($payload['payment']['operationNumber'] ?? null) === 'OP-99'
                && (float) ($payload['order']['balance'] ?? -1) === 60.0;
        });
    }

    private function createPreparingOrder(float $unitPrice = 100, float $paid = 0): Order
    {
        $admin = $this->createAdminUser();
        $customer = Customer::query()->create([
            'name' => 'Maria',
            'lastname' => 'Pérez',
            'dni' => str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
            'phone_number' => '51927882368',
            'city' => 'Lima',
        ]);

        $category = Category::query()->create(['name' => 'Cat '.uniqid()]);
        $product = Product::query()->create([
            'name' => 'Traje marinera',
            'sale_price' => $unitPrice,
            'sku' => 'SKU-'.strtoupper(uniqid()),
            'category_id' => $category->id,
        ]);
        Movement::query()->create([
            'product_id' => $product->id,
            'type' => MovementType::IN,
            'quantity' => 5,
            'unit_cost' => 20,
            'movement_date' => now()->toDateString(),
            'reference_type' => MovementReferenceType::PURCHASE,
            'reference_id' => 1,
        ]);

        $order = Order::query()->create([
            'order_number' => 'PED-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'status' => OrderStatus::PREPARING,
            'order_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        OrderDetail::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
            'unit_price' => $unitPrice,
            'fulfillment_type' => FulfillmentType::STOCK,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        if ($paid > 0) {
            OrderPayment::query()->create([
                'order_id' => $order->id,
                'amount' => $paid,
                'payment_method' => PaymentMethod::YAPE,
                'payment_date' => now()->toDateString(),
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
            ]);
        }

        return $order->fresh(['customer', 'details', 'payments']) ?? $order;
    }

    private function createShipmentAt(string $status, float $paid, float $unitPrice = 100): Shipment
    {
        $admin = $this->createAdminUser();
        $customer = Customer::query()->create([
            'name' => 'Maria',
            'lastname' => 'Pérez',
            'dni' => str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
            'phone_number' => '51927882368',
            'city' => 'Lima',
        ]);

        $category = Category::query()->create(['name' => 'Cat '.uniqid()]);
        $product = Product::query()->create([
            'name' => 'Traje marinera',
            'sale_price' => $unitPrice,
            'sku' => 'SKU-'.strtoupper(uniqid()),
            'category_id' => $category->id,
        ]);
        Movement::query()->create([
            'product_id' => $product->id,
            'type' => MovementType::IN,
            'quantity' => 5,
            'unit_cost' => 20,
            'movement_date' => now()->toDateString(),
            'reference_type' => MovementReferenceType::PURCHASE,
            'reference_id' => 1,
        ]);

        $order = Order::query()->create([
            'order_number' => 'PED-'.str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'status' => OrderStatus::SHIPPED,
            'order_date' => now()->toDateString(),
            'customer_id' => $customer->id,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        OrderDetail::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'product_name' => $product->name,
            'quantity' => 1,
            'unit_price' => $unitPrice,
            'fulfillment_type' => FulfillmentType::STOCK,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        if ($paid > 0) {
            OrderPayment::query()->create([
                'order_id' => $order->id,
                'amount' => $paid,
                'payment_method' => PaymentMethod::YAPE,
                'payment_date' => now()->toDateString(),
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
            ]);
        }

        return Shipment::query()->create([
            'order_id' => $order->id,
            'agency' => 'Shalom',
            'shipment_date' => now()->toDateString(),
            'delivery_date' => now()->addDays(2)->toDateString(),
            'shipping_key' => '1234',
            'destination' => 'Lima',
            'agency_destination' => 'Shalom San Isidro',
            'status' => $status,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ])->load(['order.customer', 'order.payments', 'order.details']);
    }

    private function createAdminUser(): User
    {
        $role = Role::query()->firstOrCreate(
            ['name' => 'Admin-webhook'],
            ['description' => 'Admin'],
        );

        return User::query()->firstOrCreate(
            ['email' => 'webhook-admin@example.com'],
            [
                'username' => 'webhook-admin',
                'password' => Hash::make('password'),
                'role_id' => $role->id,
            ],
        );
    }
}
