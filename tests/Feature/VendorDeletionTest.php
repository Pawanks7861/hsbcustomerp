<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Vendor;
use App\Support\DocumentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesVendorBilling;
use Tests\TestCase;

class VendorDeletionTest extends TestCase
{
    use CreatesVendorBilling;
    use RefreshDatabase;

    public function test_an_authorized_user_can_delete_a_vendor_without_transactions(): void
    {
        $admin = User::factory()->admin()->create();
        $vendor = Vendor::factory()->create(['vendor_code' => 'VEN-050', 'name' => 'Unused Vendor']);

        $this->actingAs($admin)
            ->from(route('vendors.index', ['search' => 'unused', 'page' => 1]))
            ->delete(route('vendors.destroy', $vendor))
            ->assertRedirect(route('vendors.index', ['search' => 'unused', 'page' => 1]))
            ->assertSessionHas('success', 'Vendor VEN-050 Unused Vendor deleted.');

        $this->assertNull($vendor->fresh());
        $this->assertTrue(ActivityLog::query()->where('action', 'vendor.deleted')->where('subject_id', $vendor->id)->exists());
    }

    public function test_deleting_from_the_vendor_page_returns_to_the_vendor_list(): void
    {
        $admin = User::factory()->admin()->create();
        $vendor = Vendor::factory()->create();

        $this->actingAs($admin)
            ->from(route('vendors.show', $vendor))
            ->delete(route('vendors.destroy', $vendor))
            ->assertRedirect(route('vendors.index'));
    }

    public function test_users_without_vendor_edit_rights_cannot_delete_or_see_the_action(): void
    {
        $viewer = User::factory()->withRoles('viewer')->create(['project_access' => 'all']);
        $vendor = Vendor::factory()->create();

        $this->actingAs($viewer)->delete(route('vendors.destroy', $vendor))->assertForbidden();
        $this->assertNotNull($vendor->fresh());

        $this->actingAs($viewer)->get(route('vendors.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('can.delete', false));

        $this->post('/logout');
        $this->delete(route('vendors.destroy', $vendor))->assertRedirect('/login');
        $this->assertNotNull($vendor->fresh());
    }

    public function test_vendors_with_purchase_orders_or_invoices_are_never_deleted(): void
    {
        $admin = User::factory()->admin()->create();
        $vendor = Vendor::factory()->create(['opening_outstanding' => 0]);
        $invoice = $this->recordedInvoice($admin, $vendor, '500.00');
        $order = PurchaseOrder::query()->where('vendor_id', $vendor->id)->firstOrFail();

        $this->actingAs($admin)->get(route('vendors.index'))
            ->assertInertia(fn ($page) => $page->where('vendors.data', fn ($rows) => collect($rows)->firstWhere('id', $vendor->id)['has_transactions'] === true));

        $this->actingAs($admin)
            ->from(route('vendors.index'))
            ->delete(route('vendors.destroy', $vendor))
            ->assertRedirect(route('vendors.index'))
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'cannot be deleted'));

        $this->assertNotNull($vendor->fresh());
        $this->assertSame($vendor->id, (int) $order->fresh()->vendor_id);
        $this->assertSame($vendor->id, (int) $invoice->fresh()->vendor_id);
        $this->actingAs($admin)->get(route('vendors.ledger', $vendor))->assertOk();
    }

    public function test_vendors_referenced_only_by_an_expense_are_kept_with_the_expense_link(): void
    {
        $admin = User::factory()->admin()->create();
        $vendor = Vendor::factory()->create();
        $expense = Expense::query()->create([
            'number' => 'EXP-VEN-1',
            'date' => '2026-10-15',
            'project_id' => Project::factory()->create()->id,
            'category_id' => ExpenseCategory::query()->where('name', 'Labour')->value('id'),
            'description' => 'Paid to vendor',
            'vendor_id' => $vendor->id,
            'amount' => 10,
            'tax_amount' => 0,
            'round_off' => 0,
            'total_amount' => 10,
            'payment_status' => Expense::PAYMENT_UNPAID,
            'status' => DocumentStatus::DRAFT,
        ]);

        $this->actingAs($admin)->delete(route('vendors.destroy', $vendor))->assertSessionHas('error');

        $this->assertNotNull($vendor->fresh());
        $this->assertSame($vendor->id, (int) $expense->fresh()->vendor_id);
    }

    public function test_archived_vendors_keep_history_and_leave_new_transaction_vendor_lists(): void
    {
        $admin = User::factory()->admin()->create();
        $project = Project::factory()->create();
        $vendor = Vendor::factory()->create(['name' => 'Archived Supplier', 'status' => Vendor::ACTIVE]);

        $this->actingAs($admin)->patch(route('vendors.status', $vendor))->assertRedirect();
        $this->assertSame(Vendor::INACTIVE, $vendor->fresh()->status);

        $this->actingAs($admin)->get(route('projects.store.create', $project))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('vendors', fn ($vendors) => ! collect($vendors)->contains('id', $vendor->id)));

        $this->actingAs($admin)->get(route('vendors.index', ['search' => 'archived']))
            ->assertInertia(fn ($page) => $page->where('vendors.data.0.status', Vendor::INACTIVE));
    }

    public function test_deleted_vendors_disappear_from_the_listing_and_counts(): void
    {
        $admin = User::factory()->admin()->create();
        $keep = Vendor::factory()->create(['name' => 'Keep Me']);
        $gone = Vendor::factory()->create(['name' => 'Remove Me']);

        $this->actingAs($admin)->delete(route('vendors.destroy', $gone));

        $this->actingAs($admin)->get(route('vendors.index'))
            ->assertInertia(fn ($page) => $page
                ->where('vendors.total', 1)
                ->where('vendors.data.0.id', $keep->id));
    }
}
