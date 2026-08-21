<?php

use App\Models\Permission;
use App\Models\Role;
use App\Support\Auth\PermissionCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->string('ruc', 11)->nullable()->unique()->after('dni');
            $table->string('legal_name')->nullable()->after('ruc');
            $table->string('address')->nullable()->after('legal_name');
        });

        Schema::create('sales_notes', function (Blueprint $table) {
            $table->id();
            $table->string('series', 4);
            $table->unsignedInteger('number');
            $table->string('full_number');
            $table->date('issue_date');
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained('customers')->restrictOnDelete();
            $table->string('customer_name');
            $table->string('customer_document', 20)->nullable();
            $table->decimal('subtotal', 12, 2);
            $table->decimal('total', 12, 2);
            $table->string('status', 20)->default('ISSUED');
            $table->text('observations')->nullable();
            $table->json('items_snapshot');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['series', 'number']);
            $table->index('full_number');
            $table->index('issue_date');
        });

        Schema::create('order_billing_references', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('document_kind', 32);
            $table->string('origin', 32);
            $table->uuid('billing_document_id')->nullable();
            $table->foreignId('sales_note_id')->nullable()->constrained('sales_notes')->nullOnDelete();
            $table->string('series', 8)->nullable();
            $table->unsignedInteger('number')->nullable();
            $table->string('full_number')->nullable();
            $table->string('idempotency_key', 128);
            $table->timestamps();

            $table->unique('order_id');
            $table->unique('idempotency_key');
            $table->index('billing_document_id');
        });

        Schema::create('purchase_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_id')->unique()->constrained('purchases')->cascadeOnDelete();
            $table->string('document_type', 40)->nullable();
            $table->string('series', 20)->nullable();
            $table->string('number', 20)->nullable();
            $table->date('issue_date')->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->string('file_path')->nullable();
            $table->string('file_name')->nullable();
            $table->string('file_mime', 120)->nullable();
            $table->text('observations')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('billing_events', function (Blueprint $table) {
            $table->id();
            $table->string('event', 64);
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('billing_document_id')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['event', 'created_at']);
        });

        $ids = [];
        foreach (PermissionCatalog::all() as $name) {
            $permission = Permission::query()->firstOrCreate(['name' => $name]);
            $ids[] = $permission->id;
        }
        Cache::forget('permission_name_id_map');

        $admin = Role::query()->where('name', 'Admin')->first();
        if ($admin !== null) {
            $admin->permissions()->syncWithoutDetaching($ids);
        }

        $userRole = Role::query()->where('name', 'User')->first();
        if ($userRole !== null) {
            $userPermissionIds = Permission::query()
                ->whereIn('name', PermissionCatalog::forUserRole())
                ->pluck('id')
                ->all();
            $userRole->permissions()->syncWithoutDetaching($userPermissionIds);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_events');
        Schema::dropIfExists('purchase_documents');
        Schema::dropIfExists('order_billing_references');
        Schema::dropIfExists('sales_notes');
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['ruc', 'legal_name', 'address']);
        });
    }
};
