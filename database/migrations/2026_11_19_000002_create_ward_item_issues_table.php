<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ward_item_issues', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('issue_number', 30)->unique();
            $table->foreignUuid('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->foreignUuid('location_id')->constrained('locations')->cascadeOnDelete();
            $table->foreignUuid('bed_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignUuid('inventory_item_id')->nullable()->constrained('inventory_items')->nullOnDelete();
            $table->string('item_name', 255);
            $table->string('serial_or_asset_tag', 100)->nullable();
            $table->string('category', 30)->default('medical_equipment');
            $table->string('issue_type', 30)->default('broken');
            $table->string('severity', 20)->default('medium');
            $table->unsignedSmallInteger('quantity_affected')->default(1);
            $table->text('description');
            $table->string('status', 30)->default('reported');
            $table->boolean('bed_blocked')->default(false);
            $table->foreignId('reported_by')->constrained('users')->cascadeOnDelete();
            $table->dateTime('reported_at');
            $table->foreignId('verified_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('verified_at')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestamps();

            $table->index(['location_id', 'status'], 'ward_item_issues_ward_status_idx');
            $table->index(['branch_id', 'status', 'severity'], 'ward_item_issues_branch_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ward_item_issues');
    }
};
