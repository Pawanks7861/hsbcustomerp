<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->ensureTextName('purchase_request_items');
        $this->ensureTextName('purchase_order_items');

        if (! Schema::hasColumn('purchase_order_items', 'purchase_request_item_id')) {
            Schema::table('purchase_order_items', function (Blueprint $table) {
                $table->foreignId('purchase_request_item_id')
                    ->nullable()
                    ->after('purchase_order_id')
                    ->constrained('purchase_request_items')
                    ->nullOnDelete();
            });
        }

        if (! $this->hasIndex('purchase_order_items', 'po_items_po_pr_line_unique')) {
            Schema::table('purchase_order_items', function (Blueprint $table) {
                $table->unique(['purchase_order_id', 'purchase_request_item_id'], 'po_items_po_pr_line_unique');
            });
        }

        $this->nullableForeign('purchase_requests', 'boq_id', 'boqs');
        $this->nullableForeign('purchase_requests', 'boq_item_id', 'boq_items');
        $this->nullableForeign('purchase_orders', 'boq_id', 'boqs');
        $this->nullableForeign('purchase_orders', 'boq_item_id', 'boq_items');

        foreach ($this->unnamedRows('purchase_request_items') as $row) {
            DB::table('purchase_request_items')->where('id', $row->id)->update([
                'name' => $row->description,
            ]);
        }

        foreach ($this->unnamedRows('purchase_order_items') as $row) {
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
                ->whereNull('purchase_request_item_id')
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

    private function ensureTextName(string $table): void
    {
        if (! Schema::hasColumn($table, 'name')) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->text('name')->nullable();
            });

            return;
        }

        $type = strtolower((string) Schema::getColumnType($table, 'name'));

        if (in_array($type, ['text', 'mediumtext', 'longtext', 'clob'], true)) {
            return;
        }

        Schema::table($table, function (Blueprint $blueprint): void {
            $blueprint->text('name')->nullable()->change();
        });
    }

    /**
     * @return \Illuminate\Support\LazyCollection<int, object>
     */
    private function unnamedRows(string $table)
    {
        return DB::table($table)
            ->where(function ($query): void {
                $query->whereNull('name')->orWhere('name', '');
            })
            ->orderBy('id')
            ->cursor();
    }

    private function nullableForeign(string $table, string $column, string $parent): void
    {
        if ($this->hasForeign($table, $column)) {
            Schema::table($table, function (Blueprint $blueprint) use ($column): void {
                $blueprint->dropForeign([$column]);
            });
        }

        Schema::table($table, function (Blueprint $blueprint) use ($column): void {
            $blueprint->unsignedBigInteger($column)->nullable()->change();
        });

        if (! $this->hasForeign($table, $column)) {
            Schema::table($table, function (Blueprint $blueprint) use ($column, $parent): void {
                $blueprint->foreign($column)->references('id')->on($parent)->nullOnDelete();
            });
        }
    }

    private function hasForeign(string $table, string $column): bool
    {
        return collect(Schema::getForeignKeys($table))->contains(
            fn (array $foreign): bool => in_array($column, $foreign['columns'] ?? [], true)
        );
    }

    private function hasIndex(string $table, string $name): bool
    {
        return collect(Schema::getIndexes($table))->contains(
            fn (array $index): bool => ($index['name'] ?? '') === $name
        );
    }
};
