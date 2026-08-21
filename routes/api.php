<?php

use App\Http\Controllers\Api\AttributeController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\CategoryController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\MovementController;
use App\Http\Controllers\Api\OrderBillingController;
use App\Http\Controllers\Api\OrderController;
use App\Http\Controllers\Api\PermissionController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\PurchaseController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\SupplierController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function () {
    Route::post('/register', [AuthController::class, 'register'])
        ->middleware(['auth:api', 'permission:users.create']);
    Route::post('/login', [AuthController::class, 'login']);
    Route::post('/refresh', [AuthController::class, 'refresh']);

    Route::middleware('auth:api')->group(function () {
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::get('/me', [AuthController::class, 'me']);
        Route::put('/password', [ProfileController::class, 'updatePassword'])
            ->middleware('permission:account.update');
        Route::post('/avatar', [ProfileController::class, 'updateAvatar'])
            ->middleware('permission:account.update');
        Route::delete('/avatar', [ProfileController::class, 'deleteAvatar'])
            ->middleware('permission:account.update');
    });
});

Route::middleware('auth:api')->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])
        ->middleware('permission:dashboard.view');

    Route::get('users', [UserController::class, 'index'])->middleware('permission:users.view');
    Route::post('users', [UserController::class, 'store'])->middleware('permission:users.create');
    Route::get('users/{user}', [UserController::class, 'show'])->middleware('permission:users.view');
    Route::put('users/{user}', [UserController::class, 'update'])->middleware('permission:users.update');
    Route::patch('users/{user}', [UserController::class, 'update'])->middleware('permission:users.update');
    Route::delete('users/{user}', [UserController::class, 'destroy'])->middleware('permission:users.delete');

    Route::get('roles/catalog', [RoleController::class, 'catalog']);
    Route::get('roles', [RoleController::class, 'index'])->middleware('permission:roles.view');
    Route::post('roles', [RoleController::class, 'store'])->middleware('permission:roles.create');
    Route::get('roles/{role}', [RoleController::class, 'show'])->middleware('permission:roles.view');
    Route::put('roles/{role}', [RoleController::class, 'update'])->middleware('permission:roles.update');
    Route::patch('roles/{role}', [RoleController::class, 'update'])->middleware('permission:roles.update');
    Route::delete('roles/{role}', [RoleController::class, 'destroy'])->middleware('permission:roles.delete');
    Route::put('roles/{role}/permissions', [RoleController::class, 'syncPermissions'])
        ->middleware('permission:roles.update');

    Route::get('permissions', [PermissionController::class, 'index'])
        ->middleware('permission:permissions.view');

    Route::get('customers', [CustomerController::class, 'index'])->middleware('permission:customers.view');
    Route::post('customers', [CustomerController::class, 'store'])->middleware('permission:customers.create');
    Route::get('customers/{customer}', [CustomerController::class, 'show'])->middleware('permission:customers.view');
    Route::put('customers/{customer}', [CustomerController::class, 'update'])->middleware('permission:customers.update');
    Route::patch('customers/{customer}', [CustomerController::class, 'update'])->middleware('permission:customers.update');
    Route::delete('customers/{customer}', [CustomerController::class, 'destroy'])->middleware('permission:customers.delete');

    Route::get('suppliers', [SupplierController::class, 'index'])->middleware('permission:suppliers.view');
    Route::post('suppliers', [SupplierController::class, 'store'])->middleware('permission:suppliers.create');
    Route::get('suppliers/{supplier}', [SupplierController::class, 'show'])->middleware('permission:suppliers.view');
    Route::put('suppliers/{supplier}', [SupplierController::class, 'update'])->middleware('permission:suppliers.update');
    Route::patch('suppliers/{supplier}', [SupplierController::class, 'update'])->middleware('permission:suppliers.update');
    Route::delete('suppliers/{supplier}', [SupplierController::class, 'destroy'])->middleware('permission:suppliers.delete');

    Route::get('categories', [CategoryController::class, 'index'])->middleware('permission:categories.view');
    Route::post('categories', [CategoryController::class, 'store'])->middleware('permission:categories.create');
    Route::get('categories/{category}', [CategoryController::class, 'show'])->middleware('permission:categories.view');
    Route::put('categories/{category}', [CategoryController::class, 'update'])->middleware('permission:categories.update');
    Route::patch('categories/{category}', [CategoryController::class, 'update'])->middleware('permission:categories.update');
    Route::delete('categories/{category}', [CategoryController::class, 'destroy'])->middleware('permission:categories.delete');

    Route::get('attributes', [AttributeController::class, 'index'])->middleware('permission:attributes.view');
    Route::post('attributes', [AttributeController::class, 'store'])->middleware('permission:attributes.create');
    Route::get('attributes/{attribute}', [AttributeController::class, 'show'])->middleware('permission:attributes.view');
    Route::put('attributes/{attribute}', [AttributeController::class, 'update'])->middleware('permission:attributes.update');
    Route::patch('attributes/{attribute}', [AttributeController::class, 'update'])->middleware('permission:attributes.update');
    Route::delete('attributes/{attribute}', [AttributeController::class, 'destroy'])->middleware('permission:attributes.delete');

    Route::get('products', [ProductController::class, 'index'])->middleware('permission:products.view');
    Route::post('products', [ProductController::class, 'store'])->middleware('permission:products.create');
    Route::get('products/{product}', [ProductController::class, 'show'])->middleware('permission:products.view');
    Route::put('products/{product}', [ProductController::class, 'update'])->middleware('permission:products.update');
    Route::patch('products/{product}', [ProductController::class, 'update'])->middleware('permission:products.update');
    Route::delete('products/{product}', [ProductController::class, 'destroy'])->middleware('permission:products.delete');
    Route::delete(
        'products/{product}/details/{productDetail}',
        [ProductController::class, 'destroyDetail'],
    )->middleware('permission:products.update');

    Route::get('purchases', [PurchaseController::class, 'index'])->middleware('permission:purchases.view');
    Route::post('purchases', [PurchaseController::class, 'store'])->middleware('permission:purchases.create');
    Route::get('purchases/{purchase}', [PurchaseController::class, 'show'])->middleware('permission:purchases.view');
    Route::patch('purchases/{purchase}/status', [PurchaseController::class, 'updateStatus'])
        ->middleware('permission:purchases.update');
    Route::get('purchases/{purchase}/payments', [PurchaseController::class, 'payments'])
        ->middleware('permission:purchases.view');
    Route::post('purchases/{purchase}/payments', [PurchaseController::class, 'storePayment'])
        ->middleware('permission:purchases.payments');
    Route::get('purchases/{purchase}/payments/{payment}/receipt', [PurchaseController::class, 'downloadPaymentReceipt'])
        ->middleware('permission:purchases.view');
    Route::post('purchases/{purchase}/document', [PurchaseController::class, 'storeDocument'])
        ->middleware('permission:purchases.documents');
    Route::get('purchases/{purchase}/document/file', [PurchaseController::class, 'downloadDocument'])
        ->middleware('permission:purchases.view');

    Route::get('movements', [MovementController::class, 'index'])->middleware('permission:movements.view');
    Route::get('movements/{movement}', [MovementController::class, 'show'])->middleware('permission:movements.view');

    // Pedidos: autorización por nombre en controller (PermissionGate).
    Route::post('orders/{order}/status', [OrderController::class, 'updateStatus']);
    Route::get('orders/{order}/payments', [OrderController::class, 'payments']);
    Route::post('orders/{order}/payments', [OrderController::class, 'storePayment']);
    Route::get('orders/{order}/payments/{payment}/receipt', [OrderController::class, 'downloadPaymentReceipt']);
    Route::delete('orders/{order}/payments/{payment}', [OrderController::class, 'destroyPayment']);
    Route::get('orders/{order}/shipment', [OrderController::class, 'shipment']);
    Route::put('orders/{order}/shipment/status', [OrderController::class, 'updateShipmentStatus']);
    Route::get('orders/{order}/billing', [OrderBillingController::class, 'show']);
    Route::get('orders/{order}/billing/capabilities', [OrderBillingController::class, 'capabilities']);
    Route::post('orders/{order}/billing', [OrderBillingController::class, 'issue']);
    Route::post('orders/{order}/billing/retry', [OrderBillingController::class, 'retry']);
    Route::post('orders/{order}/billing/consult', [OrderBillingController::class, 'consult']);
    Route::post('orders/{order}/billing/cancel', [OrderBillingController::class, 'cancel']);
    Route::apiResource('orders', OrderController::class);

    Route::get('billing/capabilities', [BillingController::class, 'capabilities']);
    Route::get('billing/documents', [BillingController::class, 'index']);
    Route::get('billing/documents/{document}', [BillingController::class, 'show']);
    Route::post('billing/documents/{document}/retry', [BillingController::class, 'retry']);
    Route::post('billing/documents/{document}/consult', [BillingController::class, 'consult']);
    Route::post('billing/documents/{document}/cancel', [BillingController::class, 'cancel']);
    Route::post('billing/documents/{document}/pdf/regenerate', [BillingController::class, 'regeneratePdf']);
    Route::get('billing/documents/{document}/{kind}', [BillingController::class, 'download'])
        ->whereIn('kind', ['pdf', 'xml', 'cdr']);
    Route::get('billing/pdf-templates', [BillingController::class, 'pdfTemplates']);
    Route::put('billing/pdf-template', [BillingController::class, 'updatePdfTemplate']);
});
