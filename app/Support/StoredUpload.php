<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class StoredUpload
{
    /** @var list<string> */
    public const IMAGES = ['jpg', 'jpeg', 'png', 'webp'];

    /** @var list<string> */
    public const DOCUMENTS = ['pdf', 'jpg', 'jpeg', 'png', 'webp'];

    /** @var list<string> */
    public const SPREADSHEETS = ['xlsx', 'xls', 'csv'];

    /** @var array<string, string> */
    private const MIME_BY_EXTENSION = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
        'pdf' => 'application/pdf',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'xls' => 'application/vnd.ms-excel',
        'csv' => 'text/csv',
    ];

    /** @var list<string> */
    private const INLINE_MIMES = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'application/pdf',
    ];

    /** @var list<string> */
    private const EXECUTABLE_MIMES = [
        'text/html',
        'application/xhtml+xml',
        'image/svg+xml',
        'text/xml',
        'application/xml',
        'application/javascript',
        'text/javascript',
        'application/x-httpd-php',
    ];

    /**
     * @param  list<string>  $extensions
     * @return list<string>
     */
    public static function rules(array $extensions, int $maxKilobytes): array
    {
        $mimes = [];
        foreach ($extensions as $extension) {
            $mime = self::MIME_BY_EXTENSION[$extension] ?? null;
            if ($mime !== null) {
                $mimes[] = $mime;
            }
        }

        if (in_array('csv', $extensions, true)) {
            $mimes[] = 'text/plain';
            $mimes[] = 'application/csv';
        }

        $rules = [
            'file',
            'max:'.$maxKilobytes,
            'mimes:'.implode(',', $extensions),
            'extensions:'.implode(',', $extensions),
        ];

        if (! array_intersect($extensions, self::SPREADSHEETS)) {
            $rules[] = 'mimetypes:'.implode(',', array_values(array_unique($mimes)));
        }

        return $rules;
    }

    public static function store(UploadedFile $file, string $directory, string $disk = 'local'): string
    {
        $directory = trim(str_replace('\\', '/', $directory), '/');
        abort_if($directory === '' || str_contains($directory, '..'), 500);

        $extension = strtolower((string) ($file->guessExtension() ?: $file->getClientOriginalExtension()));
        abort_if($extension === '' || str_contains($extension, '/') || str_contains($extension, '\\'), 422);

        $name = Str::uuid()->toString().'.'.$extension;

        return $file->storeAs($directory, $name, $disk);
    }

    public static function downloadName(?string $original, string $path): string
    {
        $value = str_replace(["\r", "\n", '"', "\0"], '', (string) $original);
        $value = str_replace('\\', '/', $value);
        $base = basename($value);
        $base = preg_replace('/[^A-Za-z0-9._-]+/', '-', $base) ?? '';
        $base = trim($base, '.-');

        if ($base === '' || $base === '.' || $base === '..') {
            $fallback = basename(str_replace('\\', '/', $path));
            $base = trim((string) preg_replace('/[^A-Za-z0-9._-]+/', '-', $fallback), '.-');
        }

        return $base !== '' ? $base : 'download';
    }

    public static function serve(
        string $path,
        ?string $downloadName = null,
        bool $inlineIfSafe = false,
        string $disk = 'local',
        ?string $expectedPrefix = null,
    ): StreamedResponse {
        $normalized = str_replace('\\', '/', $path);
        abort_if(
            $normalized === ''
            || str_contains($normalized, '..')
            || str_starts_with($normalized, '/')
            || str_contains($normalized, ':'),
            404
        );

        if ($expectedPrefix !== null) {
            $prefix = trim(str_replace('\\', '/', $expectedPrefix), '/').'/';
            abort_unless(str_starts_with($normalized, $prefix), 404);
        }

        abort_unless(Storage::disk($disk)->exists($normalized), 404);

        $detected = Storage::disk($disk)->mimeType($normalized) ?: 'application/octet-stream';
        $executable = in_array($detected, self::EXECUTABLE_MIMES, true);
        $knownSafe = in_array($detected, self::INLINE_MIMES, true);
        $safeInline = $inlineIfSafe && ! $executable && $knownSafe;
        $contentType = ($executable || ! $knownSafe) ? 'application/octet-stream' : $detected;

        return Storage::disk($disk)->response(
            $normalized,
            self::downloadName($downloadName, $normalized),
            [
                'Content-Type' => $contentType,
                'X-Content-Type-Options' => 'nosniff',
            ],
            $safeInline ? 'inline' : 'attachment',
        );
    }
}
