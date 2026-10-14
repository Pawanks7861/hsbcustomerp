<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('purchase_invoices', 'tax_percentage')) {
            return;
        }

        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->decimal('tax_percentage', 5, 2)->nullable()->after('invoice_amount');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_invoices', function (Blueprint $table) {
            $table->dropColumn('tax_percentage');
        });
    }
};
