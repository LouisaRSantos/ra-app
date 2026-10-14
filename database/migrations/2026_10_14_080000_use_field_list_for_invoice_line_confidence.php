<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // The review screen highlights individual fields per line (si_number, amount, cwt_amount),
    // so a single boolean is too coarse. The table has never held rows when this runs.
    public function up(): void
    {
        Schema::table('remittance_invoices', function (Blueprint $table) {
            $table->dropColumn('low_confidence');
        });

        Schema::table('remittance_invoices', function (Blueprint $table) {
            $table->json('low_confidence_fields')->nullable()->after('cwt_amount');
        });
    }

    public function down(): void
    {
        Schema::table('remittance_invoices', function (Blueprint $table) {
            $table->dropColumn('low_confidence_fields');
        });

        Schema::table('remittance_invoices', function (Blueprint $table) {
            $table->boolean('low_confidence')->default(false)->after('cwt_amount');
        });
    }
};
