<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sazito_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sazito_product_id')->constrained('sazito_products')->cascadeOnDelete();
            $table->string('sazito_id')->unique();
            $table->string('title')->nullable();
            $table->string('sku')->nullable();
            $table->string('anar360_variant_id')->nullable()->unique();
            $table->json('external_references')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sazito_variants');
    }
};
