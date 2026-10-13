<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('materials')) {
            Schema::create('materials', function (Blueprint $table) {
            $table->id();
            $table->string('material_code')->unique();
            $table->string('name');
            $table->string('category')->nullable();
            $table->text('description')->nullable();
            $table->string('unit', 50);
            $table->decimal('minimum_stock', 15, 3)->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        }

        if (! Schema::hasColumn('purchase_request_items', 'boq_item_id')) {
        Schema::table('purchase_request_items', function (Blueprint $table) {
            $table->foreignId('boq_item_id')->nullable()->after('purchase_request_id')->constrained('boq_items')->nullOnDelete();
            $table->boolean('is_boq_quantity_exceeded')->nullable()->after('remarks');
            $table->decimal('boq_quantity_at_submission', 15, 3)->nullable();
            $table->decimal('previous_requested_quantity', 15, 3)->nullable();
            $table->decimal('excess_quantity', 15, 3)->nullable();
            $table->index('boq_item_id');
        });
        }

        if (! Schema::hasColumn('purchase_order_items', 'boq_item_id')) {
        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->foreignId('boq_item_id')->nullable()->after('purchase_order_id')->constrained('boq_items')->nullOnDelete();
        });
        }

        if (! Schema::hasColumn('material_receipts', 'status')) {
        Schema::table('material_receipts', function (Blueprint $table) {
            $table->string('document_kind', 20)->default('store')->after('receipt_number');
            $table->string('status', 20)->default('legacy')->after('document_kind');
            $table->string('challan_number')->nullable();
            $table->date('challan_date')->nullable();
            $table->string('vehicle_number')->nullable();
            $table->text('inspection_notes')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['project_id', 'document_kind', 'status']);
        });
        }

        if (! Schema::hasColumn('material_receipt_items', 'material_id')) {
        Schema::table('material_receipt_items', function (Blueprint $table) {
            $table->foreignId('material_id')->nullable()->after('purchase_order_item_id')->constrained('materials')->restrictOnDelete();
            $table->decimal('quantity_accepted', 15, 3)->default(0)->after('quantity_received');
            $table->decimal('quantity_rejected', 15, 3)->default(0)->after('quantity_accepted');
            $table->decimal('quantity_previously_received', 15, 3)->default(0)->after('quantity_rejected');
        });
        }

        if (! Schema::hasTable('stock_ledger_entries')) {
        Schema::create('stock_ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('material_id')->constrained()->restrictOnDelete();
            $table->string('transaction_number')->unique();
            $table->date('transaction_date');
            $table->string('transaction_type', 30);
            $table->string('source_type', 40);
            $table->unsignedBigInteger('source_id');
            $table->unsignedBigInteger('source_item_id')->nullable();
            $table->string('source_number');
            $table->decimal('stock_in', 15, 3)->default(0);
            $table->decimal('stock_out', 15, 3)->default(0);
            $table->decimal('balance', 15, 3);
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('remarks')->nullable();
            $table->timestamps();

            $table->unique(['source_type', 'source_item_id']);
            $table->index(['project_id', 'material_id', 'transaction_date'], 'stock_ledger_project_material_date');
        });
        } elseif (! Schema::hasIndex('stock_ledger_entries', 'stock_ledger_project_material_date')) {
            Schema::table('stock_ledger_entries', function (Blueprint $table) {
                $table->index(['project_id', 'material_id', 'transaction_date'], 'stock_ledger_project_material_date');
            });
        }

        if (! Schema::hasTable('material_issues')) {
        Schema::create('material_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('issue_number')->unique();
            $table->date('issue_date');
            $table->string('issued_to');
            $table->text('purpose')->nullable();
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        }

        if (! Schema::hasTable('material_issue_items')) {
        Schema::create('material_issue_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_issue_id')->constrained()->cascadeOnDelete();
            $table->foreignId('material_id')->constrained()->restrictOnDelete();
            $table->string('unit', 50);
            $table->decimal('quantity', 15, 3);
            $table->text('remarks')->nullable();
            $table->timestamps();
        });
        }

        if (! Schema::hasTable('stock_adjustments')) {
        Schema::create('stock_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('material_id')->constrained()->restrictOnDelete();
            $table->string('adjustment_number')->unique();
            $table->date('adjustment_date');
            $table->string('adjustment_type', 20);
            $table->decimal('quantity', 15, 3);
            $table->text('reason');
            $table->string('attachment_path')->nullable();
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('posted_at')->nullable();
            $table->foreignId('posted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_adjustments');
        Schema::dropIfExists('material_issue_items');
        Schema::dropIfExists('material_issues');
        Schema::dropIfExists('stock_ledger_entries');

        Schema::table('material_receipt_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('material_id');
            $table->dropColumn(['quantity_accepted', 'quantity_rejected', 'quantity_previously_received']);
        });

        Schema::table('material_receipts', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'document_kind', 'status']);
            $table->dropConstrainedForeignId('submitted_by');
            $table->dropConstrainedForeignId('posted_by');
            $table->dropConstrainedForeignId('rejected_by');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn([
                'document_kind',
                'status',
                'challan_number',
                'challan_date',
                'vehicle_number',
                'inspection_notes',
                'submitted_at',
                'posted_at',
                'rejected_at',
                'rejection_reason',
                'cancelled_at',
            ]);
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('boq_item_id');
        });

        Schema::table('purchase_request_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('boq_item_id');
            $table->dropColumn([
                'is_boq_quantity_exceeded',
                'boq_quantity_at_submission',
                'previous_requested_quantity',
                'excess_quantity',
            ]);
        });

        Schema::dropIfExists('materials');
    }
};
