<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->string('receipt_file_path')->nullable()->after('agency_destination');
            $table->string('receipt_file_name')->nullable()->after('receipt_file_path');
            $table->string('receipt_file_mime', 120)->nullable()->after('receipt_file_name');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropColumn([
                'receipt_file_path',
                'receipt_file_name',
                'receipt_file_mime',
            ]);
        });
    }
};
