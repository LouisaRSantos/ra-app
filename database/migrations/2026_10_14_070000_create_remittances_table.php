<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remittances', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique(); // public identifier used in URLs; never expose the bigint id

            // Owner. Restrict: a user with transactions is deactivated, never deleted.
            $table->foreignId('user_id')->constrained()->restrictOnDelete();

            $table->string('status', 20)->default('pending');

            $table->string('payment_reference_number')->nullable();
            $table->string('cr_number')->nullable();
            $table->date('deposit_date')->nullable();
            $table->decimal('total_amount', 14, 2)->nullable();
            $table->decimal('total_cwt_amount', 14, 2)->nullable();
            // Header-level field names the engine was unsure about (per-invoice flag lives on the line).
            $table->json('low_confidence_fields')->nullable();

            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();

            $table->timestamps();

            // "My transactions" (filter by owner, newest first, optional status).
            $table->index(['user_id', 'created_at']);
            $table->index(['user_id', 'status']);
            // Admin list and lookups.
            $table->index('status');
            $table->index('created_at');
            $table->index('payment_reference_number');
            $table->index('cr_number');
            $table->index('deposit_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remittances');
    }
};
