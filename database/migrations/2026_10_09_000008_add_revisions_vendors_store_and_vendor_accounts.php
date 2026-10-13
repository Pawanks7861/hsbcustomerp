<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boqs', function (Blueprint $table) {
            $table->string('boq_number', 20)->nullable()->after('project_id');
            $table->unsignedBigInteger('current_revision_id')->nullable()->after('title');
        });

        Schema::create('boq_revisions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('boq_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('revision_number');
            $table->date('revision_date');
            $table->text('revision_reason')->nullable();
            $table->string('status', 20)->default('draft');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['boq_id', 'revision_number']);
        });

        Schema::table('boq_items', function (Blueprint $table) {
            $table->foreignId('boq_revision_id')->nullable()->after('boq_id')->constrained('boq_revisions')->cascadeOnDelete();
        });

        $sequenceByProject = [];

        foreach (DB::table('boqs')->orderBy('id')->get() as $boq) {
            $sequenceByProject[$boq->project_id] = ($sequenceByProject[$boq->project_id] ?? 0) + 1;
            $number = sprintf('BOQ-%03d', $sequenceByProject[$boq->project_id]);
            $revisionId = DB::table('boq_revisions')->insertGetId([
                'boq_id' => $boq->id,
                'revision_number' => 0,
                'revision_date' => now()->toDateString(),
                'revision_reason' => null,
                'status' => 'draft',
                'created_by' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('boqs')->where('id', $boq->id)->update([
                'boq_number' => $number,
                'current_revision_id' => $revisionId,
            ]);
            DB::table('boq_items')->where('boq_id', $boq->id)->update([
                'boq_revision_id' => $revisionId,
            ]);
        }

        Schema::table('boqs', function (Blueprint $table) {
            $table->foreign('current_revision_id')->references('id')->on('boq_revisions')->nullOnDelete();
            $table->unique(['project_id', 'boq_number']);
        });

        Schema::create('vendors', function (Blueprint $table) {
            $table->id();
            $table->string('vendor_code')->unique();
            $table->string('name');
            $table->string('contact_person')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('pincode', 12)->nullable();
            $table->string('gst_number', 20)->nullable();
            $table->string('pan_number', 20)->nullable();
            $table->string('bank_name')->nullable();
            $table->string('account_number', 40)->nullable();
            $table->string('ifsc', 20)->nullable();
            $table->decimal('opening_advance', 18, 2)->default(0);
            $table->decimal('opening_outstanding', 18, 2)->default(0);
            $table->string('status', 20)->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->foreignId('vendor_id')->nullable()->after('purchase_request_id')->constrained()->restrictOnDelete();
            $table->string('vendor_name_snapshot')->nullable()->after('vendor_id');
            $table->text('vendor_address_snapshot')->nullable()->after('vendor_name_snapshot');
            $table->string('vendor_phone_snapshot', 30)->nullable()->after('vendor_address_snapshot');
            $table->string('vendor_email_snapshot')->nullable()->after('vendor_phone_snapshot');
            $table->string('vendor_gst_snapshot', 20)->nullable()->after('vendor_email_snapshot');
        });

        Schema::create('material_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vendor_id')->nullable()->constrained()->nullOnDelete();
            $table->string('receipt_number')->unique();
            $table->date('receipt_date');
            $table->string('received_by');
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('material_receipt_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->nullable()->constrained()->nullOnDelete();
            $table->text('description');
            $table->string('unit', 50)->nullable();
            $table->decimal('quantity_received', 15, 3);
            $table->text('remarks')->nullable();
            $table->timestamps();
        });

        Schema::create('material_receipt_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('material_receipt_id')->constrained()->cascadeOnDelete();
            $table->string('image_path');
            $table->string('caption')->nullable();
            $table->timestamps();
        });

        Schema::create('purchase_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $table->string('invoice_number');
            $table->date('invoice_date');
            $table->decimal('invoice_amount', 18, 2);
            $table->decimal('tax_amount', 18, 2)->default(0);
            $table->decimal('total_amount', 18, 2);
            $table->date('due_date')->nullable();
            $table->string('invoice_file_path')->nullable();
            $table->text('remarks')->nullable();
            $table->string('status', 20)->default('recorded');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['vendor_id', 'invoice_number']);
        });

        Schema::create('vendor_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('purchase_invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->date('payment_date');
            $table->string('payment_type', 30);
            $table->string('payment_mode', 30);
            $table->decimal('amount', 18, 2);
            $table->string('reference_number')->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('vendor_advance_adjustments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $table->foreignId('vendor_payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('purchase_invoice_id')->constrained()->restrictOnDelete();
            $table->date('adjustment_date');
            $table->decimal('amount', 18, 2);
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('vendor_recoveries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $table->foreignId('vendor_payment_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount_due', 18, 2);
            $table->decimal('amount_received', 18, 2)->default(0);
            $table->date('recovery_date')->nullable();
            $table->string('status', 20)->default('open');
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('vendor_refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vendor_id')->constrained()->restrictOnDelete();
            $table->foreignId('vendor_recovery_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('purchase_invoice_id')->nullable()->constrained()->nullOnDelete();
            $table->date('refund_date');
            $table->decimal('amount', 18, 2);
            $table->string('refund_mode', 30);
            $table->string('reference_number')->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_refunds');
        Schema::dropIfExists('vendor_recoveries');
        Schema::dropIfExists('vendor_advance_adjustments');
        Schema::dropIfExists('vendor_payments');
        Schema::dropIfExists('purchase_invoices');
        Schema::dropIfExists('material_receipt_images');
        Schema::dropIfExists('material_receipt_items');
        Schema::dropIfExists('material_receipts');

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('vendor_id');
            $table->dropColumn([
                'vendor_name_snapshot',
                'vendor_address_snapshot',
                'vendor_phone_snapshot',
                'vendor_email_snapshot',
                'vendor_gst_snapshot',
            ]);
        });

        Schema::dropIfExists('vendors');

        Schema::table('boqs', function (Blueprint $table) {
            $table->dropForeign(['current_revision_id']);
            $table->dropUnique(['project_id', 'boq_number']);
        });

        Schema::table('boq_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('boq_revision_id');
        });

        Schema::dropIfExists('boq_revisions');

        Schema::table('boqs', function (Blueprint $table) {
            $table->dropColumn(['boq_number', 'current_revision_id']);
        });
    }
};
