<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_adjustments', function (Blueprint $table) {
            if (! Schema::hasColumn('stock_adjustments', 'location_id')) {
                $table->foreignId('location_id')->nullable()->after('material_id')->constrained('inventory_locations')->restrictOnDelete();
            }
            if (! Schema::hasColumn('stock_adjustments', 'reference_number')) {
                $table->string('reference_number', 80)->nullable()->after('quantity');
            }
            if (! Schema::hasColumn('stock_adjustments', 'import_fingerprint')) {
                $table->string('import_fingerprint', 64)->nullable()->after('reason');
            }
            if (! Schema::hasColumn('stock_adjustments', 'import_batch_id')) {
                $table->foreignId('import_batch_id')->nullable()->after('import_fingerprint')->constrained('import_batches')->nullOnDelete();
            }
        });

        $indexes = Schema::getIndexListing('stock_adjustments');
        Schema::table('stock_adjustments', function (Blueprint $table) use ($indexes) {
            if (! in_array('stock_adj_loc_type_idx', $indexes, true)) {
                $table->index(['project_id', 'material_id', 'location_id', 'adjustment_type'], 'stock_adj_loc_type_idx');
            }
            if (! in_array('stock_adj_import_fp_idx', $indexes, true)) {
                $table->index('import_fingerprint', 'stock_adj_import_fp_idx');
            }
        });
    }

    public function down(): void
    {
        Schema::table('stock_adjustments', function (Blueprint $table) {
            $indexes = Schema::getIndexListing('stock_adjustments');
            if (in_array('stock_adj_loc_type_idx', $indexes, true)) {
                $table->dropIndex('stock_adj_loc_type_idx');
            }
            if (in_array('stock_adj_import_fp_idx', $indexes, true)) {
                $table->dropIndex('stock_adj_import_fp_idx');
            }
            if (Schema::hasColumn('stock_adjustments', 'import_batch_id')) {
                $table->dropConstrainedForeignId('import_batch_id');
            }
            if (Schema::hasColumn('stock_adjustments', 'import_fingerprint')) {
                $table->dropColumn('import_fingerprint');
            }
            if (Schema::hasColumn('stock_adjustments', 'reference_number')) {
                $table->dropColumn('reference_number');
            }
            if (Schema::hasColumn('stock_adjustments', 'location_id')) {
                $table->dropConstrainedForeignId('location_id');
            }
        });
    }
};
