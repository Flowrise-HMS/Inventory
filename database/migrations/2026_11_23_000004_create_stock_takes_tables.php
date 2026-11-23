<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Store stock-takes: a snapshot of every central lot in a store, the physical
 * counts entered against it, and (on approval) the adjustments that bring
 * the ledger in line with what was counted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_takes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('reference', 30)->unique();
            $table->foreignUuid('store_id')->constrained('stores')->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->string('status', 20)->default('in_progress');
            // Counters don't see system quantities while counting.
            $table->boolean('is_blind')->default(false);
            $table->text('notes')->nullable();

            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('started_at');
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('submitted_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('approved_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('cancelled_at')->nullable();
            $table->text('status_reason')->nullable();
            $table->timestamps();

            $table->index(['store_id', 'status'], 'stock_takes_store_status_idx');
        });

        Schema::create('stock_take_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('stock_take_id')->constrained('stock_takes')->cascadeOnDelete();
            $table->foreignUuid('inventory_item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->string('lot_number', 100)->nullable();
            $table->date('expiry_date')->nullable();
            // On hand at the snapshot; 0 for stock found that the system didn't know about.
            $table->integer('system_quantity');
            $table->boolean('is_found_item')->default(false);
            $table->integer('counted_quantity')->nullable();
            $table->text('variance_reason')->nullable();
            $table->foreignId('counted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('counted_at')->nullable();
            $table->foreignUuid('adjustment_transaction_id')->nullable()->constrained('inventory_transactions')->nullOnDelete();
            $table->timestamps();

            $table->index(['stock_take_id', 'inventory_item_id'], 'stock_take_lines_take_item_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_take_lines');
        Schema::dropIfExists('stock_takes');
    }
};
