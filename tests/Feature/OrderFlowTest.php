<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Movement;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Inventory\MovementReferenceType;
use App\Support\Inventory\MovementType;
use App\Support\Inventory\PaymentMethod;
use App\Support\Inventory\PaymentStatus;
use App\Support\Orders\FulfillmentType;
use App\Support\Orders\OrderStatus;
use App\Support\Orders\ShipmentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OrderFlowTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, string> */
    private const PERMISSIONS = [
        'users.view',
        'users.create',
        'users.update',
        'users.delete',
        'orders.view',
        'orders.create',
        'orders.update',
        'orders.delete',
        'orders.payments',
        'orders.ship',
        'orders.shipment.update',
        'orders.close',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::PERMISSIONS as $name) {
            Permission::query()->firstOrCreate(['name' => $name]);
        }
    }

    public function test_create_order_with_inventory_product(): void
    {
        $admin = $this->createAdminUser();
        $this->login($admin);

        $customer = $this->createCustomer();
        $product = $this->createProduct(stock: 10);

        $response = $this->postJson('/api/orders', [
            'customer_id' => $customer->id,
            'order_date' => now()->toDateString(),
            'observations' => 'Pedido inventario',
            'details' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 2,
                    'unit_price' => 50,
                    'fulfillment_type' => FulfillmentType::STOCK,
                ],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', OrderStatus::REGISTERED);
        $this->assertStringStartsWith('PED-', (string) $response->json('data.order_number'));
        $response->assertJsonPath('data.payment_status', PaymentStatus::UNPAID);
        $response->assertJsonPath('data.total_amount', 100);
        $response->assertJsonPath('data.details.0.fulfillment_type', FulfillmentType::STOCK);
        $response->assertJsonPath('data.details.0.product_id', $product->id);
        $response->assertJsonPath('data.details.0.product_name', $product->name);
    }

    public function test_create_order_with_external_product(): void
    {
        $admin = $this->createAdminUser();
        $this->login($admin);

        $customer = $this->createCustomer();
        $supplier = $this->createSupplier();

        $response = $this->postJson('/api/orders', [
            'customer_id' => $customer->id,
            'order_date' => now()->toDateString(),
            'details' => [
                [
                    'product_name' => 'Producto externo',
                    'quantity' => 3,
                    'unit_price' => 25.5,
                    'fulfillment_type' => FulfillmentType::SUPPLIER,
                    'supplier_id' => $supplier->id,
                ],
            ],
        ]);

        $response->assertCreated();
        $this->assertStringStartsWith('PED-', (string) $response->json('data.order_number'));
        $response->assertJsonPath('data.details.0.fulfillment_type', FulfillmentType::SUPPLIER);
        $response->assertJsonPath('data.details.0.product_name', 'Producto externo');
        $response->assertJsonPath('data.details.0.supplier_id', $supplier->id);
        $response->assertJsonPath('data.details.0.product_id', null);
        $response->assertJsonPath('data.total_amount', 76.5);
    }

    public function test_create_order_with_multiple_details_calculates_total(): void
    {
        $admin = $this->createAdminUser();
        $this->login($admin);

        $customer = $this->createCustomer();
        $product = $this->createProduct(stock: 20);
        $supplier = $this->createSupplier();

        $response = $this->postJson('/api/orders', [
            'customer_id' => $customer->id,
            'order_date' => now()->toDateString(),
            'details' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 2,
                    'unit_price' => 100,
                    'fulfillment_type' => FulfillmentType::STOCK,
                ],
                [
                    'product_name' => 'Externo A',
                    'quantity' => 1,
                    'unit_price' => 50.25,
                    'fulfillment_type' => FulfillmentType::SUPPLIER,
                    'supplier_id' => $supplier->id,
                ],
            ],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.total_amount', 250.25);
        $this->assertCount(2, $response->json('data.details'));
    }

    public function test_reject_payment_exceeding_balance(): void
    {
        $admin = $this->createAdminUser();
        $this->login($admin);

        $order = $this->createStockOrder(quantity: 1, unitPrice: 100);

        $this->postJson("/api/orders/{$order->id}/payments", $this->paymentPayload(150))
            ->assertStatus(422)->assertJsonValidationErrors(['amount']);
    }

    public function test_partial_and_full_payment_status(): void
    {
        $admin = $this->createAdminUser();
        $this->login($admin);

        $order = $this->createStockOrder(quantity: 2, unitPrice: 50);

        $this->getJson("/api/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.payment_status', PaymentStatus::UNPAID)
            ->assertJsonPath('data.total_amount', 100)
            ->assertJsonPath('data.remaining_amount', 100);

        $this->postJson("/api/orders/{$order->id}/payments", $this->paymentPayload(40))
            ->assertCreated();

        $this->getJson("/api/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.payment_status', PaymentStatus::PARTIALLY_PAID)
            ->assertJsonPath('data.paid_amount', 40)
            ->assertJsonPath('data.remaining_amount', 60);

        $this->postJson("/api/orders/{$order->id}/payments", $this->paymentPayload(60))
            ->assertCreated();

        $this->getJson("/api/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.payment_status', PaymentStatus::PAID)
            ->assertJsonPath('data.paid_amount', 100)
            ->assertJsonPath('data.remaining_amount', 0);
    }

    public function test_transition_to_preparing(): void
    {
        $admin = $this->createAdminUser();
        $this->login($admin);

        $order = $this->createStockOrder();

        $this->postJson("/api/orders/{$order->id}/status", [
            'status' => OrderStatus::PREPARING,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::PREPARING);
    }

    public function test_reject_ship_without_sufficient_stock(): void
    {
        $admin = $this->createAdminUser();
        $this->login($admin);

        $customer = $this->createCustomer();
        $product = $this->createProduct(stock: 1);

        $orderId = $this->postJson('/api/orders', [
            'customer_id' => $customer->id,
            'order_date' => now()->toDateString(),
            'details' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 5,
                    'unit_price' => 20,
                    'fulfillment_type' => FulfillmentType::STOCK,
                ],
            ],
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/orders/{$orderId}/status", [
            'status' => OrderStatus::PREPARING,
        ])->assertOk();

        $this->postJson("/api/orders/{$orderId}/status", $this->shipPayload())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_ship_order_creates_shipment_and_stock_movements_only(): void
    {
        $admin = $this->createAdminUser();
        $this->login($admin);

        $customer = $this->createCustomer();
        $product = $this->createProduct(stock: 10);
        $supplier = $this->createSupplier();

        $orderId = $this->postJson('/api/orders', [
            'customer_id' => $customer->id,
            'order_date' => now()->toDateString(),
            'details' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 3,
                    'unit_price' => 40,
                    'fulfillment_type' => FulfillmentType::STOCK,
                ],
                [
                    'product_name' => 'Externo sin stock',
                    'quantity' => 2,
                    'unit_price' => 15,
                    'fulfillment_type' => FulfillmentType::SUPPLIER,
                    'supplier_id' => $supplier->id,
                ],
            ],
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/orders/{$orderId}/status", [
            'status' => OrderStatus::PREPARING,
        ])->assertOk();

        $response = $this->postJson("/api/orders/{$orderId}/status", $this->shipPayload());
        $response->assertOk();
        $response->assertJsonPath('data.status', OrderStatus::SHIPPED);
        $response->assertJsonPath('data.shipment.status', ShipmentStatus::SHIPPED);
        $response->assertJsonPath('data.shipment.shipping_key', '1234');

        $this->assertDatabaseHas('shipments', [
            'order_id' => $orderId,
            'status' => ShipmentStatus::SHIPPED,
            'shipping_key' => '1234',
        ]);

        $outMovements = Movement::query()
            ->where('reference_type', MovementReferenceType::ORDER)
            ->where('reference_id', $orderId)
            ->where('type', MovementType::OUT)
            ->get();

        $this->assertCount(1, $outMovements);
        $this->assertSame($product->id, $outMovements->first()->product_id);
        $this->assertSame(3, $outMovements->first()->quantity);
    }

    public function test_reject_duplicate_ship_movements(): void
    {
        $admin = $this->createAdminUser();
        $this->login($admin);

        $order = $this->createStockOrder(quantity: 1, unitPrice: 30, stock: 5);

        $this->postJson("/api/orders/{$order->id}/status", [
            'status' => OrderStatus::PREPARING,
        ])->assertOk();

        Movement::query()->create([
            'product_id' => $order->details()->firstOrFail()->product_id,
            'type' => MovementType::OUT,
            'quantity' => 1,
            'unit_cost' => 30,
            'movement_date' => now()->toDateString(),
            'reference_type' => MovementReferenceType::ORDER,
            'reference_id' => $order->id,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        $this->postJson("/api/orders/{$order->id}/status", $this->shipPayload())
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_shipment_status_flow_and_deliver_closes_order_when_paid(): void
    {
        $admin = $this->createAdminUser();
        $this->login($admin);

        $order = $this->createStockOrder(quantity: 1, unitPrice: 80, stock: 5);
        $orderId = $order->id;

        $this->postJson("/api/orders/{$orderId}/status", [
            'status' => OrderStatus::PREPARING,
        ])->assertOk();

        $this->postJson("/api/orders/{$orderId}/status", $this->shipPayload())
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::SHIPPED)
            ->assertJsonPath('data.shipment.status', ShipmentStatus::SHIPPED);

        $this->postJson("/api/orders/{$orderId}/payments", $this->paymentPayload(80))
            ->assertCreated();

        $this->putJson("/api/orders/{$orderId}/shipment/status", [
            'status' => ShipmentStatus::IN_TRANSIT,
        ])
            ->assertOk()
            ->assertJsonPath('data.shipment.status', ShipmentStatus::IN_TRANSIT);

        $this->putJson("/api/orders/{$orderId}/shipment/status", [
            'status' => ShipmentStatus::AT_DESTINATION,
        ])
            ->assertOk()
            ->assertJsonPath('data.shipment.status', ShipmentStatus::AT_DESTINATION);

        $this->putJson("/api/orders/{$orderId}/shipment/status", [
            'status' => ShipmentStatus::DELIVERED,
        ])
            ->assertOk()
            ->assertJsonPath('data.shipment.status', ShipmentStatus::DELIVERED)
            ->assertJsonPath('data.status', OrderStatus::CLOSED)
            ->assertJsonPath('data.payment_status', PaymentStatus::PAID);
    }

    public function test_deliver_with_pending_balance_keeps_order_shipped(): void
    {
        $admin = $this->createAdminUser();
        $this->login($admin);

        $order = $this->createStockOrder(quantity: 1, unitPrice: 100, stock: 5);
        $orderId = $order->id;

        $this->postJson("/api/orders/{$orderId}/status", [
            'status' => OrderStatus::PREPARING,
        ])->assertOk();

        $this->postJson("/api/orders/{$orderId}/status", $this->shipPayload())->assertOk();

        $this->putJson("/api/orders/{$orderId}/shipment/status", [
            'status' => ShipmentStatus::IN_TRANSIT,
        ])->assertOk();

        $this->putJson("/api/orders/{$orderId}/shipment/status", [
            'status' => ShipmentStatus::AT_DESTINATION,
        ])->assertOk();

        // Entregar con saldo pendiente: el envío sí puede avanzar; el pedido permanece SHIPPED.
        $this->putJson("/api/orders/{$orderId}/shipment/status", [
            'status' => ShipmentStatus::DELIVERED,
        ])
            ->assertOk()
            ->assertJsonPath('data.shipment.status', ShipmentStatus::DELIVERED)
            ->assertJsonPath('data.status', OrderStatus::SHIPPED)
            ->assertJsonPath('data.payment_status', PaymentStatus::UNPAID);

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'status' => OrderStatus::SHIPPED,
        ]);

        $this->assertDatabaseHas('shipments', [
            'order_id' => $orderId,
            'status' => ShipmentStatus::DELIVERED,
        ]);

        // Completar pago y cerrar manualmente.
        $this->postJson("/api/orders/{$orderId}/payments", $this->paymentPayload(100))
            ->assertCreated();

        $this->postJson("/api/orders/{$orderId}/status", [
            'status' => OrderStatus::CLOSED,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::CLOSED)
            ->assertJsonPath('data.shipment.status', ShipmentStatus::DELIVERED);
    }

    public function test_reject_ship_without_shipment_payload(): void
    {
        $admin = $this->createAdminUser();
        $this->login($admin);

        $order = $this->createStockOrder(quantity: 1, unitPrice: 50, stock: 5);

        $this->postJson("/api/orders/{$order->id}/status", [
            'status' => OrderStatus::PREPARING,
        ])->assertOk();

        $this->postJson("/api/orders/{$order->id}/status", [
            'status' => OrderStatus::SHIPPED,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['shipment']);
    }

    public function test_reject_invalid_order_status_transition(): void
    {
        $admin = $this->createAdminUser();
        $this->login($admin);

        $order = $this->createStockOrder();

        $this->postJson("/api/orders/{$order->id}/status", [
            'status' => OrderStatus::SHIPPED,
            'shipment' => [
                'agency' => 'Shalom',
                'shipment_date' => now()->toDateString(),
                'shipping_key' => '1234',
                'destination' => 'Lima',
                'agency_destination' => 'Centro',
            ],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_reject_invalid_shipment_status_transition(): void
    {
        $admin = $this->createAdminUser();
        $this->login($admin);

        $order = $this->createStockOrder(quantity: 1, unitPrice: 30, stock: 5);

        $this->postJson("/api/orders/{$order->id}/status", [
            'status' => OrderStatus::PREPARING,
        ])->assertOk();

        $this->postJson("/api/orders/{$order->id}/status", $this->shipPayload())->assertOk();

        $this->putJson("/api/orders/{$order->id}/shipment/status", [
            'status' => ShipmentStatus::AT_DESTINATION,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_reject_close_with_pending_balance(): void
    {
        $admin = $this->createAdminUser();
        $this->login($admin);

        $order = $this->createStockOrder(quantity: 1, unitPrice: 90, stock: 5);

        $this->postJson("/api/orders/{$order->id}/status", [
            'status' => OrderStatus::PREPARING,
        ])->assertOk();

        $this->postJson("/api/orders/{$order->id}/status", $this->shipPayload())->assertOk();

        $this->postJson("/api/orders/{$order->id}/status", [
            'status' => OrderStatus::CLOSED,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    public function test_shipping_key_must_be_exactly_four_digits_and_preserve_leading_zeros(): void
    {
        $admin = $this->createAdminUser();
        $this->login($admin);

        $order = $this->createStockOrder(quantity: 1, unitPrice: 20, stock: 5);

        $this->postJson("/api/orders/{$order->id}/status", [
            'status' => OrderStatus::PREPARING,
        ])->assertOk();

        $this->postJson("/api/orders/{$order->id}/status", [
            'status' => OrderStatus::SHIPPED,
            'shipment' => [
                'agency' => 'Shalom',
                'shipment_date' => now()->toDateString(),
                'shipping_key' => '12',
                'destination' => 'Lima',
                'agency_destination' => 'Centro',
            ],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['shipment.shipping_key']);

        $this->postJson("/api/orders/{$order->id}/status", [
            'status' => OrderStatus::SHIPPED,
            'shipment' => [
                'agency' => 'Shalom',
                'shipment_date' => now()->toDateString(),
                'shipping_key' => '0123',
                'destination' => 'Lima',
                'agency_destination' => 'Centro',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.shipment.shipping_key', '0123');
    }

    public function test_close_order_when_paid_sets_shipment_delivered(): void
    {
        $admin = $this->createAdminUser();
        $this->login($admin);

        $order = $this->createStockOrder(quantity: 1, unitPrice: 60, stock: 5);
        $orderId = $order->id;

        $this->postJson("/api/orders/{$orderId}/status", [
            'status' => OrderStatus::PREPARING,
        ])->assertOk();

        $this->postJson("/api/orders/{$orderId}/status", $this->shipPayload())->assertOk();

        $this->postJson("/api/orders/{$orderId}/payments", $this->paymentPayload(60))
            ->assertCreated();

        $this->postJson("/api/orders/{$orderId}/status", [
            'status' => OrderStatus::CLOSED,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::CLOSED)
            ->assertJsonPath('data.shipment.status', ShipmentStatus::DELIVERED);

        $this->assertDatabaseHas('shipments', [
            'order_id' => $orderId,
            'status' => ShipmentStatus::DELIVERED,
        ]);
    }

    public function test_search_and_filters(): void
    {
        $admin = $this->createAdminUser();
        $this->login($admin);

        $customerA = $this->createCustomer(['name' => 'Ana', 'lastname' => 'Perez', 'dni' => '11111111']);
        $customerB = $this->createCustomer(['name' => 'Luis', 'lastname' => 'Gomez', 'dni' => '22222222']);
        $product = $this->createProduct(stock: 50);

        $orderAId = $this->postJson('/api/orders', [
            'customer_id' => $customerA->id,
            'order_date' => '2026-01-10',
            'observations' => 'Pedido especial alfa',
            'details' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => 100,
                    'fulfillment_type' => FulfillmentType::STOCK,
                ],
            ],
        ])->assertCreated()->json('data.id');

        $orderBId = $this->postJson('/api/orders', [
            'customer_id' => $customerB->id,
            'order_date' => '2026-02-15',
            'observations' => 'Otro pedido',
            'details' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => 200,
                    'fulfillment_type' => FulfillmentType::STOCK,
                ],
            ],
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/orders/{$orderAId}/payments", $this->paymentPayload(100))
            ->assertCreated();

        $this->postJson("/api/orders/{$orderAId}/status", [
            'status' => OrderStatus::PREPARING,
        ])->assertOk();

        $this->getJson('/api/orders?search=alfa')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $orderAId);

        $this->getJson('/api/orders?customer_id='.$customerB->id)
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $orderBId);

        $this->getJson('/api/orders?status='.OrderStatus::PREPARING)
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $orderAId);

        $this->getJson('/api/orders?payment_status='.PaymentStatus::PAID)
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $orderAId);

        $this->getJson('/api/orders?payment_status='.PaymentStatus::UNPAID)
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $orderBId);

        $this->getJson('/api/orders?date_from=2026-02-01&date_to=2026-02-28')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $orderBId);

        $this->getJson('/api/orders?min_total=150&max_total=250')
            ->assertOk()
            ->assertJsonCount(1, 'data.data')
            ->assertJsonPath('data.data.0.id', $orderBId);
    }

    public function test_forbidden_without_orders_permission(): void
    {
        $admin = $this->createAdminUser();
        $this->login($admin);

        $customer = $this->createCustomer();
        $product = $this->createProduct(stock: 5);

        $role = Role::query()->create([
            'name' => 'Viewer',
            'description' => 'Sin permisos de pedidos',
        ]);

        $usersView = Permission::query()->where('name', 'users.view')->firstOrFail();
        $role->permissions()->attach($usersView->id);

        $user = User::query()->create([
            'username' => 'no-orders',
            'email' => 'no-orders@example.com',
            'password' => Hash::make('password'),
            'role_id' => $role->id,
        ]);

        $this->login($user);

        $this->getJson('/api/orders')->assertForbidden();
        $this->postJson('/api/orders', [
            'customer_id' => $customer->id,
            'order_date' => now()->toDateString(),
            'details' => [
                [
                    'product_id' => $product->id,
                    'quantity' => 1,
                    'unit_price' => 1,
                    'fulfillment_type' => FulfillmentType::STOCK,
                ],
            ],
        ])->assertForbidden();
    }

    public function test_cannot_delete_order_with_billing_document(): void
    {
        $admin = $this->createAdminUser();
        $this->login($admin);

        $order = $this->createStockOrder();
        $order->billingReference()->create([
            'document_kind' => 'sales_note',
            'origin' => 'internal',
            'series' => 'NV01',
            'number' => 1,
            'full_number' => 'NV01-00001',
            'idempotency_key' => 'test-delete-'.$order->id,
        ]);

        $this->deleteJson("/api/orders/{$order->id}")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['order']);
    }

    public function test_cancel_shipped_order_returns_stock_and_blocks_edits(): void
    {
        $admin = $this->createAdminUser();
        $this->login($admin);

        $order = $this->createStockOrder(quantity: 2, unitPrice: 40, stock: 5);
        $productId = $order->details()->firstOrFail()->product_id;

        $this->postJson("/api/orders/{$order->id}/status", [
            'status' => OrderStatus::PREPARING,
        ])->assertOk();

        $this->postJson("/api/orders/{$order->id}/status", $this->shipPayload())->assertOk();

        $this->postJson("/api/orders/{$order->id}/status", [
            'status' => OrderStatus::CANCELLED,
        ])
            ->assertOk()
            ->assertJsonPath('data.status', OrderStatus::CANCELLED);

        $inReturn = Movement::query()
            ->where('reference_type', MovementReferenceType::ORDER)
            ->where('reference_id', $order->id)
            ->where('type', MovementType::IN)
            ->get();

        $this->assertCount(1, $inReturn);
        $this->assertSame($productId, $inReturn->first()->product_id);
        $this->assertSame(2, $inReturn->first()->quantity);

        $this->putJson("/api/orders/{$order->id}", [
            'observations' => 'no debería editarse',
        ])->assertStatus(422);

        $this->postJson("/api/orders/{$order->id}/payments", $this->paymentPayload(40))
            ->assertStatus(422);
    }

    /** @return array{access_token: string, refresh_token: string} */
    private function login(User $user): array
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $tokens = [
            'access_token' => (string) $response->json('data.access_token'),
            'refresh_token' => (string) $response->json('data.refresh_token'),
        ];

        $this->withHeader('Authorization', 'Bearer '.$tokens['access_token']);

        return $tokens;
    }

    private function createAdminUser(): User
    {
        $role = Role::query()->create([
            'name' => 'Admin',
            'description' => 'Admin for order flow tests',
        ]);

        $role->permissions()->attach(Permission::query()->pluck('id')->all());

        return User::query()->create([
            'username' => 'order-admin',
            'email' => 'order-admin@example.com',
            'password' => Hash::make('password'),
            'role_id' => $role->id,
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function createCustomer(array $overrides = []): Customer
    {
        return Customer::query()->create(array_merge([
            'name' => 'Juan',
            'lastname' => 'Cliente',
            'dni' => str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
            'phone_number' => '999888777',
            'city' => 'Lima',
        ], $overrides));
    }

    private function createCategory(): Category
    {
        return Category::query()->create([
            'name' => 'Categoria '.uniqid(),
        ]);
    }

    private function createProduct(int $stock = 0): Product
    {
        $category = $this->createCategory();

        $product = Product::query()->create([
            'name' => 'Producto '.uniqid(),
            'sale_price' => 100,
            'sku' => 'SKU-'.strtoupper(uniqid()),
            'category_id' => $category->id,
        ]);

        if ($stock > 0) {
            Movement::query()->create([
                'product_id' => $product->id,
                'type' => MovementType::IN,
                'quantity' => $stock,
                'unit_cost' => 40,
                'movement_date' => now()->toDateString(),
                'reference_type' => MovementReferenceType::PURCHASE,
                'reference_id' => 1,
            ]);
        }

        return $product;
    }

    private function createSupplier(): Supplier
    {
        return Supplier::query()->create([
            'name' => 'Proveedor',
            'lastname' => 'Externo',
            'company_name' => 'Proveedor SA '.uniqid(),
            'ruc' => str_pad((string) random_int(1, 99999999999), 11, '0', STR_PAD_LEFT),
            'dni' => str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
            'phone_number' => '911222333',
            'city' => 'Arequipa',
        ]);
    }

    private function createStockOrder(
        int $quantity = 1,
        float $unitPrice = 100,
        int $stock = 10,
    ): Order {
        $customer = $this->createCustomer();
        $product = $this->createProduct(stock: $stock);

        $orderId = $this->postJson('/api/orders', [
            'customer_id' => $customer->id,
            'order_date' => now()->toDateString(),
            'details' => [
                [
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'fulfillment_type' => FulfillmentType::STOCK,
                ],
            ],
        ])->assertCreated()->json('data.id');

        return Order::query()->with('details')->findOrFail($orderId);
    }

    /** @return array<string, mixed> */
    private function paymentPayload(float $amount, array $extra = []): array
    {
        return array_merge([
            'amount' => $amount,
            'payment_method' => PaymentMethod::YAPE,
            'payment_date' => now()->toDateString(),
        ], $extra);
    }

    /** @return array{status: string, shipment: array<string, mixed>} */
    private function shipPayload(): array
    {
        return [
            'status' => OrderStatus::SHIPPED,
            'shipment' => [
                'agency' => 'Shalom',
                'shipment_date' => now()->toDateString(),
                'delivery_date' => now()->addDays(3)->toDateString(),
                'shipping_key' => '1234',
                'destination' => 'Lima',
                'agency_destination' => 'Agencia Centro',
            ],
        ];
    }
}
