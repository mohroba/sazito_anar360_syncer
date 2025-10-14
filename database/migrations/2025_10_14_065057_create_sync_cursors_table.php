<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_cursors', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('key', 64)->unique();
            $table->json('value_json')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_cursors');
    }
};
