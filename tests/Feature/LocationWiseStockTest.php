<?php

namespace Tests\Feature;

use App\Imports\Definitions\StockAdjustmentsImport;
use App\Imports\ImportEngine;
use App\Models\ImportBatch;
use App\Models\InventoryLocation;
use App\Models\Material;
use App\Models\Project;
use App\Models\Role;
use App\Models\StockAdjustment;
use App\Models\StockLedgerEntry;
use App\Models\User;
use App\Services\InventoryStock;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class LocationWiseStockTest extends TestCase
{
    use RefreshDatabase;

    public function test_opening_stock_can_be_recorded_at_two_locations(): void
    {
        [$admin, $project, $material, $main, $site] = $this->catalog();

        $this->actingAs($admin)->post(route('projects.inventory.adjustments.store', $project), [
            'material_id' => $material->id,
            'location_id' => $main->id,
            'adjustment_date' => '2026-10-24',
            'adjustment_type' => 'opening',
            'quantity' => 250,
            'reference_number' => 'OS-MAIN',
            'reason' => 'Existing main store bags',
        ])->assertRedirect();

        $mainAdj = StockAdjustment::query()->where('location_id', $main->id)->firstOrFail();
        $this->actingAs($admin)->post(route('projects.inventory.adjustments.post', [$project, $mainAdj]))->assertRedirect();

        $this->actingAs($admin)->post(route('projects.inventory.adjustments.store', $project), [
            'material_id' => $material->id,
            'location_id' => $site->id,
            'adjustment_date' => '2026-10-24',
            'adjustment_type' => 'opening',
            'quantity' => 75,
            'reason' => 'Existing site bags',
        ])->assertRedirect();

        $siteAdj = StockAdjustment::query()->where('location_id', $site->id)->firstOrFail();
        $this->actingAs($admin)->post(route('projects.inventory.adjustments.post', [$project, $siteAdj]))->assertRedirect();

        $stock = app(InventoryStock::class);
        $this->assertSame('250.000', $stock->locationBalance($project->id, $material->id, $main->id));
        $this->assertSame('75.000', $stock->locationBalance($project->id, $material->id, $site->id));
        $this->assertSame('325.000', $stock->balance($project->id, $material->id));

        $this->assertDatabaseHas('stock_ledger_entries', [
            'material_id' => $material->id,
            'location_id' => $main->id,
            'transaction_type' => StockLedgerEntry::ADJUSTMENT_IN,
            'source_number' => $mainAdj->adjustment_number,
        ]);

        $this->actingAs($admin)
            ->get(route('projects.inventory.stock', $project))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Inventory/Stock')
                ->has('rows.data', 2)
                ->where('rows.data.0.available', '250.000')
                ->where('rows.data.1.available', '75.000')
                ->where('rows.data.0.total_available', '325.000'));
    }

    public function test_invalid_and_unauthorized_locations_are_rejected(): void
    {
        [$admin, $project, $material, $main] = $this->catalog();
        $other = Project::factory()->create();
        $foreign = InventoryLocation::query()->where('project_id', $other->id)->where('is_default', true)->firstOrFail();

        $this->actingAs($admin)->post(route('projects.inventory.adjustments.store', $project), [
            'material_id' => $material->id,
            'location_id' => $foreign->id,
            'adjustment_date' => '2026-10-24',
            'adjustment_type' => 'opening',
            'quantity' => 10,
            'reason' => 'Wrong project store',
        ])->assertSessionHasErrors('location_id');

        $this->actingAs($admin)->post(route('projects.inventory.adjustments.store', $project), [
            'material_id' => $material->id,
            'location_id' => 999999,
            'adjustment_date' => '2026-10-24',
            'adjustment_type' => 'opening',
            'quantity' => 10,
            'reason' => 'Missing store',
        ])->assertSessionHasErrors('location_id');

        $this->assertSame(0, StockAdjustment::query()->count());
        $this->assertSame('0.000', app(InventoryStock::class)->locationBalance($project->id, $material->id, $main->id));
    }

    public function test_importing_location_stock_posts_ledger_without_duplicating_items(): void
    {
        [$admin, $project, $material, $main, $site] = $this->catalog();

        $path = $this->makeXlsx([
            ['Material Code', 'Item Name', 'Unit', 'Location', 'Opening Quantity', 'Entry Date', 'Reference Number', 'Remarks'],
            [$material->material_code, $material->name, $material->unit, $main->name, '250', '2026-10-24', 'IMP-1', 'Main store count'],
            [$material->material_code, $material->name, $material->unit, $site->location_code, '75', '2026-10-24', 'IMP-2', 'Site count'],
        ]);

        $preview = app(ImportEngine::class)->preview(
            'stock_adjustments',
            new UploadedFile($path, 'stock.xlsx', null, null, true),
            $admin,
            StockAdjustmentsImport::MODE_OPENING,
            $project,
        );

        $this->assertTrue($preview['can_confirm'], $preview['message'] ?? 'preview failed');
        $this->assertSame(2, $preview['counts']['valid']);
        $result = app(ImportEngine::class)->confirm(ImportBatch::query()->findOrFail($preview['batch']['id']), $admin, $project);
        $this->assertTrue($result['ok'], $result['message'] ?? 'confirm failed');

        $this->assertSame(1, Material::query()->where('name', $material->name)->count());
        $stock = app(InventoryStock::class);
        $this->assertSame('250.000', $stock->locationBalance($project->id, $material->id, $main->id));
        $this->assertSame('75.000', $stock->locationBalance($project->id, $material->id, $site->id));
        $this->assertSame('325.000', $stock->balance($project->id, $material->id));
        $this->assertSame(2, StockLedgerEntry::query()->where('transaction_type', StockLedgerEntry::ADJUSTMENT_IN)->count());

        $retry = app(ImportEngine::class)->preview(
            'stock_adjustments',
            new UploadedFile($path, 'stock.xlsx', null, null, true),
            $admin,
            StockAdjustmentsImport::MODE_OPENING,
            $project,
        );
        $this->assertSame(2, $retry['counts']['duplicate']);
        $this->assertSame(0, $retry['counts']['importable']);
        $this->assertSame('325.000', $stock->balance($project->id, $material->id));
    }

    public function test_import_rejects_unknown_location_invalid_quantity_and_file_duplicates(): void
    {
        [$admin, $project, $material, $main] = $this->catalog();

        $path = $this->makeXlsx([
            ['Material Code', 'Item Name', 'Unit', 'Location', 'Opening Quantity'],
            [$material->material_code, $material->name, $material->unit, 'Not A Real Store', '10'],
            [$material->material_code, $material->name, $material->unit, $main->name, '-4'],
            [$material->material_code, $material->name, 'Litre', $main->name, '8'],
            [$material->material_code, $material->name, $material->unit, $main->name, '8'],
            [$material->material_code, $material->name, $material->unit, $main->name, '3'],
        ]);

        $preview = app(ImportEngine::class)->preview(
            'stock_adjustments',
            new UploadedFile($path, 'bad.xlsx', null, null, true),
            $admin,
            StockAdjustmentsImport::MODE_OPENING,
            $project,
        );

        $this->assertFalse($preview['can_confirm']);
        $messages = collect($preview['rows'])->flatMap(fn ($row) => $row['errors'])->implode(' ');
        $this->assertStringContainsString('Location was not found', $messages);
        $this->assertStringContainsString('greater than zero', $messages);
        $this->assertStringContainsString('Unit does not match', $messages);
        $this->assertStringContainsString('Duplicate row for this item and location', $messages);
        $this->assertSame(0, StockAdjustment::query()->count());
    }

    public function test_failed_import_does_not_leave_partial_stock(): void
    {
        [$admin, $project, $material, $main] = $this->catalog();

        $path = $this->makeXlsx([
            ['Material Code', 'Item Name', 'Unit', 'Location', 'Opening Quantity', 'Adjustment Type'],
            [$material->material_code, $material->name, $material->unit, $main->name, '10', 'increase'],
            [$material->material_code, $material->name, $material->unit, $main->name, '9999', 'decrease'],
        ]);

        $preview = app(ImportEngine::class)->preview(
            'stock_adjustments',
            new UploadedFile($path, 'partial.xlsx', null, null, true),
            $admin,
            StockAdjustmentsImport::MODE_ADJUSTMENT,
            $project,
        );
        $this->assertTrue($preview['can_confirm'], $preview['message'] ?? 'preview failed');

        $result = app(ImportEngine::class)->confirm(ImportBatch::query()->findOrFail($preview['batch']['id']), $admin, $project);
        $this->assertFalse($result['ok']);
        $this->assertSame(0, StockAdjustment::query()->count());
        $this->assertSame(0, StockLedgerEntry::query()->count());
        $this->assertSame('0.000', app(InventoryStock::class)->balance($project->id, $material->id));
    }

    public function test_csv_and_excel_templates_include_location_fields_and_live_locations(): void
    {
        [$admin, $project, $material, $main, $site] = $this->catalog();

        $excel = $this->actingAs($admin)->get(route('imports.template', [
            'module' => 'stock_adjustments',
            'project_id' => $project->id,
        ]));
        $excel->assertOk();
        $excelPath = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        file_put_contents($excelPath, $excel->streamedContent());
        $workbook = IOFactory::load($excelPath);

        $this->assertSame('Inventory Import', $workbook->getSheet(0)->getTitle());
        $this->assertSame('Available Locations', $workbook->getSheet(1)->getTitle());
        $headers = $workbook->getSheet(0)->rangeToArray('A1:J1')[0];
        $this->assertTrue(collect($headers)->contains(fn ($h) => str_contains((string) $h, 'Location')));
        $this->assertTrue(collect($headers)->contains(fn ($h) => str_contains((string) $h, 'Opening Quantity')));

        $locationNames = collect($workbook->getSheet(1)->toArray())
            ->skip(1)
            ->pluck(0)
            ->filter()
            ->values();
        $this->assertTrue($locationNames->contains($main->name));
        $this->assertTrue($locationNames->contains($site->name));

        $locationCol = collect($headers)->search(fn ($h) => str_contains((string) $h, 'Location'));
        $letter = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($locationCol + 1);
        $validation = $workbook->getSheet(0)->getDataValidation($letter.'2');
        $this->assertSame(\PhpOffice\PhpSpreadsheet\Cell\DataValidation::TYPE_LIST, $validation->getType());
        $this->assertStringContainsString('Available Locations', (string) $validation->getFormula1());

        $csv = $this->actingAs($admin)->get(route('imports.template', [
            'module' => 'stock_adjustments',
            'format' => 'csv',
            'project_id' => $project->id,
        ]));
        $csv->assertOk();
        $csvBody = $csv->streamedContent();
        $this->assertStringContainsString('Location', $csvBody);
        $this->assertStringContainsString('Opening Quantity', $csvBody);
        $this->assertStringContainsString($main->name, $csvBody);
    }

    public function test_user_without_import_permission_cannot_import_stock(): void
    {
        [$admin, $project] = $this->catalog();
        $requester = User::factory()->withRoles('purchase_requester')->create(['project_access' => 'all']);

        $this->actingAs($requester)
            ->get(route('imports.show', ['module' => 'stock_adjustments', 'project_id' => $project->id]))
            ->assertForbidden();

        $this->actingAs($requester)
            ->get(route('imports.template', ['module' => 'stock_adjustments', 'project_id' => $project->id]))
            ->assertForbidden();
    }

    public function test_grn_history_still_posts_after_opening_stock(): void
    {
        [$admin, $project, $material, $main] = $this->catalog();

        $this->actingAs($admin)->post(route('projects.inventory.adjustments.store', $project), [
            'material_id' => $material->id,
            'location_id' => $main->id,
            'adjustment_date' => '2026-10-24',
            'adjustment_type' => 'opening',
            'quantity' => 5,
            'reason' => 'Opening',
        ])->assertRedirect();
        $adjustment = StockAdjustment::query()->firstOrFail();
        $this->actingAs($admin)->post(route('projects.inventory.adjustments.post', [$project, $adjustment]))->assertRedirect();

        $this->assertSame('5.000', app(InventoryStock::class)->locationBalance($project->id, $material->id, $main->id));
        $this->assertNotNull(StockLedgerEntry::query()->where('source_type', 'stock_adjustment')->first());
    }

    /**
     * @return array{0: User, 1: Project, 2: Material, 3: InventoryLocation, 4: InventoryLocation}
     */
    private function catalog(): array
    {
        $admin = User::factory()->admin()->create();
        $role = Role::query()->firstOrCreate(['slug' => 'super_admin'], ['name' => 'Super Admin', 'is_active' => true]);
        foreach (Permissions::all() as $permission) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $role->id,
                'permission' => $permission,
            ]);
        }
        $admin->roles()->syncWithoutDetaching([$role->id]);

        $project = Project::factory()->create(['project_name' => 'Site One']);
        $material = Material::query()->create([
            'material_code' => 'MAT-CEM-01',
            'name' => 'Cement OPC 53',
            'unit' => 'Bag',
            'is_active' => true,
        ]);
        $main = InventoryLocation::query()->where('project_id', $project->id)->where('is_default', true)->firstOrFail();
        $main->update(['name' => 'Main Store']);
        $site = InventoryLocation::query()->create([
            'location_code' => 'SITE-A-01',
            'name' => 'Site Store A',
            'location_type' => InventoryLocation::SITE,
            'project_id' => $project->id,
            'is_active' => true,
            'is_default' => false,
            'is_system' => false,
        ]);

        return [$admin, $project, $material, $main, $site];
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function makeXlsx(array $rows): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray($rows);
        $path = tempnam(sys_get_temp_dir(), 'stk').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }
}
