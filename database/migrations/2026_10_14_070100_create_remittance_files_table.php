<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('remittance_files', function (Blueprint $table) {
            $table->id();
            $table->foreignId('remittance_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('position'); // page order within the remittance
            $table->string('path');                  // path on the private "local" disk
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedInteger('size')->nullable(); // bytes
            $table->boolean('is_public')->default(false); // private until an owner/admin publishes it
            $table->timestamps();

            $table->unique(['remittance_id', 'position']);
            // "Public files" list for the read-only User role, newest first.
            $table->index(['is_public', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('remittance_files');
    }
};
