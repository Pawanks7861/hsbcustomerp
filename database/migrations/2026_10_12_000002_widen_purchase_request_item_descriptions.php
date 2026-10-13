<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_request_items', function (Blueprint $table) {
            $table->text('description')->change();
        });
    }

    public function down(): void
    {
        $tooLong = DB::table('purchase_request_items')
            ->whereRaw('CHAR_LENGTH(description) > 255')
            ->exists();

        if ($tooLong) {
            throw new RuntimeException('Cannot shrink purchase request item descriptions while longer BOQ text is stored.');
        }

        Schema::table('purchase_request_items', function (Blueprint $table) {
            $table->string('description')->change();
        });
    }
};
