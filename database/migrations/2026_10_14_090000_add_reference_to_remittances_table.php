<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    // A readable reference (RA-261014-K7Q4M) for people and for the signed-in screens' URLs.
    // The random uuid stays for the public routine callback / signed image, where unguessability matters.
    public function up(): void
    {
        Schema::table('remittances', function (Blueprint $table) {
            $table->string('reference', 20)->nullable()->unique()->after('uuid');
        });

        $alphabet = str_split('ABCDEFGHJKMNPQRSTVWXYZ23456789');

        DB::table('remittances')->whereNull('reference')->orderBy('id')->each(function ($row) use ($alphabet) {
            do {
                $code = collect(range(1, 5))->map(fn () => $alphabet[random_int(0, count($alphabet) - 1)])->implode('');
                $reference = 'RA-'.date('ymd', strtotime($row->created_at)).'-'.$code;
            } while (DB::table('remittances')->where('reference', $reference)->exists());

            DB::table('remittances')->where('id', $row->id)->update(['reference' => $reference]);
        });

        Schema::table('remittances', function (Blueprint $table) {
            $table->string('reference', 20)->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('remittances', function (Blueprint $table) {
            $table->dropUnique(['reference']);
            $table->dropColumn('reference');
        });
    }
};
