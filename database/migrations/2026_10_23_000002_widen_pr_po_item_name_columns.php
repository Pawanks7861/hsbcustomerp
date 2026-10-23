<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['purchase_request_items', 'purchase_order_items'] as $table) {
            if (! Schema::hasColumn($table, 'name')) {
                continue;
            }

            $type = strtolower((string) Schema::getColumnType($table, 'name'));

            if (in_array($type, ['text', 'mediumtext', 'longtext', 'clob'], true)) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->text('name')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // Keep names as text so historical BOQ descriptions still fit.
    }
};
