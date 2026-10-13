<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->shorten('purchase_requests', 'pr_number');
        $this->shorten('purchase_orders', 'po_number');
        $this->shorten('material_receipts', 'receipt_number');
        $this->shorten('vendors', 'vendor_code');
    }

    public function down(): void
    {
        $this->shorten('purchase_requests', 'pr_number', 6);
        $this->shorten('purchase_orders', 'po_number', 6);
        $this->shorten('material_receipts', 'receipt_number', 6);
        $this->shorten('vendors', 'vendor_code', 6);
    }

    private function shorten(string $table, string $column, int $width = 3): void
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
            return;
        }

        DB::table($table)
            ->orderByDesc('id')
            ->select(['id', $column])
            ->each(function (object $row) use ($table, $column, $width): void {
                $current = $row->{$column};

                if (! is_string($current) || $current === '') {
                    return;
                }

                $next = preg_replace_callback('/(\d+)$/', function (array $matches) use ($width): string {
                    return sprintf('%0'.$width.'d', (int) $matches[1]);
                }, $current);

                if ($next !== null && $next !== $current) {
                    DB::table($table)->where('id', $row->id)->update([$column => $next]);
                }
            });
    }
};
