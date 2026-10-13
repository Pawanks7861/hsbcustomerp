<?php

use App\Models\Project;
use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles')) {
            Schema::create('roles', function (Blueprint $table) {
                $table->id();
                $table->string('name');
                $table->string('slug')->unique();
                $table->text('description')->nullable();
                $table->boolean('is_system')->default(false);
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('role_permissions')) {
            Schema::create('role_permissions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('role_id')->constrained()->cascadeOnDelete();
                $table->string('permission');
                $table->unique(['role_id', 'permission']);
            });
        }

        if (! Schema::hasColumn('users', 'role_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreignId('role_id')->nullable()->after('is_admin')->constrained()->nullOnDelete();
                $table->string('project_access', 20)->default('all')->after('role_id');
                $table->timestamp('last_login_at')->nullable()->after('project_access');
            });
        }

        if (! Schema::hasTable('user_permission_overrides')) {
            Schema::create('user_permission_overrides', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->string('permission');
                $table->string('effect', 10);
                $table->timestamps();
                $table->unique(['user_id', 'permission']);
            });
        }

        if (! Schema::hasTable('project_user')) {
            Schema::create('project_user', function (Blueprint $table) {
                $table->id();
                $table->foreignId('project_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->unique(['project_id', 'user_id']);
            });
        }

        if (! Schema::hasTable('activity_logs')) {
            Schema::create('activity_logs', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
                $table->string('action');
                $table->string('subject_type')->nullable();
                $table->unsignedBigInteger('subject_id')->nullable();
                $table->json('properties')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->index(['subject_type', 'subject_id']);
            });
        }

        if (! Schema::hasTable('inventory_locations')) {
            Schema::create('inventory_locations', function (Blueprint $table) {
                $table->id();
                $table->string('location_code')->unique();
                $table->string('name');
                $table->string('location_type', 30);
                $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
                $table->text('address')->nullable();
                $table->foreignId('responsible_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->boolean('is_active')->default(true);
                $table->boolean('is_default')->default(false);
                $table->boolean('is_system')->default(false);
                $table->timestamps();
                $table->index(['project_id', 'is_active']);
            });
        }

        if (Schema::hasTable('stock_ledger_entries') && ! Schema::hasColumn('stock_ledger_entries', 'location_id')) {
            Schema::table('stock_ledger_entries', function (Blueprint $table) {
                $table->foreignId('location_id')->nullable()->after('material_id')->constrained('inventory_locations')->nullOnDelete();
            });
        }

        if (! Schema::hasTable('internal_delivery_notes')) {
            Schema::create('internal_delivery_notes', function (Blueprint $table) {
                $table->id();
                $table->string('idn_number')->unique();
                $table->date('transfer_date');
                $table->foreignId('source_location_id')->constrained('inventory_locations')->restrictOnDelete();
                $table->foreignId('destination_location_id')->constrained('inventory_locations')->restrictOnDelete();
                $table->foreignId('source_project_id')->nullable()->constrained('projects')->nullOnDelete();
                $table->foreignId('destination_project_id')->nullable()->constrained('projects')->nullOnDelete();
                $table->date('expected_arrival_date')->nullable();
                $table->string('vehicle_number')->nullable();
                $table->string('driver_name')->nullable();
                $table->text('remarks')->nullable();
                $table->string('status', 30)->default('draft');
                $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('submitted_at')->nullable();
                $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('approved_at')->nullable();
                $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('rejected_at')->nullable();
                $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('rejection_reason')->nullable();
                $table->timestamp('dispatched_at')->nullable();
                $table->foreignId('dispatched_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('received_at')->nullable();
                $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('cancelled_at')->nullable();
                $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('cancellation_reason')->nullable();
                $table->timestamps();
                $table->index(['status', 'transfer_date']);
            });
        }

        if (! Schema::hasTable('internal_delivery_note_items')) {
            Schema::create('internal_delivery_note_items', function (Blueprint $table) {
                $table->id();
                $table->foreignId('internal_delivery_note_id')->constrained()->cascadeOnDelete();
                $table->foreignId('material_id')->constrained()->restrictOnDelete();
                $table->decimal('quantity', 15, 3);
                $table->string('unit', 50);
                $table->text('remarks')->nullable();
                $table->decimal('quantity_accepted', 15, 3)->default(0);
                $table->decimal('quantity_damaged', 15, 3)->default(0);
                $table->decimal('quantity_short', 15, 3)->default(0);
                $table->timestamps();
                $table->unique(['internal_delivery_note_id', 'material_id'], 'idn_items_note_material_unique');
            });
        }

        if (! Schema::hasTable('internal_delivery_receipts')) {
            Schema::create('internal_delivery_receipts', function (Blueprint $table) {
                $table->id();
                $table->foreignId('internal_delivery_note_id')->constrained()->cascadeOnDelete();
                $table->timestamp('received_at');
                $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
                $table->text('remarks')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('internal_delivery_receipt_items')) {
            Schema::create('internal_delivery_receipt_items', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('internal_delivery_receipt_id');
                $table->unsignedBigInteger('internal_delivery_note_item_id');
                $table->decimal('quantity_received', 15, 3)->default(0);
                $table->decimal('quantity_damaged', 15, 3)->default(0);
                $table->decimal('quantity_short', 15, 3)->default(0);
                $table->text('remarks')->nullable();
                $table->timestamps();
                $table->foreign('internal_delivery_receipt_id', 'idn_rcpt_items_rcpt_fk')
                    ->references('id')->on('internal_delivery_receipts')->cascadeOnDelete();
                $table->foreign('internal_delivery_note_item_id', 'idn_rcpt_items_line_fk')
                    ->references('id')->on('internal_delivery_note_items')->cascadeOnDelete();
            });
        }

        $this->ensureReceiptItemForeignKeys();

        if (! Schema::hasTable('idn_attachments')) {
            Schema::create('idn_attachments', function (Blueprint $table) {
                $table->id();
                $table->foreignId('internal_delivery_note_id')->constrained()->cascadeOnDelete();
                $table->foreignId('internal_delivery_receipt_id')->nullable()->constrained()->nullOnDelete();
                $table->string('path');
                $table->string('original_name')->nullable();
                $table->timestamps();
            });
        }

        $this->seedRoles();
        $this->seedLocations();
    }

    public function down(): void
    {
        Schema::dropIfExists('idn_attachments');
        Schema::dropIfExists('internal_delivery_receipt_items');
        Schema::dropIfExists('internal_delivery_receipts');
        Schema::dropIfExists('internal_delivery_note_items');
        Schema::dropIfExists('internal_delivery_notes');

        if (Schema::hasColumn('stock_ledger_entries', 'location_id')) {
            Schema::table('stock_ledger_entries', function (Blueprint $table) {
                $table->dropConstrainedForeignId('location_id');
            });
        }

        Schema::dropIfExists('inventory_locations');
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('project_user');
        Schema::dropIfExists('user_permission_overrides');

        if (Schema::hasColumn('users', 'role_id')) {
            Schema::table('users', function (Blueprint $table) {
                $table->dropConstrainedForeignId('role_id');
                $table->dropColumn(['project_access', 'last_login_at']);
            });
        }

        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
    }

    private function ensureReceiptItemForeignKeys(): void
    {
        if (! Schema::hasTable('internal_delivery_receipt_items')) {
            return;
        }

        $columns = collect(Schema::getForeignKeys('internal_delivery_receipt_items'))
            ->pluck('columns')
            ->flatten();

        Schema::table('internal_delivery_receipt_items', function (Blueprint $table) use ($columns) {
            if (! $columns->contains('internal_delivery_receipt_id')) {
                $table->foreign('internal_delivery_receipt_id', 'idn_rcpt_items_rcpt_fk')
                    ->references('id')->on('internal_delivery_receipts')->cascadeOnDelete();
            }

            if (! $columns->contains('internal_delivery_note_item_id')) {
                $table->foreign('internal_delivery_note_item_id', 'idn_rcpt_items_line_fk')
                    ->references('id')->on('internal_delivery_note_items')->cascadeOnDelete();
            }
        });
    }

    private function seedRoles(): void
    {
        $names = [
            'super_admin' => 'Super Admin',
            'administrator' => 'Administrator',
            'project_manager' => 'Project Manager',
            'purchase_manager' => 'Purchase Manager',
            'purchase_requester' => 'Purchase Requester',
            'purchase_approver' => 'Purchase Approver',
            'store_manager' => 'Store / Inventory Manager',
            'storekeeper' => 'Storekeeper',
            'site_engineer' => 'Site Engineer',
            'accounts' => 'Accounts / Finance',
            'viewer' => 'Viewer',
        ];

        foreach ($names as $slug => $name) {
            $roleId = DB::table('roles')->where('slug', $slug)->value('id');

            if (! $roleId) {
                $roleId = DB::table('roles')->insertGetId([
                    'name' => $name,
                    'slug' => $slug,
                    'is_system' => true,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            foreach (Permissions::roleGrants()[$slug] ?? [] as $permission) {
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

        $superId = DB::table('roles')->where('slug', 'super_admin')->value('id');

        if ($superId) {
            DB::table('users')->where('is_admin', true)->whereNull('role_id')->update(['role_id' => $superId]);
        }
    }

    private function seedLocations(): void
    {
        if (! DB::table('inventory_locations')->where('location_code', 'IN-TRANSIT')->exists()) {
            DB::table('inventory_locations')->insert([
                'location_code' => 'IN-TRANSIT',
                'name' => 'In Transit',
                'location_type' => 'in_transit',
                'is_active' => true,
                'is_default' => false,
                'is_system' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $sequence = (int) DB::table('inventory_locations')->where('location_code', 'like', 'LOC-%')->count();

        foreach (Project::query()->orderBy('id')->get() as $project) {
            $exists = DB::table('inventory_locations')
                ->where('project_id', $project->id)
                ->where('is_default', true)
                ->exists();

            if ($exists) {
                continue;
            }

            $sequence++;
            DB::table('inventory_locations')->insert([
                'location_code' => sprintf('LOC-%06d', $sequence),
                'name' => $project->project_name.' Store',
                'location_type' => 'project_store',
                'project_id' => $project->id,
                'is_active' => true,
                'is_default' => true,
                'is_system' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
};
