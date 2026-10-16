<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * @return array<string, list<string>>
     */
    private function grantsByRole(): array
    {
        $adminPermissions = [
            'purchase_orders.request_rate_amendment',
            'purchase_orders.approve_rate_amendment',
            'purchase_orders.reject_rate_amendment',
            'vendors.ledger',
            'reports.export',
        ];

        return [
            'super_admin' => $adminPermissions,
            'administrator' => $adminPermissions,
            'purchase_manager' => [
                'purchase_orders.request_rate_amendment',
                'purchase_orders.approve_rate_amendment',
                'purchase_orders.reject_rate_amendment',
            ],
            'purchase_approver' => [
                'purchase_orders.approve_rate_amendment',
                'purchase_orders.reject_rate_amendment',
                'purchase_orders.view_rate_history',
            ],
            'accounts' => [
                'vendors.ledger',
                'reports.export',
                'expenses.approve',
            ],
        ];
    }

    public function up(): void
    {
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

        if (! Schema::hasTable('purchase_order_rate_amendments')) {
            Schema::create('purchase_order_rate_amendments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
                $table->foreignId('purchase_order_item_id')->constrained()->cascadeOnDelete();
                $table->decimal('previous_rate', 12, 2);
                $table->decimal('proposed_rate', 12, 2);
                $table->decimal('previous_amount', 12, 2)->nullable();
                $table->decimal('proposed_amount', 12, 2)->nullable();
                $table->text('reason');
                $table->string('status', 20)->default('pending');
                $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('requested_at');
                $table->timestamp('decided_at')->nullable();
                $table->text('decision_comment')->nullable();
                $table->timestamps();

                $table->index('purchase_order_id');
                $table->index('status');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_rate_amendments');
    }
};
