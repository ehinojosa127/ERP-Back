<?php

namespace Tests\Feature;

use App\Exceptions\Billing\BillingException;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Movement;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Services\Billing\OrderBillingService;
use App\Services\Orders\OrderService;
use App\Support\Auth\PermissionCatalog;
use App\Support\Billing\BillingEmissionStatus;
use App\Support\Billing\DocumentKind;
use App\Support\Inventory\MovementReferenceType;
use App\Support\Inventory\MovementType;
use App\Support\Inventory\PaymentMethod;
use App\Support\Orders\FulfillmentType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PaymentBillingFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermissionCatalog::all() as $name) {
            Permission::query()->firstOrCreate(['name' => $name]);
        }
    }

    public function test_payment_with_emit_saves_payment_and_links_cpe(): void
    {
        $this->fakeBillingAccepted();
        $admin = $this->createAdminUser();
        $order = $this->createOrder($admin, total: 390);
        $orderService = app(OrderService::class);
        $billingService = app(OrderBillingService::class);

        $payment = $orderService->createPayment($order, [
            'amount' => 90,
            'concept' => 'Adelanto',
            'payment_method' => PaymentMethod::YAPE,
            'payment_date' => now()->toDateString(),
        ], $admin);

        $billingService->issueFromPayment(
            $order,
            $payment,
            DocumentKind::RECEIPT,
            $admin,
            'B001',
        );

        $payment = $payment->fresh(['billingReference']);

        $this->assertDatabaseHas('order_payments', [
            'id' => $payment->id,
            'amount' => 90,
            'concept' => 'Adelanto',
            'billing_emission_status' => BillingEmissionStatus::ISSUED,
        ]);
        $this->assertNotNull($payment->billingReference);
        $this->assertSame(300.0, (float) $order->fresh(['payments', 'details'])->remaining_amount);
    }

    public function test_two_payments_create_two_cpes(): void
    {
        $docCounter = 0;
        Http::fake(function ($request) use (&$docCounter) {
            if (str_contains($request->url(), '/capabilities')) {
                return Http::response([
                    'taxRegime' => 'general',
                    'taxpayerType' => 'legal',
                    'canIssueInvoice' => true,
                    'canIssueReceipt' => true,
                    'allowedDocumentTypes' => ['01', '03'],
                ], 200);
            }

            if (str_contains($request->url(), '/series')) {
                return Http::response([[
                    'documentType' => '03',
                    'series' => 'B001',
                    'isActive' => true,
                    'lastNumber' => 0,
                    'id' => '11111111-1111-1111-1111-111111111111',
                ]], 200);
            }

            if ($request->method() === 'POST' && str_contains($request->url(), '/receipts')) {
                $docCounter++;

                return Http::response([
                    'id' => sprintf('aaaaaaaa-bbbb-cccc-dddd-%012d', $docCounter),
                    'documentType' => 'receipt',
                    'series' => 'B001',
                    'number' => $docCounter,
                    'fullNumber' => sprintf('B001-%05d', $docCounter),
                    'status' => 'accepted',
                    'sunatStatus' => 'accepted',
                    'payableAmount' => $docCounter === 1 ? 90 : 300,
                    'currency' => 'PEN',
                    'issueDate' => now()->toDateString(),
                    'files' => ['xml' => '/x', 'pdf' => '/p', 'cdr' => '/c'],
                    'canRetry' => false,
                    'canCancel' => true,
                    'canConsult' => true,
                ], 201);
            }

            return Http::response([], 200);
        });

        $admin = $this->createAdminUser();
        $order = $this->createOrder($admin, total: 390);
        $orderService = app(OrderService::class);
        $billingService = app(OrderBillingService::class);

        $payment1 = $orderService->createPayment($order, [
            'amount' => 90,
            'concept' => 'Adelanto',
            'payment_method' => PaymentMethod::YAPE,
            'payment_date' => now()->toDateString(),
        ], $admin);
        $billingService->issueFromPayment($order, $payment1, DocumentKind::RECEIPT, $admin, 'B001');

        $payment2 = $orderService->createPayment($order->fresh(), [
            'amount' => 300,
            'payment_method' => PaymentMethod::YAPE,
            'payment_date' => now()->toDateString(),
        ], $admin);
        $billingService->issueFromPayment($order->fresh(), $payment2, DocumentKind::RECEIPT, $admin, 'B001');

        $this->assertSame(2, OrderPayment::query()->where('order_id', $order->id)->count());
        $this->assertSame(2, $order->billingReferences()->count());
        $this->assertSame(0.0, (float) $order->fresh(['payments', 'details'])->remaining_amount);
    }

    public function test_billing_failure_keeps_payment_with_failed_status(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/capabilities')) {
                return Http::response([
                    'taxRegime' => 'general',
                    'canIssueInvoice' => true,
                    'canIssueReceipt' => true,
                    'allowedDocumentTypes' => ['01', '03'],
                ], 200);
            }

            if (str_contains($request->url(), '/series')) {
                return Http::response([[
                    'documentType' => '03',
                    'series' => 'B001',
                    'isActive' => true,
                    'lastNumber' => 0,
                    'id' => '11111111-1111-1111-1111-111111111111',
                ]], 200);
            }

            if (str_contains($request->url(), '/receipts')) {
                throw new ConnectionException('billing down');
            }

            if (str_contains($request->url(), '/documents')) {
                return Http::response(['items' => [], 'total' => 0], 200);
            }

            return Http::response([], 200);
        });

        $admin = $this->createAdminUser();
        $order = $this->createOrder($admin, total: 100);
        $orderService = app(OrderService::class);
        $billingService = app(OrderBillingService::class);

        $payment = $orderService->createPayment($order, [
            'amount' => 50,
            'concept' => 'Adelanto',
            'payment_method' => PaymentMethod::YAPE,
            'payment_date' => now()->toDateString(),
        ], $admin);

        try {
            $billingService->issueFromPayment($order, $payment, DocumentKind::RECEIPT, $admin, 'B001');
            $this->fail('Expected billing exception');
        } catch (BillingException) {
            // expected
        }

        $this->assertDatabaseHas('order_payments', [
            'id' => $payment->id,
            'amount' => 50,
            'billing_emission_status' => BillingEmissionStatus::FAILED,
        ]);
        $this->assertDatabaseMissing('order_billing_references', [
            'order_payment_id' => $payment->id,
        ]);
    }

    public function test_full_payment_suggests_product_concept(): void
    {
        $admin = $this->createAdminUser();
        $order = $this->createOrder($admin, total: 100, productName: 'Traje marinera celeste');

        $payment = app(OrderService::class)->createPayment($order, [
            'amount' => 100,
            'payment_method' => PaymentMethod::YAPE,
            'payment_date' => now()->toDateString(),
        ], $admin);

        $this->assertStringContainsString('Traje marinera celeste', (string) $payment->concept);
        $this->assertStringContainsString('x1', (string) $payment->concept);
    }

    private function fakeBillingAccepted(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/capabilities')) {
                return Http::response([
                    'taxRegime' => 'general',
                    'taxpayerType' => 'legal',
                    'canIssueInvoice' => true,
                    'canIssueReceipt' => true,
                    'allowedDocumentTypes' => ['01', '03'],
                ], 200);
            }

            if (str_contains($request->url(), '/series')) {
                return Http::response([[
                    'documentType' => '03',
                    'series' => 'B001',
                    'isActive' => true,
                    'lastNumber' => 0,
                    'id' => '11111111-1111-1111-1111-111111111111',
                ]], 200);
            }

            return Http::response([
                'id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
                'documentType' => 'receipt',
                'series' => 'B001',
                'number' => 1,
                'fullNumber' => 'B001-00001',
                'status' => 'accepted',
                'sunatStatus' => 'accepted',
                'payableAmount' => 90,
                'currency' => 'PEN',
                'issueDate' => now()->toDateString(),
                'files' => ['xml' => '/x', 'pdf' => '/p', 'cdr' => '/c'],
                'canRetry' => false,
                'canCancel' => true,
                'canConsult' => true,
            ], 201);
        });
    }

    private function createOrder(User $admin, float $total, string $productName = 'Producto test'): Order
    {
        $customer = Customer::query()->create([
            'name' => 'Juan',
            'lastname' => 'Cliente',
            'dni' => str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
            'phone_number' => '999888777',
            'city' => 'Lima',
        ]);
        $category = Category::query()->create(['name' => 'Cat '.uniqid()]);
        $product = Product::query()->create([
            'name' => $productName,
            'sale_price' => $total,
            'sku' => 'SKU-'.strtoupper(uniqid()),
            'category_id' => $category->id,
        ]);
        Movement::query()->create([
            'product_id' => $product->id,
            'type' => MovementType::IN,
            'quantity' => 20,
            'unit_cost' => 10,
            'movement_date' => now()->toDateString(),
            'reference_type' => MovementReferenceType::PURCHASE,
            'reference_id' => 1,
        ]);

        return app(OrderService::class)->create([
            'customer_id' => $customer->id,
            'order_date' => now()->toDateString(),
            'details' => [[
                'product_id' => $product->id,
                'quantity' => 1,
                'unit_price' => $total,
                'fulfillment_type' => FulfillmentType::STOCK,
            ]],
        ], $admin);
    }

    private function createAdminUser(): User
    {
        $role = Role::query()->firstOrCreate(
            ['name' => 'Admin-payment-billing'],
            ['description' => 'Admin'],
        );
        $role->permissions()->sync(Permission::query()->pluck('id')->all());

        return User::query()->firstOrCreate(
            ['email' => 'payment-billing-admin@example.com'],
            [
                'username' => 'payment-billing-admin',
                'password' => Hash::make('password'),
                'role_id' => $role->id,
            ],
        );
    }
}
