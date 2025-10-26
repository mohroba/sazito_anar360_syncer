<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_events', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('run_id')->constrained('sync_runs')->cascadeOnDelete();
            $table->enum('type', [
                'PRODUCTS_FETCHED',
                'VARIANT_PRICE_UPDATED',
                'VARIANT_STOCK_UPDATED',
                'SAZITO_PRODUCTS_FETCHED',
                'SAZITO_PRODUCTS_FETCH_FAILED',
                'SAZITO_CATALOGUE_UPSERTED',
                'SAZITO_UPDATE_PRICE',
                'SAZITO_UPDATE_STOCK',
                'SKIPPED',
                'VALIDATION_FAILED',
                'RATE_LIMITED',
            ]);
            $table->string('ref_id', 191)->nullable();
            $table->json('payload')->nullable();
            $table->enum('level', ['info', 'warning', 'error'])->default('info');
            $table->timestampsTz();

            $table->index(['type', 'created_at']);
            $table->index('ref_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_events');
    }
};
