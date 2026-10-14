<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per engine attempt, so retries and Groq-vs-Claude comparisons never overwrite each other.
        Schema::create('extraction_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('remittance_id')->constrained()->cascadeOnDelete();
            $table->string('engine', 20);              // groq | claude
            $table->string('status', 20)->default('pending');
            $table->char('callback_token_hash', 64)->nullable(); // sha256 of the one-time token; never the token
            $table->string('session_url', 500)->nullable();
            $table->timestamp('fired_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->string('error', 500)->nullable();
            $table->json('raw_result')->nullable();    // exactly what the engine returned, for audit/debug
            $table->timestamps();

            $table->index(['remittance_id', 'created_at']);
            // Stuck-run sweeper and per-engine reporting.
            $table->index(['status', 'fired_at']);
            $table->index(['engine', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('extraction_runs');
    }
};
