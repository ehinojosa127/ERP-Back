<?php

use App\Support\Customers\PhoneNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $customers = DB::table('customers')
            ->whereNotNull('phone_number')
            ->where('phone_number', '!=', '')
            ->orderBy('id')
            ->get(['id', 'phone_number']);

        $seen = [];

        foreach ($customers as $customer) {
            $canonical = PhoneNormalizer::canonical((string) $customer->phone_number);

            if ($canonical === '') {
                DB::table('customers')->where('id', $customer->id)->update([
                    'phone_number' => null,
                ]);

                continue;
            }

            if (isset($seen[$canonical])) {
                // Conserva el más antiguo; libera el teléfono del duplicado.
                DB::table('customers')->where('id', $customer->id)->update([
                    'phone_number' => null,
                ]);

                continue;
            }

            $seen[$canonical] = (int) $customer->id;

            if ($canonical !== (string) $customer->phone_number) {
                DB::table('customers')->where('id', $customer->id)->update([
                    'phone_number' => $canonical,
                ]);
            }
        }

        Schema::table('customers', function (Blueprint $table) {
            $table->unique('phone_number');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['phone_number']);
        });
    }
};
