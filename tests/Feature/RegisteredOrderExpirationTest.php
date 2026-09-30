<?php

namespace Tests\Feature;

use App\Jobs\CancelStaleRegisteredOrderJob;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Movement;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\Orders\OrderService;
use App\Support\Inventory\MovementReferenceType;
use App\Support\Inventory\MovementType;
use App\Support\Orders\FulfillmentType;
use App\Support\Orders\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RegisteredOrderExpirationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            'http://n8n.test/*' => Http::response(['ok' => true], 200),
        ]);

        config(['services.orders.registered_ttl_minutes' => 60]);
    }

    public function test_create_dispatches_delayed_cancel_job(): void
    {
        Queue::fake();

        [$admin, $customer, $product] = $this->seedFixture();

        $order = app(OrderService::class)->create([
            'customer_id' => $customer->id,
            'order_date' => now()->toDateString(),
            'details' => [[
                'product_id' => $product->id,
                'quantity' => 1,
                'unit_price' => 20,
                'fulfillment_type' => FulfillmentType::STOCK,
            ]],
        ], $admin);

        Queue::assertPushed(CancelStaleRegisteredOrderJob::class, function (CancelStaleRegisteredOrderJob $job) use ($order) {
            return $job->orderId === (int) $order->id
                && $job->delay !== null;
        });
    }

    public function test_job_does_not_cancel_before_ttl(): void
    {
        [$admin, $customer, $product] = $this->seedFixture();

        $order = app(OrderService::class)->create([
            'customer_id' => $customer->id,
            'order_date' => now()->toDateString(),
            'details' => [[
                'product_id' => $product->id,
                'quantity' => 1,
                'unit_price' => 20,
                'fulfillment_type' => FulfillmentType::STOCK,
            ]],
        ], $admin);

        $cancelled = app(OrderService::class)->cancelStaleRegisteredOrder((int) $order->id);

        $this->assertFalse($cancelled);
        $this->assertSame(OrderStatus::REGISTERED, $order->fresh()->status);
        $this->assertSame(4, (int) $product->fresh()->stock);
    }

    public function test_job_cancels_registered_order_after_ttl_and_releases_stock(): void
    {
        [$admin, $customer, $product] = $this->seedFixture();

        $order = app(OrderService::class)->create([
            'customer_id' => $customer->id,
            'order_date' => now()->toDateString(),
            'details' => [[
                'product_id' => $product->id,
                'quantity' => 2,
                'unit_price' => 20,
                'fulfillment_type' => FulfillmentType::STOCK,
            ]],
        ], $admin);

        $this->assertSame(3, (int) $product->fresh()->stock);

        $this->travel(61)->minutes();

        $cancelled = app(OrderService::class)->cancelStaleRegisteredOrder((int) $order->id);

        $this->assertTrue($cancelled);
        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
        $this->assertSame(5, (int) $product->fresh()->stock);
    }

    public function test_job_skips_when_order_already_preparing(): void
    {
        [$admin, $customer, $product] = $this->seedFixture();

        $order = app(OrderService::class)->create([
            'customer_id' => $customer->id,
            'order_date' => now()->toDateString(),
            'details' => [[
                'product_id' => $product->id,
                'quantity' => 1,
                'unit_price' => 20,
                'fulfillment_type' => FulfillmentType::STOCK,
            ]],
        ], $admin);

        app(OrderService::class)->updateStatus($order, [
            'status' => OrderStatus::PREPARING,
        ], $admin);

        $this->travel(61)->minutes();

        $cancelled = app(OrderService::class)->cancelStaleRegisteredOrder((int) $order->id);

        $this->assertFalse($cancelled);
        $this->assertSame(OrderStatus::PREPARING, $order->fresh()->status);
    }

    public function test_expire_command_cancels_stale_registered_orders(): void
    {
        [$admin, $customer, $product] = $this->seedFixture();

        $order = app(OrderService::class)->create([
            'customer_id' => $customer->id,
            'order_date' => now()->toDateString(),
            'details' => [[
                'product_id' => $product->id,
                'quantity' => 1,
                'unit_price' => 20,
                'fulfillment_type' => FulfillmentType::STOCK,
            ]],
        ], $admin);

        $this->travel(61)->minutes();

        $this->artisan('orders:expire-registered')
            ->expectsOutputToContain('Pedidos REGISTERED expirados: 1')
            ->assertSuccessful();

        $this->assertSame(OrderStatus::CANCELLED, $order->fresh()->status);
        $this->assertSame(5, (int) $product->fresh()->stock);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => OrderStatus::CANCELLED,
        ]);
    }

    /** @return array{0: User, 1: Customer, 2: Product} */
    private function seedFixture(): array
    {
        $role = Role::query()->firstOrCreate(
            ['name' => 'expire-admin'],
            ['description' => 'Admin'],
        );
        $admin = User::query()->firstOrCreate(
            ['email' => 'expire-admin@example.com'],
            [
                'username' => 'expire-admin',
                'password' => Hash::make('password'),
                'role_id' => $role->id,
            ],
        );

        $customer = Customer::query()->create([
            'name' => 'Luis',
            'lastname' => 'Torres',
            'dni' => str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
            'phone_number' => '51922222222',
            'city' => 'Lima',
        ]);

        $category = Category::query()->create(['name' => 'Cat '.uniqid()]);
        $product = Product::query()->create([
            'name' => 'Producto expire',
            'sale_price' => 20,
            'sku' => 'SKU-'.strtoupper(uniqid()),
            'category_id' => $category->id,
        ]);

        Movement::query()->create([
            'product_id' => $product->id,
            'type' => MovementType::IN,
            'quantity' => 5,
            'unit_cost' => 10,
            'movement_date' => now()->toDateString(),
            'reference_type' => MovementReferenceType::PURCHASE,
            'reference_id' => 1,
        ]);

        return [$admin, $customer, $product];
    }
}
