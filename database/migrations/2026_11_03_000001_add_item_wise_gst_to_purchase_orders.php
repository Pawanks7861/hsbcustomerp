<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('purchase_order_items', 'gst_percentage')) {
                $table->decimal('gst_percentage', 8, 2)->default(0)->after('discount_amount');
            }
            if (! Schema::hasColumn('purchase_order_items', 'cgst_percentage')) {
                $table->decimal('cgst_percentage', 8, 2)->default(0)->after('gst_percentage');
            }
            if (! Schema::hasColumn('purchase_order_items', 'sgst_percentage')) {
                $table->decimal('sgst_percentage', 8, 2)->default(0)->after('cgst_percentage');
            }
            if (! Schema::hasColumn('purchase_order_items', 'igst_percentage')) {
                $table->decimal('igst_percentage', 8, 2)->default(0)->after('sgst_percentage');
            }
            if (! Schema::hasColumn('purchase_order_items', 'igst_amount')) {
                $table->decimal('igst_amount', 15, 2)->default(0)->after('sgst_amount');
            }
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('purchase_orders', 'igst_amount')) {
                $table->decimal('igst_amount', 15, 2)->default(0)->after('sgst_amount');
            }
        });

        Schema::table('purchase_invoice_items', function (Blueprint $table) {
            if (! Schema::hasColumn('purchase_invoice_items', 'gst_percentage')) {
                $table->decimal('gst_percentage', 8, 2)->default(0)->after('discount_percentage');
            }
            if (! Schema::hasColumn('purchase_invoice_items', 'cgst_percentage')) {
                $table->decimal('cgst_percentage', 8, 2)->default(0)->after('gst_percentage');
            }
            if (! Schema::hasColumn('purchase_invoice_items', 'sgst_percentage')) {
                $table->decimal('sgst_percentage', 8, 2)->default(0)->after('cgst_percentage');
            }
            if (! Schema::hasColumn('purchase_invoice_items', 'igst_percentage')) {
                $table->decimal('igst_percentage', 8, 2)->default(0)->after('sgst_percentage');
            }
            if (! Schema::hasColumn('purchase_invoice_items', 'igst_amount')) {
                $table->decimal('igst_amount', 15, 2)->default(0)->after('sgst_amount');
            }
        });

        $orders = DB::table('purchase_orders')->select(
            'id',
            'gst_percentage',
            'cgst_percentage',
            'sgst_percentage'
        )->get();

        foreach ($orders as $order) {
            $gst = $this->money($order->gst_percentage);
            $cgst = $this->money($order->cgst_percentage);
            $sgst = $this->money($order->sgst_percentage);

            if ((float) $gst <= 0 && ((float) $cgst > 0 || (float) $sgst > 0)) {
                $gst = $this->money(((float) $cgst) + ((float) $sgst));
            }

            DB::table('purchase_order_items')
                ->where('purchase_order_id', $order->id)
                ->update([
                    'gst_percentage' => $gst,
                    'cgst_percentage' => $cgst,
                    'sgst_percentage' => $sgst,
                    'igst_percentage' => '0.00',
                    'igst_amount' => '0.00',
                ]);
        }

        if (Schema::hasTable('purchase_invoice_items')) {
            $invoiceItems = DB::table('purchase_invoice_items as ii')
                ->leftJoin('purchase_order_items as oi', 'oi.id', '=', 'ii.purchase_order_item_id')
                ->leftJoin('purchase_orders as po', 'po.id', '=', 'oi.purchase_order_id')
                ->select(
                    'ii.id',
                    'oi.gst_percentage as item_gst',
                    'oi.cgst_percentage as item_cgst',
                    'oi.sgst_percentage as item_sgst',
                    'oi.igst_percentage as item_igst',
                    'po.gst_percentage as order_gst',
                    'po.cgst_percentage as order_cgst',
                    'po.sgst_percentage as order_sgst'
                )
                ->get();

            foreach ($invoiceItems as $row) {
                $gst = $this->money($row->item_gst ?? $row->order_gst);
                $cgst = $this->money($row->item_cgst ?? $row->order_cgst);
                $sgst = $this->money($row->item_sgst ?? $row->order_sgst);
                $igst = $this->money($row->item_igst ?? 0);

                DB::table('purchase_invoice_items')->where('id', $row->id)->update([
                    'gst_percentage' => $gst,
                    'cgst_percentage' => $cgst,
                    'sgst_percentage' => $sgst,
                    'igst_percentage' => $igst,
                    'igst_amount' => '0.00',
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('purchase_invoice_items', function (Blueprint $table) {
            foreach (['gst_percentage', 'cgst_percentage', 'sgst_percentage', 'igst_percentage', 'igst_amount'] as $column) {
                if (Schema::hasColumn('purchase_invoice_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            if (Schema::hasColumn('purchase_orders', 'igst_amount')) {
                $table->dropColumn('igst_amount');
            }
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            foreach (['gst_percentage', 'cgst_percentage', 'sgst_percentage', 'igst_percentage', 'igst_amount'] as $column) {
                if (Schema::hasColumn('purchase_order_items', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
};
