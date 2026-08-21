<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('lastname');
            $table->string('dni', 8)->nullable()->unique();
            $table->string('phone_number', 15)->nullable();
            $table->string('city')->nullable();
            $table->string('agency_destination')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['name', 'lastname']);
            $table->index(['city']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
