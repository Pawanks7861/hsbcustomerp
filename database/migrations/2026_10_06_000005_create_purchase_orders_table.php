<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->restrictOnDelete();
            $table->foreignId('boq_id')->constrained()->restrictOnDelete();
            $table->foreignId('boq_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_request_id')->constrained()->restrictOnDelete();
            $table->string('po_number')->unique();
            $table->date('po_date');
            $table->string('supplier_name');
            $table->text('supplier_address')->nullable();
            $table->string('supplier_phone', 30)->nullable();
            $table->string('supplier_email')->nullable();
            $table->string('gst_number', 20)->nullable();
            $table->date('delivery_date')->nullable();
            $table->text('delivery_address')->nullable();
            $table->decimal('subtotal', 15, 2);
            $table->decimal('gst_percentage', 8, 2);
            $table->decimal('gst_amount', 15, 2);
            $table->decimal('grand_total', 15, 2);
            $table->text('terms_conditions')->nullable();
            $table->text('remarks')->nullable();
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->string('unit', 50)->nullable();
            $table->decimal('quantity', 15, 3);
            $table->decimal('rate', 15, 2);
            $table->decimal('amount', 15, 2);
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
    }
};
