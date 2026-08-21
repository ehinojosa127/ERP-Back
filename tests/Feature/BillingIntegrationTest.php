<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\Movement;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Role;
use App\Models\Shipment;
use App\Models\Supplier;
use App\Models\User;
use App\Support\Auth\PermissionCatalog;
use App\Support\Billing\DocumentKind;
use App\Support\Inventory\MovementReferenceType;
use App\Support\Inventory\MovementType;
use App\Support\Orders\FulfillmentType;
use App\Support\Orders\OrderStatus;
use App\Support\Orders\ShipmentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BillingIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (PermissionCatalog::all() as $name) {
            Permission::query()->firstOrCreate(['name' => $name]);
        }
    }

    public function test_create_order_with_sales_note_does_not_call_billing_service(): void
    {
        Http::fake();
        $this->login($this->createAdminUser());
        $order = $this->createOrderPayload();

        $response = $this->postJson('/api/orders', [
            ...$order,
            'document_kind' => DocumentKind::SALES_NOTE,
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', OrderStatus::REGISTERED);
        $this->assertDatabaseHas('sales_notes', [
            'order_id' => $response->json('data.id'),
        ]);
        $note = \App\Models\SalesNote::query()->where('order_id', $response->json('data.id'))->firstOrFail();
        $this->assertNotEmpty($note->items_snapshot);
        $this->assertNotSame('', $note->items_snapshot[0]['description'] ?? '');
        $this->assertGreaterThan(0, $note->items_snapshot[0]['total'] ?? 0);
        $this->assertSame($order['order_date'], $note->issue_date->toDateString());
        Http::assertNothingSent();
    }

    public function test_create_order_with_receipt_calls_billing_service(): void
    {
        $this->fakeBillingAccepted('receipt');
        $this->login($this->createAdminUser());

        $response = $this->postJson('/api/orders', [
            ...$this->createOrderPayload(),
            'document_kind' => DocumentKind::RECEIPT,
            'series' => 'B001',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', OrderStatus::REGISTERED);
        $response->assertJsonPath('billing.document.sunat_status', 'accepted');
        $this->assertDatabaseHas('order_billing_references', [
            'order_id' => $response->json('data.id'),
            'document_kind' => DocumentKind::RECEIPT,
        ]);
    }

    public function test_issue_conflict_keeps_existing_billing_document(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/capabilities')) {
                return Http::response([
                    'taxRegime' => 'rus',
                    'taxpayerType' => 'natural_with_business',
                    'canIssueInvoice' => false,
                    'canIssueReceipt' => true,
                    'allowedDocumentTypes' => ['03'],
                ], 200);
            }

            if (str_contains($request->url(), '/series')) {
                return Http::response([[
                    'documentType' => '03',
                    'series' => 'B001',
                    'isActive' => true,
                    'lastNumber' => 1,
                    'id' => '11111111-1111-1111-1111-111111111111',
                ]], 200);
            }

            if ($request->method() === 'POST' && str_contains($request->url(), '/receipts')) {
                return Http::response([
                    'title' => "Cannot transition document status from 'Accepted' to 'Failed'.",
                    'type' => 'INVALID_STATUS_TRANSITION',
                ], 409);
            }

            if (preg_match('#/documents/[0-9a-f-]+$#i', $request->url()) === 1) {
                return Http::response($this->documentPayload('draft', 'notSent'), 200);
            }

            if (str_contains($request->url(), '/documents')) {
                return Http::response([
                    'items' => [$this->documentPayload('draft', 'notSent')],
                    'total' => 1,
                    'skip' => 0,
                    'take' => 5,
                ], 200);
            }

            return Http::response([], 200);
        });

        $this->login($this->createAdminUser());
        $response = $this->postJson('/api/orders', [
            ...$this->createOrderPayload(),
            'document_kind' => DocumentKind::RECEIPT,
            'series' => 'B001',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('billing.document.id', 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');
        $this->assertDatabaseHas('order_billing_references', [
            'order_id' => $response->json('data.id'),
            'billing_document_id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
        ]);
    }

    public function test_create_order_with_invoice_and_sunat_rejected_keeps_order_status(): void
    {
        $this->fakeBillingRejected();
        $admin = $this->createAdminUser();
        $this->login($admin);
        $customer = $this->createCustomer(['ruc' => '20100070970', 'legal_name' => 'Cliente SAC']);

        $response = $this->postJson('/api/orders', [
            ...$this->createOrderPayload($customer),
            'document_kind' => DocumentKind::INVOICE,
            'series' => 'F001',
        ]);

        $response->assertCreated();
        $orderId = $response->json('data.id');
        $response->assertJsonPath('data.status', OrderStatus::REGISTERED);
        $response->assertJsonPath('billing.sunat_rejected', true);
        $this->assertDatabaseHas('orders', ['id' => $orderId, 'status' => OrderStatus::REGISTERED]);
    }

    public function test_rejected_document_does_not_change_shipment_payment_or_stock(): void
    {
        $this->fakeBillingRejected();
        $this->login($this->createAdminUser());
        $customer = $this->createCustomer(['ruc' => '20100070970', 'legal_name' => 'Cliente SAC']);
        $product = $this->createProduct(stock: 10);

        $created = $this->postJson('/api/orders', [
            'customer_id' => $customer->id,
            'order_date' => now()->toDateString(),
            'document_kind' => DocumentKind::INVOICE,
            'series' => 'F001',
            'details' => [[
                'product_id' => $product->id,
                'quantity' => 2,
                'unit_price' => 50,
                'fulfillment_type' => FulfillmentType::STOCK,
            ]],
        ])->assertCreated();

        $orderId = $created->json('data.id');
        $this->postJson("/api/orders/{$orderId}/status", [
            'status' => OrderStatus::PREPARING,
        ])->assertOk();
        $this->postJson("/api/orders/{$orderId}/status", $this->shipPayload())->assertOk();

        $order = Order::query()->findOrFail($orderId);
        $shipment = Shipment::query()->where('order_id', $orderId)->firstOrFail();
        $this->assertSame(OrderStatus::SHIPPED, $order->status);
        $this->assertSame(ShipmentStatus::SHIPPED, $shipment->status);
        $this->assertSame(8, (int) $product->fresh()->stock);
        $this->assertNotSame(OrderStatus::CLOSED, $order->status);
    }

    public function test_order_survives_when_billing_service_is_down(): void
    {
        Http::fake(fn () => throw new ConnectionException('down'));
        $this->login($this->createAdminUser());

        $response = $this->postJson('/api/orders', [
            ...$this->createOrderPayload(),
            'document_kind' => DocumentKind::RECEIPT,
            'series' => 'B001',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', OrderStatus::REGISTERED);
        $this->assertNotNull($response->json('billing_error'));
        $this->assertDatabaseHas('orders', [
            'id' => $response->json('data.id'),
            'status' => OrderStatus::REGISTERED,
        ]);
    }

    public function test_retry_allowed_only_when_billing_says_so(): void
    {
        $this->login($this->createAdminUser());
        $order = $this->createOrderWithReference('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee');

        Http::fake([
            '*/api/v1/documents/*/status' => Http::response([
                'id' => $order->billingReference->billing_document_id,
                'status' => 'failed',
                'sunatStatus' => 'communicationError',
                'canRetry' => true,
            ], 200),
            '*/api/v1/documents/*/retry' => Http::response($this->documentPayload('accepted', 'accepted'), 200),
        ]);

        $this->postJson("/api/orders/{$order->id}/billing/retry")->assertOk();
    }

    public function test_retry_rejected_when_not_retryable(): void
    {
        $this->login($this->createAdminUser());
        $order = $this->createOrderWithReference('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeee2');

        Http::fake([
            '*/api/v1/documents/*/status' => Http::response([
                'canRetry' => false,
                'status' => 'rejected',
                'sunatStatus' => 'rejected',
            ], 200),
        ]);

        $this->postJson("/api/orders/{$order->id}/billing/retry")->assertStatus(422);
    }

    public function test_consult_and_cancel_order_billing(): void
    {
        $this->login($this->createAdminUser());
        $order = $this->createOrderWithReference('aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeee3');

        Http::fake([
            '*/api/v1/documents/*/status' => Http::response([
                'id' => $order->billingReference->billing_document_id,
                'status' => 'accepted',
                'sunatStatus' => 'accepted',
                'canRetry' => false,
                'canCancel' => true,
                'canConsult' => true,
            ], 200),
            '*/api/v1/documents/*/consult' => Http::response($this->documentPayload('accepted', 'accepted'), 200),
            '*/api/v1/documents/*/cancel' => Http::response($this->documentPayload('cancelled', 'accepted'), 200),
        ]);

        $this->postJson("/api/orders/{$order->id}/billing/consult")->assertOk();
        $this->postJson("/api/orders/{$order->id}/billing/cancel", [
            'reason' => 'ab',
        ])->assertStatus(422);
        $this->postJson("/api/orders/{$order->id}/billing/cancel", [
            'reason' => 'Error en datos del cliente',
        ])->assertOk();
    }

    public function test_consult_and_cancel_from_billing_module(): void
    {
        $this->login($this->createAdminUser());
        $documentId = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeee4';

        Http::fake([
            '*/api/v1/documents/*/status' => Http::response([
                'id' => $documentId,
                'status' => 'accepted',
                'sunatStatus' => 'accepted',
                'canRetry' => false,
                'canCancel' => true,
                'canConsult' => true,
            ], 200),
            '*/api/v1/documents/*/consult' => Http::response($this->documentPayload('accepted', 'accepted'), 200),
            '*/api/v1/documents/*/cancel' => Http::response($this->documentPayload('cancelled', 'accepted'), 200),
        ]);

        $this->postJson("/api/billing/documents/{$documentId}/consult")->assertOk();
        $this->postJson("/api/billing/documents/{$documentId}/cancel", [
            'reason' => 'Anulación solicitada por el emisor',
        ])->assertOk();
    }

    public function test_user_without_permission_cannot_issue(): void
    {
        $this->login($this->createLimitedUser(['orders.view', 'orders.create', 'customers.view']));
        $order = $this->createOrderPayload();
        $created = $this->postJson('/api/orders', $order);
        $created->assertCreated();

        $this->postJson('/api/orders/'.$created->json('data.id').'/billing', [
            'document_kind' => DocumentKind::SALES_NOTE,
        ])->assertForbidden();
    }

    public function test_billing_list_includes_sales_notes_and_electronic_documents(): void
    {
        $this->login($this->createAdminUser());
        $this->postJson('/api/orders', [
            ...$this->createOrderPayload(),
            'document_kind' => DocumentKind::SALES_NOTE,
        ])->assertCreated();

        Http::fake([
            '*/api/v1/documents*' => Http::response([
                'items' => [[
                    'id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
                    'documentType' => 'receipt',
                    'series' => 'B001',
                    'number' => 1,
                    'fullNumber' => 'B001-00001',
                    'status' => 'accepted',
                    'sunatStatus' => 'accepted',
                    'externalReference' => 'PED-00002',
                    'payableAmount' => 10,
                    'issueDate' => now()->toDateString(),
                    'recipientName' => 'Cliente Boleta',
                    'recipientIdentityNumber' => '12345678',
                    'canRetry' => false,
                    'canCancel' => true,
                    'canConsult' => true,
                ]],
                'total' => 1,
                'skip' => 0,
                'take' => 200,
            ], 200),
        ]);

        $response = $this->getJson('/api/billing/documents');
        $response->assertOk();
        $types = collect($response->json('data.data'))->pluck('document_type')->all();
        $this->assertContains(DocumentKind::SALES_NOTE, $types);
        $this->assertContains('receipt', $types);
        $this->assertCount(2, $types);
    }

    public function test_user_with_permission_can_view_billing_module(): void
    {
        Http::fake([
            '*/api/v1/documents*' => Http::response(['items' => [], 'total' => 0, 'skip' => 0, 'take' => 10], 200),
        ]);
        $this->login($this->createAdminUser());
        $this->getJson('/api/billing/documents')->assertOk();
    }

    public function test_purchase_can_exist_without_document(): void
    {
        $this->login($this->createAdminUser());
        $supplier = $this->createSupplier();
        $product = $this->createProduct(stock: 0);

        $response = $this->postJson('/api/purchases', [
            'supplier_id' => $supplier->id,
            'purchase_date' => now()->toDateString(),
            'details' => [[
                'product_id' => $product->id,
                'quantity' => 1,
                'unit_cost' => 10,
            ]],
        ]);

        $response->assertCreated();
        $this->assertDatabaseMissing('purchase_documents', [
            'purchase_id' => $response->json('data.id'),
        ]);
    }

    public function test_purchase_can_store_optional_document(): void
    {
        Storage::fake('local');
        $this->login($this->createAdminUser());
        $supplier = $this->createSupplier();
        $product = $this->createProduct(stock: 0);
        $file = UploadedFile::fake()->create('comprobante.pdf', 20, 'application/pdf');

        $response = $this->postJson('/api/purchases', [
            'supplier_id' => $supplier->id,
            'purchase_date' => now()->toDateString(),
            'document_type' => 'Factura',
            'document_series' => 'F001',
            'document_number' => '123',
            'document_issue_date' => now()->toDateString(),
            'document_amount' => 10,
            'document_file' => $file,
            'details' => [[
                'product_id' => $product->id,
                'quantity' => 1,
                'unit_cost' => 10,
            ]],
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('purchase_documents', [
            'purchase_id' => $response->json('data.id'),
            'series' => 'F001',
            'number' => '123',
        ]);
    }

    public function test_idempotency_key_is_stable_for_the_same_order(): void
    {
        $captured = [];
        Http::fake(function ($request) use (&$captured) {
            if (str_contains($request->url(), '/receipts')) {
                $captured[] = $request->header('Idempotency-Key')[0] ?? null;

                return Http::response($this->documentPayload('accepted', 'accepted'), 201);
            }
            if (str_contains($request->url(), '/series')) {
                return Http::response([[
                    'id' => '11111111-1111-1111-1111-111111111111',
                    'documentType' => '03',
                    'series' => 'B001',
                    'lastNumber' => 0,
                    'isActive' => true,
                ]], 200);
            }

            return Http::response($this->documentPayload('accepted', 'accepted'), 200);
        });

        $this->login($this->createAdminUser());
        $created = $this->postJson('/api/orders', [
            ...$this->createOrderPayload(),
            'document_kind' => DocumentKind::RECEIPT,
            'series' => 'B001',
        ])->assertCreated();

        $this->postJson('/api/orders/'.$created->json('data.id').'/billing', [
            'document_kind' => DocumentKind::RECEIPT,
            'series' => 'B001',
        ])->assertOk();

        $this->assertNotEmpty($captured);
        $this->assertSame('erp:order:'.$created->json('data.id').':receipt:v1', $captured[0]);
    }

    public function test_invoice_without_ruc_does_not_call_sunat(): void
    {
        Http::fake();
        $this->login($this->createAdminUser());
        $response = $this->postJson('/api/orders', [
            ...$this->createOrderPayload(),
            'document_kind' => DocumentKind::INVOICE,
            'series' => 'F001',
        ]);

        $response->assertCreated();
        $this->assertNotNull($response->json('billing_error'));
        Http::assertNothingSent();
    }

    public function test_rus_blocks_invoice_without_calling_sunat(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/capabilities')) {
                return Http::response([
                    'taxRegime' => 'rus',
                    'taxpayerType' => 'natural_with_business',
                    'canIssueInvoice' => false,
                    'canIssueReceipt' => true,
                    'allowedDocumentTypes' => ['03'],
                ], 200);
            }

            return Http::response(['ok' => true], 201);
        });
        $this->login($this->createAdminUser());
        $customer = $this->createCustomer(['ruc' => '20100070970', 'legal_name' => 'Cliente SAC']);

        $response = $this->postJson('/api/orders', [
            ...$this->createOrderPayload($customer),
            'document_kind' => DocumentKind::INVOICE,
            'series' => 'F001',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.status', OrderStatus::REGISTERED);
        $this->assertStringContainsString('RUS', (string) $response->json('billing_error'));
        Http::assertSent(fn ($request) => str_contains($request->url(), '/capabilities'));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/invoices'));
    }

    private function fakeBillingAccepted(string $type): void
    {
        Http::fake(function ($request) use ($type) {
            if (str_contains($request->url(), '/capabilities')) {
                return Http::response([
                    'taxRegime' => 'general',
                    'taxpayerType' => 'legal',
                    'canIssueInvoice' => true,
                    'canIssueReceipt' => true,
                    'allowedDocumentTypes' => ['01', '03', '07', '08', '09'],
                ], 200);
            }

            if (str_contains($request->url(), '/series')) {
                return Http::response([[
                    'documentType' => $type === 'receipt' ? '03' : '01',
                    'series' => $type === 'receipt' ? 'B001' : 'F001',
                    'isActive' => true,
                    'lastNumber' => 0,
                    'id' => '11111111-1111-1111-1111-111111111111',
                ]], 200);
            }

            return Http::response($this->documentPayload('accepted', 'accepted'), 201);
        });
    }

    private function fakeBillingRejected(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), '/capabilities')) {
                return Http::response([
                    'taxRegime' => 'general',
                    'taxpayerType' => 'legal',
                    'canIssueInvoice' => true,
                    'canIssueReceipt' => true,
                    'allowedDocumentTypes' => ['01', '03', '07', '08', '09'],
                ], 200);
            }

            if (str_contains($request->url(), '/series')) {
                return Http::response([[
                    'documentType' => '01',
                    'series' => 'F001',
                    'isActive' => true,
                    'lastNumber' => 0,
                    'id' => '11111111-1111-1111-1111-111111111111',
                ]], 200);
            }

            return Http::response($this->documentPayload('sent', 'rejected', '2324', 'Documento observado en beta'), 201);
        });
    }

    /** @return array<string, mixed> */
    private function documentPayload(
        string $status,
        string $sunatStatus,
        ?string $code = null,
        ?string $description = null,
    ): array {
        return [
            'id' => 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'documentType' => 'invoice',
            'series' => 'F001',
            'number' => 1,
            'fullNumber' => 'F001-00001',
            'status' => $status,
            'sunatStatus' => $sunatStatus,
            'externalSystem' => 'confecciones-erika-erp',
            'externalReference' => 'PED-00001',
            'externalEntity' => 'order',
            'externalId' => '1',
            'payableAmount' => 118,
            'currency' => 'PEN',
            'issueDate' => now()->toDateString(),
            'files' => ['xml' => '/x', 'pdf' => '/p', 'cdr' => '/c'],
            'digestValue' => 'abc',
            'sunatResponseCode' => $code,
            'sunatDescription' => $description,
            'canRetry' => $sunatStatus === 'communicationError',
            'canCancel' => $status !== 'cancelled' && $status !== 'rejected',
            'canConsult' => $status !== 'cancelled',
        ];
    }

    private function createOrderWithReference(string $documentId): Order
    {
        $this->login($this->createAdminUser());
        $created = $this->postJson('/api/orders', $this->createOrderPayload())->assertCreated();
        $order = Order::query()->findOrFail($created->json('data.id'));
        $order->billingReference()->create([
            'document_kind' => DocumentKind::INVOICE,
            'origin' => 'billing_service',
            'billing_document_id' => $documentId,
            'series' => 'F001',
            'number' => 1,
            'full_number' => 'F001-00001',
            'idempotency_key' => 'erp:order:'.$order->id.':invoice:v1',
        ]);

        return $order->fresh(['billingReference']);
    }

    /** @param  array<string, mixed>  $overrides */
    private function createOrderPayload(?Customer $customer = null): array
    {
        $customer ??= $this->createCustomer();
        $product = $this->createProduct(stock: 10);

        return [
            'customer_id' => $customer->id,
            'order_date' => now()->toDateString(),
            'details' => [[
                'product_id' => $product->id,
                'quantity' => 1,
                'unit_price' => 100,
                'fulfillment_type' => FulfillmentType::STOCK,
            ]],
        ];
    }

    private function login(User $user): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => $user->email,
            'password' => 'password',
        ])->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$response->json('data.access_token'));
    }

    private function createAdminUser(): User
    {
        $role = Role::query()->firstOrCreate(
            ['name' => 'Admin-billing'],
            ['description' => 'Admin billing tests'],
        );
        $role->permissions()->sync(Permission::query()->pluck('id')->all());

        return User::query()->firstOrCreate(
            ['email' => 'billing-admin@example.com'],
            [
                'username' => 'billing-admin',
                'password' => Hash::make('password'),
                'role_id' => $role->id,
            ],
        );
    }

    /** @param  array<int, string>  $permissions */
    private function createLimitedUser(array $permissions): User
    {
        $role = Role::query()->create([
            'name' => 'Limited-'.uniqid(),
            'description' => 'Limited',
        ]);
        $role->permissions()->sync(
            Permission::query()->whereIn('name', $permissions)->pluck('id')->all(),
        );

        return User::query()->create([
            'username' => 'limited-'.uniqid(),
            'email' => 'limited-'.uniqid().'@example.com',
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

    private function createProduct(int $stock = 0): Product
    {
        $category = Category::query()->create(['name' => 'Cat '.uniqid()]);
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
            'lastname' => 'Uno',
            'company_name' => 'Proveedor SA '.uniqid(),
            'ruc' => str_pad((string) random_int(1, 99999999999), 11, '0', STR_PAD_LEFT),
            'dni' => str_pad((string) random_int(1, 99999999), 8, '0', STR_PAD_LEFT),
            'phone_number' => '911222333',
            'city' => 'Arequipa',
        ]);
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
