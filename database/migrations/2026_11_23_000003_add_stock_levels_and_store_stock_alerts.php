<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stock levels per store item: `reorder_point` (the minimum, already present)
 * plus a new `max_level` (the par level used to suggest how much to reorder).
 * Like reorder_point, it is read as the highest value across an item's rows
 * in a store, so lot-level rows don't need to repeat it.
 *
 * `store_stock_alerts` remembers which items have already triggered a
 * low/out-of-stock alert, so keepers get one alert per drop, not one per issue.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_balances', function (Blueprint $table) {
            $table->integer('max_level')->nullable()->after('reorder_point');
        });

        Schema::create('store_stock_alerts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignUuid('inventory_item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->string('level', 10);
            $table->integer('quantity_at_alert');
            $table->dateTime('alerted_at');
            $table->timestamps();

            $table->unique(['store_id', 'inventory_item_id'], 'store_stock_alerts_store_item_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('store_stock_alerts');

        Schema::table('stock_balances', function (Blueprint $table) {
            $table->dropColumn('max_level');
        });
    }
};
