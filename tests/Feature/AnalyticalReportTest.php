<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesVendorBilling;
use Tests\TestCase;

class AnalyticalReportTest extends TestCase
{
    use CreatesVendorBilling;
    use RefreshDatabase;

    public function test_admin_can_view_project_summary_with_real_data(): void
    {
        $admin = User::factory()->admin()->create();
        $vendor = Vendor::factory()->create();
        [$project] = $this->approvedOrder($admin, $vendor);

        $response = $this->actingAs($admin)->get(route('reports.show', ['report' => 'project-summary']));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Reports/Show')
            ->has('rows.data', 1)
            ->where('rows.data.0.project_id', $project->id)
            ->where('rows.data.0.po_total', fn ($value) => bccomp((string) $value, '0.00', 2) === 1)
            ->where('rows.per_page', 30)
        );
    }

    public function test_user_without_reports_view_gets_forbidden(): void
    {
        $user = User::factory()->withRoles('purchase_requester')->create();

        $this->actingAs($user)->get(route('reports.show', ['report' => 'project-summary']))->assertForbidden();
    }

    public function test_excel_export_returns_spreadsheet_when_export_granted(): void
    {
        $admin = User::factory()->admin()->create();
        $user = User::factory()->withRoles('accounts')->create();
        $vendor = Vendor::factory()->create();
        $this->approvedOrder($admin, $vendor);

        $response = $this->actingAs($user)->get(route('reports.export.excel', ['report' => 'project-summary']));

        $response->assertOk();
        $this->assertStringContainsString(
            'spreadsheet',
            (string) $response->headers->get('content-type')
        );
        $this->assertStringContainsString(
            'attachment',
            (string) $response->headers->get('content-disposition')
        );
    }
}
