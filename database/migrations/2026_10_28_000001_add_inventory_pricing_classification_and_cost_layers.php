<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('materials', function (Blueprint $table) {
            $table->decimal('default_unit_price', 15, 2)->nullable()->after('unit');
            $table->string('classification', 20)->default('consumable')->after('default_unit_price');
            $table->unsignedSmallInteger('useful_life_years')->nullable()->after('classification');
        });

        Schema::table('stock_ledger_entries', function (Blueprint $table) {
            $table->decimal('unit_cost', 15, 2)->nullable()->after('balance');
            $table->decimal('amount', 15, 2)->nullable()->after('unit_cost');
            $table->boolean('is_estimated')->default(false)->after('amount');
        });

        Schema::table('stock_adjustments', function (Blueprint $table) {
            $table->decimal('unit_cost', 15, 2)->nullable()->after('quantity');
        });

        Schema::table('material_issue_items', function (Blueprint $table) {
            $table->decimal('unit_cost', 15, 2)->nullable()->after('quantity');
            $table->decimal('amount', 15, 2)->nullable()->after('unit_cost');
            $table->boolean('is_estimated')->default(false)->after('amount');
        });

        Schema::table('internal_delivery_note_items', function (Blueprint $table) {
            $table->decimal('unit_cost', 15, 2)->nullable()->after('quantity');
            $table->decimal('amount', 15, 2)->nullable()->after('unit_cost');
            $table->boolean('is_estimated')->default(false)->after('amount');
        });

        Schema::create('stock_cost_layers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id')->nullable()->index();
            $table->foreignId('material_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('location_id')->nullable()->index();
            $table->decimal('remaining_qty', 15, 3);
            $table->decimal('unit_cost', 15, 2);
            $table->boolean('is_estimated')->default(false);
            $table->string('source_type', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('source_item_id')->nullable();
            $table->date('received_at')->nullable();
            $table->timestamps();

            $table->index(['material_id', 'project_id', 'location_id', 'id'], 'stock_cost_layers_fifo_idx');
        });

        Schema::create('stock_cost_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_ledger_entry_id')->constrained('stock_ledger_entries')->cascadeOnDelete();
            $table->unsignedBigInteger('stock_cost_layer_id')->nullable()->index();
            $table->unsignedBigInteger('inventory_asset_id')->nullable()->index();
            $table->decimal('quantity', 15, 3);
            $table->decimal('unit_cost', 15, 2);
            $table->decimal('amount', 15, 2);
            $table->boolean('is_estimated')->default(false);
            $table->timestamps();
        });

        Schema::create('inventory_assets', function (Blueprint $table) {
            $table->id();
            $table->string('asset_code', 40)->unique();
            $table->foreignId('material_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('parent_id')->nullable()->index();
            $table->decimal('quantity', 15, 3);
            $table->decimal('remaining_quantity', 15, 3);
            $table->decimal('unit_cost', 15, 2)->nullable();
            $table->decimal('acquisition_cost', 15, 2)->default('0.00');
            $table->unsignedSmallInteger('useful_life_years')->nullable();
            $table->unsignedBigInteger('project_id')->nullable()->index();
            $table->unsignedBigInteger('location_id')->nullable()->index();
            $table->string('status', 20)->default('in_store');
            $table->date('acquired_on')->nullable();
            $table->string('source_type', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->unsignedBigInteger('source_item_id')->nullable();
            $table->unsignedBigInteger('stock_cost_layer_id')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_assets');
        Schema::dropIfExists('stock_cost_movements');
        Schema::dropIfExists('stock_cost_layers');

        Schema::table('internal_delivery_note_items', function (Blueprint $table) {
            $table->dropColumn(['unit_cost', 'amount', 'is_estimated']);
        });

        Schema::table('material_issue_items', function (Blueprint $table) {
            $table->dropColumn(['unit_cost', 'amount', 'is_estimated']);
        });

        Schema::table('stock_adjustments', function (Blueprint $table) {
            $table->dropColumn('unit_cost');
        });

        Schema::table('stock_ledger_entries', function (Blueprint $table) {
            $table->dropColumn(['unit_cost', 'amount', 'is_estimated']);
        });

        Schema::table('materials', function (Blueprint $table) {
            $table->dropColumn(['default_unit_price', 'classification', 'useful_life_years']);
        });
    }
};
