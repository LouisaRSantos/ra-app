<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The payment applied to a sales invoice: which invoice, how much, and the tax withheld on it.
        Schema::create('remittance_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('remittance_id')->constrained()->cascadeOnDelete();
            $table->foreignId('sales_invoice_id')->constrained()->restrictOnDelete();
            $table->decimal('amount', 14, 2)->nullable();
            $table->decimal('cwt_amount', 14, 2)->default(0);
            $table->boolean('low_confidence')->default(false);
            $table->timestamps();

            // An invoice appears once per remittance. The unique key also serves remittance lookups;
            // sales_invoice_id is indexed by its foreign key ("which payments touched this invoice").
            $table->unique(['remittance_id', 'sales_invoice_id'], 'remit_invoice_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remittance_invoices');
    }
};
