<?php

namespace Tests\Feature;

use App\Models\Boq;
use App\Models\BoqItem;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Material;
use App\Models\Project;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Vendor;
use App\Services\GlobalSearch;
use App\Support\DocumentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Concerns\CreatesVendorBilling;
use Tests\TestCase;

class GlobalSearchTest extends TestCase
{
    use CreatesVendorBilling;
    use RefreshDatabase;

    public function test_one_query_returns_grouped_results_from_several_modules(): void
    {
        $admin = User::factory()->admin()->create();
        $vendor = Vendor::factory()->create(['name' => 'Kaveri Aggregates', 'vendor_code' => 'VEN-KAV']);
        [$project, $order] = $this->approvedOrder($admin, $vendor, Project::factory()->create(['project_name' => 'Kaveri Riverside Towers']));

        $response = $this->search($admin, 'kaveri')->assertOk();
        $groups = collect($response->json('groups'))->keyBy('key');

        $this->assertTrue($groups->has('vendors'));
        $this->assertTrue($groups->has('projects'));
        $this->assertTrue($groups->has('purchase_orders'));
        $this->assertSame('Vendors', $groups['vendors']['label']);
        $this->assertSame('Kaveri Aggregates', $groups['vendors']['items'][0]['title']);
        $this->assertSame(route('vendors.show', $vendor, false), $groups['vendors']['items'][0]['url']);
        $this->assertSame(route('projects.show', $project, false), $groups['projects']['items'][0]['url']);
        $this->assertSame(route('projects.purchase-orders.show', [$project, $order], false), $groups['purchase_orders']['items'][0]['url']);
        $this->assertSame('kaveri', $response->json('query'));
        $this->assertGreaterThanOrEqual(3, $response->json('total'));

        foreach ($groups as $group) {
            foreach ($group['items'] as $item) {
                $this->assertSame(['type', 'title', 'subtitle', 'url'], array_keys($item));
            }
        }
    }

    public function test_partial_identifiers_match_and_exact_matches_rank_first(): void
    {
        $admin = User::factory()->admin()->create();
        $vendor = Vendor::factory()->create();
        [, $order] = $this->approvedOrder($admin, $vendor);
        $fragment = substr($order->po_number, 2, 6);

        $titles = collect($this->search($admin, $fragment)->json('groups'))->firstWhere('key', 'purchase_orders')['items'];
        $this->assertSame($order->po_number, $titles[0]['title']);

        Vendor::factory()->create(['name' => 'Steel Mart Supplies']);
        Vendor::factory()->create(['name' => 'Steel']);
        $vendors = collect($this->search($admin, 'steel')->json('groups'))->firstWhere('key', 'vendors')['items'];
        $this->assertSame('Steel', $vendors[0]['title']);
        $this->assertCount(2, $vendors);
    }

    public function test_boq_items_and_materials_are_searchable(): void
    {
        $admin = User::factory()->admin()->create();
        $project = Project::factory()->create();
        $boq = Boq::factory()->create(['project_id' => $project->id]);
        BoqItem::factory()->create(['boq_id' => $boq->id, 'description' => 'Reinforced cement concrete M25 grade', 'service_code' => 'SRV-RCC25']);
        Material::query()->create(['material_code' => 'MAT-GS-01', 'name' => 'Galvanised sheet 0.5mm', 'unit' => 'Nos', 'is_active' => true]);

        $groups = collect($this->search($admin, 'concrete m25')->json('groups'))->keyBy('key');
        $this->assertSame(route('projects.boqs.show', [$project, $boq], false), $groups['boq_items']['items'][0]['url']);

        $materials = collect($this->search($admin, 'galvanised')->json('groups'))->firstWhere('key', 'materials');
        $this->assertSame('Galvanised sheet 0.5mm', $materials['items'][0]['title']);
        $this->assertStringContainsString('search=MAT-GS-01', $materials['items'][0]['url']);
    }

    public function test_per_module_limit_offers_view_all_link(): void
    {
        $admin = User::factory()->admin()->create();
        foreach (range(1, 9) as $i) {
            Vendor::factory()->create(['name' => "Limitcheck Traders {$i}"]);
        }

        $vendors = collect($this->search($admin, 'limitcheck')->json('groups'))->firstWhere('key', 'vendors');

        $this->assertCount(GlobalSearch::PER_MODULE, $vendors['items']);
        $this->assertSame(route('vendors.index', ['search' => 'limitcheck'], false), $vendors['more_url']);
    }

    public function test_total_results_are_capped(): void
    {
        $admin = User::factory()->admin()->create();
        $response = $this->search($admin, 'a');
        $response->assertOk()->assertJson(['total' => 0, 'groups' => []]);

        $total = collect($this->search($admin, 'Cement')->json('groups'))->sum(fn ($group) => count($group['items']));
        $this->assertLessThanOrEqual(GlobalSearch::MAX_RESULTS, $total);
    }

    public function test_empty_and_too_short_queries_return_no_results_without_querying_modules(): void
    {
        $admin = User::factory()->admin()->create();
        $this->search($admin, 'warm')->assertOk();

        DB::enableQueryLog();
        DB::flushQueryLog();
        $this->search($admin, '')->assertOk()->assertExactJson(['query' => '', 'total' => 0, 'groups' => []]);
        $this->search($admin, ' x ')->assertOk()->assertJsonPath('groups', []);
        $this->assertLessThanOrEqual(6, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    public function test_selected_project_users_never_see_records_from_other_projects(): void
    {
        $admin = User::factory()->admin()->create();
        $allowed = Project::factory()->create(['project_name' => 'Scopecheck Allowed']);
        $blocked = Project::factory()->create(['project_name' => 'Scopecheck Blocked']);
        $vendor = Vendor::factory()->create(['name' => 'Scopecheck Vendor']);
        [, $ownOrder] = $this->approvedOrder($admin, $vendor, $allowed);
        [, $otherOrder] = $this->approvedOrder($admin, $vendor, $blocked);
        $this->expenseOn($allowed, 'EXP-SCOPE-OWN');
        $this->expenseOn($blocked, 'EXP-SCOPE-OTHER');

        $user = User::factory()->withRoles('purchase_manager')->create(['project_access' => 'selected']);
        $user->projects()->sync([$allowed->id]);

        $groups = collect($this->search($user, 'scopecheck')->json('groups'))->keyBy('key');
        $this->assertSame(['Scopecheck Allowed'], array_column($groups['projects']['items'], 'title'));

        $orders = collect($this->search($user, 'scopecheck vendor')->json('groups'))->firstWhere('key', 'purchase_orders');
        $this->assertSame([$ownOrder->po_number], array_column($orders['items'], 'title'));

        $blob = json_encode($this->search($user, $otherOrder->po_number)->json('groups'));
        $this->assertStringNotContainsString($otherOrder->po_number, $blob);

        $expenses = collect($this->search($user, 'EXP-SCOPE')->json('groups'))->firstWhere('key', 'expenses');
        $this->assertSame(['EXP-SCOPE-OWN'], array_column($expenses['items'], 'title'));

        foreach (['scopecheck', 'scopecheck vendor', 'EXP-SCOPE'] as $term) {
            foreach ($this->search($user, $term)->json('groups') as $group) {
                foreach ($group['items'] as $item) {
                    $this->actingAs($user)->get($item['url'])->assertOk();
                }
            }
        }
    }

    public function test_modules_without_view_permission_are_excluded(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Permcheck Admin']);
        $vendor = Vendor::factory()->create(['name' => 'Permcheck Vendor']);
        $this->approvedOrder($admin, $vendor, Project::factory()->create(['project_name' => 'Permcheck Site']));

        $requester = User::factory()->withRoles('purchase_requester')->create();
        $keys = collect($this->search($requester, 'permcheck')->json('groups'))->pluck('key')->all();

        $this->assertContains('projects', $keys);
        $this->assertNotContains('vendors', $keys);
        $this->assertNotContains('users', $keys);
        $this->assertNotContains('purchase_invoices', $keys);

        $this->actingAs($requester)->get(route('vendors.show', $vendor))->assertForbidden();
    }

    public function test_responses_never_expose_sensitive_fields(): void
    {
        $admin = User::factory()->admin()->create(['name' => 'Secretcheck Person']);

        $raw = $this->search($admin, 'secretcheck')->assertOk()->getContent();

        foreach (['password', 'remember_token', 'two_factor', $admin->password] as $needle) {
            $this->assertStringNotContainsString($needle, $raw);
        }
    }

    public function test_sql_injection_payloads_are_treated_as_plain_text(): void
    {
        $admin = User::factory()->admin()->create();
        Vendor::factory()->create(['name' => 'Injection Safe Vendor']);

        foreach ([
            "' OR 1=1 --",
            '" OR ""="',
            "%' UNION SELECT password FROM users --",
            "x'); DROP TABLE vendors; --",
            '100%_!',
            '\\\\',
        ] as $payload) {
            $this->search($admin, $payload)->assertOk()->assertJsonPath('total', 0);
        }

        $this->assertSame(1, Vendor::query()->count());
        $this->assertStringNotContainsString('$2y$', $this->search($admin, "' UNION SELECT password FROM users --")->getContent());
    }

    public function test_long_queries_are_truncated(): void
    {
        $admin = User::factory()->admin()->create();

        $this->search($admin, str_repeat('b', 500))
            ->assertOk()
            ->assertJsonPath('query', str_repeat('b', 100));
    }

    public function test_guests_cannot_search(): void
    {
        $this->getJson(route('global-search', ['q' => 'vendor']))->assertUnauthorized();
    }

    public function test_search_is_rate_limited_per_user(): void
    {
        config(['security.throttle.search' => 10]);
        $admin = User::factory()->admin()->create();
        RateLimiter::clear('search');

        foreach (range(1, 10) as $i) {
            $this->search($admin, "probe{$i}")->assertOk();
        }

        $this->search($admin, 'probe11')->assertStatus(429);
    }

    public function test_internal_errors_return_a_generic_message(): void
    {
        $admin = User::factory()->admin()->create();
        $this->mock(GlobalSearch::class)->shouldReceive('search')->andThrow(new RuntimeException('SQLSTATE secret detail'));

        $response = $this->search($admin, 'anything')->assertStatus(500);

        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $response->assertJsonPath('message', 'Search is temporarily unavailable. Please try again.');
    }

    public function test_global_search_does_not_change_module_listing_filters(): void
    {
        $admin = User::factory()->admin()->create();
        Vendor::factory()->create(['name' => 'Listing Untouched']);

        $this->search($admin, 'listing')->assertOk();

        $this->actingAs($admin)->get(route('vendors.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('filters.search', '')->where('vendors.per_page', 30));
    }

    public function test_shared_layout_includes_search_and_buildify360_branding(): void
    {
        $header = file_get_contents(resource_path('js/Components/AppHeader.vue'));
        $layout = file_get_contents(resource_path('js/Layouts/AdminLayout.vue'));
        $guest = file_get_contents(resource_path('js/Layouts/GuestLayout.vue'));
        $login = file_get_contents(resource_path('js/Pages/Auth/Login.vue'));
        $search = file_get_contents(resource_path('js/Components/GlobalSearch.vue'));

        $this->assertStringContainsString('<GlobalSearch v-if="user" />', $header);
        $this->assertStringContainsString('<AppHeader', $layout);
        $this->assertStringContainsString('placeholder="Search anything in ERP..."', $search);
        $this->assertStringContainsString('No matching records found', $search);

        foreach ([$layout, $guest, $login] as $source) {
            $this->assertMatchesRegularExpression('/Powered by\s*<a\b[^>]*>Buildify360<\/a>/', $source);
            preg_match('/<a\b[^>]*>Buildify360<\/a>/', $source, $anchor);
            $this->assertStringContainsString('href="https://buildify360.com/"', $anchor[0]);
            $this->assertStringContainsString('target="_blank"', $anchor[0]);
            $this->assertStringContainsString('rel="noopener noreferrer"', $anchor[0]);
            $this->assertStringContainsString('aria-label="Buildify360 (opens in a new tab)"', $anchor[0]);
        }

        $this->actingAs(User::factory()->admin()->create())->get(route('dashboard'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Dashboard'));

        auth()->logout();
        $this->get(route('login'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Auth/Login'));
    }

    private function search(User $user, string $term): TestResponse
    {
        return $this->actingAs($user)->getJson(route('global-search', ['q' => $term]));
    }

    private function expenseOn(Project $project, string $number): Expense
    {
        return Expense::query()->create([
            'number' => $number,
            'date' => '2026-10-15',
            'project_id' => $project->id,
            'category_id' => ExpenseCategory::query()->where('name', 'Labour')->value('id'),
            'description' => 'Global search '.$number,
            'amount' => 10,
            'tax_amount' => 0,
            'round_off' => 0,
            'total_amount' => 10,
            'payment_status' => Expense::PAYMENT_UNPAID,
            'status' => DocumentStatus::DRAFT,
        ]);
    }
}
