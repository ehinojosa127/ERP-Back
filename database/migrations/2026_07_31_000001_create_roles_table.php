<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('description', 500)->nullable();
            $table->timestamps();

            $table->index(['name', 'description']);
            $table->index(['created_at', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
