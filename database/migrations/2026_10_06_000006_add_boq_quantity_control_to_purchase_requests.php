<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->decimal('requested_boq_quantity', 15, 3)->default(0);
            $table->boolean('is_boq_quantity_exceeded')->default(false);
            $table->decimal('boq_quantity_at_submission', 15, 3)->nullable();
            $table->decimal('previous_requested_quantity_at_submission', 15, 3)->nullable();
            $table->decimal('excess_quantity_at_submission', 15, 3)->nullable();
            $table->foreignId('over_quantity_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('over_quantity_approved_at')->nullable();
            $table->text('over_quantity_approval_note')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('over_quantity_approved_by');
            $table->dropColumn([
                'requested_boq_quantity',
                'is_boq_quantity_exceeded',
                'boq_quantity_at_submission',
                'previous_requested_quantity_at_submission',
                'excess_quantity_at_submission',
                'over_quantity_approved_at',
                'over_quantity_approval_note',
            ]);
        });
    }
};
