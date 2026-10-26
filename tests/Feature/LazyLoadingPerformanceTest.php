<?php

namespace Tests\Feature;

use App\Models\Boq;
use App\Models\BoqItem;
use App\Models\InventoryLocation;
use App\Models\Material;
use App\Models\Project;
use App\Models\PurchaseRequest;
use App\Models\StockLedgerEntry;
use App\Models\User;
use App\Models\Vendor;
use App\Services\ApprovalInbox;
use App\Services\InventoryStock;
use App\Support\DocumentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\Concerns\CreatesVendorBilling;
use Tests\TestCase;

class LazyLoadingPerformanceTest extends TestCase
{
    use CreatesVendorBilling;
    use RefreshDatabase;

    public function test_grouped_stock_balances_match_the_per_row_calculation(): void
    {
        $project = Project::factory()->create();
        $default = InventoryLocation::query()->where('project_id', $project->id)->where('is_default', true)->firstOrFail();
        $site = $this->location($project, 'SITE-1', InventoryLocation::SITE);
        $transit = $this->location($project, 'TRANSIT-1', InventoryLocation::IN_TRANSIT);
        $cement = $this->material('MAT-1');
        $steel = $this->material('MAT-2');

        $this->entry($project, $cement, $default, StockLedgerEntry::RECEIPT, '100.500', '0');
        $this->entry($project, $cement, null, StockLedgerEntry::ADJUSTMENT_IN, '12.250', '0');
        $this->entry($project, $cement, $site, StockLedgerEntry::TRANSFER_IN, '30', '0');
        $this->entry($project, $cement, $transit, StockLedgerEntry::IN_TRANSIT, '7', '0');
        $this->entry($project, $cement, $default, StockLedgerEntry::ISSUE, '0', '20.125');
        $this->entry($project, $steel, $site, StockLedgerEntry::RECEIPT, '5', '0');
        $this->entry($project, $steel, $site, StockLedgerEntry::ISSUE, '0', '2.5');

        $stock = app(InventoryStock::class);
        $locations = [$default, $site, $transit];
        $grouped = $stock->projectBalances($project->id, $locations);

        foreach ([$cement, $steel] as $material) {
            $this->assertSame($stock->balance($project->id, $material->id), $grouped['total'][$material->id]);
            foreach ($locations as $location) {
                $this->assertSame(
                    $stock->locationBalance($project->id, $material->id, $location->id),
                    $grouped['at'][$material->id][$location->id],
                    "Mismatch for material {$material->id} at {$location->location_code}",
                );
            }
        }

        $this->assertSame('122.625', $grouped['total'][$cement->id]);
        $this->assertSame('100.500', $grouped['received'][$cement->id]);
        $this->assertSame('20.125', $grouped['issued'][$cement->id]);
    }

    public function test_stock_page_query_count_does_not_grow_with_materials(): void
    {
        $admin = User::factory()->admin()->create();
        $project = Project::factory()->create();
        $default = InventoryLocation::query()->where('project_id', $project->id)->where('is_default', true)->firstOrFail();
        $site = $this->location($project, 'SITE-Q', InventoryLocation::SITE);

        foreach (range(1, 20) as $i) {
            $material = $this->material("MAT-Q{$i}");
            $this->entry($project, $material, $default, StockLedgerEntry::RECEIPT, '10', '0');
            $this->entry($project, $material, $site, StockLedgerEntry::RECEIPT, '4', '0');
        }

        $queries = $this->countQueries(fn () => $this->actingAs($admin)
            ->get(route('projects.inventory.stock', $project))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('rows.total', 40)->where('rows.data.0.total_available', '14.000')));

        $this->assertLessThan(30, $queries, "Stock page ran {$queries} queries for 20 materials.");
    }

    public function test_approval_badge_counts_use_one_query_and_match_the_inbox(): void
    {
        $admin = User::factory()->admin()->create();
        $project = Project::factory()->create();
        $boq = Boq::factory()->create(['project_id' => $project->id]);
        $item = BoqItem::factory()->create(['boq_id' => $boq->id]);
        foreach (range(1, 3) as $i) {
            PurchaseRequest::query()->create([
                'project_id' => $project->id,
                'boq_id' => $boq->id,
                'boq_item_id' => $item->id,
                'pr_number' => "PR-LZ-{$i}",
                'request_date' => '2026-10-20',
                'status' => DocumentStatus::SUBMITTED,
                'requested_by' => $admin->id,
            ]);
        }

        $inbox = app(ApprovalInbox::class);
        $countQueries = 0;
        DB::listen(function ($query) use (&$countQueries): void {
            if (str_contains(strtolower($query->sql), 'count(*)')) {
                $countQueries++;
            }
        });
        $counts = $inbox->counts($admin);

        $this->assertSame(1, $countQueries);
        $this->assertSame(3, $counts['purchase_requests']);
        $this->assertSame(array_sum($counts), $inbox->count($admin));
        $this->assertSame($inbox->items($admin)->count(), array_sum($counts));
    }

    public function test_vendor_tabs_load_only_when_opened(): void
    {
        $admin = User::factory()->admin()->create();
        $vendor = Vendor::factory()->create(['opening_outstanding' => 0]);
        $invoice = $this->recordedInvoice($admin, $vendor, '250.00');

        $this->actingAs($admin)->get(route('vendors.show', $vendor))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Vendors/Show')
                ->has('summary')
                ->missing('invoices')
                ->missing('purchaseOrders')
                ->missing('payments')
                ->missing('transactions'));

        $this->actingAs($admin)->get(route('vendors.show', ['vendor' => $vendor, 'tab' => 'invoices']))
            ->assertInertia(fn ($page) => $page
                ->where('invoices.data.0.id', $invoice->id)
                ->where('invoices.per_page', 30)
                ->missing('payments'));

        $partial = $this->actingAs($admin)
            ->withHeaders($this->partialHeaders('Vendors/Show', 'purchaseOrders,filters,tab'))
            ->get(route('vendors.show', ['vendor' => $vendor, 'tab' => 'purchase-orders']))
            ->assertOk()
            ->json('props');

        $this->assertArrayHasKey('purchaseOrders', $partial);
        $this->assertSame(1, $partial['purchaseOrders']['total']);
        $this->assertArrayNotHasKey('summary', $partial);
        $this->assertArrayNotHasKey('invoices', $partial);
    }

    public function test_partial_tab_requests_still_enforce_authorization_and_project_scope(): void
    {
        $admin = User::factory()->admin()->create();
        $vendor = Vendor::factory()->create(['opening_outstanding' => 0]);
        $allowed = Project::factory()->create();
        $blocked = Project::factory()->create();
        $this->recordedInvoice($admin, $vendor, '100.00', $allowed);
        $this->recordedInvoice($admin, $vendor, '999.00', $blocked);

        $user = User::factory()->withRoles('accounts')->create(['project_access' => 'selected']);
        $user->projects()->sync([$allowed->id]);

        $props = $this->actingAs($user)
            ->withHeaders($this->partialHeaders('Vendors/Show', 'invoices'))
            ->get(route('vendors.show', ['vendor' => $vendor, 'tab' => 'invoices']))
            ->assertOk()
            ->json('props');
        $this->assertSame(1, $props['invoices']['total']);

        $blockedResponse = $this->actingAs($user)
            ->withHeaders($this->partialHeaders('Vendors/Show', 'invoices'))
            ->get(route('vendors.show', ['vendor' => $vendor, 'tab' => 'invoices', 'project_id' => $blocked->id]));
        $blockedResponse->assertStatus(409);
        $this->assertStringEndsWith('/error/404', (string) $blockedResponse->headers->get('X-Inertia-Location'));
        $this->assertStringNotContainsString('999.00', (string) $blockedResponse->getContent());

        $denied = User::factory()->create();
        $denied->permissionOverrides()->create(['permission' => 'vendors.view', 'effect' => 'deny']);
        $deniedResponse = $this->actingAs($denied)
            ->withHeaders($this->partialHeaders('Vendors/Show', 'invoices'))
            ->get(route('vendors.show', ['vendor' => $vendor, 'tab' => 'invoices']));
        $deniedResponse->assertStatus(409);
        $this->assertStringEndsWith('/error/403', (string) $deniedResponse->headers->get('X-Inertia-Location'));
        $this->flushHeaders();
        $this->actingAs($denied)->get(route('vendors.show', ['vendor' => $vendor, 'tab' => 'invoices']))->assertForbidden();
    }

    public function test_dashboard_and_project_list_queries_do_not_grow_per_project(): void
    {
        $admin = User::factory()->admin()->create();
        $measure = function () use ($admin): array {
            return [
                $this->countQueries(fn () => $this->actingAs($admin)->get('/')->assertOk()),
                $this->countQueries(fn () => $this->actingAs($admin)->get(route('projects.index'))->assertOk()),
            ];
        };

        $this->projectWithBoq();
        $measure();
        [$dashboardOne, $listOne] = $measure();
        foreach (range(1, 4) as $i) {
            $this->projectWithBoq();
        }
        [$dashboardFive, $listFive] = $measure();

        $this->assertSame($dashboardOne, $dashboardFive, 'Dashboard query count grew with the number of projects.');
        $this->assertSame($listOne, $listFive, 'Project list query count grew with the number of projects.');
    }

    public function test_nested_pages_open_directly_and_on_refresh(): void
    {
        $admin = User::factory()->admin()->create();
        $vendor = Vendor::factory()->create(['opening_outstanding' => 0]);
        $invoice = $this->recordedInvoice($admin, $vendor, '300.00');
        $project = Project::query()->findOrFail($invoice->project_id);
        $boq = Boq::query()->where('project_id', $project->id)->firstOrFail();

        $urls = [
            route('vendors.show', ['vendor' => $vendor, 'tab' => 'transactions', 'page' => 1]),
            route('vendors.show', ['vendor' => $vendor, 'tab' => 'refunds']),
            route('vendors.ledger', $vendor),
            route('projects.show', $project),
            route('projects.boqs.show', [$project, $boq]),
            route('projects.inventory.dashboard', $project),
            route('projects.purchase-orders.show', [$project, $invoice->purchase_order_id]),
            route('projects.purchase-invoices.show', [$project, $invoice]),
            route('reports.show', ['report' => 'project-summary', 'search' => 'x', 'per_page' => 50]),
        ];

        foreach ($urls as $url) {
            $response = $this->actingAs($admin)->get($url);
            $response->assertOk();
            $this->assertStringContainsString('data-page=', $response->getContent(), "{$url} did not render the Inertia root.");
            $this->assertStringNotContainsString("rel = 'prefetch'", $response->getContent(), "{$url} still prefetches every module.");
            $this->assertStringNotContainsString('rel="prefetch"', $response->getContent(), "{$url} still prefetches every module.");
        }
    }

    public function test_every_rendered_inertia_page_has_a_lazy_loadable_vue_file(): void
    {
        $components = [];
        foreach (File::allFiles(app_path('Http/Controllers')) as $file) {
            preg_match_all("/Inertia::render\\(\\s*'([^']+)'/", $file->getContents(), $matches);
            array_push($components, ...$matches[1]);
            preg_match_all("/inertiaFolder\\(\\)\\s*\\.\\s*'([^']+)'/", $file->getContents(), $folderMatches);
        }
        foreach (['DebitNotes', 'CreditNotes'] as $folder) {
            foreach (['Index', 'Form', 'Show'] as $view) {
                $components[] = "{$folder}/{$view}";
            }
        }

        $components = array_unique($components);
        $this->assertGreaterThan(60, count($components));
        foreach ($components as $component) {
            $this->assertFileExists(resource_path("js/Pages/{$component}.vue"), "Missing page component {$component}");
        }
    }

    public function test_production_build_has_a_chunk_for_every_page_and_keeps_heavy_widgets_out_of_the_layout(): void
    {
        $manifestPath = public_path('build/manifest.json');
        if (! is_file($manifestPath)) {
            $this->markTestSkipped('Run npm run build first.');
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);

        foreach (File::allFiles(resource_path('js/Pages')) as $file) {
            $key = 'resources/js/Pages/'.str_replace('\\', '/', $file->getRelativePathname());
            $this->assertArrayHasKey($key, $manifest, "No production chunk for {$key}");
            $this->assertFileExists(public_path('build/'.$manifest[$key]['file']), "Chunk file missing for {$key}");
        }

        $entry = $manifest['resources/js/app.js'];
        $this->assertTrue($entry['isEntry'] ?? false);
        $this->assertNotContains('resources/js/Pages/Reports/Show.vue', $entry['imports'] ?? [], 'Report page must not be bundled into the entry.');

        $layoutKey = collect(array_keys($manifest))->first(fn ($key) => str_contains($key, 'AdminLayout') && str_ends_with($key, '.js'));
        $this->assertNotNull($layoutKey, 'Admin layout chunk missing from manifest.');
        $layout = $manifest[$layoutKey];
        $this->assertNotContains('resources/js/Components/ErpAssistant.vue', $layout['imports'] ?? []);
        $this->assertContains('resources/js/Components/ErpAssistant.vue', $layout['dynamicImports'] ?? []);
        $this->assertContains('resources/js/Components/MenuTutorial.vue', $layout['dynamicImports'] ?? []);

        $assistant = $manifest['resources/js/Components/ErpAssistant.vue'];
        $this->assertTrue($assistant['isDynamicEntry'] ?? false);
        $this->assertNotEmpty(array_filter($assistant['dynamicImports'] ?? [], fn ($import) => str_contains($import, 'dompurify')));
    }

    /**
     * @return array<string, string>
     */
    private function partialHeaders(string $component, string $props): array
    {
        return [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => (string) app(\App\Http\Middleware\HandleInertiaRequests::class)->version(request()),
            'X-Inertia-Partial-Component' => $component,
            'X-Inertia-Partial-Data' => $props,
        ];
    }

    private function countQueries(callable $callback): int
    {
        $count = 0;
        DB::listen(function () use (&$count): void {
            $count++;
        });
        $callback();
        DB::getEventDispatcher()?->forget(\Illuminate\Database\Events\QueryExecuted::class);

        return $count;
    }

    private function projectWithBoq(): Project
    {
        $project = Project::factory()->create();
        $boq = Boq::factory()->create(['project_id' => $project->id]);
        BoqItem::factory()->count(3)->create(['boq_id' => $boq->id]);

        return $project;
    }

    private function location(Project $project, string $code, string $type): InventoryLocation
    {
        return InventoryLocation::query()->create([
            'location_code' => $code,
            'name' => $code,
            'location_type' => $type,
            'project_id' => $project->id,
            'is_active' => true,
            'is_default' => false,
            'is_system' => false,
        ]);
    }

    private function material(string $code): Material
    {
        return Material::query()->create([
            'material_code' => $code,
            'name' => 'Material '.$code,
            'unit' => 'Nos',
            'is_active' => true,
        ]);
    }

    private function entry(Project $project, Material $material, ?InventoryLocation $location, string $type, string $in, string $out): void
    {
        static $sequence = 0;
        $sequence++;

        StockLedgerEntry::query()->create([
            'project_id' => $project->id,
            'material_id' => $material->id,
            'location_id' => $location?->id,
            'transaction_number' => 'STK-T-'.$sequence,
            'transaction_date' => '2026-10-20',
            'transaction_type' => $type,
            'source_type' => 'test',
            'source_id' => $sequence,
            'source_item_id' => $sequence,
            'source_number' => 'SRC-'.$sequence,
            'stock_in' => $in,
            'stock_out' => $out,
            'balance' => '0',
        ]);
    }
}
