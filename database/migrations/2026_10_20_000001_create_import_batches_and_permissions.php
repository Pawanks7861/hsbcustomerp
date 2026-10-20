<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @return list<string>
     */
    private function importPermissions(): array
    {
        return [
            'projects.import',
            'vendors.import',
            'users.import',
            'boq.import',
            'purchase_requests.import',
            'purchase_orders.import',
            'work_orders.import',
            'store.import',
            'grn.import',
            'inventory.import',
            'idn.import',
            'invoices.import',
            'debit_notes.import',
            'credit_notes.import',
            'expenses.import',
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    private function grantsByRole(): array
    {
        $all = $this->importPermissions();

        return [
            'super_admin' => $all,
            'administrator' => $all,
            'project_manager' => [
                'projects.import', 'boq.import', 'purchase_requests.import', 'work_orders.import', 'expenses.import',
            ],
            'purchase_manager' => [
                'vendors.import', 'purchase_requests.import', 'purchase_orders.import',
                'invoices.import', 'debit_notes.import', 'credit_notes.import',
            ],
            'purchase_requester' => ['purchase_requests.import'],
            'store_manager' => [
                'store.import', 'grn.import', 'inventory.import', 'idn.import',
            ],
            'storekeeper' => [
                'store.import', 'grn.import', 'inventory.import', 'idn.import',
            ],
            'site_engineer' => [
                'purchase_requests.import', 'work_orders.import',
            ],
            'accounts' => [
                'vendors.import', 'invoices.import', 'debit_notes.import', 'credit_notes.import', 'expenses.import',
            ],
        ];
    }

    public function up(): void
    {
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->string('batch_number', 40)->unique();
            $table->string('module', 60);
            $table->string('original_filename', 255);
            $table->string('stored_path', 500)->nullable();
            $table->string('duplicate_mode', 40)->default('create_only');
            $table->string('status', 20)->default('pending');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('invalid_rows')->default(0);
            $table->unsignedInteger('duplicate_rows')->default(0);
            $table->unsignedInteger('created_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->json('summary')->nullable();
            $table->json('error_report')->nullable();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['module', 'created_at']);
            $table->index('uploaded_by');
        });

        foreach ($this->grantsByRole() as $slug => $permissions) {
            $roleId = DB::table('roles')->where('slug', $slug)->value('id');

            if ($roleId === null) {
                continue;
            }

            foreach ($permissions as $permission) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission' => $permission,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batches');

        DB::table('role_permissions')
            ->whereIn('permission', $this->importPermissions())
            ->delete();
    }
};
