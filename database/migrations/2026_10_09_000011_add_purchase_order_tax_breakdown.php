<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->string('place_of_supply')->nullable()->after('delivery_address');
            $table->decimal('discount_total', 15, 2)->default(0)->after('subtotal');
            $table->decimal('taxable_value', 15, 2)->default(0)->after('discount_total');
            $table->decimal('cgst_percentage', 8, 2)->default(0)->after('gst_percentage');
            $table->decimal('sgst_percentage', 8, 2)->default(0)->after('cgst_percentage');
            $table->decimal('cgst_amount', 15, 2)->default(0)->after('gst_amount');
            $table->decimal('sgst_amount', 15, 2)->default(0)->after('cgst_amount');
            $table->decimal('freight', 15, 2)->default(0)->after('sgst_amount');
            $table->decimal('round_off', 15, 2)->default(0)->after('freight');
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->string('hsn_sac', 20)->nullable()->after('description');
            $table->decimal('discount_percentage', 8, 2)->default(0)->after('amount');
            $table->decimal('discount_amount', 15, 2)->default(0)->after('discount_percentage');
            $table->decimal('taxable_amount', 15, 2)->default(0)->after('discount_amount');
            $table->decimal('cgst_amount', 15, 2)->default(0)->after('taxable_amount');
            $table->decimal('sgst_amount', 15, 2)->default(0)->after('cgst_amount');
            $table->decimal('line_total', 15, 2)->default(0)->after('sgst_amount');
        });

        $orders = DB::table('purchase_orders')->select('id', 'gst_percentage', 'gst_amount', 'subtotal')->get();

        foreach ($orders as $order) {
            $gstRate = $this->money($order->gst_percentage);
            $cgstRate = $this->money(((float) $gstRate) / 2);
            $sgstRate = $this->money(((float) $gstRate) - ((float) $cgstRate));
            $gstAmount = $this->money($order->gst_amount);
            $cgstAmount = $this->money(((float) $gstAmount) / 2);
            $sgstAmount = $this->money(((float) $gstAmount) - ((float) $cgstAmount));

            DB::table('purchase_orders')->where('id', $order->id)->update([
                'cgst_percentage' => $cgstRate,
                'sgst_percentage' => $sgstRate,
                'cgst_amount' => $cgstAmount,
                'sgst_amount' => $sgstAmount,
                'discount_total' => '0.00',
                'taxable_value' => $this->money($order->subtotal),
                'freight' => '0.00',
                'round_off' => '0.00',
            ]);

            $items = DB::table('purchase_order_items')->where('purchase_order_id', $order->id)->orderBy('id')->get();
            $subtotal = (float) $order->subtotal;
            $remainingCgst = (float) $cgstAmount;
            $remainingSgst = (float) $sgstAmount;
            $last = $items->count() - 1;

            foreach ($items as $index => $item) {
                if ($index === $last || $subtotal == 0.0) {
                    $lineCgst = $remainingCgst;
                    $lineSgst = $remainingSgst;
                } else {
                    $share = ((float) $item->amount) / $subtotal;
                    $lineCgst = round(((float) $cgstAmount) * $share, 2);
                    $lineSgst = round(((float) $sgstAmount) * $share, 2);
                    $remainingCgst = round($remainingCgst - $lineCgst, 2);
                    $remainingSgst = round($remainingSgst - $lineSgst, 2);
                }

                $taxable = (float) $item->amount;

                DB::table('purchase_order_items')->where('id', $item->id)->update([
                    'discount_percentage' => '0.00',
                    'discount_amount' => '0.00',
                    'taxable_amount' => $this->money($taxable),
                    'cgst_amount' => $this->money($lineCgst),
                    'sgst_amount' => $this->money($lineSgst),
                    'line_total' => $this->money($taxable + $lineCgst + $lineSgst),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropColumn([
                'hsn_sac',
                'discount_percentage',
                'discount_amount',
                'taxable_amount',
                'cgst_amount',
                'sgst_amount',
                'line_total',
            ]);
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn([
                'place_of_supply',
                'discount_total',
                'taxable_value',
                'cgst_percentage',
                'sgst_percentage',
                'cgst_amount',
                'sgst_amount',
                'freight',
                'round_off',
            ]);
        });
    }

    private function money(mixed $value): string
    {
        return number_format((float) $value, 2, '.', '');
    }
};
