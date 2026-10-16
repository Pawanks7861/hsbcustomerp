<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('role_user')) {
            Schema::create('role_user', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->foreignId('role_id')->constrained()->cascadeOnDelete();
                $table->unique(['user_id', 'role_id']);
            });
        }

        if (Schema::hasColumn('users', 'role_id')) {
            $rows = DB::table('users')->whereNotNull('role_id')->get(['id', 'role_id']);

            foreach ($rows as $row) {
                DB::table('role_user')->insertOrIgnore([
                    'user_id' => $row->id,
                    'role_id' => $row->role_id,
                ]);
            }

            Schema::table('users', function (Blueprint $table) {
                $table->dropConstrainedForeignId('role_id');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('users', 'role_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreignId('role_id')->nullable()->after('is_admin')->constrained()->nullOnDelete();
            });
        }

        if (Schema::hasTable('role_user')) {
            $pairs = DB::table('role_user')->orderBy('id')->get(['user_id', 'role_id']);

            foreach ($pairs->groupBy('user_id') as $userId => $roles) {
                DB::table('users')->where('id', $userId)->update([
                    'role_id' => $roles->first()->role_id,
                ]);
            }

            Schema::dropIfExists('role_user');
        }
    }
};
