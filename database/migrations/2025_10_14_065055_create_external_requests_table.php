<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_requests', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('run_id')->nullable()->constrained('sync_runs')->nullOnDelete();
            $table->enum('driver', ['ANAR360', 'SAZITO']);
            $table->string('method', 16);
            $table->text('url');
            $table->json('query_json')->nullable();
            $table->json('req_headers')->nullable();
            $table->json('req_body')->nullable();
            $table->unsignedSmallInteger('resp_status')->nullable();
            $table->json('resp_headers')->nullable();
            $table->json('resp_body')->nullable();
            $table->integer('duration_ms')->nullable();
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->enum('outcome', ['success', 'fail', 'retry', 'circuit_open', 'timeout']);
            $table->string('idempotency_key', 191)->nullable();
            $table->timestampsTz();

            $table->index(['driver', 'created_at']);
            $table->index('idempotency_key');
            $table->index('run_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_requests');
    }
};
