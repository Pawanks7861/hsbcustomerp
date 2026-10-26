<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_changes_are_audited_with_before_and_after_values(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $vendor = Vendor::factory()->create(['name' => 'Old Name', 'vendor_code' => 'VEN-AUD']);
        $vendor->update(['name' => 'New Name']);
        $vendor->touch();
        $vendor->delete();

        $logs = ActivityLog::query()->where('subject_type', Vendor::class)->where('subject_id', $vendor->id)->orderBy('id')->get();

        $this->assertSame(['vendor.created', 'vendor.updated', 'vendor.deleted'], $logs->pluck('action')->all());
        $this->assertSame(['created', 'updated', 'deleted'], $logs->pluck('event')->all());
        $this->assertTrue($logs->every(fn (ActivityLog $log) => $log->user_id === $admin->id));
        $this->assertSame('Old Name', $logs[0]->properties['attributes']['name']);
        $this->assertSame(['name' => 'Old Name'], $logs[1]->properties['old']);
        $this->assertSame(['name' => 'New Name'], $logs[1]->properties['new']);
        $this->assertSame('VEN-AUD · New Name', $logs[2]->subject_label);
    }

    public function test_secrets_are_masked_and_logins_are_audited(): void
    {
        $user = User::factory()->create(['email' => 'site@hsb.local', 'password' => 'password']);
        $user->update(['password' => 'changed-secret']);

        $change = ActivityLog::query()->where('action', 'user.updated')->latest('id')->firstOrFail();
        $this->assertSame('[hidden]', $change->properties['new']['password']);
        $this->assertSame('[hidden]', $change->properties['old']['password']);

        $this->post('/login', ['email' => 'site@hsb.local', 'password' => 'wrong'])->assertSessionHasErrors();
        $failed = ActivityLog::query()->where('event', ActivityLog::EVENT_LOGIN_FAILED)->firstOrFail();
        $this->assertSame('site@hsb.local', $failed->properties['email']);
        $this->assertNotNull($failed->ip_address);

        $this->post('/login', ['email' => 'site@hsb.local', 'password' => 'changed-secret'])->assertRedirect();
        $login = ActivityLog::query()->where('event', ActivityLog::EVENT_LOGIN)->firstOrFail();
        $this->assertSame($user->id, $login->user_id);
        $this->assertSame('POST', $login->method);
        $this->assertFalse(ActivityLog::query()->where('action', 'user.updated')->where('id', '>', $change->id)->exists());
    }

    public function test_only_admins_can_open_the_audit_log_and_filters_apply(): void
    {
        $admin = User::factory()->admin()->create();
        $staff = User::factory()->create();
        $this->actingAs($admin);
        $vendor = Vendor::factory()->create();
        $vendor->delete();

        $this->actingAs($staff)->get('/audit-log')->assertForbidden();

        $this->actingAs($admin)
            ->get('/audit-log?event=deleted')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('AuditLog/Index')
                ->where('logs.total', 1)
                ->where('logs.data.0.action', 'vendor.deleted')
                ->where('logs.data.0.user', $admin->name));

        $batch = ActivityLog::query()->where('action', 'vendor.created')->value('batch_id');
        $this->actingAs($admin)
            ->get('/audit-log?batch='.$batch)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('filters.batch', $batch));
    }
}
