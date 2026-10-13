<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('boq_items', function (Blueprint $table) {
            $table->string('service_code', 100)->nullable()->after('item_number');
            $table->boolean('is_section')->default(false)->after('service_code');
        });

        Schema::table('purchase_request_items', function (Blueprint $table) {
            $table->string('service_code', 100)->nullable()->after('description');
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->string('service_code', 100)->nullable()->after('description');
            $table->text('description')->change();
        });
    }

    public function down(): void
    {
        Schema::table('boq_items', function (Blueprint $table) {
            $table->dropColumn(['service_code', 'is_section']);
        });

        Schema::table('purchase_request_items', function (Blueprint $table) {
            $table->dropColumn('service_code');
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->dropColumn('service_code');
            $table->string('description')->change();
        });
    }
};
