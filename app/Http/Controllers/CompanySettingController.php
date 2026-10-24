<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateCompanySettingRequest;
use App\Models\CompanySetting;
use App\Support\StoredUpload;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class CompanySettingController extends Controller
{
    public function edit(): Response
    {
        $company = CompanySetting::current();

        return Inertia::render('Settings/Company', [
            'company' => $company ? [
                'company_name' => $company->company_name,
                'address' => $company->address,
                'city' => $company->city,
                'state' => $company->state,
                'country' => $company->country,
                'pincode' => $company->pincode,
                'phone' => $company->phone,
                'email' => $company->email,
                'gst_number' => $company->gst_number,
                'pan_number' => $company->pan_number,
                'website' => $company->website,
                'logo_url' => $company->logoUrl(),
                'favicon_url' => $company->faviconUrl(),
            ] : null,
        ]);
    }

    public function update(UpdateCompanySettingRequest $request): RedirectResponse
    {
        $company = CompanySetting::current() ?? new CompanySetting;
        $data = $request->safe()->only([
            'company_name',
            'address',
            'city',
            'state',
            'country',
            'pincode',
            'phone',
            'email',
            'gst_number',
            'pan_number',
            'website',
        ]);

        foreach ($data as $key => $value) {
            if ($value === '') {
                $data[$key] = null;
            }
        }

        if ($request->hasFile('logo')) {
            $this->deletePublicFile($company->logo_path);
            $data['logo_path'] = StoredUpload::store($request->file('logo'), 'company', 'public');
        }

        if ($request->hasFile('favicon')) {
            $this->deletePublicFile($company->favicon_path);
            $data['favicon_path'] = StoredUpload::store($request->file('favicon'), 'company', 'public');
        }

        $company->fill($data);
        $company->save();

        return redirect()
            ->route('settings.company.edit')
            ->with('success', 'Company settings saved.');
    }

    private function deletePublicFile(?string $path): void
    {
        if ($path) {
            Storage::disk('public')->delete($path);
        }
    }
}
