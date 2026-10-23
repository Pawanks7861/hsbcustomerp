<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_request_items', function (Blueprint $table) {
            if (! Schema::hasColumn('purchase_request_items', 'name')) {
                $table->text('name')->nullable()->after('boq_item_id');
            }
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('purchase_order_items', 'name')) {
                $table->text('name')->nullable()->after('boq_item_id');
            }

            if (! Schema::hasColumn('purchase_order_items', 'purchase_request_item_id')) {
                $table->foreignId('purchase_request_item_id')
                    ->nullable()
                    ->after('purchase_order_id')
                    ->constrained('purchase_request_items')
                    ->nullOnDelete();
            }
        });

        if (! $this->hasIndex('purchase_order_items', 'po_items_po_pr_line_unique')) {
            Schema::table('purchase_order_items', function (Blueprint $table) {
                $table->unique(['purchase_order_id', 'purchase_request_item_id'], 'po_items_po_pr_line_unique');
            });
        }

        $this->nullableForeign('purchase_requests', 'boq_id', 'boqs');
        $this->nullableForeign('purchase_requests', 'boq_item_id', 'boq_items');
        $this->nullableForeign('purchase_orders', 'boq_id', 'boqs');
        $this->nullableForeign('purchase_orders', 'boq_item_id', 'boq_items');

        foreach (DB::table('purchase_request_items')->whereNull('name')->orWhere('name', '')->cursor() as $row) {
            DB::table('purchase_request_items')->where('id', $row->id)->update([
                'name' => $row->description,
            ]);
        }

        foreach (DB::table('purchase_order_items')->whereNull('name')->orWhere('name', '')->cursor() as $row) {
            DB::table('purchase_order_items')->where('id', $row->id)->update([
                'name' => $row->description,
            ]);
        }

        $orders = DB::table('purchase_orders')->select('id', 'purchase_request_id')->orderBy('id')->get();

        foreach ($orders as $order) {
            $prItems = DB::table('purchase_request_items')
                ->where('purchase_request_id', $order->purchase_request_id)
                ->orderBy('id')
                ->pluck('id')
                ->values();
            $poItems = DB::table('purchase_order_items')
                ->where('purchase_order_id', $order->id)
                ->orderBy('id')
                ->pluck('id')
                ->values();

            foreach ($poItems as $index => $poItemId) {
                $prItemId = $prItems[$index] ?? null;

                if ($prItemId) {
                    DB::table('purchase_order_items')->where('id', $poItemId)->update([
                        'purchase_request_item_id' => $prItemId,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            if ($this->hasIndex('purchase_order_items', 'po_items_po_pr_line_unique')) {
                $table->dropUnique('po_items_po_pr_line_unique');
            }

            if (Schema::hasColumn('purchase_order_items', 'purchase_request_item_id')) {
                $table->dropConstrainedForeignId('purchase_request_item_id');
            }

            if (Schema::hasColumn('purchase_order_items', 'name')) {
                $table->dropColumn('name');
            }
        });

        Schema::table('purchase_request_items', function (Blueprint $table) {
            if (Schema::hasColumn('purchase_request_items', 'name')) {
                $table->dropColumn('name');
            }
        });
    }

    private function nullableForeign(string $table, string $column, string $parent): void
    {
        Schema::table($table, function (Blueprint $blueprint) use ($column): void {
            $blueprint->dropForeign([$column]);
        });

        Schema::table($table, function (Blueprint $blueprint) use ($column): void {
            $blueprint->unsignedBigInteger($column)->nullable()->change();
        });

        Schema::table($table, function (Blueprint $blueprint) use ($column, $parent): void {
            $blueprint->foreign($column)->references('id')->on($parent)->nullOnDelete();
        });
    }

    private function hasIndex(string $table, string $name): bool
    {
        return collect(Schema::getIndexes($table))->contains(
            fn (array $index): bool => ($index['name'] ?? '') === $name
        );
    }
};
