<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inventory_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('inventory_item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->integer('delta');
            $table->unsignedInteger('quantity_after');
            $table->string('transaction_type', 30);
            $table->string('from_location_type', 30)->nullable();
            $table->uuid('from_location_id')->nullable();
            $table->string('to_location_type', 30)->nullable();
            $table->uuid('to_location_id')->nullable();
            $table->nullableUuidMorphs('reference');
            $table->string('unit_label_snapshot', 100)->nullable();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'created_at']);
            $table->index(['inventory_item_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_transactions');
    }
};
