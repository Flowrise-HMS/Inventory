<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_balances', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('inventory_item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->string('location_type', 30);
            $table->foreignUuid('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->uuid('stock_transfer_id')->nullable();
            $table->integer('quantity_on_hand')->default(0);
            $table->integer('reorder_point')->nullable();
            $table->foreignUuid('unit_id')->nullable()->constrained('units')->nullOnDelete();
            $table->timestamps();

            $table->unique(['inventory_item_id', 'branch_id', 'location_type', 'department_id', 'stock_transfer_id'], 'stock_balances_location_unique');
            $table->index(['branch_id', 'location_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_balances');
    }
};
