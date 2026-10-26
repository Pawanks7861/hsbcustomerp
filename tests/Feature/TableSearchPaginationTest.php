<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Project;
use App\Models\User;
use App\Models\Vendor;
use App\Support\DocumentStatus;
use App\Support\Listing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesVendorBilling;
use Tests\TestCase;

class TableSearchPaginationTest extends TestCase
{
    use CreatesVendorBilling;
    use RefreshDatabase;

    public function test_listings_default_to_thirty_rows_and_paginate_on_the_server(): void
    {
        $admin = User::factory()->admin()->create();
        $this->seedVendors(45);

        $this->actingAs($admin)->get(route('vendors.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Vendors/Index')
                ->has('vendors.data', 30)
                ->where('vendors.per_page', 30)
                ->where('vendors.total', 45)
                ->where('vendors.last_page', 2)
                ->where('filters.per_page', 30));

        $this->actingAs($admin)->get(route('vendors.index', ['page' => 2]))
            ->assertInertia(fn ($page) => $page
                ->has('vendors.data', 15)
                ->where('vendors.from', 31)
                ->where('vendors.to', 45));
    }

    public function test_every_converted_listing_defaults_to_thirty_per_page(): void
    {
        $admin = User::factory()->admin()->create();
        $project = Project::factory()->create();

        $routes = [
            [route('projects.index'), 'projects'],
            [route('vendors.index'), 'vendors'],
            [route('clients.index'), 'clients'],
            [route('users.index'), 'users'],
            [route('roles.index'), 'roles'],
            [route('audit-log.index'), 'logs'],
            [route('imports.history'), 'batches'],
            [route('approvals.index'), 'items'],
            [route('expenses.index'), 'expenses'],
            [route('client-invoices.index'), 'invoices'],
            [route('inventory.locations.index'), 'locations'],
            [route('inventory.transfers.index'), 'transfers'],
            [route('vendors.report'), 'rows'],
            [route('vendors.reconciliation.index'), 'rows'],
            [route('projects.boqs.index', $project), 'boqs'],
            [route('projects.purchase-requests.index', $project), 'purchaseRequests'],
            [route('projects.purchase-orders.index', $project), 'purchaseOrders'],
            [route('projects.grns.index', $project), 'receipts'],
            [route('projects.store.index', $project), 'receipts'],
            [route('projects.purchase-invoices.index', $project), 'invoices'],
            [route('projects.debit-notes.index', $project), 'notes'],
            [route('projects.credit-notes.index', $project), 'notes'],
            [route('projects.work-orders.index', $project), 'workOrders'],
            [route('projects.inventory.issues.index', $project), 'issues'],
            [route('projects.inventory.adjustments.index', $project), 'adjustments'],
            [route('projects.inventory.materials.index', $project), 'materials'],
            [route('projects.inventory.stock', $project), 'rows'],
            [route('projects.inventory.ledger', $project), 'entries'],
            [route('reports.show', 'project-summary'), 'rows'],
        ];

        foreach ($routes as [$url, $prop]) {
            $this->actingAs($admin)->get($url)
                ->assertOk()
                ->assertInertia(fn ($page) => $page->where("{$prop}.per_page", 30)->has("{$prop}.data"));
        }
    }

    public function test_allowed_page_sizes_are_honoured_and_invalid_ones_are_normalised(): void
    {
        $admin = User::factory()->admin()->create();
        $this->seedVendors(120);

        foreach ([10, 30, 50, 100] as $size) {
            $this->actingAs($admin)->get(route('vendors.index', ['per_page' => $size]))
                ->assertInertia(fn ($page) => $page->has('vendors.data', $size)->where('vendors.per_page', $size));
        }

        foreach (['7', '1000', 'abc', '-5', '0'] as $bad) {
            $this->actingAs($admin)->get(route('vendors.index', ['per_page' => $bad]))
                ->assertOk()
                ->assertInertia(fn ($page) => $page->where('vendors.per_page', 30)->has('vendors.data', 30));
        }
    }

    public function test_search_matches_any_displayed_field_partially_and_case_insensitively(): void
    {
        $admin = User::factory()->admin()->create();
        $this->seedVendors(40);
        Vendor::factory()->create([
            'vendor_code' => 'VEN-777',
            'name' => 'Narmada Cement Traders',
            'phone' => '9811122233',
            'gst_number' => '24ABCDE1234F1Z5',
            'status' => Vendor::ACTIVE,
        ]);

        foreach (['narmada', 'CEMENT trad', 'VEN-777', '777', '98111', 'abcde1234'] as $term) {
            $this->actingAs($admin)->get(route('vendors.index', ['search' => $term]))
                ->assertInertia(fn ($page) => $page
                    ->where('vendors.total', 1)
                    ->where('vendors.data.0.name', 'Narmada Cement Traders')
                    ->where('filters.search', $term));
        }
    }

    public function test_search_runs_across_the_whole_dataset_not_just_the_current_page(): void
    {
        $admin = User::factory()->admin()->create();
        $this->seedVendors(60);
        Vendor::factory()->create(['name' => 'Zzz Last Alphabetical Vendor', 'vendor_code' => 'VEN-999']);

        $this->actingAs($admin)->get(route('vendors.index'))
            ->assertInertia(fn ($page) => $page->where('vendors.data', fn ($rows) => ! collect($rows)->contains('vendor_code', 'VEN-999')));

        $this->actingAs($admin)->get(route('vendors.index', ['search' => 'last alphabetical']))
            ->assertInertia(fn ($page) => $page->where('vendors.total', 1)->where('vendors.data.0.vendor_code', 'VEN-999'));
    }

    public function test_search_combines_with_filters_and_clearing_restores_the_filtered_list(): void
    {
        $admin = User::factory()->admin()->create();
        Vendor::factory()->create(['name' => 'Alpha Steel', 'status' => Vendor::ACTIVE]);
        Vendor::factory()->create(['name' => 'Alpha Bricks', 'status' => Vendor::INACTIVE]);
        Vendor::factory()->create(['name' => 'Beta Steel', 'status' => Vendor::INACTIVE]);

        $this->actingAs($admin)->get(route('vendors.index', ['search' => 'alpha', 'status' => 'inactive']))
            ->assertInertia(fn ($page) => $page->where('vendors.total', 1)->where('vendors.data.0.name', 'Alpha Bricks'));

        $this->actingAs($admin)->get(route('vendors.index', ['status' => 'inactive']))
            ->assertInertia(fn ($page) => $page->where('vendors.total', 2)->where('filters.search', ''));

        $this->actingAs($admin)->get(route('vendors.index', ['search' => 'no such vendor here']))
            ->assertInertia(fn ($page) => $page->where('vendors.total', 0)->has('vendors.data', 0));
    }

    public function test_pagination_links_keep_search_filters_and_page_size(): void
    {
        $admin = User::factory()->admin()->create();
        foreach (range(1, 25) as $i) {
            Vendor::factory()->create(['name' => "Steel Supplier {$i}", 'status' => Vendor::ACTIVE]);
        }

        $this->actingAs($admin)->get(route('vendors.index', ['search' => 'steel', 'status' => 'active', 'per_page' => 10]))
            ->assertInertia(fn ($page) => $page
                ->where('vendors.total', 25)
                ->where('vendors.next_page_url', fn ($url) => str_contains($url, 'search=steel')
                    && str_contains($url, 'status=active')
                    && str_contains($url, 'per_page=10')
                    && str_contains($url, 'page=2')));
    }

    public function test_an_out_of_range_page_after_narrowing_is_corrected_to_the_last_page(): void
    {
        $admin = User::factory()->admin()->create();
        $this->seedVendors(45);

        $this->actingAs($admin)->get(route('vendors.index', ['page' => 9]))
            ->assertInertia(fn ($page) => $page->where('vendors.current_page', 2)->has('vendors.data', 15));
    }

    public function test_related_fields_dates_and_amounts_are_searchable(): void
    {
        $admin = User::factory()->admin()->create();
        $vendor = Vendor::factory()->create(['name' => 'Kaveri Aggregates']);
        [$project, $order] = $this->approvedOrder($admin, $vendor);
        $order->update(['grand_total' => '123456.00', 'po_date' => '2026-10-09']);

        $index = route('projects.purchase-orders.index', $project);
        foreach (['kaveri', $order->po_number, '1,23,456', '123456', '09/10/2026', '2026-10-09'] as $term) {
            $this->actingAs($admin)->get($index.'?search='.urlencode($term))
                ->assertInertia(fn ($page) => $page->where('purchaseOrders.total', 1));
        }

        $this->actingAs($admin)->get(route('projects.purchase-requests.index', $project).'?search='.urlencode($admin->name))
            ->assertInertia(fn ($page) => $page->where('purchaseRequests.total', 1));
    }

    public function test_sql_injection_and_wildcards_do_not_change_query_behaviour(): void
    {
        $admin = User::factory()->admin()->create();
        $this->seedVendors(5);

        foreach (["' OR 1=1 --", '%', '_', "x'); DROP TABLE vendors; --", '%%%'] as $payload) {
            $this->actingAs($admin)->get(route('vendors.index', ['search' => $payload]))
                ->assertOk()
                ->assertInertia(fn ($page) => $page->where('vendors.total', 0));
        }

        $this->assertTrue(Schema::hasTable('vendors'));
        $this->assertSame(5, Vendor::query()->count());
    }

    public function test_search_input_is_length_limited(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->get(route('vendors.index', ['search' => str_repeat('a', 500)]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('filters.search', fn ($value) => mb_strlen($value) === Listing::MAX_SEARCH_LENGTH));
    }

    public function test_search_and_counts_never_include_records_outside_the_users_projects(): void
    {
        $allowed = Project::factory()->create();
        $blocked = Project::factory()->create();
        $user = User::factory()->withRoles('accounts')->create(['project_access' => 'selected']);
        $user->projects()->sync([$allowed->id]);

        $this->expenseOn($allowed, 'EXP-SHARED-OWN');
        $this->expenseOn($blocked, 'EXP-SHARED-OTHER');

        $this->actingAs($user)->get(route('expenses.index', ['search' => 'EXP-SHARED']))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('expenses.total', 1)
                ->where('expenses.data.0.number', 'EXP-SHARED-OWN'));

        $this->actingAs($user)->get(route('expenses.index', ['search' => 'EXP-SHARED-OTHER']))
            ->assertInertia(fn ($page) => $page->where('expenses.total', 0));
    }

    public function test_computed_rows_are_searched_and_paginated_on_the_server(): void
    {
        $rows = collect(range(1, 75))->map(fn ($i) => [
            'reference' => sprintf('REF-%03d', $i),
            'type' => $i % 2 ? 'invoice_payment' : 'advance',
            'amount' => (string) ($i * 100),
        ])->all();

        $page = Listing::paginateRows($rows, Request::create('/x', 'GET'), ['reference', 'type', 'amount']);
        $this->assertSame(30, $page->perPage());
        $this->assertSame(75, $page->total());
        $this->assertCount(30, $page->items());

        $filtered = Listing::paginateRows($rows, Request::create('/x', 'GET', ['search' => 'invoice payment', 'per_page' => 10]), ['reference', 'type', 'amount']);
        $this->assertSame(38, $filtered->total());
        $this->assertCount(10, $filtered->items());

        $byAmount = Listing::paginateRows($rows, Request::create('/x', 'GET', ['search' => '7,500']), ['reference', 'type', 'amount']);
        $this->assertSame(1, $byAmount->total());
        $this->assertSame('REF-075', $byAmount->items()[0]['reference']);
    }

    private function seedVendors(int $count): void
    {
        foreach (range(1, $count) as $i) {
            Vendor::factory()->create([
                'vendor_code' => sprintf('VEN-%03d', $i),
                'name' => sprintf('Vendor %03d', $i),
                'phone' => sprintf('60000%05d', $i),
                'gst_number' => '24AAAAA0000A1Z5',
                'city' => 'Pune',
                'contact_person' => 'Ravi',
                'status' => Vendor::ACTIVE,
            ]);
        }
    }

    private function expenseOn(Project $project, string $number): Expense
    {
        return Expense::query()->create([
            'number' => $number,
            'date' => '2026-10-15',
            'project_id' => $project->id,
            'category_id' => ExpenseCategory::query()->where('name', 'Labour')->value('id'),
            'description' => 'Search scope '.$number,
            'amount' => 10,
            'tax_amount' => 0,
            'round_off' => 0,
            'total_amount' => 10,
            'payment_status' => Expense::PAYMENT_UNPAID,
            'status' => DocumentStatus::DRAFT,
        ]);
    }
}
