<?php

namespace Tests\Feature;

use App\Models\CreditNote;
use App\Models\DebitNote;
use App\Models\InternalDeliveryNote;
use App\Models\InventoryLocation;
use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorPayment;
use App\Models\VendorRecovery;
use App\Support\DocumentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class Phase2Sec06BindingTest extends TestCase
{
    use RefreshDatabase;

    public function test_debit_and_credit_note_bindings_are_scoped_to_the_parent_project(): void
    {
        $admin = User::factory()->admin()->create();
        [$allowed, $blocked, $accounts] = $this->selectedAccounts();
        $vendor = Vendor::factory()->create();
        $ownDebit = $this->debitNote($allowed, $vendor, $admin, 'DN-SEC06-OWN');
        $otherDebit = $this->debitNote($blocked, $vendor, $admin, 'DN-SEC06-OTHER');
        $ownCredit = $this->creditNote($allowed, $vendor, $admin, 'CN-SEC06-OWN');
        $otherCredit = $this->creditNote($blocked, $vendor, $admin, 'CN-SEC06-OTHER');

        $this->actingAs($accounts)->get(route('projects.debit-notes.show', [$allowed, $ownDebit]))->assertOk();
        $this->actingAs($accounts)->get(route('projects.debit-notes.show', [$allowed, $otherDebit]))->assertNotFound();
        $this->actingAs($accounts)->get(route('projects.debit-notes.show', [$blocked, $otherDebit]))->assertNotFound();
        $this->actingAs($accounts)->get(route('projects.debit-notes.show', [$allowed, 999999]))->assertNotFound();
        $this->actingAs($accounts)->get(route('projects.credit-notes.show', [$allowed, $ownCredit]))->assertOk();
        $this->actingAs($accounts)->get(route('projects.credit-notes.show', [$allowed, $otherCredit]))->assertNotFound();

        $requester = User::factory()->withRoles('purchase_requester')->create(['project_access' => 'all']);
        $this->actingAs($requester)->get(route('projects.debit-notes.show', [$allowed, $ownDebit]))->assertForbidden();

        $this->actingAs($admin)->get(route('projects.debit-notes.show', [$blocked, $otherDebit]))->assertOk();
        $this->actingAs($admin)->get(route('projects.credit-notes.show', [$blocked, $otherCredit]))->assertOk();
    }

    public function test_location_bindings_hide_other_projects_from_selected_users(): void
    {
        $admin = User::factory()->admin()->create();
        [$allowed, $blocked] = $this->projects();
        $own = InventoryLocation::query()->where('project_id', $allowed->id)->where('is_default', true)->firstOrFail();
        $other = InventoryLocation::query()->where('project_id', $blocked->id)->where('is_default', true)->firstOrFail();
        $storekeeper = User::factory()->withRoles('storekeeper')->create(['project_access' => 'selected']);
        $storekeeper->projects()->sync([$allowed->id]);
        $manager = User::factory()->withRoles('store_manager')->create(['project_access' => 'selected']);
        $manager->projects()->sync([$allowed->id]);

        $this->actingAs($storekeeper)->get(route('inventory.locations.stock', $own))->assertOk();
        $this->actingAs($storekeeper)->get(route('inventory.locations.stock', $other))->assertNotFound();
        $this->actingAs($storekeeper)->get(route('inventory.locations.stock', 999999))->assertNotFound();
        $this->actingAs($manager)->put(route('inventory.locations.update', $other), $this->locationPayload($other))->assertNotFound();

        $movable = InventoryLocation::query()->create([
            'location_code' => 'LOC-SEC06-MOVE',
            'name' => 'Movable site',
            'location_type' => InventoryLocation::SITE,
            'project_id' => $allowed->id,
            'is_active' => true,
            'is_default' => false,
            'is_system' => false,
        ]);
        $this->actingAs($manager)->put(route('inventory.locations.update', $movable), [
            ...$this->locationPayload($movable),
            'project_id' => $blocked->id,
        ])->assertNotFound();

        $viewer = User::factory()->withRoles('viewer')->create(['project_access' => 'all']);
        $this->actingAs($viewer)->put(route('inventory.locations.update', $own), $this->locationPayload($own))->assertForbidden();
        $this->actingAs($viewer)->get(route('inventory.locations.stock', $other))->assertOk();

        $this->actingAs($admin)->get(route('inventory.locations.stock', $other))->assertOk();
        $allProjects = User::factory()->withRoles('storekeeper')->create(['project_access' => 'all']);
        $this->actingAs($allProjects)->get(route('inventory.locations.stock', $other))->assertOk();
    }

    public function test_transfer_bindings_hide_unrelated_projects_and_keep_all_project_access(): void
    {
        $admin = User::factory()->admin()->create();
        [$allowed, $blocked] = $this->projects();
        $ownFrom = InventoryLocation::query()->where('project_id', $allowed->id)->where('is_default', true)->firstOrFail();
        $ownTo = InventoryLocation::query()->create([
            'location_code' => 'LOC-SEC06-OWN',
            'name' => 'Allowed site',
            'location_type' => InventoryLocation::SITE,
            'project_id' => $allowed->id,
            'is_active' => true,
            'is_default' => false,
            'is_system' => false,
        ]);
        $blockedFrom = InventoryLocation::query()->where('project_id', $blocked->id)->where('is_default', true)->firstOrFail();
        $blockedTo = InventoryLocation::query()->create([
            'location_code' => 'LOC-SEC06-BLOCK',
            'name' => 'Blocked site',
            'location_type' => InventoryLocation::SITE,
            'project_id' => $blocked->id,
            'is_active' => true,
            'is_default' => false,
            'is_system' => false,
        ]);

        $this->actingAs($admin)->post(route('inventory.transfers.store'), [
            'transfer_date' => '2026-10-15',
            'source_location_id' => $ownFrom->id,
            'destination_location_id' => $ownTo->id,
            'items' => [['material_id' => $this->material()->id, 'quantity' => 1]],
        ])->assertRedirect();
        $ownTransfer = InternalDeliveryNote::query()->latest('id')->firstOrFail();

        $this->actingAs($admin)->post(route('inventory.transfers.store'), [
            'transfer_date' => '2026-10-15',
            'source_location_id' => $blockedFrom->id,
            'destination_location_id' => $blockedTo->id,
            'items' => [['material_id' => $this->material()->id, 'quantity' => 1]],
        ])->assertRedirect();
        $blockedTransfer = InternalDeliveryNote::query()->latest('id')->firstOrFail();

        $storekeeper = User::factory()->withRoles('storekeeper')->create(['project_access' => 'selected']);
        $storekeeper->projects()->sync([$allowed->id]);

        $this->actingAs($storekeeper)->get(route('inventory.transfers.show', $ownTransfer))->assertOk();
        $this->actingAs($storekeeper)->get(route('inventory.transfers.show', $blockedTransfer))->assertNotFound();
        $this->actingAs($storekeeper)->get(route('inventory.transfers.show', 999999))->assertNotFound();

        $requester = User::factory()->withRoles('purchase_requester')->create(['project_access' => 'all']);
        $this->actingAs($requester)->get(route('inventory.transfers.show', $ownTransfer))->assertForbidden();

        $this->actingAs($admin)->get(route('inventory.transfers.show', $blockedTransfer))->assertOk();
        $allProjects = User::factory()->withRoles('storekeeper')->create(['project_access' => 'all']);
        $this->actingAs($allProjects)->get(route('inventory.transfers.show', $blockedTransfer))->assertOk();
    }

    public function test_nested_vendor_payment_and_recovery_ids_must_belong_to_the_parent_vendor(): void
    {
        $admin = User::factory()->admin()->create();
        $owner = Vendor::factory()->create();
        $other = Vendor::factory()->create();
        $accounts = User::factory()->withRoles('accounts')->create(['project_access' => 'all']);

        $payment = VendorPayment::query()->create([
            'vendor_id' => $owner->id,
            'payment_date' => '2026-10-15',
            'payment_type' => VendorPayment::TYPE_ADVANCE,
            'payment_mode' => 'cash',
            'amount' => 10,
            'created_by' => $admin->id,
        ]);
        $recovery = VendorRecovery::query()->create([
            'vendor_id' => $owner->id,
            'amount_due' => 10,
            'amount_received' => 0,
            'recovery_date' => '2026-10-15',
            'status' => VendorRecovery::OPEN,
            'created_by' => $admin->id,
        ]);

        $this->actingAs($accounts)->delete(route('vendors.payments.destroy', [$other, $payment]))->assertNotFound();
        $this->actingAs($accounts)->delete(route('vendors.payments.destroy', [$owner, $payment]))->assertRedirect();
        $this->actingAs($accounts)->post(route('vendors.recoveries.refunds.store', [$other, $recovery]), [
            'refund_date' => '2026-10-16',
            'amount' => 1,
            'refund_mode' => 'cash',
        ])->assertNotFound();

        $viewer = User::factory()->withRoles('viewer')->create(['project_access' => 'all']);
        $this->actingAs($viewer)->post(route('vendors.recoveries.refunds.store', [$owner, $recovery]), [
            'refund_date' => '2026-10-16',
            'amount' => 1,
            'refund_mode' => 'cash',
        ])->assertForbidden();
    }

    /**
     * @return array{0: Project, 1: Project, 2: User}
     */
    private function selectedAccounts(): array
    {
        [$allowed, $blocked] = $this->projects();
        $user = User::factory()->withRoles('accounts')->create(['project_access' => 'selected']);
        $user->projects()->sync([$allowed->id]);

        return [$allowed, $blocked, $user];
    }

    /**
     * @return array{0: Project, 1: Project}
     */
    private function projects(): array
    {
        return [Project::factory()->create(), Project::factory()->create()];
    }

    private function debitNote(Project $project, Vendor $vendor, User $user, string $number): DebitNote
    {
        return DebitNote::query()->create([
            'number' => $number,
            'date' => '2026-10-15',
            'project_id' => $project->id,
            'vendor_id' => $vendor->id,
            'reason' => 'Binding isolation',
            'status' => DocumentStatus::DRAFT,
            'subtotal' => 10,
            'tax_amount' => 0,
            'round_off' => 0,
            'total_amount' => 10,
            'created_by' => $user->id,
        ]);
    }

    private function creditNote(Project $project, Vendor $vendor, User $user, string $number): CreditNote
    {
        return CreditNote::query()->create([
            'number' => $number,
            'date' => '2026-10-15',
            'project_id' => $project->id,
            'vendor_id' => $vendor->id,
            'reason' => 'Binding isolation',
            'status' => DocumentStatus::DRAFT,
            'subtotal' => 10,
            'tax_amount' => 0,
            'round_off' => 0,
            'total_amount' => 10,
            'created_by' => $user->id,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function locationPayload(InventoryLocation $location): array
    {
        return [
            'name' => $location->name,
            'location_type' => $location->location_type,
            'project_id' => $location->project_id,
            'address' => $location->address,
            'responsible_user_id' => $location->responsible_user_id,
            'is_active' => true,
        ];
    }

    private function material(): \App\Models\Material
    {
        return \App\Models\Material::query()->create([
            'material_code' => 'MAT-SEC06-'.uniqid(),
            'name' => 'Binding cement',
            'unit' => 'Bag',
            'is_active' => true,
        ]);
    }
}
