<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refresh_tokens', function (Blueprint $table) {
            // Vincula el refresh con el jti del access emitido en el mismo par.
            $table->string('access_jti', 64)->nullable()->after('token_hash');
            $table->index('access_jti');
        });
    }

    public function down(): void
    {
        Schema::table('refresh_tokens', function (Blueprint $table) {
            $table->dropIndex(['access_jti']);
            $table->dropColumn('access_jti');
        });
    }
};
