<?php

namespace Tests\Feature;

use App\Imports\ImportEngine;
use App\Imports\Support\DuplicateMode;
use App\Models\User;
use App\Models\Vendor;
use App\Support\VendorRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class VendorProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_incomplete_vendor_creation_is_rejected(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)
            ->post(route('vendors.store'), ['name' => 'Incomplete Co'])
            ->assertSessionHasErrors(array_values(array_diff(VendorRules::requiredProfileFields(), ['name'])));

        $this->assertSame(0, Vendor::query()->count());
    }

    public function test_complete_vendor_creation_succeeds(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)
            ->post(route('vendors.store'), $this->vendorProfile(['name' => 'Complete Co']))
            ->assertRedirect();

        $vendor = Vendor::query()->firstOrFail();
        $this->assertTrue(VendorRules::profileIsComplete($vendor));
    }

    public function test_invalid_formats_are_rejected(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user)
            ->post(route('vendors.store'), $this->vendorProfile([
                'email' => 'not-an-email',
                'phone' => '123',
                'gst_number' => 'BADGST',
                'pan_number' => 'PAN',
                'ifsc' => 'IFSC',
                'pincode' => '12',
                'account_number' => '12',
            ]))
            ->assertSessionHasErrors(['email', 'phone', 'gst_number', 'pan_number', 'ifsc', 'pincode', 'account_number']);
    }

    public function test_import_cannot_bypass_required_fields(): void
    {
        $user = User::factory()->admin()->create();
        $path = $this->spreadsheet([
            ['Vendor Code', 'Name', 'Party Type', 'Phone'],
            ['V-INC', 'Imported Incomplete', 'vendor', '9999999999'],
        ]);

        $preview = app(ImportEngine::class)->preview(
            'vendors',
            new UploadedFile($path, 'incomplete.xlsx', null, null, true),
            $user,
            DuplicateMode::CREATE_ONLY,
        );

        $this->assertFalse($preview['can_confirm']);
        $this->assertGreaterThan(0, $preview['counts']['invalid']);
        $this->assertSame(0, Vendor::query()->count());
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function spreadsheet(array $rows): string
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->getActiveSheet()->fromArray($rows);
        $path = sys_get_temp_dir().'/vendor-profile-'.uniqid().'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return $path;
    }
}
