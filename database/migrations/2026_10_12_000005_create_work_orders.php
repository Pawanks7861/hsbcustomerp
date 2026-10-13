<?php

use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('vendors') && ! Schema::hasColumn('vendors', 'party_type')) {
            Schema::table('vendors', function (Blueprint $table) {
                $table->string('party_type', 20)->default('vendor')->after('name');
                $table->index('party_type', 'vendors_party_type_idx');
            });
        }

        if (! Schema::hasTable('work_orders')) {
            Schema::create('work_orders', function (Blueprint $table) {
                $table->id();
                $table->foreignId('project_id')->constrained('projects')->restrictOnDelete();
                $table->foreignId('vendor_id')->constrained('vendors')->restrictOnDelete();
                $table->unsignedBigInteger('amended_from_id')->nullable();
                $table->string('work_order_number', 40);
                $table->date('work_order_date');
                $table->date('completion_date')->nullable();
                $table->text('scope')->nullable();
                $table->text('payment_terms')->nullable();
                $table->text('terms_conditions')->nullable();
                $table->text('remarks')->nullable();
                $table->string('contractor_code', 40)->nullable();
                $table->string('contractor_name');
                $table->string('contractor_contact')->nullable();
                $table->string('contractor_phone', 30)->nullable();
                $table->string('contractor_email')->nullable();
                $table->text('contractor_address')->nullable();
                $table->string('contractor_gst', 20)->nullable();
                $table->decimal('subtotal', 15, 2)->default(0);
                $table->decimal('gst_percentage', 8, 2)->default(0);
                $table->decimal('gst_amount', 15, 2)->default(0);
                $table->decimal('grand_total', 15, 2)->default(0);
                $table->string('status', 30)->default('draft');
                $table->string('work_status', 30)->default('not_started');
                $table->boolean('is_quantity_exceeded')->default(false);
                $table->timestamp('submitted_at')->nullable();
                $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('approval_remarks')->nullable();
                $table->timestamp('rejected_at')->nullable();
                $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('rejection_reason')->nullable();
                $table->timestamp('issued_at')->nullable();
                $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('cancelled_at')->nullable();
                $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('cancellation_reason')->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamps();

                $table->unique('work_order_number', 'work_orders_number_unique');
                $table->index(['project_id', 'status'], 'work_orders_project_status_idx');
                $table->index('amended_from_id', 'work_orders_amended_from_idx');
            });

            Schema::table('work_orders', function (Blueprint $table) {
                $table->foreign('amended_from_id', 'work_orders_amended_from_fk')
                    ->references('id')
                    ->on('work_orders')
                    ->restrictOnDelete();
            });
        }

        if (! Schema::hasTable('work_order_items')) {
            Schema::create('work_order_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('work_order_id');
                $table->foreignId('boq_item_id');
                $table->foreignId('boq_revision_id')->nullable();
                $table->unsignedInteger('sort_order')->default(0);
                $table->string('boq_number', 40)->nullable();
                $table->unsignedInteger('revision_number')->nullable();
                $table->string('item_number', 40)->nullable();
                $table->string('service_code', 40)->nullable();
                $table->text('description');
                $table->string('unit', 30)->nullable();
                $table->decimal('boq_quantity', 15, 3)->default(0);
                $table->decimal('previously_allocated', 15, 3)->default(0);
                $table->decimal('quantity', 15, 3);
                $table->decimal('rate', 15, 2);
                $table->decimal('amount', 15, 2);
                $table->decimal('excess_quantity', 15, 3)->default(0);
                $table->boolean('is_exceeded')->default(false);
                $table->decimal('executed_quantity', 15, 3)->default(0);
                $table->string('execution_status', 30)->default('not_started');
                $table->timestamps();

                $table->unique(['work_order_id', 'boq_item_id'], 'wo_items_order_boq_unique');
                $table->index('boq_item_id', 'wo_items_boq_item_idx');
                $table->foreign('work_order_id', 'wo_items_order_fk')->references('id')->on('work_orders')->cascadeOnDelete();
                $table->foreign('boq_item_id', 'wo_items_boq_item_fk')->references('id')->on('boq_items')->restrictOnDelete();
                $table->foreign('boq_revision_id', 'wo_items_revision_fk')->references('id')->on('boq_revisions')->restrictOnDelete();
            });
        }

        $this->grantWorkOrderPermissions();
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_items');
        Schema::dropIfExists('work_orders');

        if (Schema::hasColumn('vendors', 'party_type')) {
            Schema::table('vendors', function (Blueprint $table) {
                $table->dropIndex('vendors_party_type_idx');
                $table->dropColumn('party_type');
            });
        }
    }

    private function grantWorkOrderPermissions(): void
    {
        if (! Schema::hasTable('roles') || ! Schema::hasTable('role_permissions')) {
            return;
        }

        foreach (Permissions::roleGrants() as $slug => $grants) {
            $roleId = DB::table('roles')->where('slug', $slug)->value('id');

            if (! $roleId) {
                continue;
            }

            foreach ($grants as $permission) {
                if (! str_starts_with($permission, 'work_orders.')) {
                    continue;
                }

                $exists = DB::table('role_permissions')
                    ->where('role_id', $roleId)
                    ->where('permission', $permission)
                    ->exists();

                if (! $exists) {
                    DB::table('role_permissions')->insert([
                        'role_id' => $roleId,
                        'permission' => $permission,
                    ]);
                }
            }
        }
    }
};
