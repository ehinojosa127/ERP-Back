<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchases', function (Blueprint $table) {
            $table->id();
            $table->string('purchase_number')->unique();
            $table->decimal('total_amount', 14, 2);
            $table->text('observations')->nullable();
            $table->string('status');
            $table->date('purchase_date');
            $table->foreignId('supplier_id')->constrained('suppliers')->restrictOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['purchase_number']);
            $table->index(['status']);
            $table->index(['purchase_date']);
            $table->index(['total_amount']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchases');
    }
};
