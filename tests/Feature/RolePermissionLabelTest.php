<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RolePermissionLabelTest extends TestCase
{
    use RefreshDatabase;

    public function test_permission_labels_are_human_readable(): void
    {
        $this->assertSame('View Vendors', Permissions::label('vendors.view'));
        $this->assertSame('Create Vendors', Permissions::label('vendors.create'));
        $this->assertSame('Edit Vendors', Permissions::label('vendors.update') === 'vendors.update'
            ? Permissions::label('vendors.edit')
            : Permissions::label('vendors.update'));
        $this->assertSame('Edit Vendors', Permissions::label('vendors.edit'));
        $this->assertSame('Approve Purchase Orders', Permissions::label('purchase_orders.approve'));
        $this->assertSame('Approve Purchase Invoices', Permissions::label('invoices.approve'));
        $this->assertSame('Approve Client Invoices', Permissions::label('client_invoices.approve'));
        $this->assertSame('Record Client Payment', Permissions::label('client_invoices.record_payment'));
        $this->assertSame('View Client Ledger', Permissions::label('clients.ledger'));
        $this->assertSame('Export Reports', Permissions::label('reports.export'));
        $this->assertSame('Manage Expense Heads', Permissions::label('expenses.manage_categories'));
    }

    public function test_authorized_admin_can_edit_role_permissions_and_unauthorized_cannot(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->create();
        $role = Role::query()->create([
            'name' => 'Buyer Desk',
            'slug' => 'buyer_desk_test',
            'is_active' => true,
        ]);

        $this->actingAs($staff)
            ->put(route('roles.update', $role), [
                'name' => 'Buyer Desk',
                'permissions' => ['vendors.view'],
            ])
            ->assertForbidden();

        $this->actingAs($admin)
            ->get(route('roles.edit', $role))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('modules.0.actions.0.label'));

        $this->actingAs($admin)
            ->put(route('roles.update', $role), [
                'name' => 'Buyer Desk',
                'permissions' => ['vendors.view', 'vendors.create', 'not.a.permission'],
            ])
            ->assertSessionHasErrors('permissions.2');

        $this->actingAs($admin)
            ->put(route('roles.update', $role), [
                'name' => 'Buyer Desk',
                'is_active' => true,
                'permissions' => ['vendors.view', 'vendors.create'],
            ])
            ->assertRedirect(route('roles.index'));

        $role->refresh()->load('permissions');
        $this->assertEqualsCanonicalizing(
            ['vendors.view', 'vendors.create'],
            $role->permissions->pluck('permission')->all()
        );
    }

    public function test_individual_deny_overrides_role_grant(): void
    {
        $admin = User::factory()->admin()->create();
        $role = Role::query()->create(['name' => 'Viewer Desk', 'slug' => 'viewer_desk_test', 'is_active' => true]);
        $role->permissions()->create(['permission' => 'vendors.view']);
        $staff = User::factory()->create(['is_admin' => false]);
        $staff->roles()->attach($role->id);

        $this->actingAs($admin)->put(route('users.permissions.update', $staff), [
            'overrides' => [
                ['permission' => 'vendors.view', 'mode' => 'deny'],
            ],
        ])->assertRedirect();

        $staff->refresh();
        $this->assertFalse(app(\App\Services\Access::class)->allows($staff, 'vendors.view'));
    }
}
