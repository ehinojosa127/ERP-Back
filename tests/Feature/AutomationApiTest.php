<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Movement;
use App\Models\Order;
use App\Models\OrderDetail;
use App\Models\OrderPayment;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\User;
use App\Support\Auth\PermissionCatalog;
use App\Support\Billing\DocumentKind;
use App\Support\Customers\PhoneNormalizer;
use App\Support\Inventory\MovementReferenceType;
use App\Support\Inventory\MovementType;
use App\Support\Orders\FulfillmentType;
use App\Support\Orders\OrderStatus;
use App\Support\Orders\ShipmentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AutomationApiTest extends TestCase
{
    use RefreshDatabase;

    private const API_KEY = 'test-automation-key';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermissionCatalog::all() as $name) {
            Permission::query()->firstOrCreate(['name' => $name]);
        }

        $this->createAdminUser();
    }

    public function test_missing_api_key_returns_401(): void
    {
        $this->getJson('/api/automation/customers/by-phone/51999888777')
            ->assertUnauthorized()
            ->assertJsonPath('message', 'API key inválida o ausente.');
    }

    public function test_wrong_api_key_returns_401(): void
    {
        $this->withHeader('X-API-Key', 'wrong-key')
            ->getJson('/api/automation/customers/by-phone/51999888777')
            ->assertUnauthorized();
    }

    public function test_correct_key_customer_not_found_returns_404(): void
    {
        $this->automation()
            ->getJson('/api/automation/customers/by-phone/51999888777')
            ->assertNotFound();
    }

    public function test_shipping_key_hidden_when_balance_positive(): void
    {
        $customer = $this->createCustomer(['phone_number' => PhoneNormalizer::canonical('999888777')]);
        $order = $this->createOrderWithShipment($customer, unitPrice: 100, paid: 40);

        $this->automation()
            ->getJson('/api/automation/customers/by-phone/'.$customer->phone_number.'/orders/'.$order->order_number.'/shipment')
            ->assertOk()
            ->assertJsonPath('data.shipping_key', null)
            ->assertJsonPath('data.order_balance', 60);
    }

    public function test_shipping_key_present_when_balance_zero(): void
    {
        $customer = $this->createCustomer(['phone_number' => PhoneNormalizer::canonical('999888776')]);
        $order = $this->createOrderWithShipment($customer, unitPrice: 100, paid: 100);

        $this->automation()
            ->getJson('/api/automation/customers/by-phone/'.$customer->phone_number.'/orders/'.$order->order_number.'/shipment')
            ->assertOk()
            ->assertJsonPath('data.shipping_key', '1234')
            ->assertJsonPath('data.order_balance', 0);
    }

    public function test_pdf_ownership_returns_404_for_other_customer(): void
    {
        Http::fake([
            'http://billing.test/*' => Http::response('%PDF-fake', 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="doc.pdf"',
            ]),
        ]);

        $owner = $this->createCustomer(['phone_number' => PhoneNormalizer::canonical('911111111')]);
        $other = $this->createCustomer(['phone_number' => PhoneNormalizer::canonical('922222222')]);
        $order = $this->createOrderWithShipment($owner, unitPrice: 50, paid: 50);

        $documentId = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
        $payment = $order->payments()->firstOrFail();
        $order->billingReferences()->create([
            'order_payment_id' => $payment->id,
            'document_kind' => DocumentKind::RECEIPT,
            'origin' => 'billing_service',
            'billing_document_id' => $documentId,
            'series' => 'B001',
            'number' => 1,
            'full_number' => 'B001-00001',
            'idempotency_key' => 'erp:payment:'.$payment->id.':receipt:v1',
        ]);

        $this->automation()
            ->getJson('/api/automation/customers/by-phone/'.$other->phone_number.'/billing-documents/'.$documentId.'/pdf')
            ->assertNotFound();
    }

    private function automation(): self
    {
        return $this->withHeader('X-API-Key', self::API_KEY);
    }

    private function createAdminUser(): User
    {
        $role = Role::query()->firstOrCreate(
            ['name' => 'Admin-automation'],
            ['description' => 'Admin'],
        );
        $role->permissions()->sync(Permission::query()->pluck('id')->all());

        return User::query()->firstOrCreate(
            ['email' => 'automation-admin@example.com'],
            [
                'username' => 'automation-admin',
                'password' => Hash::make('password'),
                'role_id' => $role->id,
            ],
        );
    }

    /** @param  array<string, mixed>  $overrides */
    private function createCustomer(array $overrides = []): Customer
    {
        return Customer::query()->create(array_merge([
            'name' => 'Maria',
            'lastname' => 'Cliente',
            'dni' => str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
            'phone_number' => PhoneNormalizer::canonical('999888777'),
            'city' => 'Lima',
        ], $overrides));
    }

    private function createOrderWithShipment(Customer $customer, float $unitPrice, float $paid): Order
    {
        $admin = User::query()->orderBy('id')->firstOrFail();
        $category = Category::query()->create(['name' => 'Cat '.uniqid()]);
        $product = Product::query()->create([
            'name' => 'Producto '.uniqid(),
            'sale_price' => $unitPrice,
            'sku' => 'SKU-'.strtoupper(uniqid()),
            'category_id' => $category->id,
        ]);
        Movement::query()->create([
            'product_id' => $product->id,
            'type' => MovementType::IN,
            'quantity' => 10,
            'unit_cost' => 10,
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
                'payment_method' => 1,
                'payment_date' => now()->toDateString(),
                'created_by' => $admin->id,
                'updated_by' => $admin->id,
            ]);
        }

        Shipment::query()->create([
            'order_id' => $order->id,
            'agency' => 'Shalom',
            'shipment_date' => now()->toDateString(),
            'delivery_date' => now()->addDays(2)->toDateString(),
            'shipping_key' => '1234',
            'destination' => 'Lima',
            'agency_destination' => 'Centro',
            'status' => ShipmentStatus::AT_DESTINATION,
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
        ]);

        return $order->fresh(['payments', 'details', 'shipment']) ?? $order;
    }
}
