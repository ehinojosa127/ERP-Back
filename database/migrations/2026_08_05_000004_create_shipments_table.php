<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->string('agency');
            $table->date('shipment_date');
            $table->date('delivery_date')->nullable();
            $table->string('shipping_key', 4);
            $table->string('destination');
            $table->string('status');
            $table->string('agency_destination');
            $table->foreignId('order_id')->unique()->constrained('orders')->cascadeOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status']);
            $table->index(['shipment_date']);
            $table->index(['destination']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipments');
    }
};
