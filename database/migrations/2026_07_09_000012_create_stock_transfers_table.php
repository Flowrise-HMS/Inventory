<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('transfer_number', 30)->unique();
            $table->foreignUuid('from_branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignUuid('to_branch_id')->constrained('branches')->cascadeOnDelete();
            $table->string('from_location_type', 30)->default('dispensary');
            $table->string('to_location_type', 30)->default('dispensary');
            $table->string('status', 30)->default('draft');
            $table->foreignId('shipped_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('shipped_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('received_at')->nullable();
            $table->text('closed_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['from_branch_id', 'status']);
            $table->index(['to_branch_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfers');
    }
};
