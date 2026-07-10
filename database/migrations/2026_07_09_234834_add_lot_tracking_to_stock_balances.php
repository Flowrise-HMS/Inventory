<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_balances', function (Blueprint $table) {
            $table->string('lot_number', 100)->nullable()->after('stock_transfer_id');
            $table->date('expiry_date')->nullable()->after('lot_number');
        });

        // Foreign keys may reference the composite unique index; add standalone indexes first.
        Schema::table('stock_balances', function (Blueprint $table) {
            $table->index('inventory_item_id', 'stock_balances_item_fk_idx');
            $table->index('branch_id', 'stock_balances_branch_fk_idx');
            $table->index('department_id', 'stock_balances_department_fk_idx');
        });

        Schema::table('stock_balances', function (Blueprint $table) {
            $table->dropUnique('stock_balances_location_unique');
            $table->unique(
                ['inventory_item_id', 'branch_id', 'location_type', 'department_id', 'stock_transfer_id', 'lot_number', 'expiry_date'],
                'stock_balances_location_lot_unique',
            );
        });

        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->string('lot_number', 100)->nullable()->after('transaction_type');
            $table->date('expiry_date')->nullable()->after('lot_number');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_transactions', function (Blueprint $table) {
            $table->dropColumn(['lot_number', 'expiry_date']);
        });

        Schema::table('stock_balances', function (Blueprint $table) {
            $table->dropUnique('stock_balances_location_lot_unique');
            $table->unique(
                ['inventory_item_id', 'branch_id', 'location_type', 'department_id', 'stock_transfer_id'],
                'stock_balances_location_unique',
            );
            $table->dropIndex('stock_balances_item_fk_idx');
            $table->dropIndex('stock_balances_branch_fk_idx');
            $table->dropIndex('stock_balances_department_fk_idx');
            $table->dropColumn(['lot_number', 'expiry_date']);
        });
    }
};
