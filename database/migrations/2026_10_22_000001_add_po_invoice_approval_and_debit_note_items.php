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
            $table->foreignId('first_approved_by')->nullable()->after('approved_by')->constrained('users')->nullOnDelete();
            $table->timestamp('first_approved_at')->nullable()->after('first_approved_by');
            $table->foreignId('second_approved_by')->nullable()->after('first_approved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('second_approved_at')->nullable()->after('second_approved_by');
        });

        DB::table('purchase_orders')
            ->where('status', 'approved')
            ->whereNotNull('approved_by')
            ->update([
                'first_approved_by' => DB::raw('approved_by'),
                'first_approved_at' => DB::raw('approved_at'),
                'second_approved_by' => DB::raw('approved_by'),
                'second_approved_at' => DB::raw('approved_at'),
            ]);

        Schema::create('debit_note_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('debit_note_id')->constrained('debit_notes')->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->nullable()->constrained('purchase_order_items')->nullOnDelete();
            $table->string('description');
            $table->string('unit', 30)->nullable();
            $table->decimal('quantity', 18, 3)->default(0);
            $table->decimal('rate', 18, 2)->default(0);
            $table->decimal('amount', 14, 2)->default(0);
            $table->text('remarks')->nullable();
            $table->timestamps();
        });

        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->string('approval_status', 20)->default('approved')->after('status');
            $table->timestamp('submitted_at')->nullable()->after('approval_status');
            $table->timestamp('approved_at')->nullable()->after('submitted_at');
            $table->foreignId('approved_by')->nullable()->after('approved_at')->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable()->after('approved_by');
            $table->foreignId('rejected_by')->nullable()->after('rejected_at')->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable()->after('rejected_by');
            $table->index('approval_status');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('rejected_by');
            $table->dropColumn([
                'approval_status',
                'submitted_at',
                'approved_at',
                'rejected_at',
                'rejection_reason',
            ]);
        });

        Schema::dropIfExists('debit_note_items');

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('first_approved_by');
            $table->dropConstrainedForeignId('second_approved_by');
            $table->dropColumn(['first_approved_at', 'second_approved_at']);
        });
    }
};
