<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('lastname')->nullable();
            $table->string('company_name')->nullable();
            $table->string('ruc', 11)->nullable()->unique();
            $table->string('dni', 8)->nullable();
            $table->string('phone_number', 15)->nullable();
            $table->string('city')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['company_name']);
            $table->index(['name', 'lastname']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
