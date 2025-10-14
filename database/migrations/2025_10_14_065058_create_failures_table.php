<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('failures', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->enum('context', ['ANAR360_FETCH', 'SAZITO_UPDATE_PRICE', 'SAZITO_UPDATE_STOCK']);
            $table->string('ref_id', 191)->nullable();
            $table->json('payload')->nullable();
            $table->text('last_error')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('next_retry_at')->nullable();
            $table->timestampsTz();

            $table->index(['context', 'next_retry_at']);
            $table->index('ref_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('failures');
    }
};
