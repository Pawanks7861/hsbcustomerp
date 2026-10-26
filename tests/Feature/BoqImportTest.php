<?php

namespace Tests\Feature;

use App\Models\Boq;
use App\Models\BoqItem;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Inertia\Testing\AssertableInertia as Assert;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Tests\TestCase;

class BoqImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_template_download_uses_the_required_columns(): void
    {
        $user = User::factory()->admin()->create();
        [$project, $boq] = $this->boq();

        $response = $this->actingAs($user)
            ->get(route('projects.boqs.template', [$project, $boq]));

        $response->assertOk();
        $response->assertDownload('BOQ-Import-Template.xlsx');

        $path = storage_path('framework/testing/template-check.xlsx');
        $this->ensureTestingDirectory();
        file_put_contents($path, $response->streamedContent());
        $sheet = IOFactory::load($path)->getSheet(0);

        $this->assertSame('BOQ', $sheet->getTitle());
        $this->assertSame([
            'Sr. No.',
            'Service Code',
            'Item Description',
            'Unit',
            'B.O.Q.',
            'Rate',
            'BOQ Amount',
        ], [
            $sheet->getCell('A1')->getValue(),
            $sheet->getCell('B1')->getValue(),
            $sheet->getCell('C1')->getValue(),
            $sheet->getCell('D1')->getValue(),
            $sheet->getCell('E1')->getValue(),
            $sheet->getCell('F1')->getValue(),
            $sheet->getCell('G1')->getValue(),
        ]);
        $this->assertSame('=E2*F2', $sheet->getCell('G2')->getValue());
        $this->assertSame('=E3*F3', $sheet->getCell('G3')->getValue());
    }

    public function test_reference_workbook_is_previewed_and_then_imported(): void
    {
        $user = User::factory()->admin()->create();
        [$project, $boq] = $this->boq();
        $description = "SPLY&APLY, PUTTY, CEMENT, 2 COATS\nLine text: Providing and Applying cement putty in two coats across the complete surface.";

        $file = $this->workbook(function (Worksheet $sheet) use ($description): void {
            $sheet->setCellValue('A1', 'Landscape Works');
            $sheet->fromArray([
                ['Sr. No.', 'Service Code', 'Items Description', 'Unit', 'B.O.Q.', 'Rate (Rs.)', 'BOQ Amount'],
            ], null, 'A3');
            $sheet->setCellValueExplicit('A4', '10', DataType::TYPE_STRING);
            $sheet->setCellValueExplicit('B4', '90130546', DataType::TYPE_STRING);
            $sheet->setCellValue('C4', $description);
            $sheet->setCellValue('D4', 'SQM');
            $sheet->setCellValue('E4', 7536);
            $sheet->setCellValue('F4', 110);
            $sheet->setCellValue('G4', 1);
            $sheet->fromArray([
                ['40', '99992878', 'Second finish item', 'SQM', 5408, '205.05', 1],
            ], null, 'A6');
            $sheet->setCellValue('B7', '10');
            $sheet->setCellValue('C7', 'Landscape and Basement Finishes Bapunagar.');
            $sheet->fromArray([
                [null, null, 'Grand Total', null, null, null, 999999],
                [null, null, 'GST @ 18%', null, null, null, 100],
                [null, null, 'Total Amount', null, null, null, 50],
            ], null, 'A9');
        });

        $this->actingAs($user)
            ->post(route('projects.boqs.import.preview', [$project, $boq]), ['file' => $file])
            ->assertRedirect(route('projects.boqs.import', [$project, $boq]));

        $this->assertSame(0, $boq->items()->count());

        $this->actingAs($user)
            ->get(route('projects.boqs.import', [$project, $boq]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Boqs/Import')
                ->where('preview.can_confirm', true)
                ->where('preview.rows.0.status', 'valid')
                ->where('preview.rows.0.sr_no', '10')
                ->where('preview.rows.0.service_code', '90130546')
                ->where('preview.rows.0.description', $description)
                ->where('preview.rows.0.unit', 'SQM')
                ->where('preview.rows.0.quantity', '7536.000')
                ->where('preview.rows.0.rate', '110.00')
                ->where('preview.rows.0.amount', '828960.00')
                ->where('preview.rows.1.rate', '205.05')
                ->where('preview.rows.1.quantity', '5408.000')
                ->where('preview.rows.1.amount', '1108910.40')
                ->where('preview.rows.2.status', 'header')
                ->where('preview.rows.2.service_code', '10')
                ->where('preview.rows.2.description', 'Landscape and Basement Finishes Bapunagar.')
                ->where('preview.rows.2.amount', null)
                ->has('preview.rows', 3)
            );

        $this->actingAs($user)
            ->post(route('projects.boqs.import.confirm', [$project, $boq]), [
                'project_id' => 999,
                'boq_id' => 999,
                'items' => [[
                    'description' => 'Should not be used',
                    'amount' => '1.00',
                ]],
            ])
            ->assertRedirect(route('projects.boqs.show', [$project, $boq]));

        $items = $boq->items()->orderBy('sort_order')->get();
        $this->assertCount(3, $items);
        $this->assertSame($boq->id, $items[0]->boq_id);
        $this->assertSame($project->id, $items[0]->boq->project_id);
        $this->assertSame('90130546', $items[0]->service_code);
        $this->assertSame('10', $items[0]->item_number);
        $this->assertSame($description, $items[0]->description);
        $this->assertSame('7536.000', (string) $items[0]->quantity);
        $this->assertSame('110.00', (string) $items[0]->rate);
        $this->assertSame('828960.00', (string) $items[0]->amount);
        $this->assertFalse($items[0]->is_section);
        $this->assertSame('1108910.40', (string) $items[1]->amount);
        $this->assertSame('205.05', (string) $items[1]->rate);
        $this->assertTrue($items[2]->is_section);
        $this->assertNull($items[2]->amount);
        $this->assertNull($items[2]->quantity);
        $this->assertSame('Landscape and Basement Finishes Bapunagar.', $items[2]->description);
    }

    public function test_invalid_rows_block_confirm_and_are_shown_in_preview(): void
    {
        $user = User::factory()->admin()->create();
        [$project, $boq] = $this->boq();

        $file = $this->workbook(function (Worksheet $sheet): void {
            $sheet->fromArray([
                ['Sr. No.', 'Service Code', 'Item Description', 'Unit', 'B.O.Q.', 'Rate', 'BOQ Amount'],
                ['1', '100', 'Valid looking row', 'SQM', 'abc', 10, 10],
                ['2', '200', 'Bad rate', 'SQM', 5, 'bad-rate', 10],
                ['3', '300', '', 'SQM', 5, 10, 50],
            ], null, 'A1');
        });

        $this->actingAs($user)
            ->post(route('projects.boqs.import.preview', [$project, $boq]), ['file' => $file])
            ->assertRedirect();

        $this->actingAs($user)
            ->get(route('projects.boqs.import', [$project, $boq]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('preview.can_confirm', false)
                ->where('preview.rows.0.errors.0', 'B.O.Q. must be numeric.')
                ->where('preview.rows.1.errors.0', 'Rate must be numeric.')
                ->where('preview.rows.2.errors.0', 'Item Description is missing.')
            );

        $this->actingAs($user)
            ->post(route('projects.boqs.import.confirm', [$project, $boq]))
            ->assertSessionHasErrors('import');

        $this->assertSame(0, $boq->items()->count());
    }

    public function test_import_appends_to_an_existing_boq(): void
    {
        $user = User::factory()->admin()->create();
        [$project, $boq] = $this->boq();
        $existing = BoqItem::factory()->create([
            'boq_id' => $boq->id,
            'item_number' => '1',
            'description' => 'Existing item',
            'sort_order' => 0,
        ]);

        $file = $this->workbook(function (Worksheet $sheet): void {
            $sheet->fromArray([
                ['Sr. No.', 'Service Code', 'Description', 'Unit', 'Quantity', 'Unit Rate', 'Amount'],
                ['20', '555', 'Imported item', 'Nos', 2, 25, 1],
            ], null, 'A1');
        });

        $this->actingAs($user)
            ->post(route('projects.boqs.import.preview', [$project, $boq]), ['file' => $file])
            ->assertRedirect();

        $this->actingAs($user)
            ->get(route('projects.boqs.import', [$project, $boq]))
            ->assertInertia(fn (Assert $page) => $page->where('existingCount', 1));

        $this->actingAs($user)
            ->post(route('projects.boqs.import.confirm', [$project, $boq]))
            ->assertRedirect();

        $items = $boq->items()->orderBy('sort_order')->get();
        $this->assertCount(2, $items);
        $this->assertSame($existing->id, $items[0]->id);
        $this->assertSame('Existing item', $items[0]->description);
        $this->assertSame('Imported item', $items[1]->description);
        $this->assertSame('50.00', (string) $items[1]->amount);
        $this->assertSame(1, $items[1]->sort_order);
    }

    public function test_import_cannot_target_another_project_or_boq(): void
    {
        $user = User::factory()->admin()->create();
        [$project, $boq] = $this->boq();
        $other = Project::factory()->create();
        $otherBoq = Boq::factory()->create(['project_id' => $project->id, 'title' => 'Other']);

        $file = $this->workbook(function (Worksheet $sheet): void {
            $sheet->fromArray([
                ['Sr. No.', 'Service Code', 'Item Description', 'Unit', 'BOQ Qty', 'Rate Rs.', 'Total Amount'],
                ['10', '901', 'Cross project item', 'SQM', 3, 10, 999],
            ], null, 'A1');
        });

        $this->actingAs($user)
            ->post(route('projects.boqs.import.preview', [$other, $boq]), ['file' => $file])
            ->assertNotFound();

        $this->actingAs($user)
            ->post(route('projects.boqs.import.preview', [$project, $boq]), ['file' => $file])
            ->assertRedirect();

        $this->actingAs($user)
            ->post(route('projects.boqs.import.confirm', [$project, $otherBoq]))
            ->assertSessionHasErrors('import');

        $this->assertSame(0, $boq->items()->count());
        $this->assertSame(0, $otherBoq->items()->count());

        $this->actingAs($user)
            ->post(route('projects.boqs.import.confirm', [$project, $boq]))
            ->assertRedirect();

        $this->assertSame(1, $boq->items()->count());
        $this->assertSame('30.00', (string) $boq->items()->first()->amount);
        $this->assertSame(0, $otherBoq->items()->count());
    }

    public function test_reuploaded_template_recalculates_amount_and_keeps_the_section_example(): void
    {
        $user = User::factory()->admin()->create();
        [$project, $boq] = $this->boq();

        $download = $this->actingAs($user)
            ->get(route('projects.boqs.template', [$project, $boq]));
        $path = storage_path('framework/testing/template-reupload.xlsx');
        $this->ensureTestingDirectory();
        file_put_contents($path, $download->streamedContent());

        $this->actingAs($user)
            ->post(route('projects.boqs.import.preview', [$project, $boq]), [
                'file' => new UploadedFile($path, 'BOQ-Import-Template.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
            ])->assertRedirect();

        $this->actingAs($user)
            ->post(route('projects.boqs.import.confirm', [$project, $boq]))
            ->assertRedirect();

        $items = $boq->items()->orderBy('sort_order')->get();
        $this->assertCount(3, $items);
        $this->assertSame('11000.00', (string) $items[0]->amount);
        $this->assertSame('7500.00', (string) $items[1]->amount);
        $this->assertTrue($items[2]->is_section);
        $this->assertSame('Landscape and Basement Finishes', $items[2]->description);
        $this->assertNull($items[2]->amount);
    }

    /**
     * @return array{0: Project, 1: Boq}
     */
    private function boq(): array
    {
        $project = Project::factory()->create();
        $boq = Boq::factory()->create([
            'project_id' => $project->id,
            'gst_percentage' => 0,
        ]);

        return [$project, $boq];
    }

    private function workbook(callable $build): UploadedFile
    {
        $spreadsheet = new Spreadsheet;
        $build($spreadsheet->getActiveSheet());
        $this->ensureTestingDirectory();
        $path = storage_path('framework/testing/'.uniqid('boq', true).'.xlsx');
        IOFactory::createWriter($spreadsheet, 'Xlsx')->save($path);

        return new UploadedFile(
            $path,
            'boq.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );
    }

    private function ensureTestingDirectory(): void
    {
        $directory = storage_path('framework/testing');

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }
    }
}
