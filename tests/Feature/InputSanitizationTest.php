<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vendor;
use App\Services\Reports\ReportSpreadsheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class InputSanitizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_vendor_input_preserves_unicode_and_strips_control_characters(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('vendors.store'), $this->vendorProfile([
            'name' => "  Café naïve 漢字 — “quoted”\x00\x07 ",
            'notes' => "Line 1\nLine 2",
        ]))->assertRedirect();

        $vendor = Vendor::query()->firstOrFail();
        $this->assertSame('Café naïve 漢字 — “quoted”', $vendor->name);
        $this->assertSame("Line 1\nLine 2", $vendor->notes);
    }

    public function test_html_in_vendor_names_is_stored_as_plain_text(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)->post(route('vendors.store'), $this->vendorProfile([
            'name' => 'ACME <script>alert(1)</script>',
        ]))->assertRedirect();

        $this->assertDatabaseHas('vendors', [
            'name' => 'ACME <script>alert(1)</script>',
        ]);
    }

    public function test_sql_payloads_in_search_do_not_error(): void
    {
        $admin = User::factory()->admin()->create();

        $this->actingAs($admin)
            ->get('/audit-log?search='.urlencode("1' OR 1=1 --"))
            ->assertOk();
    }

    public function test_report_exports_neutralize_formula_values(): void
    {
        $response = app(ReportSpreadsheet::class)->download(
            'Vendors',
            [['key' => 'name', 'label' => 'Name']],
            [['name' => '=CMD|"/c calc"!A0']],
            filename: 'vendors.xlsx',
        );

        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        ob_start();
        $response->sendContent();
        file_put_contents($path, (string) ob_get_clean());

        $value = IOFactory::load($path)->getActiveSheet()->getCell('A4')->getValue();
        unlink($path);

        $this->assertSame("'=CMD|\"/c calc\"!A0", $value);
    }
}
