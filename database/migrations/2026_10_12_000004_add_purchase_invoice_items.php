<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('purchase_invoice_items')) {
            return;
        }

        Schema::create('purchase_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->constrained()->restrictOnDelete();
            $table->string('service_code', 100)->nullable();
            $table->text('description');
            $table->string('unit', 50)->nullable();
            $table->decimal('po_quantity', 15, 3);
            $table->decimal('previously_invoiced', 15, 3)->default(0);
            $table->decimal('quantity', 15, 3);
            $table->decimal('rate', 15, 2);
            $table->decimal('discount_percentage', 8, 2)->default(0);
            $table->decimal('amount', 15, 2);
            $table->decimal('taxable_amount', 15, 2);
            $table->decimal('cgst_amount', 15, 2)->default(0);
            $table->decimal('sgst_amount', 15, 2)->default(0);
            $table->decimal('line_total', 15, 2);
            $table->timestamps();
            $table->unique(['purchase_invoice_id', 'purchase_order_item_id'], 'invoice_items_invoice_po_line_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_invoice_items');
    }
};
