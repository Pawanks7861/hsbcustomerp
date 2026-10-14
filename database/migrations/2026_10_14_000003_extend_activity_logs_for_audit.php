<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('activity_logs', 'event')) {
            return;
        }

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->string('event', 30)->nullable()->after('action');
            $table->string('subject_label')->nullable()->after('subject_id');
            $table->unsignedBigInteger('project_id')->nullable()->after('subject_label');
            $table->string('ip_address', 45)->nullable()->after('properties');
            $table->string('user_agent', 500)->nullable()->after('ip_address');
            $table->string('method', 10)->nullable()->after('user_agent');
            $table->text('url')->nullable()->after('method');
            $table->uuid('batch_id')->nullable()->after('url');
            $table->index('event');
            $table->index('project_id');
            $table->index('batch_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropIndex(['event']);
            $table->dropIndex(['project_id']);
            $table->dropIndex(['batch_id']);
            $table->dropIndex(['created_at']);
            $table->dropColumn(['event', 'subject_label', 'project_id', 'ip_address', 'user_agent', 'method', 'url', 'batch_id']);
        });
    }
};
