<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('requisitions', function (Blueprint $table) {
            $table->foreignUuid('location_id')->nullable()->after('department_id')->constrained('locations')->nullOnDelete();
            $table->foreignId('endorsed_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->dateTime('endorsed_at')->nullable()->after('endorsed_by');

            $table->index(['location_id', 'status'], 'requisitions_location_status_idx');
        });

        Schema::table('stock_balances', function (Blueprint $table) {
            $table->foreignUuid('location_id')->nullable()->after('department_id')->constrained('locations')->nullOnDelete();
            $table->index(['location_id', 'location_type'], 'stock_balances_ward_location_idx');
        });

        Schema::table('stock_balances', function (Blueprint $table) {
            $table->dropUnique('stock_balances_location_lot_unique');
            $table->unique(
                ['inventory_item_id', 'branch_id', 'location_type', 'department_id', 'location_id', 'stock_transfer_id', 'lot_number', 'expiry_date'],
                'stock_balances_location_ward_lot_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::table('stock_balances', function (Blueprint $table) {
            $table->dropUnique('stock_balances_location_ward_lot_unique');
            $table->unique(
                ['inventory_item_id', 'branch_id', 'location_type', 'department_id', 'stock_transfer_id', 'lot_number', 'expiry_date'],
                'stock_balances_location_lot_unique',
            );
            $table->dropIndex('stock_balances_ward_location_idx');
            $table->dropConstrainedForeignId('location_id');
        });

        Schema::table('requisitions', function (Blueprint $table) {
            $table->dropIndex('requisitions_location_status_idx');
            $table->dropConstrainedForeignId('endorsed_by');
            $table->dropColumn('endorsed_at');
            $table->dropConstrainedForeignId('location_id');
        });
    }
};
