<?php

namespace Tests\Feature;

use App\Imports\Support\DuplicateMode;
use App\Models\Client;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\ImportBatch;
use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorPayment;
use App\Support\DocumentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use Tests\Concerns\CreatesVendorBilling;
use Tests\TestCase;

class Phase1SecurityTest extends TestCase
{
    use CreatesVendorBilling;
    use RefreshDatabase;

    public function test_a_viewer_cannot_access_restricted_create_and_edit_routes(): void
    {
        $viewer = User::factory()->withRoles('viewer')->create(['project_access' => 'all']);
        $project = Project::factory()->create();
        $vendor = Vendor::factory()->create();
        $expense = $this->expenseOn($project);

        $this->actingAs($viewer)->get(route('projects.purchase-requests.create', $project))->assertForbidden();
        $this->actingAs($viewer)->get(route('projects.boqs.create', $project))->assertForbidden();
        $this->actingAs($viewer)->get(route('projects.store.create', $project))->assertForbidden();
        $this->actingAs($viewer)->get(route('projects.inventory.issues.create', $project))->assertForbidden();
        $this->actingAs($viewer)->get(route('expenses.create'))->assertForbidden();
        $this->actingAs($viewer)->get(route('expenses.edit', $expense))->assertForbidden();
        $this->actingAs($viewer)->get(route('users.create'))->assertForbidden();
        $this->actingAs($viewer)->get(route('users.edit', $viewer))->assertForbidden();
        $this->actingAs($viewer)->get(route('vendors.payments.create', $vendor))->assertForbidden();
        $this->actingAs($viewer)->get(route('vendors.create'))->assertForbidden();
    }

    public function test_a_user_without_the_relevant_permission_cannot_perform_protected_actions(): void
    {
        $requester = User::factory()->withRoles('purchase_requester')->create(['project_access' => 'all']);
        $project = Project::factory()->create();
        $vendor = Vendor::factory()->create();

        $this->actingAs($requester)->get(route('projects.purchase-orders.index', $project))->assertOk();
        $this->actingAs($requester)->get(route('users.index'))->assertForbidden();
        $this->actingAs($requester)->post(route('vendors.payments.store', $vendor), [
            'payment_date' => '2026-10-15',
            'payment_type' => VendorPayment::TYPE_ADVANCE,
            'payment_mode' => 'cash',
            'amount' => 100,
        ])->assertForbidden();
        $this->actingAs($requester)->get(route('reports.export.pdf', 'project-summary'))->assertForbidden();
        $this->actingAs($requester)->get(route('imports.show', 'expenses'))->assertForbidden();
    }

    public function test_a_selected_project_user_cannot_access_another_projects_expense(): void
    {
        $admin = User::factory()->admin()->create();
        [$allowed, $blocked, $user] = $this->selectedAccountsUser();
        $own = $this->expenseOn($allowed, 'EXP-OWN');
        $other = $this->expenseOn($blocked, 'EXP-OTHER');

        $this->actingAs($user)->get(route('expenses.show', $own))->assertOk();
        $this->actingAs($user)->get(route('expenses.show', $other))->assertNotFound();
        $this->actingAs($user)->get(route('expenses.edit', $other))->assertNotFound();
        $this->actingAs($user)->get(route('projects.expenses.show', [$allowed, $other]))->assertNotFound();
        $this->actingAs($user)->get(route('expenses.index', ['project_id' => $blocked->id]))->assertNotFound();
        $this->actingAs($user)
            ->get(route('expenses.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('expenses.data', function ($expenses) use ($own, $other) {
                $ids = collect($expenses)->pluck('id')->all();

                return in_array($own->id, $ids, true) && ! in_array($other->id, $ids, true);
            }));

        $this->actingAs($admin)->get(route('expenses.show', $other))->assertOk();
        $this->actingAs($admin)->get(route('projects.expenses.show', [$blocked, $other]))->assertOk();
    }

    public function test_a_selected_project_user_cannot_access_another_projects_vendor_financial_records(): void
    {
        $admin = User::factory()->admin()->create();
        $vendor = Vendor::factory()->create(['opening_outstanding' => 0]);
        [$allowed, $blocked, $user] = $this->selectedAccountsUser();
        $ownInvoice = $this->recordedInvoice($admin, $vendor, '100.00', $allowed);
        $otherInvoice = $this->recordedInvoice($admin, $vendor, '999.00', $blocked);
        $this->markInvoiceApproved($ownInvoice, $admin);
        $this->markInvoiceApproved($otherInvoice, $admin);

        $this->actingAs($user)
            ->get(route('vendors.show', ['vendor' => $vendor, 'tab' => 'invoices']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('invoices.data', function ($invoices) use ($ownInvoice, $otherInvoice) {
                $ids = collect($invoices)->pluck('id')->all();

                return $ids === [$ownInvoice->id] || (in_array($ownInvoice->id, $ids, true) && ! in_array($otherInvoice->id, $ids, true));
            }));

        $this->actingAs($user)->get(route('vendors.show', ['vendor' => $vendor, 'project_id' => $blocked->id]))->assertNotFound();
        $this->actingAs($user)->get(route('vendors.ledger', ['vendor' => $vendor, 'project_id' => $blocked->id]))->assertNotFound();
        $this->actingAs($user)->get(route('vendors.payments.create', ['vendor' => $vendor, 'purchase_invoice_id' => $otherInvoice->id]))->assertNotFound();
        $this->actingAs($user)->post(route('vendors.payments.store', $vendor), [
            'project_id' => $blocked->id,
            'payment_date' => '2026-10-15',
            'payment_type' => VendorPayment::TYPE_ADVANCE,
            'payment_mode' => 'cash',
            'amount' => 10,
        ])->assertNotFound();
        $this->actingAs($user)->post(route('vendors.recoveries.store', $vendor), [
            'project_id' => $blocked->id,
            'amount_due' => 10,
        ])->assertNotFound();

        $this->actingAs($user)
            ->get(route('vendors.ledger', $vendor))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('closing', '100.00'));

        $this->actingAs($admin)
            ->get(route('vendors.ledger', $vendor))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('closing', '1099.00'));

        $client = Client::factory()->create();
        $this->actingAs($user)->get(route('clients.ledger', ['client' => $client, 'project_id' => $blocked->id]))->assertNotFound();
        $this->actingAs($user)->get(route('clients.ledger', $client))->assertOk();
        $this->actingAs($admin)->get(route('clients.ledger', ['client' => $client, 'project_id' => $blocked->id]))->assertOk();
    }

    public function test_import_preview_and_confirmation_respect_project_access(): void
    {
        [$allowed, $blocked, $user] = $this->selectedAccountsUser();
        $admin = User::factory()->admin()->create();

        $this->actingAs($user)->get(route('imports.show', ['module' => 'expenses', 'project_id' => $blocked->id]))->assertNotFound();
        $this->actingAs($user)->get(route('imports.show', ['module' => 'expenses', 'project_id' => $allowed->id]))->assertOk();
        $this->actingAs($admin)->get(route('imports.show', ['module' => 'expenses', 'project_id' => $blocked->id]))->assertOk();

        $path = $this->csv([['Vendor Code', 'Name'], ['V-1', 'Ignored']]);
        $this->actingAs($user)->post(route('imports.preview', 'vendors'), [
            'file' => new UploadedFile($path, 'vendors.csv', 'text/csv', null, true),
            'duplicate_mode' => DuplicateMode::CREATE_ONLY,
            'project_id' => $blocked->id,
        ])->assertNotFound();

        $blockedBatch = ImportBatch::query()->create([
            'batch_number' => 'IMP-BLOCKED',
            'module' => 'expenses',
            'original_filename' => 'expenses.csv',
            'duplicate_mode' => DuplicateMode::CREATE_ONLY,
            'status' => ImportBatch::STATUS_PREVIEWED,
            'project_id' => $blocked->id,
            'uploaded_by' => $user->id,
        ]);
        $this->actingAs($user)->post(route('imports.confirm', $blockedBatch))->assertNotFound();

        $allowedBatch = ImportBatch::query()->create([
            'batch_number' => 'IMP-ALLOWED',
            'module' => 'expenses',
            'original_filename' => 'expenses.csv',
            'duplicate_mode' => DuplicateMode::CREATE_ONLY,
            'status' => ImportBatch::STATUS_PREVIEWED,
            'project_id' => $allowed->id,
            'uploaded_by' => $user->id,
        ]);
        $this->actingAs($user)->post(route('imports.confirm', $allowedBatch))->assertStatus(422);
    }

    public function test_report_pdf_access_respects_report_permissions(): void
    {
        $viewer = User::factory()->withRoles('viewer')->create(['project_access' => 'all']);
        $requester = User::factory()->withRoles('purchase_requester')->create(['project_access' => 'all']);
        [$allowed, $blocked, $accounts] = $this->selectedAccountsUser();

        $this->actingAs($viewer)->get(route('reports.export.pdf', 'project-summary'))->assertOk();
        $this->actingAs($requester)->get(route('reports.export.pdf', 'project-summary'))->assertForbidden();
        $this->actingAs($accounts)->get(route('reports.export.pdf', ['report' => 'procurement-summary', 'project_id' => $blocked->id]))->assertNotFound();
        $this->actingAs($accounts)->get(route('reports.export.pdf', ['report' => 'procurement-summary', 'project_id' => $allowed->id]))->assertOk();
        $this->actingAs(User::factory()->admin()->create())->get(route('reports.export.pdf', 'project-summary'))->assertOk();
    }

    public function test_all_project_access_continues_to_see_every_project(): void
    {
        $accounts = User::factory()->withRoles('accounts')->create(['project_access' => 'all']);
        $allowed = Project::factory()->create();
        $other = Project::factory()->create();
        $own = $this->expenseOn($allowed, 'EXP-ALL-OWN');
        $foreign = $this->expenseOn($other, 'EXP-ALL-OTHER');

        $this->actingAs($accounts)->get(route('expenses.show', $foreign))->assertOk();
        $this->actingAs($accounts)->get(route('projects.expenses.show', [$other, $foreign]))->assertOk();
        $this->actingAs($accounts)
            ->get(route('expenses.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('expenses.data', function ($expenses) use ($own, $foreign) {
                $ids = collect($expenses)->pluck('id')->all();

                return in_array($own->id, $ids, true) && in_array($foreign->id, $ids, true);
            }));
    }

    /**
     * @return array{0: Project, 1: Project, 2: User}
     */
    private function selectedAccountsUser(): array
    {
        $allowed = Project::factory()->create();
        $blocked = Project::factory()->create();
        $user = User::factory()->withRoles('accounts')->create(['project_access' => 'selected']);
        $user->projects()->sync([$allowed->id]);

        return [$allowed, $blocked, $user];
    }

    private function expenseOn(Project $project, string $number = 'EXP-SEC-1'): Expense
    {
        return Expense::query()->create([
            'number' => $number,
            'date' => '2026-10-15',
            'project_id' => $project->id,
            'category_id' => ExpenseCategory::query()->where('name', 'Labour')->value('id'),
            'description' => 'Security isolation '.$number,
            'amount' => 10,
            'tax_amount' => 0,
            'round_off' => 0,
            'total_amount' => 10,
            'payment_status' => Expense::PAYMENT_UNPAID,
            'status' => DocumentStatus::DRAFT,
        ]);
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function csv(array $rows): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray($rows);
        $path = tempnam(sys_get_temp_dir(), 'imp').'.csv';
        (new Csv($spreadsheet))->save($path);

        return $path;
    }
}
