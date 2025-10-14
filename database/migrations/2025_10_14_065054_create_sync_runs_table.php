<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_runs', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('finished_at')->nullable();
            $table->enum('status', ['pending', 'running', 'success', 'partial', 'failed'])->default('pending');
            $table->string('scope');
            $table->integer('since_ms')->nullable();
            $table->unsignedInteger('page')->default(1);
            $table->unsignedInteger('pages_total')->nullable();
            $table->json('totals_json')->nullable();
            $table->text('error_message')->nullable();
            $table->timestampsTz();

            $table->index(['status', 'started_at']);
            $table->index(['scope', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
    }
};
