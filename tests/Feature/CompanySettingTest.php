<?php

namespace Tests\Feature;

use App\Models\CompanySetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CompanySettingTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admin_can_update_company_logo_and_favicon(): void
    {
        Storage::fake('public');

        $admin = User::factory()->admin()->create();
        $user = User::factory()->create();

        $this->actingAs($user)->get('/settings/company')->assertForbidden();
        $this->actingAs($user)->post('/settings/company', [
            'company_name' => 'Blocked',
        ])->assertForbidden();

        $logo = UploadedFile::fake()->image('logo.png');
        $favicon = UploadedFile::fake()->image('favicon.png', 32, 32);

        $this->actingAs($admin)->post('/settings/company', [
            'company_name' => 'HSB Construction',
            'city' => 'Ahmedabad',
            'gst_number' => '24AAAAA0000A1Z5',
            'logo' => $logo,
            'favicon' => $favicon,
        ])->assertRedirect('/settings/company');

        $company = CompanySetting::query()->firstOrFail();
        $this->assertSame('HSB Construction', $company->company_name);
        $this->assertStringStartsWith('company/', $company->logo_path);
        $this->assertStringStartsWith('company/', $company->favicon_path);
        Storage::disk('public')->assertExists($company->logo_path);
        Storage::disk('public')->assertExists($company->favicon_path);

        $unsafe = UploadedFile::fake()->createWithContent(
            'icon.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'
        );

        $this->actingAs($admin)->post('/settings/company', [
            'company_name' => 'HSB Construction',
            'favicon' => $unsafe,
        ])->assertSessionHasErrors('favicon');

        $safe = UploadedFile::fake()->createWithContent(
            'icon.svg',
            '<svg xmlns="http://www.w3.org/2000/svg"></svg>'
        );

        $this->actingAs($admin)->post('/settings/company', [
            'company_name' => 'HSB Construction',
            'favicon' => $safe,
        ])->assertSessionDoesntHaveErrors();

        $replaced = CompanySetting::query()->firstOrFail();
        $this->assertStringEndsWith('.svg', $replaced->favicon_path);
        Storage::disk('public')->assertMissing($company->favicon_path);
        Storage::disk('public')->assertExists($replaced->logo_path);
    }

    public function test_guest_login_and_sidebar_receive_safe_branding_urls(): void
    {
        Storage::fake('public');

        $admin = User::factory()->admin()->create();
        $logo = UploadedFile::fake()->image('logo.png');
        $favicon = UploadedFile::fake()->image('favicon.png', 32, 32);

        $this->actingAs($admin)->post('/settings/company', [
            'company_name' => 'HSB Construction',
            'logo' => $logo,
            'favicon' => $favicon,
        ])->assertRedirect();

        $company = CompanySetting::query()->firstOrFail();
        $this->assertNotNull($company->logoPath());
        $this->assertStringStartsWith('/storage/company/', $company->logoUrl());
        $this->assertStringContainsString('?v=', $company->faviconUrl());
        $this->assertSame('image/png', $company->faviconType());

        auth()->logout();

        $this->get('/login')
            ->assertOk()
            ->assertSee('rel="icon"', false)
            ->assertSee('/storage/'.$company->favicon_path.'?v=', false)
            ->assertInertia(fn (Assert $page) => $page
                ->component('Auth/Login')
                ->where('company.company_name', 'HSB Construction')
                ->where('company.logo_url', $company->logoUrl())
                ->where('company.favicon_url', $company->faviconUrl())
                ->missing('company.address')
                ->missing('company.gst_number')
            );

        $this->actingAs($admin)->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('company.logo_url', $company->logoUrl())
                ->where('company.company_name', 'HSB Construction')
                ->missing('company.email')
            );
    }

    public function test_missing_uploaded_favicon_uses_the_default_icon(): void
    {
        Storage::fake('public');

        CompanySetting::query()->create([
            'company_name' => 'Plain Company',
        ]);

        $link = CompanySetting::faviconLink();

        $this->assertSame('/favicon.ico', $link['href']);
        $this->assertNull(CompanySetting::current()->logoUrl());

        $this->get('/login')
            ->assertOk()
            ->assertSee('href="/favicon.ico"', false)
            ->assertDontSee('/storage/company/', false);
    }
}
