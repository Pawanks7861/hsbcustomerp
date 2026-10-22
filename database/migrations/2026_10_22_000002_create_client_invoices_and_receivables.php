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
        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('client_code')->unique();
            $table->string('name');
            $table->string('contact_person');
            $table->string('phone', 30);
            $table->string('email');
            $table->text('address');
            $table->string('city', 100);
            $table->string('state', 100);
            $table->string('pincode', 12);
            $table->string('gst_number', 20)->nullable();
            $table->string('pan_number', 20)->nullable();
            $table->string('payment_terms')->nullable();
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });

        Schema::create('client_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->string('invoice_number')->unique();
            $table->date('invoice_date');
            $table->date('due_date')->nullable();
            $table->string('client_name');
            $table->text('client_address')->nullable();
            $table->string('client_gst', 20)->nullable();
            $table->string('client_pan', 20)->nullable();
            $table->string('place_of_supply')->nullable();
            $table->string('reference_number')->nullable();
            $table->text('description')->nullable();
            $table->string('payment_terms')->nullable();
            $table->text('remarks')->nullable();
            $table->decimal('gst_percentage', 8, 2)->default(0);
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('discount_total', 14, 2)->default(0);
            $table->decimal('taxable_value', 14, 2)->default(0);
            $table->decimal('cgst_percentage', 8, 2)->default(0);
            $table->decimal('sgst_percentage', 8, 2)->default(0);
            $table->decimal('cgst_amount', 14, 2)->default(0);
            $table->decimal('sgst_amount', 14, 2)->default(0);
            $table->decimal('gst_amount', 14, 2)->default(0);
            $table->decimal('freight', 14, 2)->default(0);
            $table->decimal('round_off', 14, 2)->default(0);
            $table->decimal('grand_total', 14, 2)->default(0);
            $table->string('approval_status', 20)->default('draft');
            $table->string('payment_status', 20)->default('unpaid');
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('rejection_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['project_id', 'approval_status']);
            $table->index(['client_id', 'payment_status']);
            $table->index('invoice_date');
        });

        Schema::create('client_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('boq_item_id')->nullable()->constrained()->nullOnDelete();
            $table->string('item_number')->nullable();
            $table->string('description');
            $table->string('unit', 30)->nullable();
            $table->decimal('quantity', 15, 3)->default(0);
            $table->decimal('rate', 14, 2)->default(0);
            $table->decimal('amount', 14, 2)->default(0);
            $table->decimal('discount_percentage', 8, 2)->default(0);
            $table->decimal('discount_amount', 14, 2)->default(0);
            $table->decimal('taxable_amount', 14, 2)->default(0);
            $table->decimal('cgst_amount', 14, 2)->default(0);
            $table->decimal('sgst_amount', 14, 2)->default(0);
            $table->decimal('line_total', 14, 2)->default(0);
            $table->text('remarks')->nullable();
            $table->timestamps();
            $table->index('boq_item_id');
        });

        Schema::create('client_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('client_invoice_id')->constrained()->restrictOnDelete();
            $table->date('payment_date');
            $table->string('payment_mode', 30);
            $table->decimal('amount', 14, 2);
            $table->string('reference_number')->nullable();
            $table->text('remarks')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['client_invoice_id', 'payment_date']);
            $table->unique(['client_invoice_id', 'reference_number']);
        });

        $this->grantPermissions();
    }

    private function grantPermissions(): void
    {
        $grants = Permissions::roleGrants();
        $keys = array_values(array_filter(
            Permissions::all(),
            fn (string $permission) => str_starts_with($permission, 'clients.') || str_starts_with($permission, 'client_invoices.')
        ));

        foreach ($grants as $slug => $permissions) {
            $roleId = DB::table('roles')->where('slug', $slug)->value('id');
            if ($roleId === null) {
                continue;
            }

            foreach (array_values(array_intersect($permissions, $keys)) as $permission) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission' => $permission,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('client_payments');
        Schema::dropIfExists('client_invoice_items');
        Schema::dropIfExists('client_invoices');
        Schema::dropIfExists('clients');
    }
};
