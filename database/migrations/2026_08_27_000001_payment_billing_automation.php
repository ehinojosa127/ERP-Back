<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_payments', function (Blueprint $table) {
            $table->text('concept')->nullable()->after('amount');
            $table->string('billing_emission_status', 32)->nullable()->after('receipt_file_mime');
        });

        Schema::table('order_billing_references', function (Blueprint $table) {
            $table->foreignId('order_payment_id')
                ->nullable()
                ->after('order_id')
                ->constrained('order_payments')
                ->nullOnDelete();
        });

        // Permitir varios comprobantes por pedido (antes: unique order_id).
        Schema::table('order_billing_references', function (Blueprint $table) {
            $table->dropUnique(['order_id']);
            $table->index('order_id');
            $table->unique('order_payment_id');
        });

        Schema::create('outbound_webhook_deliveries', function (Blueprint $table) {
            $table->id();
            $table->string('event', 64);
            $table->string('idempotency_key', 191);
            $table->string('resource_type', 64)->nullable();
            $table->unsignedBigInteger('resource_id')->nullable();
            $table->string('status', 32)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('last_attempt_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->text('last_error')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->unique('idempotency_key');
            $table->index(['event', 'resource_type', 'resource_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbound_webhook_deliveries');

        Schema::table('order_billing_references', function (Blueprint $table) {
            $table->dropUnique(['order_payment_id']);
            $table->dropConstrainedForeignId('order_payment_id');
        });

        // Restaurar unique order_id solo si no hay duplicados.
        $duplicates = DB::table('order_billing_references')
            ->select('order_id')
            ->groupBy('order_id')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if (! $duplicates) {
            Schema::table('order_billing_references', function (Blueprint $table) {
                $table->dropIndex(['order_id']);
                $table->unique('order_id');
            });
        }

        Schema::table('order_payments', function (Blueprint $table) {
            $table->dropColumn(['concept', 'billing_emission_status']);
        });
    }
};
