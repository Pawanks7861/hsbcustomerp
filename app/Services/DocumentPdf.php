<?php

namespace App\Services;

use App\Models\CompanySetting;
use Barryvdh\DomPDF\Facade\Pdf;
use Barryvdh\DomPDF\PDF as PdfBuilder;
use Symfony\Component\HttpFoundation\Response;

class DocumentPdf
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function inline(string $view, array $data, string $filename): Response
    {
        return $this->make($view, $data)->stream($this->filename($filename));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function download(string $view, array $data, string $filename): Response
    {
        return $this->make($view, $data)->download($this->filename($filename));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function make(string $view, array $data): PdfBuilder
    {
        return Pdf::loadView($view, $this->viewData($data))->setPaper('a4', 'portrait')
            ->setOption('defaultFont', 'DejaVu Sans')
            ->setOption('isRemoteEnabled', false)
            ->setOption('isHtml5ParserEnabled', true)
            ->setOption('chroot', base_path());
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function viewData(array $data): array
    {
        $company = CompanySetting::current();
        $logo = $this->logoBox($company?->logoPath());

        return [
            ...$data,
            'company' => $company,
            'logoPath' => $logo['path'],
            'logoWidth' => $logo['width'],
            'logoHeight' => $logo['height'],
            'companyLines' => $this->companyLines($company),
            'generatedAt' => now()->format('d M Y H:i'),
        ];
    }

    /**
     * @return array{path: ?string, width: ?int, height: ?int}
     */
    private function logoBox(?string $path): array
    {
        if ($path === null || ! is_file($path)) {
            return ['path' => null, 'width' => null, 'height' => null];
        }

        $size = @getimagesize($path);
        $width = 170;
        $height = 80;

        if (is_array($size) && $size[0] > 0 && $size[1] > 0) {
            $scale = min(170 / $size[0], 80 / $size[1], 1);
            $width = max(1, (int) round($size[0] * $scale));
            $height = max(1, (int) round($size[1] * $scale));
        }

        return ['path' => $path, 'width' => $width, 'height' => $height];
    }

    /**
     * @return list<array{label: string, text: string}>
     */
    private function companyLines(?CompanySetting $company): array
    {
        if ($company === null) {
            return [];
        }

        $lines = [];

        foreach (preg_split('/\R/', (string) $company->address) ?: [] as $line) {
            $text = trim($line);
            if ($text !== '') {
                $lines[] = ['label' => '', 'text' => $text];
            }
        }

        $place = collect([$company->city, $company->state])
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->implode(' ');

        if ($place !== '') {
            $lines[] = ['label' => '', 'text' => $place];
        }

        $country = trim((string) $company->country);
        $pin = trim((string) $company->pincode);
        $countryLine = trim($country.' '.$pin);

        if ($countryLine !== '') {
            $lines[] = ['label' => '', 'text' => $countryLine];
        }

        foreach ([
            'Phone' => $company->phone,
            'GST' => $company->gst_number,
            'PAN' => $company->pan_number,
            'Email' => $company->email,
            'Website' => $company->website,
        ] as $label => $value) {
            $text = trim((string) $value);
            if ($text !== '') {
                $lines[] = ['label' => $label, 'text' => $text];
            }
        }

        return $lines;
    }

    private function filename(string $filename): string
    {
        $name = trim($filename);
        if (! str_ends_with(strtolower($name), '.pdf')) {
            $name .= '.pdf';
        }

        return preg_replace('/[^A-Za-z0-9._-]+/', '-', $name) ?: 'document.pdf';
    }
}
