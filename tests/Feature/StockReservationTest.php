<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Movement;
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
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class StockReservationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake([
            'http://n8n.test/*' => Http::response(['ok' => true], 200),
        ]);
    }

    public function test_available_stock_subtracts_registered_reservations(): void
    {
        [$admin, $customer, $product] = $this->seedStockProduct(physical: 5);

        app(OrderService::class)->create([
            'customer_id' => $customer->id,
            'order_date' => now()->toDateString(),
            'details' => [[
                'product_id' => $product->id,
                'quantity' => 3,
                'unit_price' => 20,
                'fulfillment_type' => FulfillmentType::STOCK,
            ]],
        ], $admin);

        $this->assertSame(2, (int) $product->fresh()->stock);

        $viaSubquery = Product::query()
            ->whereKey($product->id)
            ->select('products.*')
            ->selectSub(Product::stockSubquery(), 'stock')
            ->first();

        $this->assertSame(2, (int) $viaSubquery->stock);
    }

    public function test_create_rejects_when_available_stock_is_insufficient(): void
    {
        [$admin, $customer, $product] = $this->seedStockProduct(physical: 5);

        app(OrderService::class)->create([
            'customer_id' => $customer->id,
            'order_date' => now()->toDateString(),
            'details' => [[
                'product_id' => $product->id,
                'quantity' => 3,
                'unit_price' => 20,
                'fulfillment_type' => FulfillmentType::STOCK,
            ]],
        ], $admin);

        $this->expectException(ValidationException::class);

        app(OrderService::class)->create([
            'customer_id' => $customer->id,
            'order_date' => now()->toDateString(),
            'details' => [[
                'product_id' => $product->id,
                'quantity' => 3,
                'unit_price' => 20,
                'fulfillment_type' => FulfillmentType::STOCK,
            ]],
        ], $admin);
    }

    public function test_cancel_releases_reserved_stock(): void
    {
        [$admin, $customer, $product] = $this->seedStockProduct(physical: 4);

        $order = app(OrderService::class)->create([
            'customer_id' => $customer->id,
            'order_date' => now()->toDateString(),
            'details' => [[
                'product_id' => $product->id,
                'quantity' => 4,
                'unit_price' => 20,
                'fulfillment_type' => FulfillmentType::STOCK,
            ]],
        ], $admin);

        $this->assertSame(0, (int) $product->fresh()->stock);

        app(OrderService::class)->updateStatus($order, [
            'status' => OrderStatus::CANCELLED,
        ], $admin);

        $this->assertSame(4, (int) $product->fresh()->stock);
    }

    public function test_ship_succeeds_when_order_holds_the_reservation(): void
    {
        [$admin, $customer, $product] = $this->seedStockProduct(physical: 2);

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

        app(OrderService::class)->updateStatus($order, [
            'status' => OrderStatus::PREPARING,
        ], $admin);

        $shipped = app(OrderService::class)->updateStatus($order, [
            'status' => OrderStatus::SHIPPED,
            'shipment' => [
                'agency' => 'Shalom',
                'shipment_date' => now()->toDateString(),
                'delivery_date' => now()->addDay()->toDateString(),
                'shipping_key' => '1234',
                'destination' => 'Lima',
                'agency_destination' => 'Centro',
            ],
        ], $admin);

        $this->assertSame(OrderStatus::SHIPPED, $shipped->status);
        $this->assertSame(0, (int) $product->fresh()->stock);
    }

    /** @return array{0: User, 1: Customer, 2: Product} */
    private function seedStockProduct(int $physical): array
    {
        $role = Role::query()->firstOrCreate(
            ['name' => 'stock-admin'],
            ['description' => 'Admin'],
        );
        $admin = User::query()->firstOrCreate(
            ['email' => 'stock-admin@example.com'],
            [
                'username' => 'stock-admin',
                'password' => Hash::make('password'),
                'role_id' => $role->id,
            ],
        );

        $customer = Customer::query()->create([
            'name' => 'Ana',
            'lastname' => 'Pérez',
            'dni' => str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
            'phone_number' => '51911111111',
            'city' => 'Lima',
        ]);

        $category = Category::query()->create(['name' => 'Cat '.uniqid()]);
        $product = Product::query()->create([
            'name' => 'Falda test',
            'sale_price' => 20,
            'sku' => 'SKU-'.strtoupper(uniqid()),
            'category_id' => $category->id,
        ]);

        if ($physical > 0) {
            Movement::query()->create([
                'product_id' => $product->id,
                'type' => MovementType::IN,
                'quantity' => $physical,
                'unit_cost' => 10,
                'movement_date' => now()->toDateString(),
                'reference_type' => MovementReferenceType::PURCHASE,
                'reference_id' => 1,
            ]);
        }

        return [$admin, $customer, $product];
    }
}
