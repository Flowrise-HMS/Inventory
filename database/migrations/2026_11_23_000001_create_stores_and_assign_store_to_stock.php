<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Introduces named stores (General Stores, Medical Stores, Linen Store, ...)
 * per branch, with assigned storekeepers.
 *
 * Central stock keeps location_type = 'dispensary' but now also carries a
 * store_id. Every branch gets a "Main Store" and all existing central
 * balances and requisitions are moved into it, so behaviour is unchanged
 * until a hospital adds more stores.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stores', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->string('name');
            $table->string('code', 30);
            $table->string('type', 30)->default('general');
            $table->text('description')->nullable();
            $table->boolean('is_main')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['branch_id', 'code'], 'stores_branch_code_unique');
            $table->index(['branch_id', 'is_main'], 'stores_branch_main_idx');
        });

        Schema::create('store_keepers', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['store_id', 'user_id'], 'store_keepers_store_user_unique');
        });

        Schema::table('stock_balances', function (Blueprint $table) {
            $table->foreignUuid('store_id')->nullable()->after('branch_id')->constrained('stores')->restrictOnDelete();
        });

        Schema::table('stock_balances', function (Blueprint $table) {
            $table->dropUnique('stock_balances_location_ward_lot_unique');
            $table->unique(
                ['inventory_item_id', 'branch_id', 'store_id', 'location_type', 'department_id', 'location_id', 'stock_transfer_id', 'lot_number', 'expiry_date'],
                'stock_balances_store_location_lot_unique',
            );
        });

        Schema::table('requisitions', function (Blueprint $table) {
            $table->foreignUuid('store_id')->nullable()->after('branch_id')->constrained('stores')->restrictOnDelete();
            $table->index(['store_id', 'status'], 'requisitions_store_status_idx');
        });

        $this->backfillMainStores();
    }

    public function down(): void
    {
        // MySQL backs the FK with the composite index, so the FK goes first.
        Schema::table('requisitions', function (Blueprint $table) {
            $table->dropForeign(['store_id']);
        });

        Schema::table('requisitions', function (Blueprint $table) {
            $table->dropIndex('requisitions_store_status_idx');
            $table->dropColumn('store_id');
        });

        // Merge balances that only differed by store before restoring the old
        // unique index, otherwise the rollback fails on duplicate keys.
        $this->collapseBalancesAcrossStores();

        Schema::table('stock_balances', function (Blueprint $table) {
            $table->dropUnique('stock_balances_store_location_lot_unique');
            $table->unique(
                ['inventory_item_id', 'branch_id', 'location_type', 'department_id', 'location_id', 'stock_transfer_id', 'lot_number', 'expiry_date'],
                'stock_balances_location_ward_lot_unique',
            );
        });

        Schema::table('stock_balances', function (Blueprint $table) {
            $table->dropConstrainedForeignId('store_id');
        });

        Schema::dropIfExists('store_keepers');
        Schema::dropIfExists('stores');
    }

    private function backfillMainStores(): void
    {
        $now = now();

        DB::table('branches')->orderBy('id')->pluck('id')->each(function ($branchId) use ($now): void {
            $storeId = (string) Str::uuid();

            DB::table('stores')->insert([
                'id' => $storeId,
                'branch_id' => $branchId,
                'name' => 'Main Store',
                'code' => 'MAIN',
                'type' => 'general',
                'is_main' => true,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('stock_balances')
                ->where('branch_id', $branchId)
                ->where('location_type', 'dispensary')
                ->whereNull('store_id')
                ->update(['store_id' => $storeId]);

            DB::table('requisitions')
                ->where('branch_id', $branchId)
                ->whereNull('store_id')
                ->update(['store_id' => $storeId]);
        });
    }

    private function collapseBalancesAcrossStores(): void
    {
        $groups = DB::table('stock_balances')
            ->whereNotNull('store_id')
            ->select(['inventory_item_id', 'branch_id', 'location_type', 'department_id', 'location_id', 'stock_transfer_id', 'lot_number', 'expiry_date'])
            ->groupBy(['inventory_item_id', 'branch_id', 'location_type', 'department_id', 'location_id', 'stock_transfer_id', 'lot_number', 'expiry_date'])
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($groups as $group) {
            $rows = DB::table('stock_balances')
                ->where('inventory_item_id', $group->inventory_item_id)
                ->where('branch_id', $group->branch_id)
                ->where('location_type', $group->location_type)
                ->where('department_id', $group->department_id)
                ->where('location_id', $group->location_id)
                ->where('stock_transfer_id', $group->stock_transfer_id)
                ->where('lot_number', $group->lot_number)
                ->where('expiry_date', $group->expiry_date)
                ->orderBy('created_at')
                ->get(['id', 'quantity_on_hand']);

            $keep = $rows->shift();

            DB::table('stock_balances')->where('id', $keep->id)->update([
                'quantity_on_hand' => (int) $keep->quantity_on_hand + (int) $rows->sum('quantity_on_hand'),
            ]);

            DB::table('stock_balances')->whereIn('id', $rows->pluck('id'))->delete();
        }
    }
};
