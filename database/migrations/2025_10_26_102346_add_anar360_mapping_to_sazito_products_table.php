<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sazito_products', function (Blueprint $table): void {
            $table->string('title_normalized')->nullable()->after('title');
            $table->string('anar360_product_id')->nullable()->after('slug');

            $table->index('title_normalized');
            $table->index('anar360_product_id');
        });
    }

    public function down(): void
    {
        Schema::table('sazito_products', function (Blueprint $table): void {
            $table->dropIndex('sazito_products_title_normalized_index');
            $table->dropIndex('sazito_products_anar360_product_id_index');
            $table->dropColumn(['title_normalized', 'anar360_product_id']);
        });
    }
};
