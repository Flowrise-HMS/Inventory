<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The ledger quantity at the moment a line is counted. Differences are measured
 * against it rather than the opening snapshot, so stock issued during the
 * stock-take is not subtracted twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_take_lines', function (Blueprint $table) {
            $table->integer('book_quantity')->nullable()->after('system_quantity');
        });

        // Lines counted before this column existed were compared with the snapshot.
        DB::table('stock_take_lines')
            ->whereNotNull('counted_quantity')
            ->update(['book_quantity' => DB::raw('system_quantity')]);
    }

    public function down(): void
    {
        Schema::table('stock_take_lines', function (Blueprint $table) {
            $table->dropColumn('book_quantity');
        });
    }
};
