<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_payments', function (Blueprint $table) {
            $table->unsignedTinyInteger('payment_method')->default(6)->after('amount');
            $table->date('payment_date')->useCurrent()->after('payment_method');
            $table->string('operation_number', 100)->nullable()->after('payment_date');
            $table->string('receipt_file_path')->nullable()->after('operation_number');
            $table->string('receipt_file_name')->nullable()->after('receipt_file_path');
            $table->string('receipt_file_mime', 120)->nullable()->after('receipt_file_name');
        });

        Schema::table('purchase_payments', function (Blueprint $table) {
            $table->unsignedTinyInteger('payment_method')->default(6)->after('amount');
            $table->date('payment_date')->useCurrent()->after('payment_method');
            $table->string('operation_number', 100)->nullable()->after('payment_date');
            $table->string('receipt_file_path')->nullable()->after('operation_number');
            $table->string('receipt_file_name')->nullable()->after('receipt_file_path');
            $table->string('receipt_file_mime', 120)->nullable()->after('receipt_file_name');
        });
    }

    public function down(): void
    {
        Schema::table('order_payments', function (Blueprint $table) {
            $table->dropColumn([
                'payment_method',
                'payment_date',
                'operation_number',
                'receipt_file_path',
                'receipt_file_name',
                'receipt_file_mime',
            ]);
        });

        Schema::table('purchase_payments', function (Blueprint $table) {
            $table->dropColumn([
                'payment_method',
                'payment_date',
                'operation_number',
                'receipt_file_path',
                'receipt_file_name',
                'receipt_file_mime',
            ]);
        });
    }
};
