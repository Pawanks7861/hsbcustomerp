<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->string('subject')->nullable()->after('po_number');
            $table->string('vendor_city_snapshot')->nullable()->after('vendor_address_snapshot');
            $table->string('vendor_state_snapshot')->nullable()->after('vendor_city_snapshot');
            $table->string('vendor_pincode_snapshot', 20)->nullable()->after('vendor_state_snapshot');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropColumn([
                'subject',
                'vendor_city_snapshot',
                'vendor_state_snapshot',
                'vendor_pincode_snapshot',
            ]);
        });
    }
};
