<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records every hand-over of requisitioned items at the store counter:
 * how much was issued, why it was short, whether the rest is backordered or
 * closed, who collected it (with their drawn signature), and whether the
 * ward later confirmed or disputed what it received.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requisition_items', function (Blueprint $table) {
            // Quantity the store decided it will never issue (short-issue closed).
            $table->unsignedInteger('quantity_closed')->default(0)->after('quantity_issued');
        });

        Schema::create('requisition_issues', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('requisition_id')->constrained('requisitions')->cascadeOnDelete();
            $table->foreignUuid('requisition_item_id')->constrained('requisition_items')->cascadeOnDelete();
            $table->foreignUuid('inventory_item_id')->constrained('inventory_items')->cascadeOnDelete();
            $table->foreignUuid('store_id')->nullable()->constrained('stores')->nullOnDelete();
            // Lines issued together in one hand-over share a reference and signature.
            $table->uuid('handover_reference')->index();

            $table->unsignedInteger('quantity_issued');
            $table->unsignedInteger('quantity_outstanding_before');
            $table->string('short_issue_reason', 30)->nullable();
            $table->text('short_issue_note')->nullable();
            $table->string('remainder_action', 20)->nullable();
            $table->unsignedInteger('quantity_closed')->default(0);

            $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('issued_at');

            $table->foreignId('collected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('collected_by_name')->nullable();
            $table->string('collector_signature_path')->nullable();

            $table->string('receipt_status', 30)->default('awaiting_confirmation');
            $table->unsignedInteger('quantity_received')->nullable();
            $table->foreignId('receipt_confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('receipt_confirmed_at')->nullable();
            $table->text('discrepancy_note')->nullable();

            $table->timestamps();

            $table->index(['requisition_id', 'receipt_status'], 'requisition_issues_req_receipt_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('requisition_issues');

        Schema::table('requisition_items', function (Blueprint $table) {
            $table->dropColumn('quantity_closed');
        });
    }
};
