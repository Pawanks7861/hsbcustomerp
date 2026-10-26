<?php

namespace Tests\Feature;

use App\Imports\ImportEngine;
use App\Imports\Support\DuplicateMode;
use App\Models\ExpenseCategory;
use App\Models\ImportBatch;
use App\Models\Material;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ModuleImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_projects_materials_and_categories_import(): void
    {
        $user = $this->adminUser();

        $this->assertImportCreates($user, 'projects', [
            ['Project Code', 'Project Name', 'Description'],
            ['PRJ-IMP-1', 'Imported Project', 'From spreadsheet'],
        ], 'projects', ['project_code' => 'PRJ-IMP-1']);

        $this->assertImportCreates($user, 'materials', [
            ['Material Code', 'Name', 'Unit', 'Active'],
            ['MAT-IMP-1', 'Imported Cement', 'BAG', 'yes'],
        ], 'materials', ['material_code' => 'MAT-IMP-1']);

        $this->assertImportCreates($user, 'expense_categories', [
            ['Name', 'Active'],
            ['Imported Category', 'yes'],
        ], 'expense_categories', ['name' => 'Imported Category']);

        $this->assertTrue(ExpenseCategory::query()->where('name', 'Imported Category')->exists());
        $this->assertTrue(Material::query()->where('material_code', 'MAT-IMP-1')->exists());
        $this->assertTrue(Project::query()->where('project_code', 'PRJ-IMP-1')->exists());
    }

    public function test_restricted_modules_show_guidance_page(): void
    {
        $user = $this->adminUser();

        $this->actingAs($user)
            ->get(route('imports.show', 'grn'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Imports/Restricted'));
    }

    /**
     * @param  list<list<string>>  $rows
     * @param  array<string, mixed>  $assertion
     */
    private function assertImportCreates(User $user, string $module, array $rows, string $table, array $assertion): void
    {
        $path = $this->makeXlsx($rows);
        $preview = app(ImportEngine::class)->preview(
            $module,
            new UploadedFile($path, $module.'.xlsx', null, null, true),
            $user,
            DuplicateMode::CREATE_ONLY,
        );

        $this->assertTrue($preview['can_confirm'], $preview['message'] ?? 'preview failed');
        $result = app(ImportEngine::class)->confirm(ImportBatch::query()->findOrFail($preview['batch']['id']), $user);
        $this->assertTrue($result['ok'], $result['message'] ?? 'confirm failed');
        $this->assertDatabaseHas($table, $assertion);
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function makeXlsx(array $rows): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray($rows);
        $path = tempnam(sys_get_temp_dir(), 'mod').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }

    private function adminUser(): User
    {
        $user = User::factory()->admin()->create();
        $role = Role::query()->firstOrCreate(['slug' => 'super_admin'], ['name' => 'Super Admin', 'is_active' => true]);
        foreach (Permissions::all() as $permission) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $role->id,
                'permission' => $permission,
            ]);
        }
        $user->roles()->syncWithoutDetaching([$role->id]);

        return $user;
    }
}
