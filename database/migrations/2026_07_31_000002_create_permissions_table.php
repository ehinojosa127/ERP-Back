<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();

            $table->index(['name']);
            $table->index(['created_at', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
