<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class CompanySetting extends Model
{
    protected $fillable = [
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
        'logo_path',
        'favicon_path',
    ];

    public static function current(): ?self
    {
        return static::query()->first();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function shared(): ?array
    {
        $company = static::current();

        if ($company === null) {
            return null;
        }

        return [
            'company_name' => $company->company_name,
            'logo_url' => $company->logoUrl(),
            'favicon_url' => $company->faviconUrl(),
        ];
    }

    /**
     * @return array{href: string, type: string}|null
     */
    public static function faviconLink(): ?array
    {
        $company = static::current();
        $uploaded = $company?->faviconUrl();

        if ($uploaded) {
            return [
                'href' => $uploaded,
                'type' => $company->faviconType(),
            ];
        }

        if (is_file(public_path('favicon.ico'))) {
            return [
                'href' => '/favicon.ico',
                'type' => 'image/x-icon',
            ];
        }

        return null;
    }

    public static function faviconHref(): ?string
    {
        return static::faviconLink()['href'] ?? null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function document(): ?array
    {
        return [
            'company_name' => $this->company_name,
            'address' => $this->address,
            'city' => $this->city,
            'state' => $this->state,
            'country' => $this->country,
            'pincode' => $this->pincode,
            'phone' => $this->phone,
            'email' => $this->email,
            'gst_number' => $this->gst_number,
            'pan_number' => $this->pan_number,
            'website' => $this->website,
            'logo_url' => $this->logoUrl(),
        ];
    }

    public function logoUrl(): ?string
    {
        return $this->publicUrl($this->logo_path);
    }

    public function faviconUrl(): ?string
    {
        return $this->publicUrl($this->favicon_path);
    }

    public function faviconType(): string
    {
        return match (strtolower(pathinfo((string) $this->favicon_path, PATHINFO_EXTENSION))) {
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            'jpg', 'jpeg' => 'image/jpeg',
            'webp' => 'image/webp',
            default => 'image/png',
        };
    }

    public function logoPath(): ?string
    {
        return $this->localPublicPath($this->logo_path);
    }

    private function publicUrl(?string $path): ?string
    {
        if (! $this->isStoredPath($path) || ! Storage::disk('public')->exists($path)) {
            return null;
        }

        $url = '/storage/'.str_replace('\\', '/', $path);
        $version = $this->updated_at?->getTimestamp();

        return $version ? $url.'?v='.$version : $url;
    }

    private function localPublicPath(?string $path): ?string
    {
        if (! $this->isStoredPath($path)) {
            return null;
        }

        $full = Storage::disk('public')->path($path);

        return is_file($full) ? $full : null;
    }

    private function isStoredPath(?string $path): bool
    {
        if ($path === null || $path === '') {
            return false;
        }

        $normalized = str_replace('\\', '/', $path);

        return ! str_contains($normalized, '..')
            && ! str_contains($normalized, ':')
            && ! str_starts_with($normalized, '/');
    }
}
